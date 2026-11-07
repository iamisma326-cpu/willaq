#!/usr/bin/env php
<?php
/**
 * WILLAQ — CALIBRACIÓN DE CLIMATOLOGÍA
 *
 * Calcula, por distrito, los percentiles históricos (p50 / p95 / p99) de
 * precipitación diaria y temperatura aparente usando ERA5 (Open-Meteo Archive).
 * Esos valores son los que el motor de riesgo usa para normalizar el peligro:
 * sin ellos, WILLAQ cae a los umbrales regionales de respaldo.
 *
 * ¿Por qué un script aparte y no dentro de la web?
 *   Porque son 29 distritos × ~15 años de datos. Hacerlo en una petición HTTP
 *   congelaría el navegador. La web solo LEE la caché; este script la ESCRIBE.
 *
 * Uso:
 *   php scripts/calibrar.php                  # calcula lo que falte
 *   php scripts/calibrar.php --force          # recalcula todo
 *   php scripts/calibrar.php --limite=5       # prueba rápida con 5 distritos
 *   php scripts/calibrar.php --solo=piu       # solo distritos que coincidan
 *   php scripts/calibrar.php --ayuda
 *
 * Salida:
 *   data/cache/climatologia_<id>_*.json   (caché por distrito)
 *   data/climatologia_resumen.json        (resumen legible para el jurado)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Clima.php';
require_once __DIR__ . '/../models/Cache.php';

// La calibración siempre requiere red, aunque el .env esté en modo feria.
putenv('WILLAQ_MODO_OFFLINE=0');
$_ENV['WILLAQ_MODO_OFFLINE'] = '0';
$_SERVER['WILLAQ_MODO_OFFLINE'] = '0';

set_time_limit(0);
ini_set('memory_limit', '512M');

$opciones = getopt('', ['force', 'limite::', 'solo::', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · calibración de climatología

  --force         Recalcula aunque exista caché vigente.
  --limite=N      Procesa solo los primeros N distritos.
  --solo=TEXTO    Filtra por id, distrito o región (ej. --solo=piura).
  --ayuda         Muestra esta ayuda.

La calibración consulta ERA5 y puede tardar varios minutos la primera vez.
Sin ella, el motor usa los umbrales regionales de respaldo.

TXT;
    exit(0);
}

$forzar = isset($opciones['force']);
$limite = isset($opciones['limite']) ? max(1, (int)$opciones['limite']) : null;
$filtro = isset($opciones['solo']) ? trim((string)$opciones['solo']) : null;

$territorios = territorios_todos();

if ($filtro !== null && $filtro !== '') {
    $buscado = territorio_sin_tildes(mb_strtolower($filtro));
    $territorios = array_values(array_filter($territorios, function ($t) use ($buscado) {
        $texto = territorio_sin_tildes(mb_strtolower(
            $t['id'] . ' ' . $t['distrito'] . ' ' . $t['provincia'] . ' ' . $t['region']
        ));
        return str_contains($texto, $buscado);
    }));
}

if ($limite !== null) {
    $territorios = array_slice($territorios, 0, $limite);
}

if (!$territorios) {
    fwrite(STDERR, "No hay distritos que coincidan con el filtro.\n");
    exit(1);
}

$dirDatos = __DIR__ . '/../data';
foreach ([$dirDatos, $dirDatos . '/cache'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function wq_linea($texto = '') {
    echo $texto . PHP_EOL;
}

function wq_ancho($texto, $ancho) {
    $largo = mb_strlen($texto);
    return $texto . str_repeat(' ', max(0, $ancho - $largo));
}

$inicioTotal = microtime(true);

wq_linea();
wq_linea('  ╔══════════════════════════════════════════════════════════╗');
wq_linea('  ║  WILLAQ · calibración de climatología (ERA5)             ║');
wq_linea('  ╚══════════════════════════════════════════════════════════╝');
wq_linea('  Distritos: ' . count($territorios) . ($forzar ? '   Modo: forzar recálculo' : '   Modo: incremental'));
wq_linea();

wq_linea('  ' . wq_ancho('#', 3) . wq_ancho('DISTRITO', 26) . wq_ancho('ZONA', 16)
    . wq_ancho('p50', 8) . wq_ancho('p95', 8) . wq_ancho('p99', 8)
    . wq_ancho('T p95', 8) . wq_ancho('DÍAS', 7) . 'ESTADO');
wq_linea('  ' . str_repeat('─', 104));

$resultados = [];
$ok = 0;
$desdeCache = 0;
$fallos = [];
$inicioScript = time();

foreach ($territorios as $i => $t) {
    $n = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
    $clave = 'climatologia:' . $t['id'];

    $previo = cache_leer($clave, true);
    $habiaCache = $previo && isset($previo['payload']['p95_24']);

    if ($habiaCache && !$forzar) {
        $clima = $previo['payload'];
        $estado = 'caché';
        $desdeCache++;
    } else {
        $inicio = microtime(true);
        $clima = clima_climatologia($t, true);
        $ms = number_format((microtime(true) - $inicio) * 1000, 0, '.', '');
        $estado = $clima ? 'calculado · ' . $ms . ' ms' : 'SIN DATO';
    }

    if ($clima && isset($clima['p95_24'])) {
        $ok++;
        wq_linea('  ' . wq_ancho($n, 3) . wq_ancho($t['distrito'], 26)
            . wq_ancho(str_replace('_', ' ', $t['zona']), 16)
            . wq_ancho(number_format((float)$clima['p50_24'], 2, '.', ''), 8)
            . wq_ancho(number_format((float)$clima['p95_24'], 2, '.', ''), 8)
            . wq_ancho(number_format((float)$clima['p99_24'], 2, '.', ''), 8)
            . wq_ancho(number_format((float)($clima['p95_temp'] ?? 0), 1, '.', ''), 8)
            . wq_ancho((string)($clima['muestra_dias'] ?? 0), 7)
            . $estado);

        $resultados[] = [
            'id' => $t['id'],
            'distrito' => $t['distrito'],
            'provincia' => $t['provincia'],
            'region' => $t['region'],
            'zona' => $t['zona'],
            'lat' => $t['lat'],
            'lon' => $t['lon'],
            'p50_24' => $clima['p50_24'],
            'p95_24' => $clima['p95_24'],
            'p99_24' => $clima['p99_24'],
            'p50_temp' => $clima['p50_temp'] ?? null,
            'p95_temp' => $clima['p95_temp'] ?? null,
            'p99_temp' => $clima['p99_temp'] ?? null,
            'p50_90' => $clima['p50_90'] ?? null,
            'p95_90' => $clima['p95_90'] ?? null,
            'muestra_dias' => $clima['muestra_dias'] ?? null,
            'fuente' => $clima['fuente'] ?? 'ERA5',
            'obtenido_en' => $clima['obtenido_en'] ?? time(),
        ];
    } else {
        $fallos[] = $t['distrito'] . ' (' . $t['region'] . ')';
        wq_linea('  ' . wq_ancho($n, 3) . wq_ancho($t['distrito'], 26)
            . wq_ancho(str_replace('_', ' ', $t['zona']), 16)
            . wq_ancho('—', 8) . wq_ancho('—', 8) . wq_ancho('—', 8)
            . wq_ancho('—', 8) . wq_ancho('—', 7)
            . $estado);
    }

    // Pausa breve: el servicio público de ERA5 tolera mejor las peticiones
    // espaciadas que las ráfagas (evita 429 y timeouts en las zonas de Lima).
    usleep(400000);
}

// ---------------------------------------------------------------------------
// Resumen para el jurado y para la consola de administración
// ---------------------------------------------------------------------------
$porZona = [];
foreach ($resultados as $r) {
    $z = $r['zona'];
    $porZona[$z][] = (float)$r['p95_24'];
}

$resumenZonas = [];
foreach ($porZona as $zona => $valores) {
    $resumenZonas[$zona] = [
        'distritos' => count($valores),
        'p95_24_promedio' => round(array_sum($valores) / count($valores), 2),
    ];
}

$salida = [
    'generado_en' => $inicioScript,
    'generado_legible' => date('c', $inicioScript),
    'fuente' => 'Open-Meteo Archive (ERA5) · ventana de calendario ±15 días · últimos 11 años',
    'metodo' => 'Percentiles empíricos de precipitación diaria y temperatura aparente máxima.',
    'distritos_ok' => $ok,
    'distritos_total' => count($territorios),
    'desde_cache' => $desdeCache,
    'fallos' => $fallos,
    'zonas' => $resumenZonas,
    'distritos' => $resultados,
];

$rutaResumen = $dirDatos . '/climatologia_resumen.json';
$json = json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$escrito = $json !== false && @file_put_contents($rutaResumen, $json . PHP_EOL) !== false;

wq_linea();
wq_linea('  ' . str_repeat('─', 104));
wq_linea(sprintf('  Calibrados: %d/%d   ·   desde caché: %d   ·   fallos: %d',
    $ok, count($territorios), $desdeCache, count($fallos)));
wq_linea('  Tiempo total: ' . number_format(microtime(true) - $inicioTotal, 1, '.', '') . ' s');

if ($resumenZonas) {
    wq_linea();
    wq_linea('  p95 de precipitación por zona (mm/día):');
    foreach ($resumenZonas as $zona => $meta) {
        wq_linea(sprintf('    %-18s %2d distritos   %5.2f mm',
            str_replace('_', ' ', $zona), $meta['distritos'], $meta['p95_24_promedio']));
    }
}

if ($escrito) {
    wq_linea();
    wq_linea('  ✓ Resumen escrito en data/climatologia_resumen.json');
} else {
    wq_linea();
    wq_linea('  ⚠ No se pudo escribir data/climatologia_resumen.json (permisos).');
}

if ($fallos) {
    wq_linea();
    wq_linea('  ⚠ Distritos sin climatología (usarán umbrales de respaldo):');
    foreach (array_slice($fallos, 0, 12) as $fallo) {
        wq_linea('     - ' . $fallo);
    }
    if (count($fallos) > 12) {
        wq_linea('     … y ' . (count($fallos) - 12) . ' más.');
    }
}

wq_linea();
wq_linea('  Siguiente paso: php scripts/actualizar.php');
wq_linea();

exit($ok > 0 ? 0 : 1);
