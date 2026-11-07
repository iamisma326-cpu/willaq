<?php
/**
 * ESTACIONES HIDROMETEOROLÓGICAS — WILLAQ (fase 1)
 * ===================================================
 *
 * QUÉ ES ESTA CAPA
 *   La fase 1 de estaciones tiene tres piezas:
 *
 *   1. `config/estaciones.json` — el REGISTRO: qué estaciones existen, de
 *      dónde salió la ficha, con qué estado de verificación y a qué distritos
 *      se asignan. Lo genera `scripts/generar_registro_estaciones.py` a
 *      partir del catálogo del SENAMHI descargado con Garúa.
 *
 *   2. `data/estaciones/{id}.json` — la SERIE normalizada + el reporte QA de
 *      cada estación. Los produce `scripts/importar_estaciones.php` a partir
 *      de los CSV crudos que deja Garúa en `data/estaciones/_garua/csv/`.
 *
 *   3. Este archivo — la CAPA DE LECTURA: carga el registro y las series,
 *      calcula cobertura y decide si una estación puede usarse.
 *
 * QUÉ NO HACE ESTA CAPA (y es lo importante)
 *   · NO cambia el motor de riesgo. La fase 2 (climatología y fusión) está
 *     BLOQUEADA por compuerta: se exige ≥10 años de serie, <10% de días
 *     faltantes y medición de falsos positivos. `estacion_puede_usarse()`
 *     calcula esa compuerta y hoy casi ninguna estación la pasa.
 *   · NO inventa datos. Una estación sin serie cargada se reporta como tal.
 *   · NO es una fuente de alerta. Sigue siendo un sistema de recomendaciones
 *     de apoyo a la decisión.
 *
 * EL CONCEPTO CLAVE: TOTAL OFICIAL vs. FRACCIÓN EXPUESTA
 *   Igual que en la exposición, aquí se separa lo que el dato dice de lo que
 *   el sistema deduce:
 *   · La PRECIPITACIÓN de una estación es una medición (con sus QA y sus
 *     huecos). Es oficial.
 *   · La PRECIPITACIÓN DE UN DISTRITO no es una medición: es lo que la
 *     estación representa para ese distrito, y esa transferencia es una
 *     estimación con supuestos explícitos (distancia, altitud, exposición).
 */

/**
 * Ruta del registro de estaciones.
 */
function estacion_ruta_registro() {
    return __DIR__ . '/../config/estaciones.json';
}
/**
 * Ruta del directorio donde viven las series normalizadas.
 */
function estacion_ruta_datos() {
    return __DIR__ . '/../data/estaciones';
}

/**
 * COMPUERTA DE LA FASE 2 — los umbrales que el plan fijó ANTES de mirar los
 * resultados, para no maquillar el backtest.
 *
 * · 10 años: una serie más corta no cubre los eventos históricos (2017-18 y
 *   2023) con margen. Se cuentan años CALENDARIO tocados (decisión registrada
 *   en el plan); el QA guarda además `anos_span` y `anos_completos` para que
 *   la UI no sobrevenda la cobertura.
 * · 10% de días faltantes: con más huecos, el percentil p95 que decide una
 *   detección deja de ser confiable.
 */
function estacion_compuerta() {
    return [
        'anios_minimos'        => 10,
        'faltantes_max_pct'    => 10.0,
        // La fase 2 además exige medir falsos positivos sobre los 13 casos ya
        // detectados antes de adoptar cualquier estación. Eso no se puede
        // comprobar leyendo series: lo hace el backtest.
        'requiere_backtest'    => true,
        'fuentes_peligro'      => ['ERA5', 'estacion', 'fusion'],
    ];
}

/**
 * Carga el registro de estaciones. Nunca lanza: si el archivo falta o está
 * roto, se registra el error y se sigue (la aplicación no se cae).
 */
