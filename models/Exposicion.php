<?php
/**
 * EXPOSICIÓN OFICIAL — WILLAQ (capa de overlay)
 *
 * QUÉ ES ESTE ARCHIVO
 *   Los valores oficiales de población, viviendas, escuelas y centros de
 *   salud NO se escriben en config/territorios.json. Viven en un archivo
 *   aparte: data/exposicion_oficial.json, que genera
 *   `php scripts/importar_exposicion.php --aplicar`.
 *
 * POR QUÉ UN OVERLAY Y NO UNA REESCRITURA DEL CATÁLOGO
 *   1. Borrar el overlay devuelve el sistema a la estimación actual. Se puede
 *      dar atrás sin git y sin perder el catálogo base.
 *   2. Permite mezclar campos oficiales y estimados sin mentir: cada campo
 *      lleva su propia procedencia.
 *   3. El jurado puede ver el diff completo entre lo que había y lo que hay.
 *
 * QUÉ ES Y QUÉ NO ES "OFICIAL"
 *   · Es oficial el TOTAL: "en este distrito hay 9 824 personas (Censo 2017)".
 *   · NO es oficial la FRACCIÓN expuesta: "8 842 personas están en zona
 *     inundable" sigue siendo `total × susceptibilidad`, una estimación.
 *     Saber cuántos colegios hay no dice cuántos están en zona inundable:
 *     eso exige cruzar la coordenada de cada uno con una capa de peligro,
 *     que está fuera del alcance del piloto.
 *
 * IMPORTANTE
 *   Nada de esto convierte a WILLAQ en un emisor de alertas oficiales.
 *   Sigue siendo un sistema de recomendaciones de apoyo a la decisión.
 */

/**
 * Ruta del archivo de overlay.
 */
function exposicion_ruta() {
    return __DIR__ . '/../data/exposicion_oficial.json';
}

/**
 * LISTA BLANCA de campos importables.
 *
 * Cualquier otro campo se rechaza. En particular NO son importables
 * `vulnerabilidad`, `susc_*`, `hectareas_cultivo`, `albergues_capacidad` ni
 * `km_vias`: no provienen de INEI / MINEDU / MINSA, y sobrescribirlos con
 * datos de otra naturaleza sería un error metodológico, no una mejora.
 */
function exposicion_campos_permitidos() {
    return ['poblacion', 'viviendas', 'escuelas', 'centros_salud'];
}

/**
 * Etiqueta legible de un campo de exposición.
 */
function exposicion_campo_etiqueta($campo) {
    $m = [
        'poblacion'     => 'Población',
        'viviendas'     => 'Viviendas',
        'escuelas'      => 'Escuelas',
        'centros_salud' => 'Centros de salud',
    ];
    return $m[$campo] ?? $campo;
}

/**
 * Rango admisible por campo: [mínimo, máximo].
 * Los topes son de sentido común para un distrito peruano y sirven para
 * detectar erratas de tecleo (un cero de más, un año en el valor).
 */
function exposicion_campo_rango($campo) {
    $m = [
        'poblacion'     => [1, 5000000],
        'viviendas'     => [0, 5000000],
        'escuelas'      => [0, 200000],
        'centros_salud' => [0, 50000],
    ];
    return $m[$campo] ?? [0, PHP_INT_MAX];
}

/**
 * Carga el overlay. Devuelve ['_meta' => [...], 'territorios' => [...]].
 * Nunca lanza una excepción: si el archivo está roto, se registra el error y
 * se sigue con el catálogo (la aplicación no se cae por un JSON malo).
 */
