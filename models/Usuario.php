<?php
/**
 * USUARIOS Y SESIÓN — WILLAQ
 *
 * Tres modos:
 *   1. PostgreSQL/MySQL disponible → tabla `usuarios`
 *      (ver database/schema_postgresql.sql).
 *   2. Base no disponible → cuentas de demostración, para que la feria
 *      funcione en cualquier laptop sin instalar nada.
 *
 * Las contraseñas de demostración se guardan con password_hash() (bcrypt).
 */

require_once __DIR__ . '/../config/database.php';

const WILLAQ_DEMO_HASH = '$2y$12$ZOM92o7A.xmwcaDJwLtNT.veXwq26leBdPloU70w0y7up9NYPD8TW';

function usuario_iniciar_sesion() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function usuario_cuentas_demo() {
    return [
        'admin@willaq.pe' => [
            'id' => 1,
            'nombre' => 'Administrador WILLAQ',
            'email' => 'admin@willaq.pe',
            'rol' => 'admin',
            'organizacion' => 'WILLAQ',
        ],
        'defensa@willaq.pe' => [
            'id' => 2,
            'nombre' => 'Defensa Civil Piura',
            'email' => 'defensa@willaq.pe',
            'rol' => 'autoridad',
            'organizacion' => 'INDECI — Piura',
        ],
        'ciudadano@willaq.pe' => [
            'id' => 3,
            'nombre' => 'Ciudadano Demo',
            'email' => 'ciudadano@willaq.pe',
            'rol' => 'ciudadano',
            'organizacion' => 'Comunidad',
        ],
    ];
}

function usuario_login($email, $password) {
    $email = strtolower(trim((string)$email));
    $password = (string)$password;

    // --- Modo base de datos (PostgreSQL o MySQL) ---
    $db = conectar_db();
    if ($db) {
        try {
            // `activo` es BOOLEAN en PostgreSQL y numérico en MySQL: el
            // literal TRUE sirve en ambos (en MySQL se guarda como 1).
            $stmt = $db->prepare('SELECT id, nombre, email, password_hash, rol, organizacion FROM usuarios WHERE email = ? AND activo = TRUE LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($u && password_verify($password, $u['password_hash'])) {
                unset($u['password_hash']);
                usuario_establecer($u);
                return $u;
            }
            return null;
        } catch (Throwable $e) {
            error_log('[WILLAQ usuario] ' . $e->getMessage());
            // cae al modo demo
        }
    }

    // --- Modo demostración ---
    $cuentas = usuario_cuentas_demo();
    if (isset($cuentas[$email]) && password_verify($password, WILLAQ_DEMO_HASH)) {
        $u = $cuentas[$email];
        usuario_establecer($u);
        return $u;
    }

    return null;
}

function usuario_establecer($u) {
    usuario_iniciar_sesion();
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_name'] = $u['nombre'];
    $_SESSION['usuario'] = $u;
}

function usuario_actual() {
    usuario_iniciar_sesion();
    return $_SESSION['usuario'] ?? null;
}

function usuario_es_admin() {
    return (usuario_actual()['rol'] ?? '') === 'admin';
}

function usuario_es_autoridad() {
    return in_array(usuario_actual()['rol'] ?? '', ['admin', 'autoridad'], true);
}

function usuario_salir() {
    usuario_iniciar_sesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Registro: si la base falla, se informa con claridad en lugar de fingir éxito. */
function usuario_registrar($nombre, $email, $password, $organizacion = '') {
    $db = conectar_db();
    if (!$db) {
        return ['ok' => false, 'mensaje' => 'El registro requiere una base de datos. En modo demostración use las cuentas de prueba.'];
    }

    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'mensaje' => 'Correo inválido.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'mensaje' => 'La contraseña debe tener al menos 8 caracteres.'];
    }

    try {
        $stmt = $db->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'mensaje' => 'Ese correo ya está registrado.'];
        }

        // `activo` es BOOLEAN en PostgreSQL: se escribe TRUE. En MySQL TRUE
        // se guarda como 1, así que la misma sentencia sirve para ambos.
        // `creado_en` usa NOW(), que existe en los dos motores.
        if (db_motor() === 'pgsql') {
            $sql = 'INSERT INTO usuarios (nombre, email, password_hash, rol, organizacion, activo, creado_en)
                    VALUES (?, ?, ?, ?, ?, TRUE, NOW()) RETURNING id';
        } else {
            $sql = 'INSERT INTO usuarios (nombre, email, password_hash, rol, organizacion, activo, creado_en)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())';
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([
            trim($nombre),
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            'ciudadano',
            trim($organizacion),
        ]);

        $id = null;
        if (db_motor() === 'pgsql') {
            // PostgreSQL devuelve el id generado con RETURNING.
            $id = (int)$stmt->fetchColumn();
        } else {
            $id = (int)$db->lastInsertId();
        }

        $u = [
            'id' => $id,
            'nombre' => trim($nombre),
            'email' => $email,
            'rol' => 'ciudadano',
            'organizacion' => trim($organizacion),
        ];
        usuario_establecer($u);
        return ['ok' => true, 'usuario' => $u];
    } catch (Throwable $e) {
        error_log('[WILLAQ registro] ' . $e->getMessage());
        return ['ok' => false, 'mensaje' => 'No se pudo completar el registro.'];
    }
}
