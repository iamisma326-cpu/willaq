<?php
/**
 * CACHÉ MULTINIVEL — WILLAQ
 *
 * Nivel 1: memoria del proceso (array estático).
 * Nivel 2: archivo JSON en data/cache/ (sobrevive entre peticiones y funciona sin base de datos).
 * Nivel 3: respaldo "expirado" — si la API falla, se usa el último dato bueno conocido.
 *          Ese respaldo es lo que permite el MODO FERIA OFFLINE.
 *
 * No requiere base de datos. Si PostgreSQL está disponible, la tabla cache_geodatos
 * puede usarse como espejo, pero nunca es obligatoria.
 */

require_once __DIR__ . '/../config/env.php';

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

/**
 * Lee un ítem completo de caché.
 * @return array|null ['clave','payload','fuente','guardado_en','expira_en','expirado']
 */
function cache_leer($clave, $permitir_expirado = false) {
    static $memoria = [];

    $ruta = cache_ruta($clave);
    $mtime = @filemtime($ruta);

    // Nivel 1: memoria (solo si el archivo no cambió)
    if (isset($memoria[$clave]) && $memoria[$clave]['mtime'] === $mtime) {
        $item = $memoria[$clave]['item'];
        if ($permitir_expirado || $item['expira_en'] >= time()) {
            return $item;
        }
        return null;
    }

    if (!is_file($ruta)) {
        return null;
    }

    $raw = @file_get_contents($ruta);
    if ($raw === false || $raw === '') {
        return null;
    }

    $item = json_decode($raw, true);
    if (!is_array($item) || !array_key_exists('payload', $item)) {
        return null;
    }

    $item['expirado'] = ($item['expira_en'] ?? 0) < time();
    $memoria[$clave] = ['mtime' => $mtime, 'item' => $item];

    if (!$permitir_expirado && $item['expirado']) {
        return null;
    }
    return $item;
}

function cache_guardar($clave, $payload, $ttl = 3600, $fuente = 'desconocida') {
    $item = [
        'clave' => $clave,
        'payload' => $payload,
        'fuente' => $fuente,
        'guardado_en' => time(),
        'expira_en' => time() + max(0, (int)$ttl),
        'ttl' => (int)$ttl,
    ];

    $ruta = cache_ruta($clave);
    $tmp = $ruta . '.tmp';

    // Escritura atómica: si dos procesos escriben a la vez, ninguno lee un archivo a medias.
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

/** Borra caché. Si se pasa patrón, solo las claves que lo contengan. */
function cache_limpiar($patron = null) {
    $dir = cache_dir();
    $archivos = glob($dir . '/*.json') ?: [];
    $borrados = 0;

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

function cache_estadisticas() {
    $dir = cache_dir();
    $archivos = glob($dir . '/*.json') ?: [];
    $vigentes = 0;
    $expirados = 0;
    $bytes = 0;

    foreach ($archivos as $archivo) {
        $bytes += (int)@filesize($archivo);
        $item = json_decode(@file_get_contents($archivo), true);
        if (($item['expira_en'] ?? 0) >= time()) {
            $vigentes++;
        } else {
            $expirados++;
        }
    }

    return [
        'archivos' => count($archivos),
        'vigentes' => $vigentes,
        'expirados' => $expirados,
        'mb' => round($bytes / 1048576, 2),
    ];
}
