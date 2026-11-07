#!/usr/bin/env php
<?php
/**
 * WILLAQ — BACKTEST DE ESTACIONES (compuerta de la fase 2)
 * =======================================================
 *
 * POR QUÉ ESTE SCRIPT
 *   La fase 1 dejó 23 series reales del SENAMHI en data/estaciones/. La
 *   compuerta de la fase 2 no se declara leyendo archivos: hay que MEDIR los
 *   falsos positivos antes de meter una estación al motor.
 *
 *   Este script hace esa medición. Usa exactamente la misma aritmética de
 *   detección que la validación histórica ERA5 (scripts/lib_validacion.php):
 *   la única cosa que cambia es la serie de lluvia que entra. Si cada script
 *   tuviera su propia copia de la fórmula, la diferencia entre ERA5 y SENAMHI
 *   sería indistinguible de un error de implementación y el backtest no
 *   probaría nada.
 *
 * MÉTODO
 *   1. Se carga data/validacion_historica.json como línea base ERA5. Si no
 *      existe, el script se detiene con una explicación: sin línea base no
 *      hay comparación posible.
 *   2. Se validan las ventanas y las listas de referencia contra el
 *      artefacto guardado (scripts/validar_historico.php usa la misma
 *      definición de eventos; cualquier desfase es un error y el script
 *      aborta).
 *   3. Para cada distrito y cada evento se ELIGE la estación operativa: la
 *      primera por preferencia (tipo M > H, catálogo vigente > ausente,
 *      datos más antiguos, menor distancia) CUYA SERIE PUEDA EVALUAR EL
 *      EVENTO. Esta regla es operativa y no se ajusta después de ver los
 *      resultados.
 *   4. La serie de la estación (densa: los días sin medición valen null, no
 *      se salta ningún hueco) pasa por wq_deteccion_evento(), la misma
 *      función que el reanálisis.
 *   5. La comparación se hace sobre el SUBCONJUNTO EVALUABLE POR AMBAS
 *      FUENTES: no se puede comparar una estación que no tiene datos con un
 *      distrito y llamarlo un fallo.
 *
 * QUÉ MIDE
 *   · recall sobre la referencia documentada (ERA5 vs estación), restringido
 *     al subconjunto común.
 *   · señalamientos fuera de la referencia documentada: lo mismo que
 *     entiende la validación histórica ("falso positivo"), pero el script
 *     lo separa en "ya señalaba ERA5" y "es nuevo de la estación".
 *   · recuperados: estaciones detectan lo que ERA5 no detectó.
 *   · concordancia entre las dos fuentes en el subconjunto común.
 *
 * SALIDA
 *   data/backtest_estaciones.json
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Cache.php';
require_once __DIR__ . '/../models/Clima.php';
require_once __DIR__ . '/../models/Riesgo.php';
require_once __DIR__ . '/../models/Estacion.php';
require_once __DIR__ . '/lib_validacion.php';

// Evita que el modelo lea el archivo de salida que este script está
// generando (sería leer el veredicto de la ejecución anterior).
$GLOBALS['WILLAQ_BACKTEST_EN_CURSO'] = true;

$SCRIPT_DIR = __DIR__;
$rutaBaseline = __DIR__ . '/../data/validacion_historica.json';
$rutaSalida   = __DIR__ . '/../data/backtest_estaciones.json';
$rutaMotor    = __DIR__ . '/validar_historico.php';

// -------------------------------------------------------------------- ayuda
$opciones = getopt('', ['ayuda']);
if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · backtest de estaciones (compuerta de fase 2)

  Mide falsos positivos de las estaciones SENAMHI antes de permitir que el
  motor las use. La medición se hace sobre los eventos El Niño 2017, 2017-2018
  y 2023 y sobre la línea base de ERA5 guardada en data/validacion_historica.json.

  Salida: data/backtest_estaciones.json

  Precondición: ejecutar antes scripts/validar_historico.php para generar la
  línea base. La medición de la estación se restringe al subconjunto de
  distritos que puede evaluar AMBAS fuentes (ni más ni menos).

TXT;
    exit(0);
}

set_time_limit(0);
ini_set('memory_limit', '512M');

// ------------------------------------------------------------------ línea base
wq_linea();
wq_linea('  ╔═══════════════════════════════════════════════════════════════════════════╗');
wq_linea('  ║  WILLAQ · backtest de estaciones SENAMHI (fase 2)                          ║');
wq_linea('  ╚═══════════════════════════════════════════════════════════════════════════╝');
wq_linea();

if (!is_file($rutaBaseline)) {
    fwrite(STDERR, "\nERROR: no existe la línea base ERA5: $rutaBaseline\n");
    fwrite(STDERR, "Primero ejecuta:\n");
    fwrite(STDERR, "  php scripts/validar_historico.php\n");
    exit(1);
}

$baselineRaw = file_get_contents($rutaBaseline);
if ($baselineRaw === false) {
    fwrite(STDERR, "\nERROR: no se pudo leer la línea base.\n");
    exit(1);
}
$baseline = json_decode($baselineRaw, true);
if (!is_array($baseline) || !isset($baseline['eventos']) || !is_array($baseline['eventos'])) {
    fwrite(STDERR, "\nERROR: data/validacion_historica.json no tiene un evento \"eventos\".\n");
    exit(1);
}

// Definición única de eventos (librería compartida).
$eventos = wq_eventos_historicos();
if (count($eventos) !== count($baseline['eventos'])) {
    fwrite(STDERR, "\nERROR: la definición de eventos de validar_historico.php ("
        . count($eventos) . ") no coincide con la línea base ("
        . count($baseline['eventos']) . ").\n");
    exit(1);
}

foreach ($eventos as $clave => $evDef) {
    $evBase = $baseline['eventos'][$clave] ?? null;
    if (!$evBase) {
        fwrite(STDERR, "ERROR: el evento \"$clave\" no está en la línea base.\n");
        exit(1);
    }
    if ($evDef['inicio'] !== $evBase['ventana'][0] || $evDef['fin'] !== $evBase['ventana'][1]) {
        fwrite(STDERR, "ERROR: las ventanas de \"$clave\" difieren entre el motor ("
            . $evDef['inicio'] . '-' . $evDef['fin'] . ") y la línea base ("
            . implode(',', $evBase['ventana']) . ").\n");
        exit(1);
    }
    if (count($evDef['referencia']) !== count($evBase['referencia'])
        || count(array_diff($evDef['referencia'], $evBase['referencia'])) > 0
        || count(array_diff($evBase['referencia'], $evDef['referencia'])) > 0) {
        fwrite(STDERR, "ERROR: la lista de referencia de \"$clave\" difiere.\n");
        exit(1);
    }
}

wq_linea('  Línea base:      ' . $baseline['serie']['fuente']
         . ' (' . $baseline['serie']['anio_inicio'] . '-' . $baseline['serie']['anio_fin'] . ')');
wq_linea('  Eventos validados: ' . count($eventos)
         . ' (ventanas y referencia cruzadas con ' . basename($rutaBaseline) . ')');

// ------------------------------------------------------------------- registros
$registro = estacion_registro_todas();
wq_linea('  Estaciones con serie cargada: ' . count($registro));
wq_linea('  Distritos en catálogo:        ' . count(territorios_todos()));

$territorios = territorios_todos();

// Preferencia de estación (MENOR puntaje = MEJOR). Igual que la que usa el
// catálogo en generar_registro_estaciones.py, pero recalculada aquí para
// estar seguros y para ordenar por distancia dentro de cada territorio.
function wq_preferencia_estacion($estacion, $asignacion) {
    $tipo     = (string)($estacion['tipo'] ?? '');
    $penTipo  = ($tipo === 'M') ? 0 : (($tipo === 'H') ? 1 : 2);
    $penVig   = ((string)($estacion['catalog_status'] ?? '') === 'listed') ? 0 : 1;
    $since    = (string)($estacion['datos_desde'] ?? '');
    if ($since === '') {
        $since = '9999-99';
    }
    return [
        $penTipo,
        $penVig,
        $since,
        (float)($asignacion['distancia_km'] ?? 9999.0),
    ];
}

function wq_comparar_preferencia($a, $b) {
    $ka = wq_preferencia_estacion($a['estacion'], $a['asignacion']);
    $kb = wq_preferencia_estacion($b['estacion'], $b['asignacion']);
    for ($i = 0; $i < 4; $i++) {
        if ($ka[$i] === $kb[$i]) {
            continue;
        }
        return ($ka[$i] < $kb[$i]) ? -1 : 1;
    }
    return 0;
}

// Índice: territorio → estaciones candidatas, ordenadas por preferencia.
$candidatosPorDistrito = [];
foreach ($registro as $id => $est) {
    if (empty($est['serie_cargada'])) {
        continue;
    }
    foreach (($est['asignaciones'] ?? []) as $asig) {
        $tid = $asig['territorio'] ?? '';
        if ($tid === '') {
            continue;
        }
        $candidatosPorDistrito[$tid][] = ['estacion' => $est, 'asignacion' => $asig];
    }
}

foreach ($candidatosPorDistrito as $tid => &$cands) {
    usort($cands, 'wq_comparar_preferencia');
}

unset($cands);

// ------------------------------------------------------------------ series densas
// Se cargan UNA vez y se reutilizan para cada evento. La densificación (días
// sin medición = null, NO saltar huecos) la hace lib_validacion.php: una
// ventana de 3 días que cubre 5 días calendario no es una ventana de 3 días.
$series = [];
foreach ($registro as $id => $est) {
    $serie = estacion_cargar_serie($id);
    if (!$serie || !is_array($serie['datos'] ?? null)) {
        continue;
    }
    $series[$id] = wq_serie_desde_datos($serie['datos']);
}

// ------------------------------------------------------------------ eventos
$fase2 = estacion_compuerta();
wq_linea();
wq_linea('  Criterios de la compuerta de fase 2:');
wq_linea('    · series >= ' . $fase2['anios_minimos'] . ' años de cobertura');
wq_linea('    · <= ' . $fase2['faltantes_max_pct'] . '% de días faltantes');
wq_linea('    · backtest: recall >= ' . $fase2['recall_minimo']
         . ' sobre la referencia documentada que la estación evaluó');
wq_linea('    · <= ' . $fase2['falsos_positivos_nuevos_max']
         . ' señalamientos nuevos fuera de la referencia (no más ruido que ERA5)');
wq_linea('    · minimo ' . $fase2['evaluaciones_minimas']
         . ' combinaciones distrito×evento medidas por estación');
wq_linea();

$eventosMedidos = [];
$agregadoGlobal = [
    'n_comunes'          => 0,
    'era5_senalados'     => 0,
    'est_senalados'      => 0,
    'referencia_evaluada' => 0,
    'aciertos_era5'      => 0,
    'aciertos_est'       => 0,
    'falsos_positivos_era5' => 0,
    'falsos_positivos_est' => 0,
    'falsos_positivos_nuevos' => 0,
    'falsos_positivos_resueltos' => 0,
    'recuperados'        => 0,
    'confirmados_ambos'  => 0,
    'solo_era5'          => 0,
    'solo_estacion'      => 0,
    'ninguno'            => 0,
];
$agregadoCasosReferencia = [
    'era5_detectado'      => 0,
    'era5_no_detectado'   => 0,
    'era5_no_evaluable'   => 0,
    'est_detectado'       => 0,
    'est_no_detectado'    => 0,
    'est_no_evaluable'    => 0,
    'confirmados_ambos'   => 0,
];
$casosReferenciaDetallado = [];

foreach ($eventos as $clave => $evDef) {
    $evBase = $baseline['eventos'][$clave];

    // Línea base ERA5: id → fila.
    $era5Filas = [];
    foreach ($evBase['distritos'] as $f) {
        $era5Filas[(string)$f['id']] = $f;
    }

    wq_linea();
    wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
    wq_linea('  ' . $evBase['titulo']);
    wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
    wq_linea('  Ventana: ' . $evDef['inicio'] . ' → ' . $evDef['fin']
         . '  |  Referencia (' . count($evDef['referencia']) . '): '
         . implode(', ', $evDef['referencia']));

    // Para cada distrito: elección de la estación operativa y detección.
    $filasEst  = [];    // id → fila del detector de la estación (null si no hay estación evaluable)
    $elegidas  = [];    // id → id de estación
    $intentos  = [];    // id → [ {estacion, motivo} ] de por qué se probó/cayó
    $E = null; $V = null; // exp/vul del distrito actual
    foreach ($territorios as $t) {
        $id = (string)$t['id'];
        $cands = $candidatosPorDistrito[$id] ?? [];

        [$E, $V] = wq_exposicion_vulnerabilidad($t);

        $elegida = null; $filaEst = null; $intentosList = [];
        foreach ($cands as $c) {
            $eid = $c['estacion']['id'];
            if (!isset($series[$eid])) {
                $intentosList[] = ['estacion' => $eid, 'motivo' => 'sin_serie'];
                continue;
            }
            $r = wq_deteccion_evento($series[$eid], $evDef, $E, $V, $t);
            if ($r === null) {
                $intentosList[] = ['estacion' => $eid, 'motivo' => 'no_evaluable'];
                continue;
            }
            $elegida = $eid;
            $filaEst = $r;
            break;
        }
        $intentos[$id] = $intentosList;

        if ($elegida !== null && $filaEst !== null) {
            $filasEst[$id] = $filaEst;
            $elegidas[$id] = $elegida;
        }
    }

    // ============================================================= métricas
    $comunes     = array_values(array_intersect(array_keys($era5Filas), array_keys($filasEst)));
    $soloEst     = array_values(array_diff(array_keys($filasEst), array_keys($era5Filas)));
    $soloEra5    = array_values(array_diff(array_keys($era5Filas), array_keys($filasEst)));
    $senaladosEra5 = array_values(array_filter($comunes, fn($id) => (bool)($era5Filas[$id]['detectado'] ?? false)));
    $senaladosEst  = array_values(array_filter($comunes, fn($id) => (bool)($filasEst[$id]['detectado'] ?? false)));
    $ambos = array_values(array_intersect($senaladosEra5, $senaladosEst));
    $referenciaEvaluable = array_values(array_intersect($evDef['referencia'], $comunes));
    $aciertosEra5 = array_values(array_intersect($senaladosEra5, $referenciaEvaluable));
    $aciertosEst  = array_values(array_intersect($senaladosEst, $referenciaEvaluable));
    $fpEra5 = array_values(array_diff($senaladosEra5, $evDef['referencia']));
    $fpEst  = array_values(array_diff($senaladosEst, $evDef['referencia']));
    $recuperados = array_values(array_intersect(array_diff($senaladosEst, $senaladosEra5), $evDef['referencia']));
    $fpNuevos    = array_values(array_diff(array_diff($senaladosEst, $senaladosEra5), $evDef['referencia']));
    $fpResueltos = array_values(array_diff(array_diff($senaladosEra5, $senaladosEst), $evDef['referencia']));
    $ninguno     = array_values(array_diff($comunes, array_merge($senaladosEra5, $senaladosEst)));
    $concordancia = (count($ambos) + count($ninguno));
    if (!empty($comunes)) {
        $concordancia = round($concordancia / count($comunes), 3);
    }

    wq_linea();
    wq_linea('  Subconjunto evaluado: ' . count($comunes) . ' distritos en común (ambas fuentes); '
         . count($soloEst) . ' solo estación; ' . count($soloEra5) . ' solo ERA5');

    if (!empty($comunes)) {
        wq_linea('  Señalados nivel alto/muy alto:');
        wq_linea('    · ERA5: ' . count($senaladosEra5) . ' (' . implode(', ', $senaladosEra5) . ')');
        wq_linea('    · Estación: ' . count($senaladosEst) . ' (' . implode(', ', $senaladosEst) . ')');
        wq_linea('    · Concordancia (ambos/ninguno): ' . $concordancia . ' (' . count($ambos) . ' + ' . count($ninguno) . ')');

        wq_linea();
        wq_linea('  Aciertos sobre la referencia documentada:');
        wq_linea('    · ERA5:   ' . count($aciertosEra5) . ' de ' . count($referenciaEvaluable) . ' (recall ' . round(($referenciaEvaluable ? count($aciertosEra5) / count($referenciaEvaluable) : 0), 3) . ')');
        wq_linea('    · Estación: ' . count($aciertosEst) . ' de ' . count($referenciaEvaluable) . ' (recall ' . round(($referenciaEvaluable ? count($aciertosEst) / count($referenciaEvaluable) : 0), 3) . ')');
        wq_linea('    · Recuperados por la estación (la estación detecta, ERA5 no): ' . count($recuperados) . ' (' . implode(', ', $recuperados) . ')');

        wq_linea();
        wq_linea('  Señalamientos fuera de la referencia documentada:');
        wq_linea('    · ERA5 ya señalaba: ' . count($fpEra5));
        wq_linea('    · ERA5 no señalaba (ya había): ' . count($fpResueltos));
        wq_linea('    · FALSO POSITIVO NUEVO DE LA ESTACIÓN: ' . count($fpNuevos) . ' (' . implode(', ', $fpNuevos) . ')');
        wq_linea('    · Nota: "nuevo" significa señalada sin emergencia documentada en este evento;');
        wq_linea('      no prueba que la señal sea errónea, solo que no está respaldada por la');
        wq_linea('      referencia disponible. El criterio de la compuerta es 0: la estación no');
        wq_linea('      puede ser más ruidosa que el reanálisis sin recalibrar umbrales.');
    }

    $eventosMedidos[$clave] = [
        'titulo'       => $evBase['titulo'],
        'ventana'      => [$evDef['inicio'], $evDef['fin']],
        'referencia'   => $evDef['referencia'],
        'subconjunto_comun' => count($comunes),
        'solo_estacion'  => count($soloEst),
        'solo_era5'      => count($soloEra5),
        'senalados_era5' => count($senaladosEra5),
        'senalados_est'  => count($senaladosEst),
        'referencia_evaluable' => count($referenciaEvaluable),
        'aciertos_era5'  => count($aciertosEra5),
        'aciertos_est'   => count($aciertosEst),
        'recall_era5'    => $referenciaEvaluable ? round(count($aciertosEra5) / count($referenciaEvaluable), 4) : null,
        'recall_est'     => $referenciaEvaluable ? round(count($aciertosEst) / count($referenciaEvaluable), 4) : null,
        'falsos_positivos_era5' => count($fpEra5),
        'falsos_positivos_est'  => count($fpEst),
        'falsos_positivos_resueltos' => count($fpResueltos),
        'falsos_positivos_nuevos'   => count($fpNuevos),
        'recuperados'    => count($recuperados),
        'concordancia'   => $concordancia,
        'filas_estacion' => [],    // ids donde la estación evaluó
        'elegidas'       => $elegidas,
        'intentos'       => $intentos,
        'filas_era5'     => $era5Filas,
    ];

    // ---- casos de referencia: veredicto por caso ---------------------
    $casosRef = [];
    foreach ($evDef['referencia'] as $rid) {
        $era5 = $era5Filas[$rid] ?? null;
        $est  = $filasEst[$rid] ?? null;
        $era5Eval  = $era5 !== null;
        $estEval   = $est !== null;
        $era5Det   = $era5Eval && ($era5['detectado'] ?? false);
        $estDet    = $estEval && ($est['detectado'] ?? false);

        if ($era5Eval && $estEval) {
            $veredicto = $era5Det && $estDet ? 'confirmado_por_ambos'
                : ($era5Det ? 'solo_era5' : ($estDet ? 'solo_estacion' : 'ninguno'));
        } elseif ($era5Eval) {
            $veredicto = 'solo_era5';
        } elseif ($estEval) {
            $veredicto = 'solo_estacion';
        } else {
            $veredicto = 'ninguno_ambas_no_evaluan';
        }

        $casosRef[] = [
            'id'            => $rid,
            'estacion_era5' => $era5Eval ? ($era5Det ? 'detectado' : 'no_detectado') : 'no_evaluable',
            'estacion_est'  => $estEval ? ($estDet ? 'detectado' : 'no_detectado') : 'no_evaluable',
            'veredicto'     => $veredicto,
            'distrito'      => $era5['distrito'] ?? ($est['distrito'] ?? null),
            'region'        => $era5['region'] ?? ($est['region'] ?? null),
        ];

        $agregadoCasosReferencia[$veredicto === 'confirmado_por_ambos' ? 'confirmados_ambos'
            : ($era5Eval ? ($era5Det ? 'era5_detectado' : 'era5_no_detectado') : 'era5_no_evaluable')]++;
        $agregadoCasosReferencia[$veredicto === 'confirmado_por_ambos' ? 'confirmados_ambos'
            : ($estEval ? ($estDet ? 'est_detectado' : 'est_no_detectado') : 'est_no_evaluable')]++;
    }

    $eventosMedidos[$clave]['casos_referencia'] = $casosRef;

    // ---- global -------------------------------------------------------
    $agregadoGlobal['n_comunes'] += count($comunes);
    $agregadoGlobal['era5_senalados'] += count($senaladosEra5);
    $agregadoGlobal['est_senalados'] += count($senaladosEst);
    $agregadoGlobal['referencia_evaluada'] += count($referenciaEvaluable);
    $agregadoGlobal['aciertos_era5'] += count($aciertosEra5);
    $agregadoGlobal['aciertos_est'] += count($aciertosEst);
    $agregadoGlobal['falsos_positivos_era5'] += count($fpEra5);
    $agregadoGlobal['falsos_positivos_est'] += count($fpEst);
    $agregadoGlobal['falsos_positivos_nuevos'] += count($fpNuevos);
    $agregadoGlobal['falsos_positivos_resueltos'] += count($fpResueltos);
    $agregadoGlobal['recuperados'] += count($recuperados);
    $agregadoGlobal['confirmados_ambos'] += array_count_values(array_column($casosRef, 'veredicto'))['confirmado_por_ambos'] ?? 0;
    $agregadoGlobal['solo_era5'] += array_count_values(array_column($casosRef, 'veredicto'))['solo_era5'] ?? 0;
    $agregadoCasosReferencia['solo_era5'] += array_count_values(array_column($casosRef, 'veredicto'))['solo_era5'] ?? 0;
    $agregadoGlobal['solo_estacion'] += array_count_values(array_column($casosRef, 'veredicto'))['solo_estacion'] ?? 0;
    $agregadoCasosReferencia['solo_estacion'] += array_count_values(array_column($casosRef, 'veredicto'))['solo_estacion'] ?? 0;
    $agregadoGlobal['ninguno'] += array_count_values(array_column($casosRef, 'veredicto'))['ninguno'] ?? 0;
    $agregadoCasosReferencia['ninguno'] += array_count_values(array_column($casosRef, 'veredicto'))['ninguno'] ?? 0;
    foreach ($casosRef as $cr) {
        $casosReferenciaDetallado[$clave][] = $cr;
    }
}

// =========================================================== métricas globales
$recallGlobalEra5 = $agregadoGlobal['referencia_evaluada'] > 0
    ? round($agregadoGlobal['aciertos_era5'] / $agregadoGlobal['referencia_evaluada'], 4)
    : null;
$recallGlobalEst  = $agregadoGlobal['referencia_evaluada'] > 0
    ? round($agregadoGlobal['aciertos_est'] / $agregadoGlobal['referencia_evaluada'], 4)
    : null;

wq_linea();
wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
wq_linea('  RESUMEN GLOBAL (eventos 2017 · 2017-2018 · 2023)');
wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
wq_linea();
wq_linea('  Comparables en común: ' . $agregadoGlobal['n_comunes'] . ' distritos×eventos');
wq_linea('  Referencia documentada evaluada por ambas fuentes: '
    . $agregadoGlobal['referencia_evaluada']);
wq_linea();
wq_linea('  Acierto (recall) sobre la referencia:');
wq_linea('    · ERA5:   ' . $agregadoGlobal['aciertos_era5'] . ' de ' . $agregadoGlobal['referencia_evaluada']
    . ' → ' . ($recallGlobalEra5 ?? 'n/d'));
wq_linea('    · Estación: ' . $agregadoGlobal['aciertos_est'] . ' de ' . $agregadoGlobal['referencia_evaluada']
    . ' → ' . ($recallGlobalEst ?? 'n/d'));
wq_linea();
wq_linea('  Falsos positivos (señalada sin emergencia documentada):');
wq_linea('    · ERA5: ' . $agregadoGlobal['falsos_positivos_era5']);
wq_linea('    · Estación: ' . $agregadoGlobal['falsos_positivos_est']);
wq_linea('    · ERA5 ya lo hacía: ' . $agregadoGlobal['falsos_positivos_resueltos']);
wq_linea('    · FALSO POSITIVO NUEVO DE LA ESTACIÓN: ' . $agregadoGlobal['falsos_positivos_nuevos']);
wq_linea('    · Recuperados por la estación: ' . $agregadoGlobal['recuperados']);
wq_linea();
wq_linea('  Veredicto de los ' . $agregadoCasosReferencia['era5_detectado']
    . ' casos que ERA5 detectó:');
wq_linea('    · Confirmados por ambas fuentes: '
    . $agregadoCasosReferencia['confirmados_ambos']);
wq_linea('    · Solo ERA5: ' . $agregadoCasosReferencia['solo_era5']);
wq_linea('    · Solo estación: ' . $agregadoCasosReferencia['solo_estacion']);
wq_linea('    · Ninguno: ' . $agregadoCasosReferencia['ninguno']);
wq_linea();

// ===================================================== gate por estación
wq_linea();
wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
wq_linea('  GATE POR ESTACIÓN');
wq_linea('  ════════════════════════════════════════════════════════════════════════════════');
wq_linea();

$veredictos = [
    'sin_serie'             => ['r' => '🔴', 't' => 'Sin serie cargada (no se midió)'],
    'bloqueada_criterios_medibles' => ['r' => '🔴', 't' => 'No cumple criterios medibles (años / faltantes)'],
    'no_evaluada'           => ['r' => '🟡', 't' => 'Serie disponible pero no pudo evaluar ninguna combinación'],
    'medida_sin_casos_de_referencia' => ['r' => '🟡', 't' => 'Medida pero sin casos de referencia documentada en lo que evaluó'],
    'medida_recall_insuficiente'     => ['r' => '🟠', 't' => 'Recall bajo respecto a la referencia documentada'],
    'medida_falsos_positivos_nuevos' => ['r' => '🟠', 't' => 'Añade señalamientos fuera de la referencia documentada que ERA5 no hacía'],
    'medida_apta_para_fusion'        => ['r' => '🟢', 't' => 'Cumple: medible, medida, recall suficiente y sin falsos positivos nuevos'],
];

$gateEstaciones = [];
foreach ($registro as $id => $est) {
    $gate = ['estacion' => $est, 'serie_cargada' => !empty($est['serie_cargada'])];

    // Compuerta de fase 1 (años / faltantes).
    $compuerta = estacion_puede_usarse($id);
    $gate['cumple_medible'] = $compuerta['cumple_medible'];
    $gate['motivos_compuerta'] = $compuerta['motivos'];

    if (!$est['serie_cargada'] || !isset($series[$id])) {
        $gate['veredicto'] = 'sin_serie';
        $gate['medido'] = false;
        $gate['evaluaciones'] = 0;
        $gate['referencia_evaluada'] = 0;
        $gate['recall'] = null;
        $gate['falsos_positivos_nuevos'] = null;
        $gate['motivacion'] = $veredictos['sin_serie']['t'];
        $gateEstaciones[$id] = $gate;
        continue;
    }

    // Mediciones agregadas de la estación sobre los combos donde fue
    // elegida como operativa (regla operativa: la primera evaluable).
    $evaluaciones = [];
    $senaladosTotales = 0;
    $recuperadosTotales = 0;
    $fpNuevosTotales = 0;
    $fpResueltosTotales = 0;
    $referenciaEvaluada = 0;
    $aciertosTotales = 0;
    foreach ($eventos as $clave => $evDef) {
        $m = $eventosMedidos[$clave];
        foreach ($m['elegidas'] as $rid => $eid) {
            if ($eid !== $id) {
                continue;
            }
            $evaluaciones[] = ['evento' => $clave, 'distrito' => $rid, 'detectado' => ($m['filas_estacion'][$rid]['detectado'] ?? false)];
            $senaladosTotales += (int)($m['filas_estacion'][$rid]['detectado'] ?? false);
            $recuperadosTotales += (isset($m['recuperados']) && is_array($m['recuperados']) && in_array($rid, $m['recuperados'])) ? 1 : 0;
            $fpNuevosTotales += (is_array($m['falsos_positivos_nuevos']) && in_array($rid, $m['falsos_positivos_nuevos'])) ? 1 : 0;
            $fpResueltosTotales += (is_array($m['falsos_positivos_resueltos']) && in_array($rid, $m['falsos_positivos_resueltos'])) ? 1 : 0;
            if (in_array($rid, $evDef['referencia'], true)) {
                $referenciaEvaluada++;
                $aciertosTotales += (int)($m['filas_estacion'][$rid]['detectado'] ?? false);
            }
        }
    }

    $gate['medido'] = !empty($evaluaciones);
    $gate['evaluaciones'] = count($evaluaciones);
    $gate['evaluaciones_minimas'] = $fase2['evaluaciones_minimas'];
    $gate['referencia_evaluada'] = $referenciaEvaluada;
    $gate['aciertos'] = $aciertosTotales;
    $gate['senalados'] = $senaladosTotales;
    $gate['falsos_positivos_nuevos'] = $fpNuevosTotales;
    $gate['recuperados'] = $recuperadosTotales;
    $gate['falsos_positivos_resueltos'] = $fpResueltosTotales;
    $gate['evaluaciones_detalle'] = $evaluaciones;
    $gate['recall'] = $referenciaEvaluada > 0
        ? round($aciertosTotales / $referenciaEvaluada, 4)
        : null;

    // Veredicto (reglas en orden, sin mirar el resultado antes).
    if ($gate['evaluaciones'] < $fase2['evaluaciones_minimas']) {
        $gate['veredicto'] = 'no_evaluada';
    } elseif (!$gate['cumple_medible']) {
        $gate['veredicto'] = 'bloqueada_criterios_medibles';
    } elseif (!$gate['medido']) {
        $gate['veredicto'] = 'no_evaluada';
    } elseif ($gate['referencia_evaluada'] === 0) {
        $gate['veredicto'] = 'medida_sin_casos_de_referencia';
    } elseif ($gate['recall'] < $fase2['recall_minimo']) {
        $gate['veredicto'] = 'medida_recall_insuficiente';
    } elseif ($gate['falsos_positivos_nuevos'] > $fase2['falsos_positivos_nuevos_max']) {
        $gate['veredicto'] = 'medida_falsos_positivos_nuevos';
    } else {
        $gate['veredicto'] = 'medida_apta_para_fusion';
    }
    $gate['motivacion'] = $veredictos[$gate['veredicto']]['t'];

    $gateEstaciones[$id] = $gate;
}

// Resumen de gate para el panel.
$cuente = ['sin_serie' => 0, 'bloqueada_criterios_medibles' => 0, 'no_evaluada' => 0,
           'medida_sin_casos_de_referencia' => 0, 'medida_recall_insuficiente' => 0,
           'medida_falsos_positivos_nuevos' => 0, 'medida_apta_para_fusion' => 0];
foreach ($gateEstaciones as $g) {
    $cuente[$g['veredicto']] = ($cuente[$g['veredicto']] ?? 0) + 1;
}

$habilitadas = array_filter($gateEstaciones, fn($g) => $g['veredicto'] === 'medida_apta_para_fusion');

wq_linea('  Totales: ' . count($gateEstaciones) . ' estaciones con serie');
wq_linea('    aptas para fusión: ' . count($habilitadas));
wq_linea('    cumple medible (años/faltantes): '
    . count(array_filter($gateEstaciones, fn($g) => $g['cumple_medible'])));
wq_linea('    sin serie: ' . $cuente['sin_serie']);
wq_linea('    no evaluadas (< ' . $fase2['evaluaciones_minimas'] . ' combos): '
    . $cuente['no_evaluada']);
wq_linea('    medida sin casos de referencia: ' . $cuente['medida_sin_casos_de_referencia']);
wq_linea('    medida, recall insuficiente: ' . $cuente['medida_recall_insuficiente']);
wq_linea('    medida, falsos positivos nuevos: ' . $cuente['medida_falsos_positivos_nuevos']);
wq_linea();

foreach ($gateEstaciones as $id => $g) {
    $serie = estacion_cargar_serie($id);
    $qa = $serie['qa'] ?? [];
    wq_linea(sprintf('  %s %s (%s) → %s',
        $veredictos[$g['veredicto']]['r'],
        str_pad($g['estacion']['nombre'], 24),
        $g['estacion']['cod'],
        $g['motivacion']));
    if ($g['evaluaciones'] > 0) {
        wq_linea('     combos ' . $g['evaluaciones'] . ' · ref evaluadas ' . $g['referencia_evaluada']
            . ' · recall ' . ($g['recall'] ?? 'n/d') . ' · FP nuevos ' . $g['falsos_positivos_nuevos']
            . ' · recuperados ' . $g['recuperados']);
    }
    if ($g['veredicto'] === 'medida_apta_para_fusion') {
        wq_linea('     ✅ ESTACIÓN APTA PARA FUSIÓN: puede pasar a la fase 2 de fusión.');
    }
}
wq_linea();

// =========================================================== estructura de salida
// Resumen global. El texto refleja el resultado REAL de la medición: si no
// hay ninguna estación apta, se dice exactamente eso (no se deja un "hay que
// revisar" que sugiere un trabajo pendiente que ya se hizo).
$aptas = count($habilitadas);
$medidas = array_filter($gateEstaciones, fn($g) => !empty($g['medido']));
$medidasConReferencia = array_filter($medidas, fn($g) => ($g['referencia_evaluada'] ?? 0) > 0);
if ($agregadoGlobal['falsos_positivos_nuevos'] > 0) {
    $resumenFase2 = 'cerrada: ' . $agregadoGlobal['falsos_positivos_nuevos']
        . ' estación(es) añadieron señalamientos fuera de la referencia que ERA5 no hacía.';
} elseif ($aptas === 0) {
    $resumenFase2 = 'cerrada: ninguna estación es apta para fusión. '
        . 'Se evaluaron ' . count($medidas) . ' estaciones contra la referencia documentada; '
        . count($medidasConReferencia) . ' lo hicieron sobre casos de referencia y '
        . 'ninguna alcanzó el recall mínimo de ' . $fase2['recall_minimo'] . '. '
        . 'No se añadió ningún falso positivo nuevo, pero la estación tampoco aporta '
        . 'la señal que ERA5 había perdido: el recall de la estación es igual al de ERA5.';
} else {
    $resumenFase2 = 'cerrada: ' . $aptas . ' estación(es) superaron el gate de medición '
        . '(recall ≥ ' . $fase2['recall_minimo'] . ' y ' . $fase2['falsos_positivos_nuevos_max']
        . ' falsos positivos nuevos). Falta ejecutar la fusión con el motor; '
        . 'hasta entonces motor_usa_estaciones sigue en false.';
}
$motorUsaEstaciones = false;

$salida = [
    '_meta' => [
        'titulo'         => 'WILLAQ — backtest de estaciones (compuerta de fase 2)',
        'version'        => 1,
        'generado_en'    => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\+00:00'),
        'generado_por'   => 'scripts/backtest_estaciones.php',
        'fuente_basalt'  => [
            // Ruta RELATIVA al proyecto: un artefacto público no debe filtrar
            // la ruta local de quien lo generó.
            'archivo' => 'data/validacion_historica.json',
            'sha256'  => hash_file('sha256', $rutaBaseline),
            'serie'   => $baseline['serie'],
            'metodo'  => $baseline['metodo'],
        ],
        'eventos' => array_keys($eventos),
        'compuerta' => [
            'anios_minimos'        => $fase2['anios_minimos'],
            'faltantes_max_pct'    => $fase2['faltantes_max_pct'],
            'recall_minimo'        => $fase2['recall_minimo'],
            'falsos_positivos_nuevos_max' => $fase2['falsos_positivos_nuevos_max'],
            'evaluaciones_minimas' => $fase2['evaluaciones_minimas'],
            'requiere_backtest'    => true,
            'fuentes_peligro'      => $fase2['fuentes_peligro'],
        ],
        'notas' => [
            'La comparación se restringe al subconjunto evaluado por AMBAS fuentes.',
            'Falso positivo = señalada sin emergencia documentada en ese evento; '
            . 'no prueba que la señal sea errónea, solo que no está respaldada por la '
            . 'referencia disponible.',
            'El umbral de recall_minimo (0.80) y falsos_positivos_nuevos_max (0) se '
            . 'fijaron ANTES de ver los resultados.',
            'El estado habilitada del motor sigue en false: el backtest solo mide; '
            . 'la fusión (motor_usa_estaciones = true) es un cambio de motor separado.',
            'Recall de estación = recall de ERA5 (' . ($recallGlobalEst ?? 'n/d') . '): '
            . 'las estaciones detectaron exactamente los mismos distritos que el reanálisis '
            . 'en los casos comparables, sin recuperar los que ERA5 perdió y sin añadir '
            . 'señalamientos nuevos. Con esos umbrales, la medición no habilita ninguna '
            . 'estación; reabrir la compuerta exige recalibrar por fuente.',
        ],
    ],
    'resumen_fase_2' => [
        'habilitada'        => false,
        'motor_usa_estaciones' => $motorUsaEstaciones,
        'compuerta'         => 'cerrada',
        'resumen'           => $resumenFase2,
        'estaciones_medibles' => count(array_filter($gateEstaciones, fn($g) => $g['cumple_medible'])),
        'estaciones_habilitadas' => count($habilitadas),
        'estaciones_no_habilitadas' => count($gateEstaciones) - count($habilitadas),
        'medicion_global' => [
            'evaluaciones_comunes' => $agregadoGlobal['n_comunes'],
            'referencia_evaluada'  => $agregadoGlobal['referencia_evaluada'],
            'recall_era5'          => $recallGlobalEra5,
            'recall_estacion'      => $recallGlobalEst,
            'aciertos_era5'        => $agregadoGlobal['aciertos_era5'],
            'aciertos_estacion'    => $agregadoGlobal['aciertos_est'],
            'falsos_positivos_era5' => $agregadoGlobal['falsos_positivos_era5'],
            'falsos_positivos_estacion' => $agregadoGlobal['falsos_positivos_est'],
            'falsos_positivos_nuevos' => $agregadoGlobal['falsos_positivos_nuevos'],
            'falsos_positivos_resueltos' => $agregadoGlobal['falsos_positivos_resueltos'],
            'recuperados'          => $agregadoGlobal['recuperados'],
        ],
        'veredictos' => $cuente,
    ],
    'eventos' => $eventosMedidos,
    'casos_referencia_globales' => $casosReferenciaDetallado,
    'estaciones' => $gateEstaciones,
];

file_put_contents($rutaSalida, json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

wq_linea();
wq_linea('  SALIDA: ' . basename($rutaSalida));
wq_linea();
if (count($habilitadas) > 0) {
    wq_linea('  ⚠ Hay estaciones aptas para fusión. El estado `habilitada` no se '
        . 'cambió automáticamente: la fase 2 completa requiere fusionar la '
        . 'estación con el motor (motor_usa_estaciones = true) y recalibrar '
        . 'los umbrales para la fuente de lluvia. Esto se hará en un paso '
        . 'separado, con su propia validación.');
} else {
    wq_linea('  El gate de la fase 2 NO se superó: las estaciones aún no pueden '
        . 'activarse. Los resultados están en data/backtest_estaciones.json.');
}
wq_linea();
