<?php
/**
 * MOTOR DE RIESGO MULTI-AMENAZA — WILLAQ
 *
 * Convierte datos climáticos en una decisión territorial explicable.
 *
 * Estructura del índice (idéntica para las seis amenazas, para que sean comparables):
 *
 *     R(t,h,w) = 100 · H · ( 0.40 + 0.35·E + 0.25·V )
 *
 *   H = peligro normalizado 0-1. Es la CONDICIÓN NECESARIA: sin peligro no hay riesgo.
 *   E = exposición normalizada 0-1 (población e infraestructura en la zona).
 *   V = vulnerabilidad estructural 0-1 (pobreza, material de vivienda, drenaje).
 *
 * Con H=0 el riesgo es exactamente 0. Con H=1, E=1 y V=1 el riesgo es 100.
 * Exposición y vulnerabilidad AMPLIFICAN, no crean, el riesgo. Eso es defendible
 * ante un jurado: un distrito vulnerable sin lluvia no está en emergencia.
 *
 * Normalización del peligro (piecewise, interpretable):
 *   valor ≤ p95 :  H = 0.75 · (valor - p50) / (p95 - p50)
 *   valor > p95 :  H = 0.75 + 0.25 · min(1, (valor - p95) / (p99 - p95))
 *
 * Es decir: "una lluvia en el p95 histórico ya es el 75 % del peligro máximo".
 */

require_once __DIR__ . '/Clima.php';
require_once __DIR__ . '/Territorio.php';
require_once __DIR__ . '/../config/umbrales.php';
require_once __DIR__ . '/../config/env.php';

function riesgo_limitar($v, $min = 0.0, $max = 1.0) {
    if (!is_numeric($v)) {
        return $min;
    }
    return max($min, min($max, (float)$v));
}

/** Normalización piecewise descrita arriba. */
function riesgo_norm($valor, $p50, $p95, $p99 = null) {
    if (!is_numeric($valor) || !is_numeric($p50) || !is_numeric($p95)) {
        return 0.0;
    }
    if ($p95 <= $p50) {
        $p95 = $p50 + 1.0;
    }
    if ($p99 === null || $p99 <= $p95) {
        $p99 = $p95 + max(1.0, ($p95 - $p50));
    }

    if ($valor <= $p50) {
        return 0.0;
    }
    if ($valor <= $p95) {
        return riesgo_limitar(0.75 * (($valor - $p50) / ($p95 - $p50)));
    }
    return riesgo_limitar(0.75 + 0.25 * (($valor - $p95) / ($p99 - $p95)));
}

/**
 * Rampa de materialidad: convierte una magnitud en un factor 0–1 según el
 * impacto que realmente tiene.
 *
 * Motivo: un valor puede ser estadísticamente extremo PARA SU ZONA y, a la vez,
 * trivial en términos de impacto. En Lima, 1.3 mm de lluvia en 24 h está por
 * encima del p99 local, pero no es un evento: es llovizna. La rampa impone un
 * mínimo absoluto (a partir del cual el evento existe) y un umbral de
 * significancia (a partir del cual el evento es plenamente material).
 *
 * @param float $valor        magnitud observada
 * @param float $minimo       por debajo de este valor, materialidad = 0
 * @param float $significativo a partir de este valor, materialidad = 1
 */
function riesgo_rampa($valor, $minimo, $significativo) {
    if (!is_numeric($valor)) {
        return 0.0;
    }
    $v = (float)$valor;
    $min = (float)$minimo;
    $sig = (float)$significativo;
    if ($sig <= $min) {
        return $v >= $sig ? 1.0 : 0.0;
    }
    return riesgo_limitar(($v - $min) / ($sig - $min));
}

/**
 * Índice de peligro por lluvia: percentil local × materialidad.
 *
 * Este es EL camino de código compartido entre producción y validación
 * histórica: si la fórmula cambia aquí, cambia en ambos lados. Es la garantía
 * de que la validación no valida una reimplementación.
 *
 * @param float $valor             magnitud observada (mm acumulados)
 * @param float $p50               mediana local de la escala comparable
 * @param float $p95               percentil 95 local de la escala comparable
 * @param float|null $p99          percentil 99 local
 * @param float $minAbs            umbral absoluto de materialidad (desde aquí hay evento)
 * @param float $signoAbs          umbral absoluto de evento pleno
 * @param float $probProbabilidad  probabilidad máxima de lluvia (0–100); si ≥ 60
 *                                 refuerza el peligro ×1.08
 * @return array
 */
function riesgo_indice_lluvia($valor, $p50, $p95, $p99, $minAbs, $signoAbs, $probProbabilidad = 0.0) {
    $valor = (float)$valor;
    $p50 = (float)$p50;
    $p95 = (float)$p95;

    // El mínimo efectivo nunca es menor que el p95 local: en una zona que
    // llueve normalmente el evento no empieza antes del p95.
    $minEf = max((float)$minAbs, $p95);
    $sigEf = max((float)$signoAbs, $minEf * 1.6);

    $M = riesgo_rampa($valor, $minEf, $sigEf);
    $H = riesgo_norm($valor, $p50, $p95, $p99) * $M;
    if ((float)$probProbabilidad >= 60 && $M > 0) {
        $H = riesgo_limitar($H * 1.08);
    }

    return [
        'H' => $H,
        'M' => $M,
        'min_efectivo' => $minEf,
        'signo_efectivo' => $sigEf,
        'p95' => $p95,
        'ratio_p95' => $p95 > 0 ? round($valor / $p95, 2) : null,
    ];
}

