<?php
/**
 * CATÁLOGO DE TERRITORIOS — WILLAQ
 *
 * FUENTE DE VERDAD: la tabla `territorios` de PostgreSQL (Neon).
 *
 * Si la base no responde (feria sin internet, MySQL caído, credenciales aún
 * sin configurar), se cae a `config/territorios.json`, que se mantiene como
 * respaldo offline. Ese respaldo es el mismo archivo del que antes se leía
 * siempre, así que el comportamiento degradado es idéntico al histórico.
 *
 * Los valores oficiales de población, viviendas, escuelas y centros de salud
 * NO están aquí: viven en el overlay data/exposicion_oficial.json, que genera
 * scripts/importar_exposicion.php. Cada campo conserva su procedencia.
 */

require_once __DIR__ . '/Exposicion.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Lee el catálogo desde la tabla `territorios` de la base de datos.
 * Devuelve null si la base no está disponible, para permitir el respaldo JSON.
 *
 * @return array|null ['territorios' => [...], '_meta' => [...]]
 */
function territorios_desde_bd() {
    $db = conectar_db();
    if (!$db) {
        return null;
    }

    try {
        $sql = 'SELECT id, ubigeo, pais, region, provincia, distrito, lat, lon,
                       altitud, zona, poblacion, viviendas, escuelas,
                       centros_salud, km_vias, hectareas_cultivo, vulnerabilidad,
                       susc_inundacion, susc_huaico, susc_sequia, pendiente,
                       cuenca_km2, umbral_caudal, cultivo, albergues_capacidad
                  FROM territorios
                 WHERE activo = TRUE
              ORDER BY region, distrito';
        $filas = $db->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('[WILLAQ territorios] No se pudo leer la tabla: ' . $e->getMessage());
        return null;
    }

    if (!$filas) {
        return null;
    }

    // PDO devuelve todo como string; normalizamos los tipos para que las
    // vistas y el cálculo de riesgo sigan recibiendo int/float como antes.
    $territorios = [];
    foreach ($filas as $f) {
        $territorios[] = [
            'id'                  => (string)$f['id'],
            'ubigeo'              => (string)($f['ubigeo'] ?? ''),
            'pais'                => (string)$f['pais'],
            'region'              => (string)$f['region'],
            'provincia'           => (string)$f['provincia'],
            'distrito'            => (string)$f['distrito'],
            'lat'                 => (float)$f['lat'],
            'lon'                 => (float)$f['lon'],
            'altitud'             => (int)$f['altitud'],
            'zona'                => (string)$f['zona'],
            'poblacion'           => (int)$f['poblacion'],
            'viviendas'           => (int)$f['viviendas'],
            'escuelas'            => (int)$f['escuelas'],
            'centros_salud'       => (int)$f['centros_salud'],
            'km_vias'             => (float)$f['km_vias'],
            'hectareas_cultivo'   => (float)$f['hectareas_cultivo'],
            'vulnerabilidad'      => (float)$f['vulnerabilidad'],
            'susc_inundacion'     => (float)$f['susc_inundacion'],
            'susc_huaico'         => (float)$f['susc_huaico'],
            'susc_sequia'         => (float)$f['susc_sequia'],
            'pendiente'           => (int)$f['pendiente'],
            'cuenca_km2'          => (float)$f['cuenca_km2'],
            'umbral_caudal'       => (float)$f['umbral_caudal'],
            'cultivo'             => (string)$f['cultivo'],
            'albergues_capacidad' => (int)$f['albergues_capacidad'],
        ];
    }

    return [
        'territorios' => $territorios,
        '_meta'       => [
            'version'     => 'bd',
            'fuente'      => 'PostgreSQL · tabla territorios',
            'total'       => count($territorios),
        ],
    ];
}

