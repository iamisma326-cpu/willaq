<?php
/**
 * CACHÉ MULTINIVEL — WILLAQ
 *
 * Nivel 1: memoria del proceso (array estático).
 * Nivel 2: tabla `cache_geodatos` en PostgreSQL (Neon). Es la fuente principal
 *          en producción: sobrevive a los redeploys, que en Railway borran el
 *          filesystem efímero.
 * Nivel 3: archivo JSON en data/cache/ — respaldo cuando la base no responde
 *          (feria sin internet, credenciales aún sin configurar).
 * Nivel 4: respaldo "expirado" — si la API falla, se usa el último dato bueno
 *          conocido, venga de la base o del disco.
 *
 * La caché nunca es un requisito duro: si todos los niveles fallan, la consulta
 * se hace a la API y el sistema sigue funcionando, solo más lento.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

function cache_dir() {
    $dir = __DIR__ . '/../data/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function cache_ruta($clave) {
    return cache_dir() . '/' . sha1($clave) . '.json';
}

/** Indica si la caché persistente en base de datos está disponible. */
function cache_bd_disponible() {
    static $disponible = null;
    if ($disponible !== null) {
        return $disponible;
    }
    $disponible = conectar_db() !== null;
    return $disponible;
}

/**
 * Lee un ítem desde la tabla cache_geodatos.
 * @return array|null Formato idéntico al de disco.
 */
function cache_leer_bd($clave) {
    if (!cache_bd_disponible()) {
        return null;
    }
    try {
        $db = conectar_db();
        $stmt = $db->prepare(
            'SELECT clave, payload, fuente, guardado_en, expira_en, ttl
               FROM cache_geodatos
              WHERE clave = ?
              LIMIT 1'
        );
        $stmt->execute([$clave]);
        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }

        $payload = $fila['payload'];
        // jsonb llega como string JSON en algunos drivers; normalizamos.
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $expira = strtotime((string)$fila['expira_en']);
        $guardado = strtotime((string)$fila['guardado_en']);

        return [
            'clave'       => (string)$fila['clave'],
            'payload'     => $payload,
            'fuente'      => (string)$fila['fuente'],
            'guardado_en' => $guardado,
            'expira_en'   => $expira,
            'ttl'         => (int)$fila['ttl'],
            'expirado'    => $expira < time(),
            'origen'      => 'bd',
        ];
    } catch (Throwable $e) {
        error_log('[WILLAQ cache] lectura en BD falló: ' . $e->getMessage());
        return null;
    }
}

