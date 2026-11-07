<?php
/**
 * WILLAQ — LIBRERÍA DE VALIDACIÓN HISTÓRICA (compartida)
 * =====================================================
 *
 * POR QUÉ EXISTE ESTE ARCHIVO
 *   La validación histórica (ERA5) y el backtest de estaciones (SENAMHI)
 *   tienen que responder EXACTAMENTE la misma pregunta con dos fuentes de
 *   lluvia distintas. Si cada script tiene su propia copia de la aritmética,
 *   cualquier diferencia entre los dos resultados se vuelve indistinguible de
 *   un efecto real de la fuente: es el error clásico de comparar dos
 *   implementaciones y atribuir la diferencia al dato.
 *
 *   Aquí vive UNA sola implementación. `validar_historico.php` la usa para el
 *   reanálisis ERA5 y `backtest_estaciones.php` la usa para las series del
 *   SENAMHI. Lo que cambia entre una corrida y otra es la serie que entra;
 *   la fórmula es la misma en las dos.
 *
 * LO QUE ES Y LO QUE NO ES
 *   · Es la MISMA aritmética que usa el motor de producción:
 *     `riesgo_indice_lluvia()` + el índice con exposición y vulnerabilidad.
 *   · NO es un modelo nuevo. Si esto cambia, hay que revisar el motor.
 *   · NO inventa datos: una serie con huecos deja huecos; una ventana con un
 *     día sin dato no se cuenta.
 */

/** Echo de una línea. */
function wq_linea($texto = '') {
    echo $texto . PHP_EOL;
}

/**
 * Eventos históricos del piloto: las ventanas siguen el calendario de los
 * Episodes El Niño costeros en Perú.
 *
 * UNA sola definición para los dos scripts: la validación ERA5 y el backtest
 * de estaciones comparan las mismas fechas y las mismas listas de referencia.
 * Si cada script declarara las suyas, una diferencia de resultados podría
 * ser un desfase de ventanas y no un efecto de la fuente de lluvia.
 *
 * @return array ['clave' => ['titulo'=>, 'inicio'=>, 'fin'=>, 'referencia'=>[ids]]]
 */
function wq_eventos_historicos() {
    return [
        '2017' => [
            'titulo' => 'El Niño costero 2017 (enero–marzo)',
            'inicio' => '2017-01-01',
            'fin' => '2017-03-31',
            'referencia' => ['tum-tumbes', 'piu-catacaos', 'piu-piura', 'piu-sullana', 'piu-tambogrande', 'piu-chulucanas'],
        ],
        '2017-18' => [
            'titulo' => 'El Niño costero 2017–2018 (diciembre–febrero)',
            'inicio' => '2017-12-01',
            'fin' => '2018-02-28',
            'referencia' => ['tum-tumbes', 'piu-catacaos', 'piu-piura', 'piu-sullana', 'piu-tambogrande'],
        ],
        '2023' => [
            'titulo' => 'El Niño costero 2023 (diciembre 2022 – marzo 2023)',
            'inicio' => '2022-12-01',
            'fin' => '2023-03-31',
            'referencia' => ['tum-tumbes', 'tum-zarumilla', 'tum-aguas-verdes', 'piu-catacaos', 'piu-piura', 'piu-sullana', 'piu-paita'],
        ],
    ];
}

/** ¿La fecha cae en la ventana de calendario ±días alrededor de un mes-día? */
function wq_en_ventana_calendario($mes, $dia, $mesRef, $diaRef, $rango = 15) {
    $a = (int)date('z', mktime(0, 0, 0, (int)$mesRef, (int)$diaRef, 2001));
    $b = (int)date('z', mktime(0, 0, 0, (int)$mes, (int)$dia, 2001));
    $dist = abs($a - $b);
    $dist = min($dist, 365 - $dist);
    return $dist <= $rango;
}

/**
 * Serie de precipitación del reanálisis (formato de la caché de Open-Meteo).
 */
function wq_serie_precipitacion($archivo) {
    $serie = [];
    foreach ($archivo['dias'] as $i => $fecha) {
        $serie[$fecha] = is_numeric($archivo['precipitacion'][$i] ?? null)
            ? (float)$archivo['precipitacion'][$i]
            : null;
    }
    ksort($serie);
    return $serie;
}

/**
 * Serie de precipitación de una estación del SENAMHI (formato de
 * data/estaciones/{id}.json).
 *
 * IMPORTANTE: se devuelve una serie DENSA (todos los días entre la primera y
 * la última fecha). Los días sin medición quedan en `null`, no se saltan.
 * La diferencia importa: si se saltaran, una ventana de 3 días podría
 * abarcar 5 días calendario y el detector mediría otra cosa.
 *
 * · `S/D` y `T` (traza) quedan en `null`: no hay milimetraje medido.
 * · Los días que ni siquiera aparecen en el archivo también quedan en `null`.
 */