/**
 * Devuelve el nivel (verde/amarillo/naranja/rojo) de un riesgo 0-100.
 *
 * Las bandas son continuas: [0,26) verde · [26,51) amarillo · [51,76) naranja
 · [76,100] rojo. Se comparan por el mínimo (y no por un intervalo cerrado)
 * porque un valor intermedio (por ejemplo 25.7) no debe quedar fuera de la
 * tabla y caer por defecto en el nivel más alto.
 */
function riesgo_nivel($valor) {
    global $NIVELES;
    $valor = (float)$valor;
    $nivel = $NIVELES[0];
    foreach ($NIVELES as $n) {
        if ($valor >= $n['min']) {
            $nivel = $n;
        }
    }
    return $nivel;
}

/**
 * Escala de exposición poblacional.
 * Con 4 750 habitantes expuestos → 0.31; con 66 500 → 0.81; con 600 000 → 1.0.
 */
function riesgo_exp_poblacion($personas) {
    $p = max(0.0, (float)$personas);
    if ($p <= 0) {
        return 0.0;
    }
    return riesgo_limitar(pow($p / 120000.0, 0.35), 0.02, 1.0);
}

/** Umbrales efectivos: climatología real si existe; si no, respaldo regional. */
function riesgo_umbrales($territorio, $permitir_calculo = false) {
    global $UMBRALES_ZONA;
    $zona = $territorio['zona'] ?? 'costa_centro';
    $respaldo = $UMBRALES_ZONA[$zona] ?? $UMBRALES_ZONA['costa_centro'];

    $climatologia = clima_climatologia($territorio, $permitir_calculo);
    if ($climatologia) {
        return array_merge($respaldo, $climatologia);
    }

    return array_merge($respaldo, [
        'fuente' => 'Umbrales regionales de respaldo (' . $zona . ')',
        'confianza' => 'media',
        'p50_90' => $respaldo['p50_90'] ?? ($respaldo['p50_24'] * 90),
        'p95_90' => $respaldo['p95_90'] ?? ($respaldo['p95_24'] * 90),
    ]);
}

/** Índice del día de hoy dentro del arreglo diario (porque se piden 7 días de pasado). */
function riesgo_indice_hoy($forecast) {
    if (!$forecast || empty($forecast['dias'])) {
        return null;
    }
    $hoy = clima_hoy()->format('Y-m-d');
    $i = array_search($hoy, $forecast['dias'], true);
    if ($i !== false) {
        return (int)$i;
    }
    return count($forecast['dias']) > 7 ? count($forecast['dias']) - 7 : 0;
}

/** Suma máxima de 3 horas consecutivas dentro de la ventana (disparador de huaico). */
function riesgo_max_3h($forecast, $ventanaHoras) {
    if (!$forecast || empty($forecast['horas']['precipitacion'])) {
        return 0.0;
    }

    $hoy = clima_hoy()->format('Y-m-d');
    $inicio = strtotime($hoy . ' 00:00:00');
    $fin = $inicio + $ventanaHoras * 3600;

    $serie = [];
    foreach ($forecast['horas']['tiempo'] as $i => $marca) {
        $ts = strtotime($marca);
        if ($ts >= $inicio && $ts < $fin) {
            $serie[] = (float)($forecast['horas']['precipitacion'][$i] ?? 0.0);
        }
    }

    $maximo = 0.0;
    $n = count($serie);
    for ($i = 0; $i + 3 <= $n; $i++) {
        $suma = $serie[$i] + $serie[$i + 1] + $serie[$i + 2];
        if ($suma > $maximo) {
            $maximo = $suma;
        }
    }
    return $maximo;
}

/** Obtiene y cachea todas las fuentes de un territorio. */
function riesgo_datos($territorio) {
    $forecast = clima_forecast($territorio['lat'], $territorio['lon']);
    $flood = clima_flood($territorio['lat'], $territorio['lon']);
    $reciente = clima_reciente($territorio['lat'], $territorio['lon']);
    $umbrales = riesgo_umbrales($territorio, false);

    return [
        'forecast' => $forecast,
        'flood' => $flood,
        'reciente' => $reciente,
        'umbrales' => $umbrales,
    ];
}

/**
 * CALCULA EL RIESGO COMPLETO DE UN TERRITORIO.
 *
 * @param array $t       Territorio (de territorios_todos())
 * @param int   $ventana 24, 48 o 72 horas
 * @param array|null $escenario ['factor_lluvia'=>1.5,'delta_temp'=>1.0,'factor_caudal'=>1.0]
 */
