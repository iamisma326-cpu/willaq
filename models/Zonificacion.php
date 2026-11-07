<?php
/**
 * ZONIFICACIÓN TERRITORIAL — WILLAQ (Pilar 2)
 *
 * Responde la pregunta que ningún servicio actual responde:
 *   "¿Se puede construir aquí?"
 *
 * Índice de Seguridad de Emplazamiento (ISE), 0-100:
 *
 *   amenaza = 0.35·susc_inundación + 0.30·susc_huaico + 0.20·susc_sequía + 0.15·susc_calor
 *   ISE     = 100 · (1 - amenaza) · (0.85 + 0.15·(1 - vulnerabilidad))
 *
 * Clases:
 *   A  76-100  Apto
 *   B  51-75   Apto con condiciones
 *   C  26-50   Restringido
 *   D  0-25    No apto para equipamientos críticos
 *
 * La sensibilidad del equipamiento ajusta la exigencia: un hospital exige más
 * que una vivienda unifamiliar.
 */

require_once __DIR__ . '/Territorio.php';

function zonificacion_sensibilidad_equipamiento($tipo) {
    $tipos = [
        'colegio' => 1.10,
        'hospital' => 1.25,
        'vivienda' => 0.80,
        'planta_agua' => 1.15,
        'albergue' => 0.95,
        'carretera' => 0.90,
    ];
    return $tipos[$tipo] ?? 1.0;
}

function zonificacion_etiqueta_equipamiento($tipo) {
    $etiquetas = [
        'colegio' => 'Colegio / institución educativa',
        'hospital' => 'Hospital / centro de salud',
        'vivienda' => 'Vivienda / conjunto residencial',
        'planta_agua' => 'Planta de tratamiento de agua',
        'albergue' => 'Albergue / centro de evacuación',
        'carretera' => 'Carretera / puente',
    ];
    return $etiquetas[$tipo] ?? 'Equipamiento';
}

function zonificacion_clase($ise) {
    if ($ise >= 76) {
        return ['id' => 'A', 'nombre' => 'Apto', 'color' => '#22c55e'];
    }
    if ($ise >= 51) {
        return ['id' => 'B', 'nombre' => 'Apto con condiciones', 'color' => '#eab308'];
    }
    if ($ise >= 26) {
        return ['id' => 'C', 'nombre' => 'Restringido', 'color' => '#f97316'];
    }
    return ['id' => 'D', 'nombre' => 'No apto', 'color' => '#ef4444'];
}

/** ISE base de un distrito (sin ajuste por tipo de equipamiento). */
function zonificacion_ise_territorio($t) {
    $amenaza = 0.35 * (float)$t['susc_inundacion']
        + 0.30 * (float)$t['susc_huaico']
        + 0.20 * (float)$t['susc_sequia']
        + 0.15 * 0.50; // el calor se trata como amenaza media y uniforme a escala distrital

    $ise = 100.0 * (1.0 - $amenaza) * (0.85 + 0.15 * (1.0 - (float)$t['vulnerabilidad']));
    return round(max(0, min(100, $ise)), 1);
}

function zonificacion_ranking() {
    $lista = [];
    foreach (territorios_todos() as $t) {
        $ise = zonificacion_ise_territorio($t);
        $lista[] = [
            'territorio' => $t,
            'ise' => $ise,
            'clase' => zonificacion_clase($ise),
        ];
    }
    usort($lista, fn($a, $b) => $b['ise'] <=> $a['ise']);
    return $lista;
}

/**
 * EVALUADOR DE SITIOS.
 * Dado un punto y un tipo de equipamiento, devuelve veredicto y razones.
 */
