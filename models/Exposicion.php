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

/**
 * Etiquetas en prosa de los campos importables (minúsculas, para texto
 * corrido). No es lo mismo que `exposicion_campo_etiqueta()`, que sirve para
 * cabeceras de tabla: aquí el campo `escuelas` se lee «colegios».
 */
function exposicion_campo_prosa($campo) {
    $m = [
        'poblacion'     => 'población',
        'viviendas'     => 'viviendas',
        'escuelas'      => 'colegios',
        'centros_salud' => 'centros de salud',
    ];
    return $m[$campo] ?? $campo;
}

/**
 * Enlaza una lista en español: «población», «población y viviendas»,
 * «población, viviendas y colegios».
 */
function exposicion_enlace_lista($items) {
    $items = array_values(array_filter(array_map('strval', $items), function ($x) {
        return trim($x) !== '';
    }));
    $n = count($items);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return $items[0];
    }
    $ultimo = array_pop($items);
    return implode(', ', $items) . ' y ' . $ultimo;
}

/**
 * Estado de la exposición agrupado por campo.
 *
 * Sirve para escribir textos honestos sin repetirlos a mano en cada vista:
 * las pantallas preguntan al modelo qué es oficial y qué no, en lugar de
 * decir «estimación» a propósito o por olvido.
 *
 * @return array
 *   [
 *     'estado'            => 'estimada' | 'mixta' | 'oficial',
 *     'oficiales'         => [['campo'=>..,'etiqueta'=>..,'distritos'=>..,'fuente'=>..,'anio'=>..]],
 *     'estimados'         => [['campo'=>..,'etiqueta'=>..]],
 *     'total_territorios' => 29,
 *     'con_oficial'       => 29,
 *   ]
 */
function exposicion_campos_estado() {
    $c = exposicion_cargar();
    $permitidos = exposicion_campos_permitidos();

    $oficiales = [];
    $estimados = [];

    foreach ($permitidos as $campo) {
        $n = 0;
        $fuente = '';
        $anio = null;
        foreach ($c['territorios'] as $bloque) {
            if (!isset($bloque['campos'][$campo])) {
                continue;
            }
            $n++;
            if ($fuente === '') {
                $fuente = (string)($bloque['campos'][$campo]['fuente'] ?? '');
                $anio = $bloque['campos'][$campo]['anio'] ?? null;
            }
        }
        $etiqueta = exposicion_campo_prosa($campo);
        if ($n > 0) {
            $oficiales[] = [
                'campo'     => $campo,
                'etiqueta'  => $etiqueta,
                'distritos' => $n,
                'fuente'    => $fuente,
                'anio'      => $anio,
            ];
        } else {
            $estimados[] = ['campo' => $campo, 'etiqueta' => $etiqueta];
        }
    }

    $totalTerritorios = count($c['territorios']);
    $conOficial = 0;
    foreach ($c['territorios'] as $bloque) {
        if (!empty($bloque['campos'])) {
            $conOficial++;
        }
    }

    $estado = 'estimada';
    if ($totalTerritorios > 0 && !empty($oficiales)) {
        $completo = count($oficiales) === count($permitidos);
        foreach ($oficiales as $o) {
            if ($o['distritos'] < $totalTerritorios) {
                $completo = false;
                break;
            }
        }
        $estado = $completo ? 'oficial' : 'mixta';
    }

    return [
        'estado'            => $estado,
        'oficiales'         => $oficiales,
        'estimados'         => $estimados,
        'total_territorios' => $totalTerritorios,
        'con_oficial'       => $conOficial,
    ];
}

/**
 * Rótulo de uno o varios campos para texto corrido:
 * «el campo de población» o «los campos de viviendas y colegios».
 */
function exposicion_rotulo_campos($campos, $preposicion = 'el campo de', $preposicionPlural = 'los campos de') {
    $n = count($campos);
    if ($n === 0) {
        return '';
    }
    $nombres = exposicion_enlace_lista(array_column($campos, 'etiqueta'));
    return ($n === 1 ? $preposicion : $preposicionPlural) . ' ' . $nombres;
}

/**
 * Cómo nombrar las fuentes de los campos oficiales: si viene de una sola
 * fuente se cita; si son varias, se dice «N fuentes oficiales» en vez de
 * atribuirlo todo a la primera (que sería falso).
 */
function exposicion_fuentes_texto($campos) {
    $fuentes = [];
    foreach ($campos as $c) {
        $f = trim((string)($c['fuente'] ?? ''));
        if ($f !== '') {
            $fuentes[$f] = true;
        }
    }
    if (count($fuentes) === 1) {
        return (string)array_key_first($fuentes);
    }
    if (count($fuentes) === 0) {
        return 'fuente oficial';
    }
    return count($fuentes) . ' fuentes oficiales';
}

/**
 * Texto breve y DINÁMICO sobre el estado real de la exposición.
 *
 * No está escrito a mano por caso: se deriva del overlay cargado. Si se
 * borra `data/exposicion_oficial.json`, el texto vuelve a decir que todo es
 * estimación; si se aplica un padrón nuevo, se actualiza solo. Esa es la
 * diferencia entre un alcance declarado y un alcance que se puede desmentir.
 */