function riesgo_calcular($t, $ventana = 24, $escenario = null) {
    global $PESOS_RIESGO, $SENSIBILIDAD_CULTIVO, $MATERIALIDAD;

    $ventana = in_array((int)$ventana, [24, 48, 72], true) ? (int)$ventana : 24;
    $factorLluvia = (float)($escenario['factor_lluvia'] ?? 1.0);
    $deltaTemp = (float)($escenario['delta_temp'] ?? 0.0);
    $factorCaudal = (float)($escenario['factor_caudal'] ?? 1.0);

    $datos = riesgo_datos($t);
    $f = $datos['forecast'];
    $fl = $datos['flood'];
    $rec = $datos['reciente'];
    $u = $datos['umbrales'];

    $i0 = riesgo_indice_hoy($f);
    $diasVentana = (int)($ventana / 24);
    $factorVentana = [1 => 1.0, 2 => 1.6, 3 => 2.1][$diasVentana] ?? 1.0;

    // ---------- Lluvia ----------
    $precipVentana = ($f && $i0 !== null)
        ? array_slice($f['precipitacion'], $i0, $diasVentana)
        : [];
    $lluviaAcum = (float)(clima_suma($precipVentana) ?? 0.0) * $factorLluvia;

    $probVentana = ($f && $i0 !== null)
        ? max(array_merge([0], array_slice($f['prob_lluvia'], $i0, $diasVentana)))
        : 0;

    $p50 = (float)$u['p50_24'] * $factorVentana;
    $p95 = (float)$u['p95_24'] * $factorVentana;
    $p99 = (float)$u['p99_24'] * $factorVentana;

    // ---------- Materialidad ----------
    // Un valor puede ser extremo para su zona y a la vez irrelevante en
    // términos de impacto (1 mm de lluvia en Lima). El percentil dice "es
    // raro"; la materialidad dice "importa". Se combinan así:
    //   H = H_percentil × factor_materialidad
    // El cálculo vive en riesgo_indice_lluvia() para que la validación
    // histórica use exactamente la misma fórmula.
    $matLluvia = $MATERIALIDAD['lluvia'][$ventana] ?? $MATERIALIDAD['lluvia'][24];
    $idxLluvia = riesgo_indice_lluvia(
        $lluviaAcum,
        $p50,
        $p95,
        $p99,
        $matLluvia['min_abs'],
        $matLluvia['signo_abs'],
        $probVentana
    );
    $M_lluvia = $idxLluvia['M'];
    $H_lluvia = $idxLluvia['H'];
    $vMinLluvia = $idxLluvia['min_efectivo'];
    $vSigLluvia = $idxLluvia['signo_efectivo'];

    // ---------- Inundación fluvial ----------
    $p95Pasado = null;
    $picoCaudal = null;
    if ($fl && !empty($fl['descarga_max'])) {
        $serie = $fl['descarga_max'];
        $pasado = array_slice($serie, 0, 31);
        $futuro = array_slice($serie, 31, 3);
        $p95Pasado = clima_percentil($pasado, 95);
        $picoCaudal = !empty($futuro) ? max($futuro) * $factorCaudal : null;
    }

    $ratioCaudal = null;
    $H_caudal = 0.0;
    if ($picoCaudal !== null && $p95Pasado !== null) {
        $ratioCaudal = $picoCaudal / max(0.5, (float)$p95Pasado);
        $ratioMin = (float)($MATERIALIDAD['caudal']['ratio_min'] ?? 1.05);
        $ratioSig = (float)($MATERIALIDAD['caudal']['ratio_signo'] ?? 2.50);
        // Las variaciones menores al 5 % sobre el p95 local se ignoran.
        $H_caudal = riesgo_limitar(($ratioCaudal - $ratioMin) / max(0.1, ($ratioSig - $ratioMin)));
    }

    $H_inundacion = 0.45 * $H_caudal
        + 0.35 * $H_lluvia
        + 0.20 * (float)$t['susc_inundacion'];

    // ---------- Huaico ----------
    $max3h = riesgo_max_3h($f, $ventana) * $factorLluvia;
    $p95_3h = max(0.5, (float)$u['p95_24'] * 0.45);
    $p99_3h = max($p95_3h + 1.0, (float)$u['p99_24'] * 0.60);
    $H_intensidad3h = riesgo_norm($max3h, $p95_3h * 0.4, $p95_3h, $p99_3h);

    // Materialidad de la lluvia intensa: 4 mm en 3 h es el mínimo físico.
    $mat3h = $MATERIALIDAD['intensidad_3h'];
    $vMin3h = max((float)$mat3h['min_abs'], $p95_3h);
    $vSig3h = max((float)$mat3h['signo_abs'], $vMin3h * 1.8);
    $M_3h = riesgo_rampa($max3h, $vMin3h, $vSig3h);
    $H_intensidad3h *= $M_3h;

    $disparadorHuaico = max($H_lluvia, $H_intensidad3h);
    $H_huaico = $disparadorHuaico * (float)$t['susc_huaico'];

    // ---------- Calor ----------
    $stmaxVentana = ($f && $i0 !== null)
        ? array_slice($f['stmax'], $i0, $diasVentana)
        : [];
    $stmaxVentana = array_values(array_filter($stmaxVentana, 'is_numeric'));
    $stmaxMax = !empty($stmaxVentana) ? max($stmaxVentana) + $deltaTemp : null;
    $H_calor = riesgo_norm($stmaxMax, (float)$u['p50_temp'], (float)$u['p95_temp'], (float)$u['p99_temp']);
    // Materialidad térmica: por debajo de 28 °C de temperatura aparente no hay
    // riesgo para la salud; a partir de 35 °C el evento es pleno.
    $matCalor = $MATERIALIDAD['calor'];
    $M_calor = riesgo_rampa($stmaxMax, (float)$matCalor['min_abs'], (float)$matCalor['signo_abs']);
    $H_calor *= $M_calor;
    if ($stmaxMax !== null && $M_calor > 0) {
        $diasSobreP95 = count(array_filter($stmaxVentana, fn($v) => ($v + $deltaTemp) > (float)$u['p95_temp']));
        $H_calor = riesgo_limitar($H_calor + 0.05 * $diasSobreP95 * $M_calor);
    }

    // ---------- Sequía ----------
    $acum90 = isset($rec['acumulado']) ? (float)$rec['acumulado'] : null;
    $p50_90 = max(1.0, (float)($u['p50_90'] ?? ((float)$u['p50_24'] * 90)));
    $deficit = $acum90 === null ? 0.0 : riesgo_limitar(1.0 - ($acum90 / $p50_90));
    $diasSecos = (int)($rec['dias_sin_lluvia'] ?? 0);
    $matSequia = $MATERIALIDAD['sequia'];
    $M_sequia = riesgo_rampa($deficit, (float)$matSequia['min_abs'], (float)$matSequia['signo_abs']);
    $H_sequia = riesgo_limitar(0.75 * $deficit + 0.25 * riesgo_limitar($diasSecos / 45.0)) * $M_sequia;

    // ---------- Agropecuario ----------
    $exceso = max(0.0, $lluviaAcum - $p95) / max(1.0, $p95);
    $sensibilidad = (float)($SENSIBILIDAD_CULTIVO[$t['cultivo']] ?? 0.6);
    // El exceso sobre el p95 solo cuenta si la lluvia es material: en un
    // desierto, 6 mm pueden superar el p95 local sin dañar ningún cultivo.
    $estresHidrico = max(riesgo_limitar($exceso) * $M_lluvia, $deficit * $M_sequia);
    $H_agro = riesgo_limitar(0.55 * $estresHidrico + 0.45 * $H_calor);

    // ---------- Exposición ----------
    $E_lluvia = 0.5 * riesgo_exp_poblacion($t['poblacion_expuesta_inundacion']) + 0.5 * (float)$t['susc_inundacion'];
    $E_inundacion = $E_lluvia;
    $E_huaico = 0.5 * riesgo_exp_poblacion($t['poblacion_expuesta_huaico']) + 0.5 * (float)$t['susc_huaico'];
    $E_sequia = 0.5 * riesgo_exp_poblacion($t['poblacion_expuesta_sequia']) + 0.5 * (float)$t['susc_sequia'];
    $E_calor = 0.65 * riesgo_exp_poblacion($t['poblacion_expuesta_calor']) + 0.35 * 0.70;
    $E_agro = 0.5 * riesgo_exp_poblacion($t['poblacion_expuesta_agro']) + 0.5 * min(1.0, $t['hectareas_cultivo'] / 9000.0);

    // La vulnerabilidad se redondea a 3 decimales ANTES de entrar en la
    // fórmula, igual que H y E. Motivo: el valor que el sistema muestra en
    // pantalla tiene que ser exactamente el que se usa en el cálculo. Si el
    // índice se calculara con un valor más preciso que el mostrado, un
    // auditor que rehaga la cuenta a mano obtendría una diferencia (≤0.1) y
    // el sistema perdería su principal garantía: ser reproducible.
    $V = round((float)$t['vulnerabilidad'], 3);

    $definiciones = [
        'lluvia' => [
            'nombre' => 'Lluvia extrema',
            'icono' => 'lluvia',
            'H' => $H_lluvia,
            'E' => $E_lluvia,
            'detalle' => [
                ['variable' => 'Precipitación acumulada ' . $ventana . ' h', 'valor' => round($lluviaAcum, 1), 'unidad' => 'mm', 'umbral' => round($p95, 1), 'fuente' => 'Open-Meteo', 'peso' => 1.0],
                ['variable' => 'Probabilidad máxima de lluvia', 'valor' => round($probVentana), 'unidad' => '%', 'umbral' => 60, 'fuente' => 'Open-Meteo', 'peso' => 0.3],
                ['variable' => 'Factor de materialidad', 'valor' => round($M_lluvia, 2), 'unidad' => '×', 'umbral' => round($vMinLluvia, 1), 'fuente' => 'Regla de materialidad', 'peso' => 1.0],
            ],
        ],
        'inundacion' => [
            'nombre' => 'Inundación fluvial',
            'icono' => 'agua',
            'H' => riesgo_limitar($H_inundacion),
            'E' => $E_inundacion,
            'detalle' => [
                ['variable' => 'Caudal máximo pronosticado', 'valor' => $picoCaudal !== null ? round($picoCaudal, 1) : null, 'unidad' => 'm³/s', 'umbral' => $p95Pasado !== null ? round((float)$p95Pasado, 1) : null, 'fuente' => 'GloFAS 5 km', 'peso' => 0.45],
                ['variable' => 'Anomalía de caudal', 'valor' => $ratioCaudal !== null ? round($ratioCaudal, 2) : null, 'unidad' => '×', 'umbral' => 1.0, 'fuente' => 'GloFAS 5 km', 'peso' => 0.45],
                ['variable' => 'Lluvia acumulada', 'valor' => round($lluviaAcum, 1), 'unidad' => 'mm', 'umbral' => round($p95, 1), 'fuente' => 'Open-Meteo', 'peso' => 0.35],
                ['variable' => 'Susceptibilidad de llanura', 'valor' => $t['susc_inundacion'], 'unidad' => 'índice', 'umbral' => 0.7, 'fuente' => 'Inventario histórico', 'peso' => 0.20],
            ],
        ],
        'huaico' => [
            'nombre' => 'Huaico / deslizamiento',
            'icono' => 'montana',
            'H' => riesgo_limitar($H_huaico),
            'E' => $E_huaico,
            'detalle' => [
                ['variable' => 'Intensidad máxima en 3 h', 'valor' => round($max3h, 1), 'unidad' => 'mm', 'umbral' => round($p95_3h, 1), 'fuente' => 'Open-Meteo horario', 'peso' => 0.55],
                ['variable' => 'Disparador de lluvia', 'valor' => round($disparadorHuaico, 2), 'unidad' => 'índice', 'umbral' => 0.5, 'fuente' => 'Open-Meteo', 'peso' => 0.55],
                ['variable' => 'Susceptibilidad de cuenca', 'valor' => $t['susc_huaico'], 'unidad' => 'índice', 'umbral' => 0.7, 'fuente' => 'Pendiente + suelo + historial', 'peso' => 0.45],
                ['variable' => 'Factor de materialidad (3 h)', 'valor' => round($M_3h, 2), 'unidad' => '×', 'umbral' => round($vMin3h, 1), 'fuente' => 'Regla de materialidad', 'peso' => 1.0],
            ],
        ],
        'sequia' => [
            'nombre' => 'Sequía',
            'icono' => 'sol',
            'H' => $H_sequia,
            'E' => $E_sequia,
            'detalle' => [
                ['variable' => 'Déficit de lluvia (90 días)', 'valor' => round($deficit * 100, 1), 'unidad' => '%', 'umbral' => 30, 'fuente' => 'ERA5', 'peso' => 0.75],
                ['variable' => 'Días secos consecutivos', 'valor' => $diasSecos, 'unidad' => 'días', 'umbral' => 30, 'fuente' => 'ERA5', 'peso' => 0.25],
                ['variable' => 'Factor de materialidad', 'valor' => round($M_sequia, 2), 'unidad' => '×', 'umbral' => 30, 'fuente' => 'Regla de materialidad', 'peso' => 1.0],
            ],
        ],
        'calor' => [
            'nombre' => 'Calor extremo',
            'icono' => 'termometro',
            'H' => $H_calor,
            'E' => $E_calor,
            'detalle' => [
                ['variable' => 'Temperatura aparente máxima', 'valor' => $stmaxMax !== null ? round($stmaxMax, 1) : null, 'unidad' => '°C', 'umbral' => round((float)$u['p95_temp'], 1), 'fuente' => 'Open-Meteo', 'peso' => 1.0],
                ['variable' => 'Factor de materialidad térmica', 'valor' => round($M_calor, 2), 'unidad' => '×', 'umbral' => (float)$matCalor['min_abs'], 'fuente' => 'Regla de materialidad', 'peso' => 1.0],
            ],
        ],
        'agro' => [
            'nombre' => 'Riesgo agropecuario',
            'icono' => 'planta',
            'H' => $H_agro,
            'E' => $E_agro,
            'detalle' => [
                ['variable' => 'Estrés hídrico', 'valor' => round($estresHidrico * 100, 1), 'unidad' => '%', 'umbral' => 40, 'fuente' => 'ERA5 + Open-Meteo', 'peso' => 0.55],
                ['variable' => 'Estrés térmico', 'valor' => round($H_calor * 100, 1), 'unidad' => '%', 'umbral' => 50, 'fuente' => 'Open-Meteo', 'peso' => 0.45],
                ['variable' => 'Sensibilidad del cultivo (' . $t['cultivo'] . ')', 'valor' => $sensibilidad, 'unidad' => 'índice', 'umbral' => 0.7, 'fuente' => 'Tabla agronómica', 'peso' => 0.3],
            ],
        ],
    ];

    $amenazas = [];
    foreach ($definiciones as $id => $d) {
        // H y E se redondean a 3 decimales antes de la fórmula: son los mismos
        // valores que la amenaza expone en 'peligro' y 'exposicion', así que
        // la ficha, el reporte y el API permiten reproducir el índice a mano.
        $H = round(riesgo_limitar($d['H']), 3);
        $E = round(riesgo_limitar($d['E']), 3);
        $riesgo = 100.0 * $H * (
            $PESOS_RIESGO['base']
            + $PESOS_RIESGO['exposicion'] * $E
            + $PESOS_RIESGO['vulnerabilidad'] * $V
        );
        $riesgo = round(riesgo_limitar($riesgo, 0, 100), 1);

        $amenazas[$id] = [
            'id' => $id,
            'nombre' => $d['nombre'],
            'icono' => $d['icono'],
            'peligro' => $H,
            'exposicion' => $E,
            'vulnerabilidad' => $V,
            'riesgo' => $riesgo,
            'nivel' => riesgo_nivel($riesgo),
            'detalle' => $d['detalle'],
        ];
    }

    // Amenaza dominante
    $principalId = 'lluvia';
    $mayor = -1;
    foreach ($amenazas as $id => $a) {
        if ($a['riesgo'] > $mayor) {
            $mayor = $a['riesgo'];
            $principalId = $id;
        }
    }

    $principal = $amenazas[$principalId];
    $resumen = riesgo_resumen($t, $principal, $lluviaAcum, $u);

    return [
        'territorio' => $t,
        'ventana' => $ventana,
        'actualizado' => time(),
        'confianza' => $u['confianza'] ?? 'media',
        'fuente_climatologia' => $u['fuente'] ?? 'Respaldo regional',
        'materialidad' => [
            'lluvia' => ['min_mm' => round($vMinLluvia, 1), 'signo_mm' => round($vSigLluvia, 1), 'factor' => round($M_lluvia, 2)],
            'intensidad_3h' => ['min_mm' => round($vMin3h, 1), 'signo_mm' => round($vSig3h, 1), 'factor' => round($M_3h, 2)],
            'calor' => ['min_c' => (float)$matCalor['min_abs'], 'signo_c' => (float)$matCalor['signo_abs'], 'factor' => round($M_calor, 2)],
            'sequia' => ['min_deficit' => (float)$matSequia['min_abs'], 'signo_deficit' => (float)$matSequia['signo_abs'], 'factor' => round($M_sequia, 2)],
        ],
        'escenario' => ['factor_lluvia' => $factorLluvia, 'delta_temp' => $deltaTemp, 'factor_caudal' => $factorCaudal],
        'amenazas' => $amenazas,
        'principal' => $principalId,
        'riesgo' => $principal['riesgo'],
        'nivel' => $principal['nivel'],
        'resumen' => $resumen,
        'datos' => [
            'lluvia_ventana' => round($lluviaAcum, 1),
            'lluvia_threshold_p95' => round($p95, 1),
            'prob_lluvia' => round($probVentana),
            'caudal_pico' => $picoCaudal !== null ? round($picoCaudal, 1) : null,
            'caudal_p95_pasado' => $p95Pasado !== null ? round((float)$p95Pasado, 1) : null,
            'caudal_ratio' => $ratioCaudal !== null ? round($ratioCaudal, 2) : null,
            'intensidad_3h' => round($max3h, 1),
            'stmax' => $stmaxMax !== null ? round($stmaxMax, 1) : null,
            'acumulado_90d' => $acum90,
            'dias_sin_lluvia' => $diasSecos,
        ],
        'fuentes' => [
            'pronostico' => $f['fuente'] ?? 'Sin datos',
            'caudal' => $fl['fuente'] ?? 'Sin datos',
            'historico' => $rec['fuente'] ?? 'Sin datos',
            'climatologia' => $u['fuente'] ?? 'Respaldo regional',
        ],
    ];
}