function territorios_catalogo() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // 1. Fuente de verdad: la base de datos.
    $desdeBd = territorios_desde_bd();
    if ($desdeBd !== null) {
        $cache = $desdeBd;
        return $cache;
    }

    // 2. Respaldo offline: el JSON versionado.
    $ruta = __DIR__ . '/../config/territorios.json';
    if (!is_file($ruta)) {
        return ['territorios' => [], '_meta' => []];
    }

    $datos = json_decode(file_get_contents($ruta), true);
    if (!is_array($datos) || empty($datos['territorios'])) {
        return ['territorios' => [], '_meta' => []];
    }

    $cache = $datos;
    return $cache;
}

function territorios_meta() {
    $cat = territorios_catalogo();
    return $cat['_meta'] ?? [];
}

/** Lista de territorios con campos derivados listos para usar. */
function territorios_todos() {
    static $lista = null;
    if ($lista !== null) {
        return $lista;
    }

    $cat = territorios_catalogo();
    $lista = array_map('territorio_derivar', $cat['territorios'] ?? []);
    return $lista;
}

function territorio_por_id($id) {
    foreach (territorios_todos() as $t) {
        if ($t['id'] === $id) {
            return $t;
        }
    }
    return null;
}

/** Agrupa por región conservando el orden del catálogo. */
function territorios_por_region() {
    $grupos = [];
    foreach (territorios_todos() as $t) {
        $grupos[$t['region']][] = $t;
    }
    return $grupos;
}

/** Búsqueda tolerante a mayúsculas y tildes. */
function territorio_buscar($texto) {
    $texto = territorio_sin_tildes(mb_strtolower(trim((string)$texto), 'UTF-8'));
    if ($texto === '') {
        return territorios_todos();
    }

    $resultados = [];
    foreach (territorios_todos() as $t) {
        $blob = territorio_sin_tildes(mb_strtolower(
            $t['distrito'] . ' ' . $t['provincia'] . ' ' . $t['region'],
            'UTF-8'
        ));
        if (strpos($blob, $texto) !== false) {
            $resultados[] = $t;
        }
    }
    return $resultados;
}

/** Distrito más cercano a un punto (Haversine). Usado por el evaluador de sitios. */
function territorio_mas_cercano($lat, $lon) {
    $mejor = null;
    $mejorDistancia = PHP_FLOAT_MAX;

    foreach (territorios_todos() as $t) {
        $d = territorio_distancia_km((float)$lat, (float)$lon, $t['lat'], $t['lon']);
        if ($d < $mejorDistancia) {
            $mejorDistancia = $d;
            $mejor = $t;
        }
    }

    if ($mejor === null) {
        return null;
    }
    $mejor['distancia_km'] = round($mejorDistancia, 2);
    return $mejor;
}

