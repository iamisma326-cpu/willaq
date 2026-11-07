#!/usr/bin/env php
<?php
/**
 * WILLAQ — ACTUALIZACIÓN Y PRECALENTAMIENTO
 *
 * Uso:
 *   php scripts/actualizar.php                 # calienta caché y genera snapshot 24 h
 *   php scripts/actualizar.php --ventana=all   # genera snapshots 24, 48 y 72 h
 *   php scripts/actualizar.php --solo=snapshot # solo recalcula el snapshot
 *   php scripts/actualizar.php --limite=5      # prueba rápida con 5 distritos
 *   php scripts/actualizar.php --ayuda
 *
 * Por qué existe: la primera carga sin caché realiza ~90 llamadas HTTP.
 * Este script las hace una vez, deja los archivos en data/cache/ y guarda
 * data/snapshots/riesgo_nacional_{24,48,72}h.json para que la feria
 * funcione sin internet.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Clima.php';
require_once __DIR__ . '/../models/Riesgo.php';
require_once __DIR__ . '/../models/Zonificacion.php';
require_once __DIR__ . '/../models/Simulador.php';
require_once __DIR__ . '/../models/Alerta.php';
require_once __DIR__ . '/../models/Cache.php';

// El script siempre trabaja en línea, incluso si el .env dice offline.
putenv('WILLAQ_MODO_OFFLINE=0');
$_ENV['WILLAQ_MODO_OFFLINE'] = '0';
$_SERVER['WILLAQ_MODO_OFFLINE'] = '0';

set_time_limit(0);
ini_set('memory_limit', '512M');

$opciones = getopt('', ['ventana::', 'solo::', 'limite::', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · actualizar datos

  --ventana=24|48|72|all   Ventana(s) del snapshot (por defecto 24).
  --solo=snapshot          No consulta APIs; solo recalcula con la caché actual.
  --limite=N               Procesa solo los primeros N distritos.
  --ayuda                  Muestra esta ayuda.

TXT;
    exit(0);
}

$soloSnapshot = ($opciones['solo'] ?? '') === 'snapshot';
$limite = isset($opciones['limite']) ? max(1, (int)$opciones['limite']) : null;
$ventanaArg = $opciones['ventana'] ?? '24';
$ventanas = $ventanaArg === 'all' ? [24, 48, 72] : [(int)$ventanaArg];

foreach ($ventanas as $v) {
    if (!in_array($v, [24, 48, 72], true)) {
        fwrite(STDERR, "Ventana inválida: {$v}. Use 24, 48, 72 o all.\n");
        exit(1);
    }
}

function wq_linea($texto = '') {
    echo $texto . PHP_EOL;
}

function wq_titulo($texto) {
    wq_linea();
    wq_linea('  ' . $texto);
    wq_linea('  ' . str_repeat('─', max(10, mb_strlen($texto))));
}

function wq_ms($inicio) {
    return number_format((microtime(true) - $inicio) * 1000, 0, '.', '');
}

$inicioTotal = microtime(true);
$territorios = territorios_todos();
if ($limite !== null) {
    $territorios = array_slice($territorios, 0, $limite);
}

// Asegurar estructura de datos
foreach (['data/cache', 'data/snapshots'] as $dir) {
    $ruta = __DIR__ . '/../' . $dir;
    if (!is_dir($ruta)) {
        mkdir($ruta, 0775, true);
    }
}
$rutaAlertas = alertas_ruta();
if (!is_file($rutaAlertas)) {
    file_put_contents($rutaAlertas, json_encode(['alertas' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    wq_linea('  · Creado data/alertas.json vacío.');
}

wq_linea();
wq_linea('  ╔══════════════════════════════════════════════════════════╗');
wq_linea('  ║  WILLAQ · actualización de datos                         ║');
wq_linea('  ╚══════════════════════════════════════════════════════════╝');
wq_linea('  Distritos: ' . count($territorios) . '   Ventanas: ' . implode(', ', $ventanas) . ' h');
wq_linea('  Modo: ' . ($soloSnapshot ? 'solo snapshot (sin red)' : 'consulta de APIs + snapshot'));

// ---------------------------------------------------------------------------
// 1. Calentar caché por distrito
// ---------------------------------------------------------------------------
if (!$soloSnapshot) {
    wq_titulo('1. Consultando fuentes por distrito');

    $okForecast = 0;
    $okFlood = 0;
    $okReciente = 0;
    $fallos = [];

    foreach ($territorios as $i => $t) {
        $n = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
        $etiqueta = sprintf('%s. %-24s %-12s', $n, $t['distrito'], $t['region']);
        echo '  ' . $etiqueta;

        $inicio = microtime(true);
        $f = clima_forecast($t['lat'], $t['lon']);
        $fl = clima_flood($t['lat'], $t['lon']);
        $rec = clima_reciente($t['lat'], $t['lon'], 90);

        if ($f) {
            $okForecast++;
        } else {
            $fallos[] = $t['distrito'] . ' (forecast)';
        }
        if ($fl) {
            $okFlood++;
        } else {
            $fallos[] = $t['distrito'] . ' (flood)';
        }
        if ($rec) {
            $okReciente++;
        } else {
            $fallos[] = $t['distrito'] . ' (reciente)';
        }

        $marca = ($f && $fl) ? '✓' : '⚠';
        printf("  %s  %5s ms\n", $marca, wq_ms($inicio));
    }

    wq_linea();
    wq_linea(sprintf('  Pronóstico: %d/%d · Caudal: %d/%d · Histórico: %d/%d',
        $okForecast, count($territorios),
        $okFlood, count($territorios),
        $okReciente, count($territorios)
    ));

    if ($fallos) {
        wq_linea();
        wq_linea('  Avisos (se usará caché de respaldo si existe):');
        foreach (array_slice($fallos, 0, 12) as $fallo) {
            wq_linea('    ⚠ ' . $fallo);
        }
        if (count($fallos) > 12) {
            wq_linea('    … y ' . (count($fallos) - 12) . ' más.');
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Generar snapshots
// ---------------------------------------------------------------------------
wq_titulo('2. Generando snapshots de riesgo');

foreach ($ventanas as $ventana) {
    $inicio = microtime(true);
    $ok = riesgo_snapshot_guardar($ventana);
    $ruta = riesgo_snapshot_ruta($ventana);
    $peso = is_file($ruta) ? round(filesize($ruta) / 1024, 1) : 0;

    if ($ok) {
        $snap = riesgo_snapshot_leer($ventana);
        $conteo = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
        foreach (($snap['riesgos'] ?? []) as $r) {
            $conteo[$r['nivel']['id']] = ($conteo[$r['nivel']['id']] ?? 0) + 1;
        }
        wq_linea(sprintf(
            '  ✓ Ventana %2d h → %s  (%s KB, %s ms)',
            $ventana,
            basename($ruta),
            number_format($peso, 1, '.', ''),
            wq_ms($inicio)
        ));
        wq_linea(sprintf(
            '    Riesgo: %d rojo · %d naranja · %d amarillo · %d verde',
            $conteo['rojo'], $conteo['naranja'], $conteo['amarillo'], $conteo['verde']
        ));
        if (!empty($snap['riesgos'][0])) {
            $peor = $snap['riesgos'][0];
            wq_linea(sprintf(
                '    Mayor riesgo: %s (%s) con %d/100 — %s',
                $peor['territorio']['distrito'],
                $peor['territorio']['region'],
                (int)round($peor['riesgo']),
                $peor['nivel']['etiqueta']
            ));
        }
    } else {
        wq_linea('  ✗ No se pudo escribir el snapshot de ' . $ventana . ' h.');
        wq_linea('    Verifique permisos de escritura en data/snapshots/');
    }
}

// ---------------------------------------------------------------------------
// 3. Resumen
// ---------------------------------------------------------------------------
$cache = cache_estadisticas();

wq_titulo('3. Resumen');

wq_linea('  Caché: ' . $cache['archivos'] . ' archivos · '
    . $cache['vigentes'] . ' vigentes · '
    . $cache['expirados'] . ' expirados · '
    . number_format($cache['mb'], 2, '.', '') . ' MB');
wq_linea('  Tiempo total: ' . number_format(microtime(true) - $inicioTotal, 1, '.', '') . ' s');
wq_linea();
wq_linea('  Para la feria:');
wq_linea('    WILLAQ_MODO_OFFLINE=1 php -S localhost:8000');
wq_linea('  o simplemente ejecutar el servidor y presentar con el snapshot.');
wq_linea();
wq_linea('  ✓ Listo.');
wq_linea();

exit(0);