/**
 * Población expuesta del territorio para una amenaza concreta.
 *
 * IMPORTANTE (bug de honestidad corregido): antes esta función devolvía la
 * población TOTAL del distrito y la etiqueta decía "población expuesta".
 * Son dos números distintos:
 *   · el TOTAL puede ser oficial (Censo INEI) o una estimación;
 *   · la fracción EXPUESTA es siempre `total × susceptibilidad`, o sea una
 *     estimación, aunque el total sea oficial.
 */
function riesgo_poblacion_expuesta($t, $amenazaId) {
    $mapa = [
        'lluvia'     => 'poblacion_expuesta_inundacion',
        'inundacion' => 'poblacion_expuesta_inundacion',
        'huaico'     => 'poblacion_expuesta_huaico',
        'sequia'     => 'poblacion_expuesta_sequia',
        'calor'      => 'poblacion_expuesta_calor',
        'agro'       => 'poblacion_expuesta_agro',
    ];
    $clave = $mapa[$amenazaId] ?? 'poblacion_expuesta_inundacion';
    return (int)($t[$clave] ?? 0);
}

/** Nombre legible de la amenaza dominante, para los textos de resumen. */
function riesgo_nombre_amenaza($id) {
    $m = [
        'lluvia'     => 'lluvia extrema',
        'inundacion' => 'inundación fluvial',
        'huaico'     => 'huaico o deslizamiento',
        'sequia'     => 'sequía',
        'calor'      => 'calor extremo',
        'agro'       => 'estrés agropecuario',
    ];
    return $m[$id] ?? $id;
}

