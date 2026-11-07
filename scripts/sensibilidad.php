#!/usr/bin/env php
<?php
/**
 * WILLAQ — ANÁLISIS DE SENSIBILIDAD
 *
 * Pregunta: ¿el ranking de riesgo depende de los pesos que elegimos, o es
 * una decisión de los parámetros?
 *
 * Método
 * ------
 * La fórmula del índice es
 *
 *     R = 100 · H · (base + wE·E + wV·V)
 *
 * y los pesos (base, exposición, vulnerabilidad) aparecen ÚNICAMENTE en esa
 * expresión final: el peligro H y la exposición E no dependen de ellos. Por
 * eso este script:
 *
 *   1. Calcula UNA VEZ el riesgo de cada distrito con el motor real, y extrae
 *      H, E y V de las seis amenazas.
 *   2. Recalcula el índice para cada escenario de pesos con aritmética pura.
 *
 * El resultado es IDÉNTICO a volver a ejecutar el motor con otros pesos, pero
 * tarda segundos en lugar de minutos. Para verificarlo, el script incluye la
 * comprobación `--verificar`: recalcula un escenario completo con el motor
 * real y compara con el cálculo rápido. Si difieren, el script lo dice.
 *
 * Escenarios
 * ----------
 *   · cada peso por separado en −20 %, −10 %, +10 %, +20 %
 *   · las 27 combinaciones simultáneas de {−20 %, 0, +20 %}³
 *   · el caso extremo: base = 0 (solo E y V) y base = 1 (todo peligro)
 *
 * Métricas
 * --------
 *   · correlación de rangos de Spearman con el ranking base
 *   · solapamiento del top-10 (Jaccard)
 *   · distritos que cambian de nivel
 *   · banda de posición de cada distrito (mínima–máxima) en todo el abanico
 *
 * Uso
 * ---
 *   php scripts/sensibilidad.php
 *   php scripts/sensibilidad.php --verificar
 *   php scripts/sensibilidad.php --ayuda
 *
 * Salida: data/sensibilidad.json
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

$opciones = getopt('', ['verificar', 'ayuda']);

if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · análisis de sensibilidad

  Varía los pesos del índice (±20 % y combinaciones) y mide cuánto cambia
  el ranking de riesgo de los 29 distritos.

  Métricas: Spearman, solapamiento del top-10, cambios de nivel, banda de
  posición por distrito.

  --verificar   Recalcula un escenario con el motor real y comprueba que
                coincide con el cálculo rápido (debe salir "coincide").
  --ayuda       Esta ayuda.

  Salida: data/sensibilidad.json

TXT;
    exit(0);
}

set_time_limit(0);
ini_set('memory_limit', '512M');

/** Pesos base: deben coincidir con config/umbrales.php. */
$PESOS_BASE = ['base' => 0.40, 'exposicion' => 0.35, 'vulnerabilidad' => 0.25];

/** Multiplicadores aplicados a cada peso (etiqueta => factor). */
$MULT = [
    '-20%' => 0.80,
    '-10%' => 0.90,
    '+10%' => 1.10,
    '+20%' => 1.20,
];

function wq_linea($texto = '') {
    echo $texto . PHP_EOL;
}

/**
 * Recalcula el índice de un distrito con otros pesos, usando los H/E/V ya
 * calculados por el motor real. Es aritméticamente idéntico a volver a
 * ejecutar riesgo_calcular() con esos pesos.
 */
function wq_recalcular($riesgoBase, $pesos) {
    $mejor = 0.0;
    $principal = null;
    foreach ($riesgoBase['amenazas'] as $id => $a) {
        $r = 100.0 * (float)$a['peligro'] * (
            $pesos['base']
            + $pesos['exposicion'] * (float)$a['exposicion']
            + $pesos['vulnerabilidad'] * (float)$a['vulnerabilidad']
        );
        $r = max(0.0, min(100.0, $r));
        if ($r > $mejor) {
            $mejor = $r;
            $principal = $id;
        }
    }
    return ['riesgo' => round($mejor, 1), 'principal' => $principal];
}

/**
 * Ranking por riesgo descendente. Los empates comparten rango medio
 * (Spearman con rangos promedio).
 *
 * @param array $lista id => ['riesgo'=>float, ...]
 * @return array id => posición (1-based, promedio en empates)
 */