function zonificacion_evaluar($lat, $lon, $tipo = 'colegio') {
    $t = territorio_mas_cercano($lat, $lon);
    if (!$t) {
        return null;
    }

    $sensibilidad = zonificacion_sensibilidad_equipamiento($tipo);
    $iseBase = zonificacion_ise_territorio($t);

    // Penalización por tipo: un hospital en zona restringida cae más que una vivienda.
    $penalizacion = max(0.0, (100.0 - $iseBase) * ($sensibilidad - 1.0) * 0.55);
    $ise = round(max(0, min(100, $iseBase - $penalizacion)), 1);
    $clase = zonificacion_clase($ise);

    $razones = [];
    $componentes = [
        ['factor' => 'Inundación', 'valor' => (float)$t['susc_inundacion'], 'peso' => 0.35,
         'detalle' => 'Llanura de inundación recurrente; eventos documentados en 2017 y 2023.'],
        ['factor' => 'Huaico / deslizamiento', 'valor' => (float)$t['susc_huaico'], 'peso' => 0.30,
         'detalle' => 'Quebrada con pendiente ' . $t['pendiente'] . '° y cuenca de aporte de ' . numero_legible($t['cuenca_km2']) . ' km².'],
        ['factor' => 'Sequía', 'valor' => (float)$t['susc_sequia'], 'peso' => 0.20,
         'detalle' => 'Déficit hídrico estructural en la zona.'],
        ['factor' => 'Calor extremo', 'valor' => 0.50, 'peso' => 0.15,
         'detalle' => 'Exposición térmica media según la zona climática.'],
    ];

    foreach ($componentes as $c) {
        if ($c['valor'] >= 0.6) {
            $razones[] = [
                'factor' => $c['factor'],
                'severidad' => $c['valor'] >= 0.85 ? 'crítica' : 'alta',
                'peso' => $c['peso'],
                'detalle' => $c['detalle'],
            ];
        }
    }

    if ((float)$t['vulnerabilidad'] >= 0.75) {
        $razones[] = [
            'factor' => 'Vulnerabilidad social',
            'severidad' => 'alta',
            'peso' => 0.20,
            'detalle' => 'Alta vulnerabilidad estructural: capacidad de respuesta local limitada.',
        ];
    }

    $recomendaciones = [];
    if ((float)$t['susc_inundacion'] >= 0.7) {
        $recomendaciones[] = 'Elevar la cota de cimentación por encima de la línea de inundación histórica.';
        $recomendaciones[] = 'Diseñar drenaje pluvial de capacidad superior al evento de 100 años.';
    }
    if ((float)$t['susc_huaico'] >= 0.7) {
        $recomendaciones[] = 'Mantener una franja de exclusión mínima de 50 m respecto al cauce de la quebrada.';
        $recomendaciones[] = 'Construir obras de disipación y reforestar la cuenca de aporte.';
    }
    if ((float)$t['susc_sequia'] >= 0.75) {
        $recomendaciones[] = 'Asegurar reserva de agua para 90 días y captación de lluvia.';
    }
    if ($clase['id'] === 'D') {
        $recomendaciones[] = 'Recomendación principal: NO emplazar el equipamiento en este punto. Evaluar un sitio alternativo.';
    } elseif ($clase['id'] === 'C') {
        $recomendaciones[] = 'Requiere estudio de riesgo específico y obras de mitigación antes de la licencia.';
    }

    $veredicto = match ($clase['id']) {
        'A' => 'APTO',
        'B' => 'APTO CON RESTRICCIONES',
        'C' => 'RESTRINGIDO — requiere estudio específico',
        default => 'NO APTO',
    };

    return [
        'lat' => (float)$lat,
        'lon' => (float)$lon,
        'tipo' => $tipo,
        'tipo_etiqueta' => zonificacion_etiqueta_equipamiento($tipo),
        'territorio' => $t,
        'ise' => $ise,
        'ise_base' => $iseBase,
        'clase' => $clase,
        'veredicto' => $veredicto,
        'razones' => $razones,
        'recomendaciones' => array_values(array_unique($recomendaciones)),
        'distancia_km' => $t['distancia_km'] ?? null,
        'generado_en' => time(),
    ];
}

/** Alternativa: evalúa un sitio hipotético con el clima futuro (+X % lluvia). */
function zonificacion_evaluar_futuro($lat, $lon, $tipo, $factorLluvia = 1.2) {
    $base = zonificacion_evaluar($lat, $lon, $tipo);
    if (!$base) {
        return null;
    }
    $t = $base['territorio'];

    // La lluvia futura incrementa la presión sobre inundación y huaico.
    $presionExtra = min(0.35, ($factorLluvia - 1.0) * 0.45);
    $iseFuturo = round(max(0, $base['ise'] - 100 * $presionExtra * 0.5), 1);

    return [
        'base' => $base,
        'futuro' => [
            'ise' => $iseFuturo,
            'clase' => zonificacion_clase($iseFuturo),
            'factor_lluvia' => $factorLluvia,
        ],
        'delta' => round($iseFuturo - $base['ise'], 1),
    ];
}