function riesgo_resumen($t, $principal, $lluviaAcum, $u) {
    $frases = [
        'lluvia' => sprintf(
            'Lluvia de %.1f mm en la ventana analizada frente a un umbral de referencia de %.1f mm (p95 histórico).',
            $lluviaAcum, (float)$u['p95_24']
        ),
        'inundacion' => 'El caudal pronosticado supera su propio registro reciente y el distrito está sobre una llanura de inundación recurrente.',
        'huaico' => 'La intensidad de lluvia en pocas horas activa quebradas con alta susceptibilidad de cuenca.',
        'sequia' => 'Déficit sostenido de precipitación frente a la mediana histórica de 90 días.',
        'calor' => 'Temperatura aparente por encima del percentil 95 local, con riesgo para la salud.',
        'agro' => 'Estrés hídrico o térmico sobre un cultivo sensible en el distrito.',
    ];

    $base = $frases[$principal['id']] ?? 'Condiciones de riesgo detectadas.';

    // La población expuesta es la de la amenaza dominante, y es siempre
    // una ESTIMACIÓN (total × susceptibilidad), nunca un dato oficial.
    $expuestos = riesgo_poblacion_expuesta($t, $principal['id']);

    return $base . ' ' . sprintf(
        'Población expuesta estimada a %s: %s habitantes (total del distrito: %s).',
        riesgo_nombre_amenaza($principal['id']),
        numero_legible($expuestos),
        numero_legible($t['poblacion'])
    );
}