function exposicion_texto_breve() {
    $e = exposicion_campos_estado();

    if ($e['estado'] === 'estimada') {
        return 'La exposición (población, viviendas, colegios y centros de salud) es una '
             . 'estimación de referencia del catálogo piloto. El importador CSV desde INEI, '
             . 'MINEDU y MINSA está construido, pero este despliegue no tiene aplicado '
             . 'ningún padrón oficial.';
    }

    $oficiales = $e['oficiales'];

    $anios = [];
    foreach ($oficiales as $o) {
        if ($o['anio'] !== null && $o['anio'] !== '') {
            $anios[(int)$o['anio']] = true;
        }
    }
    $anioTxt = count($anios) === 1 ? (string)array_key_first($anios) : 'varios años';

    $txt = 'La exposición tiene dato oficial en '
         . exposicion_rotulo_campos($oficiales)
         . ' (' . exposicion_fuentes_texto($oficiales) . ', ' . $anioTxt . ', '
         . $e['con_oficial'] . '/' . $e['total_territorios'] . ' distritos)';

    if (!empty($e['estimados'])) {
        $txt .= '; ' . exposicion_rotulo_campos($e['estimados'])
              . ' ' . (count($e['estimados']) === 1 ? 'sigue' : 'siguen')
              . ' siendo estimación del catálogo piloto';
    }

    $txt .= '. La fracción expuesta siempre es estimada (total × susceptibilidad).';
    return $txt;
}

/**
 * Etiqueta corta de procedencia de un campo, para KPIs y tarjetas donde no
 * cabe la fuente completa: «oficial · INEI 2026» o «estimación del catálogo».
 */
function exposicion_etiqueta_campo($campo) {
    $e = exposicion_campos_estado();
    foreach ($e['oficiales'] as $o) {
        if ($o['campo'] !== $campo) {
            continue;
        }
        $corto = trim((string)preg_replace('/[·(].*$/u', '', (string)$o['fuente']));
        if ($corto === '') {
            $corto = 'fuente oficial';
        }
        return 'oficial · ' . $corto . (($o['anio'] === null || $o['anio'] === '') ? '' : ' ' . $o['anio']);
    }
    return 'estimación del catálogo';
}

/**
 * Versión compacta del estado de la exposición, para avisos cortos (el
 * pie del informe en PDF) donde no cabe una frase larga.
 */
function exposicion_texto_corto() {
    $e = exposicion_campos_estado();

    if ($e['estado'] === 'estimada') {
        return 'La exposición de este informe es una estimación de referencia del catálogo piloto '
             . 'y debe validarse con los padrones oficiales de INEI, MINEDU y MINSA antes de '
             . 'cualquier uso administrativo.';
    }

    $oficiales = $e['oficiales'];
    $anio = $oficiales[0]['anio'];
    $anioTxt = ($anio === null || $anio === '') ? '' : ', ' . $anio;

    if ($e['estado'] === 'oficial') {
        return 'La exposición total de este informe es oficial (' . exposicion_fuentes_texto($oficiales)
             . $anioTxt . ', con año y hash por campo). La fracción expuesta sigue siendo una '
             . 'estimación (total × susceptibilidad).';
    }

    $rotuloEst = exposicion_rotulo_campos($e['estimados']);
    $pluralEst = count($e['estimados']) === 1;

    return 'La exposición de este informe es mixta: '
         . exposicion_rotulo_campos($oficiales) . ' es oficial ('
         . exposicion_fuentes_texto($oficiales) . $anioTxt . '); ' . $rotuloEst . ' '
         . ($pluralEst ? 'sigue' : 'siguen')
         . ' siendo estimación del catálogo piloto y '
         . ($pluralEst ? 'debe' : 'deben') . ' validarse con los padrones oficiales '
         . 'de MINEDU y MINSA antes de cualquier uso administrativo. La fracción expuesta sigue '
         . 'siendo una estimación (total × susceptibilidad).';
}

/**
 * Par [título, texto] para la lista de «Límites y alcance».
 *
 * El límite se declara sobre lo que REALMENTE pasa, no sobre lo que pasaba
 * cuando se escribió la vista.
 */
function exposicion_texto_limite() {
    $e = exposicion_campos_estado();

    if ($e['estado'] === 'estimada') {
        return [
            'No usa datos oficiales de exposición.',
            'La exposición es una estimación de referencia del catálogo piloto, '
            . 'marcada como tal en cada ficha.',
        ];
    }

    if ($e['estado'] === 'oficial') {
        return [
            'La exposición total es oficial; la expuesta no.',
            'Población, viviendas, colegios y centros de salud provienen de fuentes '
            . 'oficiales, con año y hash por campo. La fracción expuesta sigue siendo '
            . 'estimada (total × susceptibilidad).',
        ];
    }

    $oficiales = $e['oficiales'];
    $anio = $oficiales[0]['anio'];
    $anioTxt = ($anio === null || $anio === '') ? '' : ', ' . $anio;

    return [
        'La exposición es oficial solo en parte.',
        ucfirst(exposicion_rotulo_campos($oficiales)) . ' '
        . (count($oficiales) === 1 ? 'es oficial' : 'son oficiales') . ' ('
        . exposicion_fuentes_texto($oficiales) . $anioTxt . ', '
        . $e['con_oficial'] . '/' . $e['total_territorios'] . ' distritos); '
        . exposicion_rotulo_campos($e['estimados']) . ' '
        . (count($e['estimados']) === 1 ? 'sigue' : 'siguen')
        . ' siendo estimación del catálogo piloto. '
        . 'La fracción expuesta sigue siendo estimada (total × susceptibilidad).',
    ];
}