/** Guarda un ítem en la tabla cache_geodatos. @return bool */
function cache_guardar_bd($clave, $payload, $ttl, $fuente) {
    if (!cache_bd_disponible()) {
        return false;
    }
    try {
        $db = conectar_db();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        // UPSERT: la clave es única (uq_cache_clave).
        $stmt = $db->prepare(
            'INSERT INTO cache_geodatos (clave, fuente, payload, guardado_en, expira_en, ttl)
                  VALUES (?, ?, ?::jsonb, NOW(), NOW() + (? || \' seconds\')::interval, ?)
             ON CONFLICT (clave) DO UPDATE
                    SET payload     = EXCLUDED.payload,
                        fuente      = EXCLUDED.fuente,
                        guardado_en = EXCLUDED.guardado_en,
                        expira_en   = EXCLUDED.expira_en,
                        ttl         = EXCLUDED.ttl'
        );
        $stmt->execute([$clave, $fuente, $json, max(0, (int)$ttl), max(0, (int)$ttl)]);
        return true;
    } catch (Throwable $e) {
        error_log('[WILLAQ cache] escritura en BD falló: ' . $e->getMessage());
        return false;
    }
}

/**
 * Lee un ítem completo de caché.
 * Orden: memoria → base de datos → disco.
 *
 * @return array|null ['clave','payload','fuente','guardado_en','expira_en','expirado','origen']
 */
function cache_leer($clave, $permitir_expirado = false) {
    static $memoria = [];

    // Nivel 1: memoria del proceso.
    if (isset($memoria[$clave])) {
        $item = $memoria[$clave];
        if ($permitir_expirado || $item['expira_en'] >= time()) {
            return $item;
        }
        return null;
    }

    // Nivel 2: base de datos.
    $item = cache_leer_bd($clave);

    // Nivel 3: disco (respaldo si la BD no lo tiene o no está disponible).
    if ($item === null) {
        $ruta = cache_ruta($clave);
        if (is_file($ruta)) {
            $raw = @file_get_contents($ruta);
            if ($raw !== false && $raw !== '') {
                $disco = json_decode($raw, true);
                if (is_array($disco) && array_key_exists('payload', $disco)) {
                    $disco['expirado'] = ($disco['expira_en'] ?? 0) < time();
                    $disco['origen'] = 'disco';
                    $item = $disco;
                }
            }
        }
    }

    if ($item === null) {
        return null;
    }

    $memoria[$clave] = $item;

    if (!$permitir_expirado && $item['expirado']) {
        return null;
    }
    return $item;
}

function cache_guardar($clave, $payload, $ttl = 3600, $fuente = 'desconocida') {
    $item = [
        'clave'       => $clave,
        'payload'     => $payload,
        'fuente'      => $fuente,
        'guardado_en' => time(),
        'expira_en'   => time() + max(0, (int)$ttl),
        'ttl'         => (int)$ttl,
    ];

    // Persistencia principal: base de datos (sobrevive a los redeploys).
    $enBd = cache_guardar_bd($clave, $payload, $ttl, $fuente);

    // Espejo en disco: conserva el respaldo offline y el modo feria.
    // Si la BD ya lo guardó, el archivo es solo una copia de seguridad.
    $ruta = cache_ruta($clave);
    $tmp = $ruta . '.tmp';
    if (@file_put_contents($tmp, json_encode($item, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($tmp, $ruta);
    }

    return $payload;
}

/** Devuelve solo el payload válido, o null. */
function cache_payload($clave) {
    $item = cache_leer($clave);
    return $item ? $item['payload'] : null;
}

/** Devuelve el payload aunque esté expirado (respaldo para modo offline). */
function cache_payload_respaldo($clave) {
    $item = cache_leer($clave, true);
    return $item ? $item['payload'] : null;
}

/**
 * Patrón "recordar": devuelve caché si existe; si no, ejecuta $fn, guarda y devuelve.
 * Si $fn falla, intenta el respaldo expirado antes de devolver $por_defecto.
 */
function cache_recordar($clave, $ttl, callable $fn, $fuente = 'desconocida', $por_defecto = null) {
    $item = cache_leer($clave);
    if ($item) {
        return $item['payload'];
    }

    try {
        $payload = $fn();
    } catch (Throwable $e) {
        error_log('[WILLAQ cache] fallo al generar "' . $clave . '": ' . $e->getMessage());
        $payload = null;
    }

    if ($payload !== null) {
        cache_guardar($clave, $payload, $ttl, $fuente);
        return $payload;
    }

    $respaldo = cache_payload_respaldo($clave);
    if ($respaldo !== null) {
        error_log('[WILLAQ cache] usando respaldo expirado para "' . $clave . '"');
        return $respaldo;
    }

    return $por_defecto;
}

/**
 * Borra caché. Si se pasa patrón, solo las claves que lo contengan.
 * Limpia la base de datos y el disco.
 */
function cache_limpiar($patron = null) {
    $borrados = 0;

    // Base de datos.
    if (cache_bd_disponible()) {
        try {
            $db = conectar_db();
            if ($patron !== null) {
                $stmt = $db->prepare('DELETE FROM cache_geodatos WHERE clave ILIKE ?');
                $stmt->execute(['%' . $patron . '%']);
            } else {
                $stmt = $db->query('DELETE FROM cache_geodatos');
            }
            $borrados += $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[WILLAQ cache] limpieza en BD falló: ' . $e->getMessage());
        }
    }

    // Disco.
    $archivos = glob(cache_dir() . '/*.json') ?: [];
    foreach ($archivos as $archivo) {
        if ($patron !== null) {
            $item = json_decode(@file_get_contents($archivo), true);
            $clave = $item['clave'] ?? '';
            if (stripos($clave, $patron) === false) {
                continue;
            }
        }
        if (@unlink($archivo)) {
            $borrados++;
        }
    }

    return $borrados;
}

/**
 * Estadísticas de caché. Combina base de datos y disco.
 * `origen` indica de dónde salieron los números.
 */
function cache_estadisticas() {
    $stats = ['archivos' => 0, 'vigentes' => 0, 'expirados' => 0, 'mb' => 0.0, 'origen' => 'disco'];

    // Base de datos.
    if (cache_bd_disponible()) {
        try {
            $db = conectar_db();
            $fila = $db->query(
                "SELECT count(*) AS total,
                        count(*) FILTER (WHERE expira_en >= NOW()) AS vigentes,
                        COALESCE(sum(pg_column_size(payload)), 0) AS bytes
                   FROM cache_geodatos"
            )->fetch();
            if ($fila) {
                return [
                    'archivos'  => (int)$fila['total'],
                    'vigentes'  => (int)$fila['vigentes'],
                    'expirados' => (int)$fila['total'] - (int)$fila['vigentes'],
                    'mb'        => round(((int)$fila['bytes']) / 1048576, 2),
                    'origen'    => 'bd',
                ];
            }
        } catch (Throwable $e) {
            error_log('[WILLAQ cache] estadísticas en BD fallaron: ' . $e->getMessage());
        }
    }

    // Disco (respaldo).
    $archivos = glob(cache_dir() . '/*.json') ?: [];
    $bytes = 0;
    foreach ($archivos as $archivo) {
        $bytes += (int)@filesize($archivo);
        $item = json_decode(@file_get_contents($archivo), true);
        if (($item['expira_en'] ?? 0) >= time()) {
            $stats['vigentes']++;
        } else {
            $stats['expirados']++;
        }
    }
    $stats['archivos'] = count($archivos);
    $stats['mb'] = round($bytes / 1048576, 2);

    return $stats;
}