/** Calcula el riesgo de todos los territorios y los ordena por severidad. */
function riesgo_nacional($ventana = 24) {
    $ventana = in_array((int)$ventana, [24, 48, 72], true) ? (int)$ventana : 24;

    $offline = willaq_env_bool('WILLAQ_MODO_OFFLINE');
    if ($offline) {
        $snap = riesgo_snapshot_leer();
        if ($snap && (int)$snap['ventana'] === $ventana && !empty($snap['riesgos'])) {
            return $snap['riesgos'];
        }
    }

    $resultados = [];
    foreach (territorios_todos() as $t) {
        $resultados[] = riesgo_calcular($t, $ventana);
    }

    usort($resultados, fn($a, $b) => $b['riesgo'] <=> $a['riesgo']);
    return $resultados;
}

function riesgo_top($n = 10, $ventana = 24) {
    return array_slice(riesgo_nacional($ventana), 0, $n);
}

/** Resumen agregado para la sala de situación. */
function riesgo_resumen_nacional($ventana = 24) {
    $lista = riesgo_nacional($ventana);
    $conteo = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
    $poblacionExpuesta = 0;
    $escuelas = 0;
    $salud = 0;

    foreach ($lista as $r) {
        $conteo[$r['nivel']['id']] = ($conteo[$r['nivel']['id']] ?? 0) + 1;
        if (in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
            $poblacionExpuesta += $r['territorio']['poblacion'];
            $escuelas += $r['territorio']['escuelas'];
            $salud += $r['territorio']['centros_salud'];
        }
    }

    $peor = $lista[0] ?? null;
    $orden = ['verde' => 0, 'amarillo' => 1, 'naranja' => 2, 'rojo' => 3];
    $nivelNacional = 'verde';
    foreach ($lista as $r) {
        if ($orden[$r['nivel']['id']] > $orden[$nivelNacional]) {
            $nivelNacional = $r['nivel']['id'];
        }
    }

    return [
        'ventana' => $ventana,
        'total' => count($lista),
        'conteo' => $conteo,
        'nivel_nacional' => $nivelNacional,
        // Claves renombradas (bug de honestidad corregido): estas cifras
        // suman el TOTAL de población / escuelas / centros de salud de los
        // distritos en riesgo alto, NO la fracción expuesta. La fracción
        // expuesta es por amenaza y no se puede sumar entre amenazas
        // distintas, así que no se agrega aquí.
        //   antes: poblacion_expuesta / escuelas_expuestas / centros_salud_expuestos
        //   ahora: poblacion_total_riesgo_alto / escuelas_totales_riesgo_alto /
        //          centros_salud_totales_riesgo_alto
        //
        // Los nombres viejos (poblacion_expuesta / escuelas_expuestas /
        // centros_salud_expuestos) se ELIMINARON a propósito: llamar
        // "expuesto" a un total es exactamente el error que este proyecto
        // quiere evitar. No se deja alias para que nadie los siga usando.
        'poblacion_total_riesgo_alto'       => $poblacionExpuesta,
        'escuelas_totales_riesgo_alto'      => $escuelas,
        'centros_salud_totales_riesgo_alto' => $salud,
        'peor' => $peor,
        'generado_en' => time(),
    ];
}

