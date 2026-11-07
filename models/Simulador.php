<?php
/**
 * SIMULADOR DE ESCENARIOS — WILLAQ (Pilar 3)
 *
 * Tres modos:
 *   A. Climático   : ¿qué pasa si llueve el doble o sube la temperatura?
 *   B. Evacuación  : ¿cuánta gente hay que evacuar y cuánta capacidad falta?
 *   C. Reubicación : ¿el sitio sigue siendo seguro en el clima futuro?
 *
 * Todos los cálculos son deterministas y auditables: usan el mismo motor
 * de riesgo con parámetros modificados. No hay caja negra.
 */

require_once __DIR__ . '/Riesgo.php';
require_once __DIR__ . '/Zonificacion.php';

/** Población que queda en riesgo alto o muy alto para un resultado de riesgo. */
function simulacion_poblacion_en_riesgo($r) {
    if (!in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
        return 0;
    }
    $principal = $r['amenazas'][$r['principal']] ?? null;
    if (!$principal) {
        return 0;
    }
    return (int)round($r['territorio']['poblacion'] * $principal['exposicion']);
}

function simulacion_escuelas_en_riesgo($r) {
    if (!in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
        return 0;
    }
    return (int)round($r['territorio']['escuelas'] * ($r['amenazas'][$r['principal']]['exposicion'] ?? 0));
}

function simulacion_salud_en_riesgo($r) {
    if (!in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
        return 0;
    }
    return (int)round($r['territorio']['centros_salud'] * ($r['amenazas'][$r['principal']]['exposicion'] ?? 0));
}

/**
 * MODO A — Simulación climática.
 */
function simular_clima($t, $factorLluvia = 1.5, $deltaTemp = 0.0, $ventana = 24) {
    $factorLluvia = max(0.5, min(3.0, (float)$factorLluvia));
    $deltaTemp = max(-2.0, min(6.0, (float)$deltaTemp));

    $base = riesgo_calcular($t, $ventana);
    $simulado = riesgo_calcular($t, $ventana, [
        'factor_lluvia' => $factorLluvia,
        'delta_temp' => $deltaTemp,
        'factor_caudal' => $factorLluvia,
    ]);

    $pobBase = simulacion_poblacion_en_riesgo($base);
    $pobSim = simulacion_poblacion_en_riesgo($simulado);

    return [
        'modo' => 'clima',
        'territorio' => $t,
        'ventana' => $ventana,
        'parametros' => ['factor_lluvia' => $factorLluvia, 'delta_temp' => $deltaTemp],
        'base' => $base,
        'simulado' => $simulado,
        'deltas' => [
            'riesgo' => round($simulado['riesgo'] - $base['riesgo'], 1),
            'poblacion' => $pobSim - $pobBase,
            'escuelas' => simulacion_escuelas_en_riesgo($simulado) - simulacion_escuelas_en_riesgo($base),
            'salud' => simulacion_salud_en_riesgo($simulado) - simulacion_salud_en_riesgo($base),
            'lluvia_mm' => round(($simulado['datos']['lluvia_ventana'] ?? 0) - ($base['datos']['lluvia_ventana'] ?? 0), 1),
        ],
        'totales' => [
            'poblacion_base' => $pobBase,
            'poblacion_simulada' => $pobSim,
            'escuelas_simuladas' => simulacion_escuelas_en_riesgo($simulado),
            'salud_simuladas' => simulacion_salud_en_riesgo($simulado),
        ],
    ];
}

/**
 * MODO B — Simulación de evacuación.
 * Modelo simple y transparente:
 *   - población a evacuar = población expuesta al escenario
 *   - capacidad     = albergues declarados
 *   - déficit       = max(0, a evacuar - capacidad)
 *   - tiempo        = población / (capacidad de salida por hora)
 */
function simular_evacuacion($t, $factorLluvia = 1.5, $ventana = 24) {
    $factorLluvia = max(0.5, min(3.0, (float)$factorLluvia));
    $sim = simular_clima($t, $factorLluvia, 0.0, $ventana);

    $aEvacuar = $sim['totales']['poblacion_simulada'];
    if ($aEvacuar === 0) {
        // Aun sin riesgo alto, se reporta la población en zonas susceptibles.
        $aEvacuar = (int)round($t['poblacion'] * max(
            $t['susc_inundacion'],
            $t['susc_huaico']
        ) * 0.35);
    }

    $capacidad = (int)$t['albergues_capacidad'];
    $deficit = max(0, $aEvacuar - $capacidad);

    // Capacidad de salida: 1 200 personas por hora por vía principal (supuesto documentado).
    $personasPorHora = 1200;
    $horas = $aEvacuar > 0 ? round($aEvacuar / $personasPorHora, 1) : 0.0;

    return [
        'modo' => 'evacuacion',
        'territorio' => $t,
        'parametros' => ['factor_lluvia' => $factorLluvia, 'ventana' => $ventana],
        'escenario' => $sim['simulado'],
        'resultado' => [
            'poblacion_evacuar' => $aEvacuar,
            'capacidad_albergues' => $capacidad,
            'deficit' => $deficit,
            'cobertura_pct' => $capacidad > 0 ? round(min(100, 100 * $capacidad / max(1, $aEvacuar)), 1) : 0.0,
            'horas_estimadas' => $horas,
            'personas_por_hora' => $personasPorHora,
        ],
    ];
}

/**
 * MODO C — Reubicación / planificación con clima futuro.
 */
function simular_reubicacion($lat, $lon, $tipo = 'colegio', $factorLluvia = 1.2) {
    return zonificacion_evaluar_futuro($lat, $lon, $tipo, max(1.0, min(2.0, (float)$factorLluvia)));
}