function territorio_distancia_km($lat1, $lon1, $lat2, $lon2) {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function territorio_sin_tildes($texto) {
    return strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
}

/** Añade campos calculados que el resto del sistema asume presentes. */
function territorio_derivar($t) {
    // ------------------------------------------------------------------
    // 1. Exposición: total oficial si el overlay lo trae; si no, la
    //    estimación del catálogo. Cada campo guarda su procedencia.
    // ------------------------------------------------------------------
    $oficial = exposicion_campos_ubigeo($t['ubigeo'] ?? '');
    $permitidos = exposicion_campos_permitidos();
    $procedencia = [];
    $nOficiales = 0;

    foreach ($permitidos as $campo) {
        if (isset($oficial[$campo])) {
            $t[$campo] = (int)$oficial[$campo]['valor'];
            $nOficiales++;
            $procedencia[$campo] = [
                'valor'        => (int)$oficial[$campo]['valor'],
                'oficial'      => true,
                'fuente'       => $oficial[$campo]['fuente'],
                'anio'         => $oficial[$campo]['anio'],
                'archivo'      => $oficial[$campo]['archivo'],
                'sha256'       => $oficial[$campo]['sha256'],
                'importado_en' => $oficial[$campo]['importado_en'],
                'nota'         => $oficial[$campo]['nota'],
            ];
        } else {
            $procedencia[$campo] = [
                'valor'   => (int)($t[$campo] ?? 0),
                'oficial' => false,
                'fuente'  => '',
                'anio'    => null,
                'nota'    => 'estimación de referencia del catálogo piloto',
            ];
        }
    }

    // ------------------------------------------------------------------
    // 2. Derivados. La BASE puede ser oficial, pero la FRACCIÓN expuesta
    //    siempre es una estimación: total × susceptibilidad.
    // ------------------------------------------------------------------
    $t['poblacion_expuesta_inundacion'] = (int)round($t['poblacion'] * $t['susc_inundacion']);
    $t['poblacion_expuesta_huaico'] = (int)round($t['poblacion'] * $t['susc_huaico']);
    $t['poblacion_expuesta_sequia'] = (int)round($t['poblacion'] * $t['susc_sequia'] * 0.8);
    $t['poblacion_expuesta_calor'] = (int)round($t['poblacion'] * 0.6);
    $t['poblacion_expuesta_agro'] = (int)round(
        $t['poblacion'] * 0.4 + min($t['poblacion'] * 0.6, $t['hectareas_cultivo'] * 4)
    );

    $t['region_slug'] = strtolower(territorio_sin_tildes(str_replace(' ', '-', $t['region'])));
    $t['nombre_completo'] = $t['distrito'] . ', ' . $t['provincia'] . ' (' . $t['region'] . ')';

    // ------------------------------------------------------------------
    // 3. Estado de la exposición y procedencia por campo.
    //    Nota metodológica: "población expuesta a inundación" NO es un dato
    //    oficial aunque la población total sí lo sea. Es total ×
    //    susceptibilidad, y así se declara.
    // ------------------------------------------------------------------
    $estado = 'estimada';
    if ($nOficiales === count($permitidos)) {
        $estado = 'oficial';
    } elseif ($nOficiales > 0) {
        $estado = 'mixta';
    }

    $t['exposicion'] = [
        'estado' => $estado,
        'campos' => $procedencia,
        'expuestos' => [
            'inundacion' => [
                'valor'   => $t['poblacion_expuesta_inundacion'],
                'estimado' => true,
                'metodo'  => 'población total × susceptibilidad a inundación ('
                    . $t['susc_inundacion'] . ')',
            ],
            'huaico' => [
                'valor'   => $t['poblacion_expuesta_huaico'],
                'estimado' => true,
                'metodo'  => 'población total × susceptibilidad a huaico ('
                    . $t['susc_huaico'] . ')',
            ],
            'sequia' => [
                'valor'   => $t['poblacion_expuesta_sequia'],
                'estimado' => true,
                'metodo'  => 'población total × susceptibilidad a sequía ('
                    . $t['susc_sequia'] . ') × 0.8',
            ],
            'calor' => [
                'valor'   => $t['poblacion_expuesta_calor'],
                'estimado' => true,
                'metodo'  => 'población total × 0.6 (exposición fija al calor)',
            ],
            'agro' => [
                'valor'   => $t['poblacion_expuesta_agro'],
                'estimado' => true,
                'metodo'  => 'población total × 0.4 + min(población × 0.6, hectares × 4)',
            ],
        ],
    ];

    // Campo legado que usan varias vistas: ahora refleja el estado real.
    if ($estado === 'oficial') {
        $t['fuente_exposicion'] = 'oficial';
    } elseif ($estado === 'mixta') {
        $t['fuente_exposicion'] = 'mixta_oficial_estimada';
    } else {
        $t['fuente_exposicion'] = territorios_meta()['fuente_exposicion'] ?? 'estimado';
    }

    return $t;
}

/** Formato legible de números grandes en español. */
function numero_legible($n) {
    $n = (float)$n;
    if ($n >= 1000000) {
        return number_format($n / 1000000, 1, ',', ' ') . ' M';
    }
    if ($n >= 1000) {
        return number_format($n / 1000, 0, ',', ' ') . ' mil';
    }
    return number_format($n, 0, ',', ' ');
}