/**
 * Ruta del snapshot de una ventana concreta.
 *
 * Cada ventana (24/48/72 h) tiene su propio archivo. Antes había uno solo,
 * y `actualizar.php --ventana=all` lo sobrescribía tres veces: al final
 * quedaba el de 72 h y el modo offline no encontraba el de 24 h (la ventana
 * por defecto de la sala de situación).
 */
function riesgo_snapshot_ruta($ventana = 24) {
    $ventana = in_array((int)$ventana, [24, 48, 72], true) ? (int)$ventana : 24;
    return __DIR__ . '/../data/snapshots/riesgo_nacional_' . $ventana . 'h.json';
}

/** Ruta del snapshot heredado de archivo único (compatible con versiones previas). */
function riesgo_snapshot_ruta_legacy() {
    return __DIR__ . '/../data/snapshots/riesgo_nacional.json';
}

function riesgo_snapshot_guardar($ventana = 24) {
    $ventana = in_array((int)$ventana, [24, 48, 72], true) ? (int)$ventana : 24;
    $ruta = riesgo_snapshot_ruta($ventana);
    $dir = dirname($ruta);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $datos = [
        'ventana' => $ventana,
        'generado_en' => time(),
        'riesgos' => riesgo_nacional($ventana),
    ];

    $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    // Persistencia principal: PostgreSQL. En Railway el filesystem es efímero,
    // así que el snapshot en disco desaparece en cada redeploy.
    $enBd = riesgo_snapshot_guardar_bd($ventana, $json, count($datos['riesgos']));

    // Escritura atómica en disco (espejo y respaldo offline).
    // Así una lectura concurrente (web o cron) nunca ve un JSON a medias.
    $tmp = $ruta . '.tmp.' . getmypid();
    $enDisco = false;
    if (@file_put_contents($tmp, $json) !== false && @rename($tmp, $ruta)) {
        $enDisco = true;
    } else {
        @unlink($tmp);
    }

    return $enBd || $enDisco;
}

