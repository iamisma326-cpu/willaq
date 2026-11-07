#!/usr/bin/env php
<?php
/**
 * WILLAQ — SIEMBRA DE RECOMENDACIONES
 *
 * Genera recomendaciones de apoyo a la decisión a partir del snapshot de
 * riesgo vigente, para que la vista de alertas tenga contenido real durante la
 * demostración (y para auditar qué se habría recomendado en cada evento).
 *
 * IMPORTANTE: no son alertas oficiales. El texto legal viaja con cada registro.
 *
 * Uso:
 *   php scripts/semear_alertas.php              # crea las que falten
 *   php scripts/semear_alertas.php --limpiar    # borra todas y vuelve a crear
 *   php scripts/semear_alertas.php --nivel=rojo # solo un nivel
 *   php scripts/semear_alertas.php --ayuda
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Cache.php';
require_once __DIR__ . '/../models/Clima.php';
require_once __DIR__ . '/../models/Riesgo.php';
require_once __DIR__ . '/../models/Alerta.php';

$opciones = getopt('', ['limpiar', 'nivel::', 'ventana::', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · siembra de recomendaciones

  --limpiar      Borra las existentes antes de sembrar.
  --nivel=ID     Solo siembra un nivel: verde, amarillo, naranja o rojo.
  --ventana=H    Ventana del snapshot a usar: 24, 48 o 72 (por defecto 24).
  --ayuda        Muestra esta ayuda.

TXT;
    exit(0);
}

$ventanaSemilla = (int)($opciones['ventana'] ?? 24);
if (!in_array($ventanaSemilla, [24, 48, 72], true)) {
    fwrite(STDERR, "Ventana inválida: {$ventanaSemilla}. Use 24, 48 o 72.\n");
    exit(1);
}

$nivelPedido = $opciones['nivel'] ?? null;
if ($nivelPedido !== null && !in_array($nivelPedido, ['verde', 'amarillo', 'naranja', 'rojo'], true)) {
    fwrite(STDERR, "Nivel inválido: {$nivelPedido}\n");
    exit(1);
}

if (isset($opciones['limpiar'])) {
    alertas_guardar([]);
    echo "  · Alertas anteriores eliminadas.\n";
}
$snapshot = riesgo_snapshot_leer($ventanaSemilla);
if (!$snapshot || empty($snapshot['riesgos'])) {
    fwrite(STDERR, "No hay snapshot de {$ventanaSemilla} h. Ejecute primero: php scripts/actualizar.php --ventana={$ventanaSemilla}\n");
    exit(1);
}

$existentes = alertas_todas();
$creadas = 0;
$omitidas = 0;

foreach ($snapshot['riesgos'] as $r) {
    $nivel = $r['nivel']['id'];
    if ($nivelPedido !== null && $nivel !== $nivelPedido) {
        continue;
    }
    if ($nivel === 'verde') {
        continue; // no se recomienda nada en nivel bajo
    }

    $t = $r['territorio'];
    $amenaza = $r['principal'];

    // Evita duplicados: misma amenaza y mismo nivel para el mismo distrito.
    $duplicada = false;
    foreach ($existentes as $a) {
        if ($a['territorio_id'] === $t['id'] && $a['amenaza'] === $amenaza && $a['nivel'] === $nivel) {
            $duplicada = true;
            break;
        }
    }
    if ($duplicada) {
        $omitidas++;
        continue;
    }

    $sugerida = alerta_sugerir_desde_riesgo($r);
    $alerta = alerta_crear(
        $t,
        $amenaza,
        $nivel,
        $sugerida['mensaje'],
        'Sistema · WILLAQ'
    );
    $existentes[] = $alerta;
    $creadas++;

    printf(
        "  ✓ %-26s %-22s %-8s %s\n",
        $t['distrito'],
        $sugerida['titulo'],
        $nivel,
        $alerta['codigo']
    );
}

$almacen = alertas_bd_disponible()
    ? db_motor_nombre() . ' (con espejo JSON)'
    : 'archivo JSON';

echo "\n  Creadas: {$creadas}   Omitidas (duplicadas): {$omitidas}   Total almacenado: " . count(alertas_todas()) . "\n";
echo "  Almacenamiento: {$almacen}\n";
echo "  Recuerde: son recomendaciones de apoyo a la decisión, no alertas oficiales.\n\n";

exit(0);
