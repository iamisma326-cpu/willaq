<?php
/**
 * ALERTAS Y PROTOCOLOS — WILLAQ
 *
 * IMPORTANTE (ética y legalidad): WILLAQ NO emite alertas oficiales.
 * Emite RECOMENDACIONES de apoyo a la decisión. La alerta oficial corresponde
 * a SENAMHI / INDECI / ENFEN. Este texto aparece siempre en la interfaz.
 *
 * ALMACENAMIENTO (dos capas, sin perder nada)
 *   1. PostgreSQL (o MySQL) cuando la base está conectada:
 *      tabla `alertas` del esquema database/schema_postgresql.sql.
 *   2. Archivo JSON en data/alertas.json siempre, como espejo de seguridad:
 *      si la base se cae, la aplicación sigue mostrando la última información
 *      conocida y la feria no se interrumpe.
 *
 *   Cuando la base NO está disponible, el JSON es el almacén principal.
 *   Cuando la base SÍ está disponible, es la fuente de verdad y el JSON se
 *   regenera como espejo tras cada cambio.
 *
 * Motivo de diseño: funciona sin base de datos y sin internet, condición
 * indispensable para una feria.
 */

require_once __DIR__ . '/../config/umbrales.php';
require_once __DIR__ . '/../config/database.php';

function alertas_ruta() {
    return __DIR__ . '/../data/alertas.json';
}

/**
 * ¿Hay una base de datos utilizable ahora mismo?
 * @return bool
 */
function alertas_bd_disponible() {
    return conectar_db() !== null;
}

/**
 * Traduce una marca de tiempo UNIX a una expresión SQL portable.
 * PostgreSQL usa to_timestamp(); MySQL usa FROM_UNIXTIME().
 * @return string
 */
function alertas_sql_timestamp($ts) {
    return db_motor() === 'pgsql'
        ? 'to_timestamp(?)'
        : 'FROM_UNIXTIME(?)';
}

// ============================================================================
// LECTURA
// ============================================================================

/**
 * Normaliza una fila de la base al mismo formato que usa el archivo JSON,
 * para que las vistas no cambien según dónde esté el dato.
 * @return array
 */
function alertas_normalizar_fila($fila) {
    $acciones = $fila['acciones'] ?? [];
    if (is_string($acciones)) {
        $acciones = json_decode($acciones, true);
    }
    if (!is_array($acciones)) {
        $acciones = [];
    }

    return [
        'id'            => isset($fila['id']) ? (int)$fila['id'] : null,
        'codigo'        => (string)($fila['codigo'] ?? ''),
        'territorio_id' => (string)($fila['territorio_id'] ?? ''),
        'distrito'      => (string)($fila['distrito'] ?? ''),
        'provincia'     => (string)($fila['provincia'] ?? ''),
        'region'        => (string)($fila['region'] ?? ''),
        'amenaza'       => (string)($fila['amenaza'] ?? ''),
        'nivel'         => (string)($fila['nivel'] ?? ''),
        'titulo'        => (string)($fila['titulo'] ?? ''),
        'mensaje'       => (string)($fila['mensaje'] ?? ''),
        'acciones'      => array_values($acciones),
        'estado'        => (string)($fila['estado'] ?? 'emitida'),
        'emitida_por'   => (string)($fila['emitida_por'] ?? 'Sistema'),
        'emitida_en'    => isset($fila['emitida_ts']) ? (int)$fila['emitida_ts'] : 0,
        'acuse_por'     => $fila['acuse_por'] ?? null,
        'acuse_en'      => isset($fila['acuse_ts']) ? (int)$fila['acuse_ts'] : null,
        'nota_legal'    => (string)($fila['nota_legal'] ?? ''),
    ];
}

/**
 * Lee todas las alertas desde la base de datos.
 * @return array|null null si la base no está disponible o falla la consulta
 */