function wq_serie_desde_datos($datos) {
    if (!is_array($datos) || empty($datos)) {
        return [];
    }

    $mapa = [];
    foreach ($datos as $d) {
        $f = (string)($d['fecha'] ?? '');
        if ($f === '') {
            continue;
        }
        $v = $d['precip_mm'] ?? null;
        $mapa[$f] = is_numeric($v) ? (float)$v : null;
    }
    if (empty($mapa)) {
        return [];
    }
    ksort($mapa);

    $fechas = array_keys($mapa);
    $ini = strtotime($fechas[0]);
    $fin = strtotime($fechas[count($fechas) - 1]);
    if ($ini === false || $fin === false) {
        return [];
    }

    $serie = [];
    for ($ts = $ini; $ts <= $fin; $ts += 86400) {
        $f = date('Y-m-d', $ts);
        $serie[$f] = array_key_exists($f, $mapa) ? $mapa[$f] : null;
    }
    return $serie;
}

/**
 * Máximo acumulado de N días consecutivos DENTRO del rango del evento.
 *
 * La ventana debe caber completamente dentro del evento: una ventana de 30
 * días que empieza el 20 de febrero y termina el 22 de marzo incluye marzo,
 * que no pertenece al evento. Sin esta restricción el detector "roba" lluvia
 * de meses vecinos y el análisis se vuelve optimista.
 *
 * @return array ['acumulado'=>float,'fecha'=>string|null,'inicio'=>string|null]
 */
function wq_max_acumulado($serie, $N, $inicio, $fin) {
    $fechas = array_keys($serie);
    $mejor = 0.0;
    $mejorFin = null;
    $mejorIni = null;
    for ($idx = 0; $idx < count($fechas); $idx++) {
        $fechaIni = $fechas[$idx];
        if ($fechaIni < $inicio) {
            continue;
        }
        $fechaFin = $fechas[$idx + $N - 1] ?? null;
        if ($fechaFin === null || $fechaFin > $fin) {
            continue; // la ventana no cabe entera en el evento
        }
        $suma = 0.0;
        $ok = true;
        for ($k = 0; $k < $N; $k++) {
            $f = $fechas[$idx + $k] ?? null;
            if ($f === null || !isset($serie[$f]) || $serie[$f] === null) {
                $ok = false;
                break;
            }
            $suma += $serie[$f];
        }
        if ($ok && $suma > $mejor) {
            $mejor = $suma;
            $mejorFin = $fechaFin;
            $mejorIni = $fechaIni;
        }
    }
    return ['acumulado' => $mejor, 'fecha' => $mejorFin, 'inicio' => $mejorIni];
}

/**
 * Distribución de referencia: acumulados móviles de N días en la ventana de
 * calendario (±días alrededor del mes-día del evento), sobre toda la serie.
 * Las ventanas de referencia también deben caber dentro de su propia ventana
 * de calendario, de modo que la comparación sea simétrica.
 */
function wq_referencia_acumulado($serie, $N, $mesRef, $diaRef, $rango = 15) {
    $fechas = array_keys($serie);
    $valores = [];
    $total = count($fechas);
    for ($idx = 0; $idx + $N - 1 < $total; $idx++) {
        $fin = $fechas[$idx + $N - 1];
        $ini = $fechas[$idx];
        $tsIni = strtotime($ini);
        $tsFin = strtotime($fin);
        if (!wq_en_ventana_calendario((int)date('n', $tsIni), (int)date('j', $tsIni), $mesRef, $diaRef, $rango)) {
            continue;
        }
        if (!wq_en_ventana_calendario((int)date('n', $tsFin), (int)date('j', $tsFin), $mesRef, $diaRef, $rango)) {
            continue;
        }
        $suma = 0.0;
        $ok = true;
        for ($k = 0; $k < $N; $k++) {
            $f = $fechas[$idx + $k] ?? null;
            if ($f === null || !isset($serie[$f]) || $serie[$f] === null) {
                $ok = false;
                break;
            }
            $suma += $serie[$f];
        }
        if ($ok) {
            $valores[] = $suma;
        }
    }
    sort($valores);
    return $valores;
}

/**
 * Total del evento: precipitación acumulada en toda la ventana del evento.
 * Se usa como dato descriptivo (no como detector) porque la escala depende
 * de la duración de cada evento.
 */
function wq_total_evento($serie, $inicio, $fin) {
    $total = 0.0;
    $dias = 0;
    foreach ($serie as $f => $v) {
        if ($f < $inicio || $f > $fin || $v === null) {
            continue;
        }
        $total += $v;
        $dias++;
    }
    return ['total' => round($total, 1), 'dias' => $dias];
}

/**
 * Detección de un evento histórico sobre UNA serie de precipitación.
 *
 * Esta es la función que comparten la validación ERA5 y el backtest de
 * estaciones. Devuelve la fila de resultado (la misma estructura que se
 * guarda en data/validacion_historica.json) o `null` si la serie no permite
 * evaluar ninguna escala.
 *
 * @param array  $serie  ['YYYY-MM-DD' => float|null]
 * @param array  $evento ['inicio'=>..,'fin'=>..,'referencia'=>[ids]]
 * @param float  $E      exposición normalizada del distrito
 * @param float  $V      vulnerabilidad del distrito
 * @param array  $t      territorio (para id, distrito, region)
 */