/** Guarda el snapshot en la tabla `snapshots`. @return bool */
function riesgo_snapshot_guardar_bd($ventana, $json, $total = 0) {
    $db = conectar_db();
    if (!$db) {
        return false;
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO snapshots (ventana, generado_en, payload, total, actualizado_en)
                  VALUES (?, NOW(), ?::jsonb, ?, NOW())
             ON CONFLICT (ventana) DO UPDATE
                    SET payload        = EXCLUDED.payload,
                        generado_en    = EXCLUDED.generado_en,
                        total          = EXCLUDED.total,
                        actualizado_en = NOW()'
        );
        $stmt->execute([(int)$ventana, $json, (int)$total]);
        return true;
    } catch (Throwable $e) {
        error_log('[WILLAQ snapshots] escritura en BD falló: ' . $e->getMessage());
        return false;
    }
}

/** Lee el snapshot desde la tabla `snapshots`. @return array|null */
function riesgo_snapshot_leer_bd($ventana) {
    $db = conectar_db();
    if (!$db) {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT payload FROM snapshots WHERE ventana = ? LIMIT 1');
        $stmt->execute([(int)$ventana]);
        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }
        $payload = $fila['payload'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        return is_array($payload) ? $payload : null;
    } catch (Throwable $e) {
        error_log('[WILLAQ snapshots] lectura en BD falló: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lee el snapshot de una ventana.
 * Si no existe el archivo específico, prueba el archivo heredado y lo acepta
 * solo si su ventana coincide.
 */
function riesgo_snapshot_leer($ventana = 24) {
    $ventana = in_array((int)$ventana, [24, 48, 72], true) ? (int)$ventana : 24;

    // 1. Base de datos (sobrevive a los redeploys).
    $desdeBd = riesgo_snapshot_leer_bd($ventana);
    if ($desdeBd !== null) {
        return $desdeBd;
    }

    // 2. Disco (respaldo offline).
    $ruta = riesgo_snapshot_ruta($ventana);
    if (is_file($ruta)) {
        $datos = json_decode(file_get_contents($ruta), true);
        if (is_array($datos)) {
            return $datos;
        }
    }

    // 3. Compatibilidad con el snapshot de archivo único de versiones anteriores.
    $legacy = riesgo_snapshot_ruta_legacy();
    if (is_file($legacy)) {
        $datos = json_decode(file_get_contents($legacy), true);
        if (is_array($datos) && (int)($datos['ventana'] ?? 0) === $ventana) {
            return $datos;
        }
    }

    return null;
}

/** Lista los snapshots disponibles (ventana => datos). */
function riesgo_snapshots_disponibles() {
    $disponibles = [];
    foreach ([24, 48, 72] as $ventana) {
        $snap = riesgo_snapshot_leer($ventana);
        if ($snap && !empty($snap['riesgos'])) {
            $disponibles[$ventana] = $snap;
        }
    }
    return $disponibles;
}