function alertas_leer_bd($db) {
    try {
        if (db_motor() === 'pgsql') {
            $sql = 'SELECT id, codigo, territorio_id, distrito, provincia, region, amenaza,
                           nivel, titulo, mensaje, acciones::text AS acciones, estado,
                           emitida_por, EXTRACT(EPOCH FROM emitida_en)::bigint AS emitida_ts,
                           acuse_por,
                           CASE WHEN acuse_en IS NULL THEN NULL
                                ELSE EXTRACT(EPOCH FROM acuse_en)::bigint END AS acuse_ts,
                           nota_legal
                    FROM alertas
                    ORDER BY emitida_en DESC';
        } else {
            $sql = 'SELECT id, codigo, territorio_id, distrito, provincia, region, amenaza,
                           nivel, titulo, mensaje, CAST(acciones AS CHAR) AS acciones, estado,
                           emitida_por, UNIX_TIMESTAMP(emitida_en) AS emitida_ts,
                           acuse_por, UNIX_TIMESTAMP(acuse_en) AS acuse_ts, nota_legal
                    FROM alertas
                    ORDER BY emitida_en DESC';
        }

        $stmt = $db->query($sql);
        $lista = [];
        foreach ($stmt->fetchAll() as $fila) {
            $lista[] = alertas_normalizar_fila($fila);
        }
        return $lista;

    } catch (Throwable $e) {
        error_log('[WILLAQ alertas] No se pudo leer de la base: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lee todas las alertas del archivo JSON.
 * @return array
 */
function alertas_leer_json() {
    $ruta = alertas_ruta();
    if (!is_file($ruta)) {
        return [];
    }
    $datos = json_decode(file_get_contents($ruta), true);
    $lista = $datos['alertas'] ?? [];
    usort($lista, fn($a, $b) => ($b['emitida_en'] ?? 0) <=> ($a['emitida_en'] ?? 0));
    return $lista;
}

/**
 * Todas las alertas, ordenadas de la más reciente a la más antigua.
 * La base manda; el JSON es el respaldo si algo falla.
 * @return array
 */
function alertas_todas() {
    $db = conectar_db();
    if ($db) {
        $lista = alertas_leer_bd($db);
        if ($lista !== null) {
            return $lista;
        }
        // Si la base falla, seguimos con el espejo JSON (feria sin cortes).
    }
    return alertas_leer_json();
}

// ============================================================================
// ESCRITURA
// ============================================================================

/**
 * Escribe el archivo JSON de forma atómica (temporal + rename), para que la
 * web pueda leer mientras el cron reescribe el archivo.
 * @return bool
 */
function alertas_guardar_json($lista) {
    $ruta = alertas_ruta();
    $dir = dirname($ruta);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // El JSON es un espejo: no necesita el id interno de la base.
    $limpio = [];
    foreach ($lista as $a) {
        unset($a['id']);
        $limpio[] = $a;
    }

    $payload = json_encode(['alertas' => array_values($limpio)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) {
        return false;
    }

    $tmp = $ruta . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $payload) === false) {
        return false;
    }
    if (!@rename($tmp, $ruta)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Asegura que el territorio exista en la tabla `territorios` antes de guardar
 * una alerta (la llave foránea lo exige). Si no está, se inserta con los
 * datos del catálogo de referencia.
 */
function alertas_asegurar_territorio($db, $territorio) {
    if (empty($territorio['id'])) {
        return;
    }

    $stmt = $db->prepare('SELECT id FROM territorios WHERE id = ? LIMIT 1');
    $stmt->execute([$territorio['id']]);
    if ($stmt->fetch()) {
        return;
    }

    $sql = 'INSERT INTO territorios
            (id, pais, region, provincia, distrito, lat, lon, altitud, zona, poblacion,
             viviendas, escuelas, centros_salud, km_vias, hectareas_cultivo,
             vulnerabilidad, susc_inundacion, susc_huaico, susc_sequia, pendiente,
             cuenca_km2, umbral_caudal, cultivo, albergues_capacidad, fuente_exposicion)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $territorio['id'],
        $territorio['pais'] ?? 'Perú',
        $territorio['region'] ?? '',
        $territorio['provincia'] ?? '',
        $territorio['distrito'] ?? '',
        $territorio['lat'] ?? 0,
        $territorio['lon'] ?? 0,
        $territorio['altitud'] ?? 0,
        $territorio['zona'] ?? 'sierra',
        $territorio['poblacion'] ?? 0,
        $territorio['viviendas'] ?? 0,
        $territorio['escuelas'] ?? 0,
        $territorio['centros_salud'] ?? 0,
        $territorio['km_vias'] ?? 0,
        $territorio['hectareas_cultivo'] ?? 0,
        $territorio['vulnerabilidad'] ?? 0.5,
        $territorio['susc_inundacion'] ?? 0,
        $territorio['susc_huaico'] ?? 0,
        $territorio['susc_sequia'] ?? 0,
        $territorio['pendiente'] ?? 0,
        $territorio['cuenca_km2'] ?? 0,
        $territorio['umbral_caudal'] ?? 0,
        $territorio['cultivo'] ?? 'ninguno',
        $territorio['albergues_capacidad'] ?? 0,
        'estimacion_ratio_poblacional',
    ]);
}

/**
 * Inserta una alerta en la base de datos.
 * @return bool
 */
function alertas_insertar_bd($db, $alerta) {
    try {
        $acciones = json_encode($alerta['acciones'] ?? [], JSON_UNESCAPED_UNICODE);
        $tsExpr = alertas_sql_timestamp($alerta['emitida_en'] ?? time());

        if (db_motor() === 'pgsql') {
            $sql = 'INSERT INTO alertas
                    (codigo, territorio_id, distrito, provincia, region, amenaza, nivel,
                     titulo, mensaje, acciones, estado, emitida_por, emitida_en,
                     acuse_por, acuse_en, nota_legal)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ' . $tsExpr . ', ?, NULL, ?)';
        } else {
            $sql = 'INSERT INTO alertas
                    (codigo, territorio_id, distrito, provincia, region, amenaza, nivel,
                     titulo, mensaje, acciones, estado, emitida_por, emitida_en,
                     acuse_por, acuse_en, nota_legal)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . $tsExpr . ', ?, NULL, ?)';
        }

        $params = [
            $alerta['codigo'],
            $alerta['territorio_id'],
            $alerta['distrito'],
            $alerta['provincia'],
            $alerta['region'],
            $alerta['amenaza'],
            $alerta['nivel'],
            $alerta['titulo'],
            $alerta['mensaje'],
            $acciones,
            $alerta['estado'] ?? 'emitida',
            $alerta['emitida_por'] ?? 'Sistema',
            $alerta['emitida_en'] ?? time(),
            $alerta['acuse_por'] ?? null,
            $alerta['nota_legal'] ?? '',
        ];

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return true;

    } catch (Throwable $e) {
        error_log('[WILLAQ alertas] No se pudo insertar en la base: ' . $e->getMessage());
        return false;
    }
}

/**
 * Reemplaza por completo el conjunto de alertas (base y JSON).
 * Se usa al sembrar desde cero (`--limpiar`) y al migrar el JSON a la base.
 * @return bool
 */
function alertas_guardar($lista) {
    $ok = true;
    $db = conectar_db();

    if ($db) {
        try {
            $db->beginTransaction();
            $db->exec('DELETE FROM alertas');

            foreach ($lista as $alerta) {
                alertas_insertar_bd($db, $alerta);
            }

            $db->commit();

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[WILLAQ alertas] No se pudo guardar el conjunto: ' . $e->getMessage());
            $ok = false;
        }
    }

    // El JSON siempre se escribe: es el respaldo para la feria sin base.
    if (!alertas_guardar_json($lista)) {
        $ok = false;
    }

    return $ok;
}

/**
 * Refresca el espejo JSON con el contenido actual de la base.
 * @return bool
 */
function alertas_espejo_json() {
    $db = conectar_db();
    if (!$db) {
        return false;
    }
    $lista = alertas_leer_bd($db);
    if ($lista === null) {
        return false;
    }
    return alertas_guardar_json($lista);
}

// ============================================================================
// LÓGICA DE ALERTAS (sin cambios de comportamiento)
// ============================================================================

function alerta_codigo() {
    return 'WQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function alerta_protocolo($amenaza, $nivelId) {
    global $PROTOCOLOS;
    $amenaza = $PROTOCOLOS[$amenaza] ?? $PROTOCOLOS['lluvia'];
    return $amenaza[$nivelId] ?? $amenaza['verde'];
}

function alerta_titulo($territorio, $amenaza, $nivelId) {
    $nombres = [
        'lluvia' => 'Lluvia extrema',
        'inundacion' => 'Inundación fluvial',
        'huaico' => 'Huaico / deslizamiento',
        'sequia' => 'Sequía',
        'calor' => 'Calor extremo',
        'agro' => 'Riesgo agropecuario',
    ];
    $nivel = [
        'verde' => 'Bajo', 'amarillo' => 'Medio',
        'naranja' => 'Alto', 'rojo' => 'Muy alto',
    ];
    $nombre = $nombres[$amenaza] ?? $amenaza;
    $n = $nivel[$nivelId] ?? $nivelId;
    return "{$nombre} — riesgo {$n} en {$territorio['distrito']}";
}

function alerta_crear($territorio, $amenaza, $nivelId, $mensaje = '', $usuario = 'Sistema') {
    $acciones = alerta_protocolo($amenaza, $nivelId);

    $alerta = [
        'codigo' => alerta_codigo(),
        'territorio_id' => $territorio['id'],
        'distrito' => $territorio['distrito'],
        'provincia' => $territorio['provincia'],
        'region' => $territorio['region'],
        'amenaza' => $amenaza,
        'nivel' => $nivelId,
        'titulo' => alerta_titulo($territorio, $amenaza, $nivelId),
        'mensaje' => $mensaje !== '' ? $mensaje : 'Recomendación generada a partir del índice de riesgo WILLAQ.',
        'acciones' => $acciones,
        'estado' => 'emitida',
        'emitida_por' => $usuario,
        'emitida_en' => time(),
        'acuse_por' => null,
        'acuse_en' => null,
        'nota_legal' => 'Recomendación de apoyo a la decisión. No reemplaza una alerta oficial de SENAMHI/INDECI/ENFEN.',
    ];

    $db = conectar_db();
    if ($db) {
        alertas_asegurar_territorio($db, $territorio);
        if (alertas_insertar_bd($db, $alerta)) {
            alertas_espejo_json();
            return $alerta;
        }
        // Si la base falla, la alerta no se pierde: cae al archivo JSON.
        error_log('[WILLAQ alertas] Se guarda en JSON por fallo de la base.');
    }

    $lista = alertas_leer_json();
    array_unshift($lista, $alerta);
    alertas_guardar_json($lista);

    return $alerta;
}

function alerta_buscar($codigo) {
    foreach (alertas_todas() as $a) {
        if ($a['codigo'] === $codigo) {
            return $a;
        }
    }
    return null;
}

function alerta_acusar($codigo, $usuario = 'Autoridad local') {
    $db = conectar_db();

    if ($db) {
        try {
            $tsExpr = alertas_sql_timestamp(time());
            $stmt = $db->prepare(
                'UPDATE alertas SET estado = \'acusada\', acuse_por = ?, acuse_en = ' . $tsExpr . '
                 WHERE codigo = ?'
            );
            $stmt->execute([$usuario, time(), $codigo]);

            if ($stmt->rowCount() > 0) {
                alertas_espejo_json();
                return alerta_buscar($codigo);
            }
            return null;

        } catch (Throwable $e) {
            error_log('[WILLAQ alertas] No se pudo acusar en la base: ' . $e->getMessage());
            // Sigue el camino JSON.
        }
    }

    $lista = alertas_leer_json();
    $encontrada = false;
    $actualizada = null;

    foreach ($lista as &$a) {
        if ($a['codigo'] === $codigo) {
            $a['estado'] = 'acusada';
            $a['acuse_por'] = $usuario;
            $a['acuse_en'] = time();
            $actualizada = $a;
            $encontrada = true;
            break;
        }
    }
    unset($a);

    if ($encontrada) {
        alertas_guardar_json($lista);
        // Se devuelve la fila ya actualizada del JSON: releer de la base
        // devolvería el estado anterior si la base es la que falló.
        return $actualizada;
    }
    return null;
}

function alerta_sugerir_desde_riesgo($r) {
    return [
        'territorio' => $r['territorio'],
        'amenaza' => $r['principal'],
        'nivel' => $r['nivel']['id'],
        'titulo' => alerta_titulo($r['territorio'], $r['principal'], $r['nivel']['id']),
        'acciones' => alerta_protocolo($r['principal'], $r['nivel']['id']),
        'mensaje' => $r['resumen'],
    ];
}

function alerta_estadisticas() {
    $lista = alertas_todas();
    $porNivel = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
    $acusadas = 0;

    foreach ($lista as $a) {
        $porNivel[$a['nivel']] = ($porNivel[$a['nivel']] ?? 0) + 1;
        if (($a['estado'] ?? '') === 'acusada') {
            $acusadas++;
        }
    }

    return [
        'total' => count($lista),
        'por_nivel' => $porNivel,
        'acusadas' => $acusadas,
        'pendientes' => count($lista) - $acusadas,
    ];
}