function estacion_cargar_registro() {
    static $cache = null;
    static $yaCargado = false;

    if ($yaCargado) {
        return $cache;
    }
    $yaCargado = true;

    $vacio = ['_meta' => [], 'estaciones' => []];
    $ruta = estacion_ruta_registro();

    if (!is_file($ruta)) {
        $cache = $vacio;
        return $cache;
    }

    $crudo = @file_get_contents($ruta);
    $datos = json_decode((string)$crudo, true);
    if (!is_array($datos)) {
        error_log('[WILLAQ estaciones] config/estaciones.json ilegible: ' . json_last_error_msg());
        $cache = $vacio;
        return $cache;
    }

    $meta = is_array($datos['_meta'] ?? null) ? $datos['_meta'] : [];
    $estaciones = [];

    foreach (($datos['estaciones'] ?? []) as $e) {
        if (!is_array($e) || empty($e['id'])) {
            continue;
        }
        $estaciones[$e['id']] = $e;
    }

    $cache = ['_meta' => $meta, 'estaciones' => $estaciones];
    return $cache;
}

/**
 * Metadatos del registro.
 */
function estacion_meta() {
    $c = estacion_cargar_registro();
    return $c['_meta'];
}

/**
 * Todas las estaciones del registro, indexadas por id.
 */
function estacion_registro_todas() {
    $c = estacion_cargar_registro();
    return $c['estaciones'];
}

/**
 * Una estación del registro por id.
 */
function estacion_registro($id) {
    $c = estacion_cargar_registro();
    return $c['estaciones'][$id] ?? null;
}

/**
 * Una estación por código del SENAMHI.
 */
function estacion_registro_por_cod($cod) {
    $cod = (string)$cod;
    foreach (estacion_registro_todas() as $e) {
        if ((string)($e['cod'] ?? '') === $cod) {
            return $e;
        }
    }
    return null;
}

/**
 * Carga la serie normalizada de una estación (data/estaciones/{id}.json).
 * Devuelve null si no hay serie cargada.
 */
function estacion_cargar_serie($id) {
    static $cache = [];

    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    $ruta = estacion_ruta_datos() . '/' . $id . '.json';
    if (!is_file($ruta)) {
        $cache[$id] = null;
        return null;
    }

    $crudo = @file_get_contents($ruta);
    $datos = json_decode((string)$crudo, true);
    if (!is_array($datos)) {
        error_log("[WILLAQ estaciones] serie ilegible: {$id}.json");
        $cache[$id] = null;
        return null;
    }

    $cache[$id] = $datos;
    return $datos;
}

/**
 * ¿La estación tiene serie cargada en disco?
 */
function estacion_tiene_serie($id) {
    return estacion_cargar_serie($id) !== null;
}

/**
 * La serie diaria de una estación: array de ['fecha' => 'YYYY-MM-DD', ...].
 */
function estacion_datos_diarios($id) {
    $serie = estacion_cargar_serie($id);
    if (!$serie || !is_array($serie['datos'] ?? null)) {
        return [];
    }
    return $serie['datos'];
}

/**
 * Evalúa la compuerta de la fase 2 para una estación.
 *
 * Devuelve:
 *   [
 *     'cumple_medible' => bool,  // ¿pasa los criterios que se miden aquí?
 *     'habilitada'     => bool,  // siempre false: falta el backtest
 *     'anios'          => float,
 *     'faltantes_pct'  => float,
 *     'motivos'        => array, // por qué no cumple los criterios medibles
 *     'criterios'      => array,
 *   ]
 *
 * OJO: `cumple_medible` no significa que se pueda activar. La fase 2 exige
 * además medir falsos positivos en el backtest, y eso no se puede declarar
 * desde una lectura de archivos.
 */