function wq_ranking($lista) {
    // Ordenar por riesgo descendente, luego por id para que sea determinista.
    $orden = $lista;
    uasort($orden, fn($a, $b) => [$b['riesgo'], $a['id']] <=> [$a['riesgo'], $b['id']]);

    // Las claves en el orden final se calculan UNA vez: evita rehacer
    // array_keys() en cada comparación (O(n²)) y cualquier acceso fuera de rango.
    $ids = array_keys($orden);
    $n = count($ids);

    $rangos = [];
    $i = 0;
    while ($i < $n) {
        $j = $i;
        $riesgoActual = $orden[$ids[$i]]['riesgo'];
        while ($j + 1 < $n && $orden[$ids[$j + 1]]['riesgo'] === $riesgoActual) {
            $j++;
        }
        // Rango promedio del bloque empatado (posiciones 1-based).
        $rangoMedio = (($i + 1) + ($j + 1)) / 2;
        for ($k = $i; $k <= $j; $k++) {
            $rangos[$ids[$k]] = $rangoMedio;
        }
        $i = $j + 1;
    }
    return $rangos;
}

/**
 * Identificadores del top-N según el ranking (orden determinista).
 * @param array $rangos id => posición
 * @param int   $n      cantidad de posiciones
 * @return array lista de id
 */
function wq_top_ids($rangos, $n = 10) {
    $ids = array_keys($rangos);
    usort($ids, function ($a, $b) use ($rangos) {
        if ($rangos[$a] == $rangos[$b]) {
            return strcmp($a, $b);
        }
        return $rangos[$a] <=> $rangos[$b];
    });
    return array_slice($ids, 0, $n);
}

/** Correlación de Pearson entre dos series de rangos. */
function wq_pearson($x, $y) {
    $n = count($x);
    if ($n < 2) {
        return null;
    }
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $num = 0.0;
    $dx2 = 0.0;
    $dy2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $a = $x[$i] - $mx;
        $b = $y[$i] - $my;
        $num += $a * $b;
        $dx2 += $a * $a;
        $dy2 += $b * $b;
    }
    if ($dx2 <= 0 || $dy2 <= 0) {
        return 1.0;
    }
    return round($num / sqrt($dx2 * $dy2), 4);
}

/** Índice de Jaccard entre dos conjuntos. */
function wq_jaccard($a, $b) {
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union > 0 ? round($inter / $union, 3) : 1.0;
}

// ------------------------------------------------------------------
// 1. Cálculo base con el motor real
// ------------------------------------------------------------------
$territorios = territorios_todos();
$ventana = 24;

wq_linea();
wq_linea('  ╔══════════════════════════════════════════════════════════╗');
wq_linea('  ║  WILLAQ · análisis de sensibilidad de los pesos           ║');
wq_linea('  ╚══════════════════════════════════════════════════════════╝');
wq_linea('  Distritos: ' . count($territorios) . '   Ventana: ' . $ventana . ' h');
wq_linea('  Pesos base: base=' . $PESOS_BASE['base']
    . '  exposición=' . $PESOS_BASE['exposicion']
    . '  vulnerabilidad=' . $PESOS_BASE['vulnerabilidad']);
wq_linea();

$base = [];
foreach ($territorios as $t) {
    $r = riesgo_calcular($t, $ventana);
    $base[$t['id']] = [
        'id' => $t['id'],
        'distrito' => $t['distrito'],
        'region' => $t['region'],
        'riesgo' => (float)$r['riesgo'],
        'nivel' => $r['nivel']['id'],
        'amenazas' => $r['amenazas'],
    ];
}

$rankingBase = wq_ranking($base);
$top10Base = wq_top_ids($rankingBase, 10);

wq_linea('  Ranking base (top 10):');
$ordenBase = $base;
uasort($ordenBase, fn($a, $b) => [$b['riesgo'], $a['id']] <=> [$a['riesgo'], $a['id']]);
$pos = 0;
foreach ($ordenBase as $id => $b) {
    if (++$pos > 10) {
        break;
    }
    wq_linea(sprintf(
        '    %2d. %-26s %5s  %s',
        $pos,
        $b['distrito'],
        $b['riesgo'],
        $b['nivel']
    ));
}
wq_linea();