function exposicion_cargar() {
    static $cache = null;
    static $yaCargado = false;

    if ($yaCargado) {
        return $cache;
    }
    $yaCargado = true;

    $vacio = ['_meta' => [], 'territorios' => []];
    $ruta = exposicion_ruta();

    if (!is_file($ruta)) {
        $cache = $vacio;
        return $cache;
    }

    $crudo = @file_get_contents($ruta);
    $datos = json_decode((string)$crudo, true);
    if (!is_array($datos)) {
        error_log('[WILLAQ exposición] data/exposicion_oficial.json ilegible: ' . json_last_error_msg());
        $cache = $vacio;
        return $cache;
    }

    $meta = is_array($datos['_meta'] ?? null) ? $datos['_meta'] : [];
    $permitidos = exposicion_campos_permitidos();
    $territorios = [];

    foreach ($datos as $clave => $bloque) {
        if ($clave === '_meta' || !is_array($bloque)) {
            continue;
        }
        if (!preg_match('/^[0-9]{6}$/', (string)$clave)) {
            continue;
        }
        $campos = [];
        foreach ($permitidos as $campo) {
            if (!isset($bloque[$campo]) || !is_array($bloque[$campo])) {
                continue;
            }
            $entrada = $bloque[$campo];
            if (!isset($entrada['valor']) || !is_numeric($entrada['valor'])) {
                continue;
            }
            $campos[$campo] = [
                'valor'        => (int)$entrada['valor'],
                'fuente'       => (string)($entrada['fuente'] ?? ''),
                'anio'         => isset($entrada['anio']) ? (int)$entrada['anio'] : null,
                'archivo'      => (string)($entrada['archivo'] ?? ''),
                'sha256'       => (string)($entrada['sha256'] ?? ''),
                'importado_en' => (string)($entrada['importado_en'] ?? ''),
                'nota'         => (string)($entrada['nota'] ?? ''),
            ];
        }
        if ($campos) {
            $territorios[(string)$clave] = [
                'distrito'  => (string)($bloque['distrito'] ?? ''),
                'provincia' => (string)($bloque['provincia'] ?? ''),
                'campos'    => $campos,
            ];
        }
    }

    $cache = ['_meta' => $meta, 'territorios' => $territorios];
    return $cache;
}

/**
 * ¿Hay overlay cargado?
 */
function exposicion_hay_datos() {
    $c = exposicion_cargar();
    return !empty($c['territorios']);
}

/**
 * Procedencia de los campos oficiales de un territorio, indexados por su
 * ubigeo. Devuelve [] si el territorio no está en el overlay.
 *
 * @return array ['poblacion' => ['valor'=>..,'fuente'=>..,'anio'=>..], ...]
 */
function exposicion_campos_ubigeo($ubigeo) {
    if (!$ubigeo) {
        return [];
    }
    $c = exposicion_cargar();
    return $c['territorios'][(string)$ubigeo]['campos'] ?? [];
}

/**
 * Metadatos del overlay (fecha de generación, fuentes, totales).
 */
function exposicion_meta() {
    $c = exposicion_cargar();
    return $c['_meta'];
}

/**
 * Resumen para el panel de administración:
 * cuántos territorios tienen datos oficiales, cuántos campos, qué fuentes.
 */
function exposicion_resumen() {
    $c = exposicion_cargar();
    $permitidos = exposicion_campos_permitidos();

    $conOficial = 0;
    $porCampo = array_fill_keys($permitidos, 0);
    $fuentes = [];

    foreach ($c['territorios'] as $ubigeo => $bloque) {
        $tiene = false;
        foreach ($bloque['campos'] as $campo => $entrada) {
            $tiene = true;
            if (isset($porCampo[$campo])) {
                $porCampo[$campo]++;
            }
            $f = $entrada['fuente'] !== '' ? $entrada['fuente'] : '(sin fuente)';
            if (!isset($fuentes[$f])) {
                $fuentes[$f] = ['fuente' => $f, 'campos' => 0, 'archivos' => []];
            }
            $fuentes[$f]['campos']++;
            if ($entrada['archivo'] !== '') {
                $fuentes[$f]['archivos'][$entrada['archivo']] = true;
            }
        }
        if ($tiene) {
            $conOficial++;
        }
    }

    foreach ($fuentes as &$f) {
        $f['archivos'] = array_keys($f['archivos']);
    }
    unset($f);

    return [
        'total_territorios' => count($c['territorios']),
        'con_datos_oficiales' => $conOficial,
        'por_campo' => $porCampo,
        'fuentes' => array_values($fuentes),
        'generado_en' => $c['_meta']['generado_en'] ?? '',
        'version' => $c['_meta']['version'] ?? 0,
        // Archivos importados con su hash y su conteo de filas: es lo que
        // permite reconstruir de dónde salió cada campo.
        'archivos' => is_array($c['_meta']['archivos'] ?? null) ? $c['_meta']['archivos'] : [],
        'advertencia' => $c['_meta']['advertencia'] ?? '',
    ];
}