function estacion_puede_usarse($id) {
    $compuerta = estacion_compuerta();
    $serie = estacion_cargar_serie($id);

    if (!$serie) {
        return [
            'cumple_medible' => false,
            'habilitada'     => false,
            'anios'          => 0.0,
            'anios_span'     => 0.0,
            'anios_completos'=> 0,
            'faltantes_pct'  => 100.0,
            'motivos'        => ['No hay serie cargada para esta estación.'],
            'motivo_bloqueo' => 'Sin serie no hay nada que evaluar.',
            'criterios'      => [
                'serie_cargada' => false,
                'anios_minimos' => $compuerta['anios_minimos'],
                'faltantes_max_pct' => $compuerta['faltantes_max_pct'],
            ],
        ];
    }

    $qa = is_array($serie['qa'] ?? null) ? $serie['qa'] : [];
    $anios = (float)($qa['anos_cobertura'] ?? 0.0);
    $aniosSpan = (float)($qa['anos_span'] ?? 0.0);
    $aniosCompletos = (int)($qa['anos_completos'] ?? 0);
    $faltantes = (float)($qa['pct_dias_faltantes'] ?? 100.0);
    $motivos = [];
    if ($anios < $compuerta['anios_minimos']) {
        $motivos[] = sprintf(
            'Serie de %.1f años; se exigen %d años.',
            $anios,
            $compuerta['anios_minimos']
        );
    }
    if ($faltantes > $compuerta['faltantes_max_pct']) {
        $motivos[] = sprintf(
            '%.1f%% de días faltantes; se admiten hasta %.0f%%.',
            $faltantes,
            $compuerta['faltantes_max_pct']
        );
    }

    return [
        'cumple_medible' => empty($motivos),
        'habilitada'     => false,
        'anios'          => $anios,
        'anios_span'     => $aniosSpan,
        'anios_completos'=> $aniosCompletos,
        'faltantes_pct'  => $faltantes,
        'motivos'        => $motivos,
        'motivo_bloqueo' => 'La fase 2 exige medir falsos positivos en el backtest sobre los casos ya detectados. Es una medición que no se puede declarar desde una serie.',
        'criterios'      => [
            'serie_cargada'       => true,
            'anios_cobertura'     => $anios,
            'anios_span'          => $aniosSpan,
            'anios_completos'     => $aniosCompletos,
            'anios_minimos'       => $compuerta['anios_minimos'],
            'pct_dias_faltantes'  => $faltantes,
            'faltantes_max_pct'   => $compuerta['faltantes_max_pct'],
            'requiere_backtest'   => $compuerta['requiere_backtest'],
        ],
    ];
}

/**
 * Resumen para el panel de administración: cuántas estaciones hay, cuántas
 * tienen serie, cuántas pasan la compuerta, cobertura por distrito.
 */
function estacion_resumen() {
    $registro = estacion_registro_todas();
    $compuerta = estacion_compuerta();

    $conSerie = 0;
    $pasanCompuerta = 0;
    $porPrioridad = [];
    $porTipo = [];
    $porFuente = [];
    $distritosCubiertos = [];

    foreach ($registro as $id => $e) {
        $prio = $e['prioridad'] ?? 'baja';
        $porPrioridad[$prio] = ($porPrioridad[$prio] ?? 0) + 1;

        $tipo = $e['tipo'] ?? '?';
        $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;

        $fuente = $e['fuente_catalogo'] ?? '(sin fuente)';
        if (!isset($porFuente[$fuente])) {
            $porFuente[$fuente] = ['fuente' => $fuente, 'estaciones' => 0];
        }
        $porFuente[$fuente]['estaciones']++;

        if (estacion_tiene_serie($id)) {
            $conSerie++;
            if (estacion_puede_usarse($id)['cumple_medible']) {
                $pasanCompuerta++;
            }
        }

        foreach (($e['asignaciones'] ?? []) as $a) {
            if (!empty($a['territorio'])) {
                $distritosCubiertos[$a['territorio']] = true;
            }
        }
    }

    return [
        'total_estaciones'      => count($registro),
        'con_serie'             => $conSerie,
        'sin_serie'             => count($registro) - $conSerie,
        'pasan_compuerta'       => $pasanCompuerta,
        'cumplen_criterios_medibles' => $pasanCompuerta,
        'habilitadas'           => 0,
        'por_prioridad'         => $porPrioridad,
        'por_tipo'              => $porTipo,
        'fuentes'               => array_values($porFuente),
        'distritos_cubiertos'   => count($distritosCubiertos),
        'compuerta'             => $compuerta,
        'fase_2_bloqueada'      => true,
        'motivo_bloqueo'        => 'La fase 2 exige además medir falsos positivos en el backtest sobre los 13 casos ya detectados. Es una medición, no un dato: no se puede declarar superada sin ejecutarla.',
        'motor_usa_estaciones'  => false,
        'generado_en'           => estacion_meta()['generado_en'] ?? '',
        'version'               => estacion_meta()['version'] ?? 0,
        'catalogo_sha256'       => estacion_meta()['catalogo_sha256'] ?? '',
        'advertencia'           => estacion_meta()['advertencia']
            ?? estacion_meta()['estado_fase']
            ?? '',
    ];
}