// ------------------------------------------------------------------
// 2. Escenarios
// ------------------------------------------------------------------
$escenarios = [];
foreach ($PESOS_BASE as $clave => $valor) {
    foreach ($MULT as $etiqueta => $factor) {
        $p = $PESOS_BASE;
        $p[$clave] = round($valor * $factor, 4);
        // La etiqueta viene del array, no de aritmética de flotantes:
        // 0.2 * 100 no es exactamente 20 en IEEE-754 y producía claves
        // como "exposicion +20.000000000000004%", que rompían la verificación.
        $escenarios["{$clave} {$etiqueta}"] = $p;
    }
}
// Combinaciones simultáneas de {-20 %, 0, +20 %}³
$COMBOS = [
    '-20%' => 0.8,
    '0%'   => 1.0,
    '+20%' => 1.2,
];
foreach ($COMBOS as $etb => $mb) {
    foreach ($COMBOS as $ete => $me) {
        foreach ($COMBOS as $etv => $mv) {
            if ($mb === 1.0 && $me === 1.0 && $mv === 1.0) {
                continue; // es el escenario base
            }
            $nombre = sprintf('combo %s/%s/%s', $etb, $ete, $etv);
            $escenarios[$nombre] = [
                'base' => round($PESOS_BASE['base'] * $mb, 4),
                'exposicion' => round($PESOS_BASE['exposicion'] * $me, 4),
                'vulnerabilidad' => round($PESOS_BASE['vulnerabilidad'] * $mv, 4),
            ];
        }
    }
}
// Casos límite
$escenarios['limite sin base (0.00)'] = ['base' => 0.0, 'exposicion' => 0.50, 'vulnerabilidad' => 0.50];
$escenarios['limite todo peligro (1.00)'] = ['base' => 1.0, 'exposicion' => 0.35, 'vulnerabilidad' => 0.25];

$bandas = [];
foreach ($base as $id => $b) {
    $bandas[$id] = ['min' => $rankingBase[$id], 'max' => $rankingBase[$id]];
}

$resultados = [];
$peor = null;

foreach ($escenarios as $nombre => $pesos) {
    $esc = [];
    foreach ($base as $id => $b) {
        $rec = wq_recalcular($b, $pesos);
        $esc[$id] = [
            'id' => $id,
            'distrito' => $b['distrito'],
            'riesgo' => $rec['riesgo'],
            'nivel' => riesgo_nivel($rec['riesgo'])['id'],
        ];
    }
    $ranking = wq_ranking($esc);

    $xs = [];
    $ys = [];
    foreach ($base as $id => $b) {
        $xs[] = $rankingBase[$id];
        $ys[] = $ranking[$id];
    }
    $spearman = wq_pearson($xs, $ys);

    $top10 = wq_top_ids($ranking, 10);
    $jaccard = wq_jaccard($top10Base, $top10);

    $cambiosNivel = 0;
    foreach ($base as $id => $b) {
        if ($esc[$id]['nivel'] !== $b['nivel']) {
            $cambiosNivel++;
        }
        $bandas[$id]['min'] = min($bandas[$id]['min'], $ranking[$id]);
        $bandas[$id]['max'] = max($bandas[$id]['max'], $ranking[$id]);
    }

    $fila = [
        'nombre' => $nombre,
        'pesos' => $pesos,
        'spearman' => $spearman,
        'jaccard_top10' => $jaccard,
        'cambios_nivel' => $cambiosNivel,
        'top10' => $top10,
    ];
    $resultados[] = $fila;

    if ($peor === null || $spearman < $peor['spearman']) {
        $peor = $fila;
    }
}

// ------------------------------------------------------------------
// 3. Verificación: el cálculo rápido debe coincidir con el motor real
// ------------------------------------------------------------------
$verificacion = null;
if (isset($opciones['verificar'])) {
    wq_linea();
    wq_linea('  Verificando equivalencia con el motor real...');

    $claveVerificacion = 'exposicion +20%';
    if (!isset($escenarios[$claveVerificacion])) {
        wq_linea('    ✗ No existe el escenario de verificación: ' . $claveVerificacion);
        fwrite(STDERR, "Error interno: escenario de verificación no encontrado.\n");
        exit(1);
    }
    $prueba = $escenarios[$claveVerificacion];

    global $PESOS_RIESGO;
    $pesosOriginales = $PESOS_RIESGO;
    $PESOS_RIESGO = $prueba;

    $maxDiff = 0.0;
    foreach ($territorios as $t) {
        $r = riesgo_calcular($t, $ventana);
        $rapido = wq_recalcular($base[$t['id']], $prueba);
        $diff = abs((float)$r['riesgo'] - $rapido['riesgo']);
        $maxDiff = max($maxDiff, $diff);
    }
    $PESOS_RIESGO = $pesosOriginales;
    $verificacion = [
        'escenario' => 'exposicion +20%',
        'diferencia_maxima' => round($maxDiff, 4),
        'coincide' => $maxDiff < 0.05,
    ];
    wq_linea('    diferencia máxima: ' . round($maxDiff, 4)
        . '  →  ' . ($maxDiff < 0.05 ? 'COINCIDE ✓' : 'DIFIERE ✗'));
}