function wq_deteccion_evento($serie, $evento, $E, $V, $t) {
    global $ESCALAS_DETECCION;

    $escalas = [];
    foreach ($ESCALAS_DETECCION as $nombre => $cfg) {
        $N = (int)$cfg['dias'];
        $acum = wq_max_acumulado($serie, $N, $evento['inicio'], $evento['fin']);
        if ($acum['fecha'] === null) {
            continue;
        }
        $ts = strtotime($acum['fecha']);
        $ref = wq_referencia_acumulado($serie, $N, (int)date('n', $ts), (int)date('j', $ts));
        if (count($ref) < 20) {
            continue;
        }
        $p50 = clima_percentil($ref, 50);
        $p95 = clima_percentil($ref, 95);
        $p99 = clima_percentil($ref, 99);

        // Misma fórmula de producción.
        $idx = riesgo_indice_lluvia(
            $acum['acumulado'],
            $p50,
            $p95,
            $p99,
            $cfg['min_abs'],
            $cfg['signo_abs']
        );

        $R = round(riesgo_limitar(100 * $idx['H'] * (0.40 + 0.35 * $E + 0.25 * $V), 0, 100), 1);
        $nivel = riesgo_nivel($R);

        $escalas[$nombre] = [
            'dias' => $N,
            'valor' => round($acum['acumulado'], 1),
            'fecha' => $acum['fecha'],
            'referencia_p50' => round($p50, 2),
            'referencia_p95' => round($p95, 2),
            'referencia_p99' => round($p99, 2),
            'ratio_p95' => $idx['ratio_p95'],
            'peligro' => round($idx['H'], 3),
            'materialidad' => round($idx['M'], 2),
            'min_efectivo' => round($idx['min_efectivo'], 1),
            'riesgo' => $R,
            'nivel' => $nivel['id'],
            'nivel_etiqueta' => $nivel['etiqueta'],
        ];
    }

    if (empty($escalas)) {
        return null;
    }

    // Escala ganadora (mayor índice).
    $ganadora = array_keys($escalas);
    usort($ganadora, fn($a, $b) => $escalas[$b]['riesgo'] <=> $escalas[$a]['riesgo']);
    $claveEscala = $ganadora[0];
    $mejor = $escalas[$claveEscala];

    // Anomalía máxima observada en cualquier escala (para el diagnóstico).
    $ratioMax = 0.0;
    $ratioMaxEscala = null;
    foreach ($escalas as $nombreEscala => $e) {
        if ($e['ratio_p95'] !== null && $e['ratio_p95'] > $ratioMax) {
            $ratioMax = $e['ratio_p95'];
            $ratioMaxEscala = $nombreEscala;
        }
    }

    // Total del evento (descriptivo): cuánta lluvia cayó en toda la ventana.
    $totalEvento = wq_total_evento($serie, $evento['inicio'], $evento['fin']);

    $detectado = in_array($mejor['nivel'], ['naranja', 'rojo'], true);
    $referencia = $evento['referencia'] ?? [];

    return [
        'id' => $t['id'],
        'distrito' => $t['distrito'],
        'region' => $t['region'],
        'escala_ganadora' => $claveEscala,
        'escalas' => $escalas,
        'valor' => $mejor['valor'],
        'fecha_valor' => $mejor['fecha'],
        'referencia_p50' => $mejor['referencia_p50'],
        'referencia_p95' => $mejor['referencia_p95'],
        'ratio_p95_max' => round($ratioMax, 2),
        'ratio_p95_max_escala' => $ratioMaxEscala,
        'evento_total_mm' => $totalEvento['total'],
        'evento_dias' => $totalEvento['dias'],
        'peligro' => $mejor['peligro'],
        'materialidad' => $mejor['materialidad'],
        'exposicion' => round($E, 3),
        'vulnerabilidad' => round($V, 3),
        'riesgo' => $mejor['riesgo'],
        'nivel' => $mejor['nivel'],
        'nivel_etiqueta' => $mejor['nivel_etiqueta'],
        'detectado' => $detectado,
        'en_referencia' => in_array($t['id'], $referencia, true),
        'diagnostico' => null,
    ];
}

/**
 * Exposición y vulnerabilidad de un distrito, con el MISMO redondeo a 3
 * decimales que usa la validación histórica. Así la validación y la
 * producción comparten una aritmética literalmente idéntica.
 */
function wq_exposicion_vulnerabilidad($t) {
    $E = round(riesgo_limitar(
        0.5 * riesgo_exp_poblacion($t['poblacion_expuesta_inundacion'])
        + 0.5 * (float)$t['susc_inundacion']
    ), 3);
    $V = round((float)$t['vulnerabilidad'], 3);
    return [$E, $V];
}