/**
 * Detalle de una estación para el panel: registro + estado de serie + compuerta.
 */
function estacion_detalle($id) {
    $reg = estacion_registro($id);
    if (!$reg) {
        return null;
    }

    $serie = estacion_cargar_serie($id);
    $compuerta = estacion_puede_usarse($id);

    return [
        'registro'   => $reg,
        'serie'      => $serie,
        'tiene_serie'=> $serie !== null,
        'compuerta'  => $compuerta,
        'cobertura'  => [
            'primera_fecha' => $serie['rango']['primera'] ?? null,
            'ultima_fecha'  => $serie['rango']['ultima'] ?? null,
            'dias'          => $serie['qa']['dias_con_dato'] ?? 0,
            'dias_faltantes'=> $serie['qa']['dias_faltantes'] ?? null,
            'pct_faltantes' => $serie['qa']['pct_dias_faltantes'] ?? null,
            'anios_span'    => $serie['qa']['anos_span'] ?? null,
            'anios_completos' => $serie['qa']['anos_completos'] ?? null,
        ],
    ];
}

/**
 * Listado compacto para la UI: una fila por estación con su estado.
 */
function estacion_tablero() {
    $filas = [];
    foreach (estacion_registro_todas() as $id => $e) {
        $serie = estacion_cargar_serie($id);
        $comp = estacion_puede_usarse($id);

        $filas[] = [
            'id'              => $id,
            'cod'             => $e['cod'] ?? '',
            'nombre'          => $e['nombre'] ?? '',
            'tipo'            => $e['tipo'] ?? '',
            'categoria'       => $e['categoria'] ?? '',
            'departamento'    => $e['departamento'] ?? '',
            'provincia'       => $e['provincia'] ?? '',
            'altitud_msnm'    => $e['altitud_msnm'] ?? null,
            'datos_desde'     => $e['datos_desde'] ?? '',
            'prioridad'       => $e['prioridad'] ?? 'baja',
            'serie_cargada'   => $serie !== null,
            'primera_fecha'   => $serie['rango']['primera'] ?? null,
            'ultima_fecha'    => $serie['rango']['ultima'] ?? null,
            'dias_con_dato'   => $serie['qa']['dias_con_dato'] ?? null,
            'pct_faltantes'   => $serie['qa']['pct_dias_faltantes'] ?? null,
            'anios_cobertura' => $comp['anios'],
            'anios_span'      => $comp['anios_span'],
            'anios_completos' => $comp['anios_completos'],
            'pasa_compuerta'  => $comp['cumple_medible'],
            'habilitada'      => $comp['habilitada'],
            'motivos'         => $comp['motivos'],
            'asignaciones'    => $e['asignaciones'] ?? [],
        ];
    }
    return $filas;
}