// ------------------------------------------------------------------
// 4. Informe
// ------------------------------------------------------------------
wq_linea();
wq_linea('  ────────────────────────────────────────────────────────────────');
wq_linea('  Resultado por escenario');
wq_linea('  ' . str_repeat('─', 78));
wq_linea('  ' . str_pad('ESCENARIO', 28) . str_pad('SPEARMAN', 11)
    . str_pad('TOP-10', 9) . 'CAMBIOS DE NIVEL');
wq_linea('  ' . str_repeat('─', 78));

usort($resultados, fn($a, $b) => $a['spearman'] <=> $b['spearman']);
foreach ($resultados as $r) {
    wq_linea(sprintf(
        '  %-28s %-11s %-9s %d',
        substr($r['nombre'], 0, 27),
        $r['spearman'],
        $r['jaccard_top10'],
        $r['cambios_nivel']
    ));
}

$spearmans = array_column($resultados, 'spearman');
$jaccards = array_column($resultados, 'jaccard_top10');
$cambios = array_column($resultados, 'cambios_nivel');

wq_linea();
wq_linea(sprintf('  Escenarios evaluados: %d', count($resultados)));
wq_linea(sprintf('  Spearman  · mínimo %.4f   medio %.4f   máximo %.4f',
    min($spearmans), array_sum($spearmans) / count($spearmans), max($spearmans)));
wq_linea(sprintf('  Top-10   · solapamiento mínimo %.3f   medio %.3f',
    min($jaccards), array_sum($jaccards) / count($jaccards)));
wq_linea(sprintf('  Nivel    · cambios mínimos %d   máximos %d',
    min($cambios), max($cambios)));
wq_linea();

wq_linea('  Banda de posición (mínima–máxima) de los 10 primeros:');
$bandasOrden = $bandas;
uasort($bandasOrden, fn($a, $b) => $a['min'] <=> $b['min']);
$pos = 0;
foreach ($bandasOrden as $id => $banda) {
    if (++$pos > 10) {
        break;
    }
    $ancho = $banda['max'] - $banda['min'];
    wq_linea(sprintf(
        '    %-26s %2d – %2d   (amplitud %d)',
        $base[$id]['distrito'],
        $banda['min'],
        $banda['max'],
        $ancho
    ));
}
wq_linea();

$ruta = __DIR__ . '/../data/sensibilidad.json';
$salida = [
    'generado_en' => time(),
    'generado_legible' => date('c'),
    'ventana' => $ventana,
    'distritos' => count($base),
    'pesos_base' => $PESOS_BASE,
    'metodo' => 'Los pesos (base, exposición, vulnerabilidad) solo intervienen en la expresión final '
        . 'R = 100·H·(base + wE·E + wV·V). El script calcula H, E y V una vez con el motor real y '
        . 'recalcula el índice con aritmética pura, lo que es idéntico a reejecutar el motor con '
        . 'otros pesos. La opción --verificar comprueba esa equivalencia contra el motor real.',
    'resumen' => [
        'escenarios' => count($resultados),
        'spearman_min' => min($spearmans),
        'spearman_medio' => round(array_sum($spearmans) / count($spearmans), 4),
        'spearman_max' => max($spearmans),
        'jaccard_top10_min' => min($jaccards),
        'jaccard_top10_medio' => round(array_sum($jaccards) / count($jaccards), 4),
        'cambios_nivel_min' => min($cambios),
        'cambios_nivel_max' => max($cambios),
        'peor_escenario' => $peor['nombre'],
    ],
    'verificacion' => $verificacion,
    'bandas_posicion' => $bandas,
    'escenarios' => $resultados,
];
$json = json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($ruta, $json . PHP_EOL) !== false) {
    wq_linea('  ✓ Resultado escrito en data/sensibilidad.json');
    wq_linea();
} else {
    wq_linea('  ⚠ No se pudo escribir data/sensibilidad.json (permisos).');
    wq_linea();
}

exit(0);
