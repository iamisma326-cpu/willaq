<?php
/**
 * ARCHIVO DE CONEXIÓN A LA BASE DE DATOS (VERSIÓN PROCEDURAL SIMPLE)
 *
 * Este archivo establece la conexión entre PHP y la base de datos.
 * Está diseñado sin clases ni POO para que los estudiantes de nivel básico
 * entiendan y puedan explicar fácilmente cada línea durante su sustentación.
 *
 * MOTOR PRINCIPAL: PostgreSQL
 *   - Local (DBeaver Community, php -S):  DB_DRIVER=pgsql
 *   - Nube (AWS RDS / Google Cloud SQL): DB_DRIVER=pgsql + DB_SSLMODE=require
 *   - MySQL sigue disponible como alternativa heredada: DB_DRIVER=mysql
 *
 * ESCALAS
 *   - Si la base no responde, `conectar_db()` devuelve null y la aplicación
 *     sigue funcionando con los archivos JSON de data/ y las cuentas demo.
 *   - Nunca se lanza una excepción al usuario por un problema de conexión.
 *
 * VARIABLES DE ENTORNO (.env)
 *   DB_DRIVER            pgsql (por defecto) | mysql
 *   DB_HOST              localhost
 *   DB_PORT              5432 (pgsql) | 3306 (mysql)
 *   DB_NAME              willaq_db
 *   DB_USER              willaq
 *   DB_PASS              (vacío en local)
 *   DB_SSLMODE           disable|allow|prefer|require|verify-ca|verify-full
 *   DB_CONNEC_TIMEOUT    segundos de espera al conectar (por defecto 5)
 */

require_once __DIR__ . '/env.php';

/**
 * Devuelve el motor configurado: 'pgsql' o 'mysql'.
 * @return string
 */
function db_motor() {
    $motor = strtolower(trim((string)willaq_env('DB_DRIVER', 'pgsql')));
    return $motor === 'mysql' ? 'mysql' : 'pgsql';
}

/**
 * Etiqueta legible del motor, para mostrar en el panel de administración.
 * @return string
 */
function db_motor_nombre() {
    return db_motor() === 'mysql' ? 'MySQL' : 'PostgreSQL';
}

/**
 * Función principal para obtener una conexión activa a la base de datos.
 * Retorna un objeto de conexión PDO o null si falla.
 *
 * La conexión se reutiliza dentro de la misma petición: abrirla tres veces
 * por página solo ralentiza el sitio sin cambiar nada.
 *
 * @return PDO|null
 */
function conectar_db() {
    static $conexion = null;
    static $yaIntentado = false;

    if ($conexion !== null) {
        return $conexion;
    }
    if ($yaIntentado) {
        return null;
    }
    $yaIntentado = true;

    // 1. Leemos las variables de configuración del entorno (o usamos valores por defecto)
    $motor      = db_motor();
    $servidor  = trim((string)willaq_env('DB_HOST', 'localhost'));
    $base      = trim((string)willaq_env('DB_NAME', 'willaq_db'));
    $usuario   = trim((string)willaq_env('DB_USER', $motor === 'mysql' ? 'root' : 'willaq'));
    $password  = (string)willaq_env('DB_PASS', '');
    $puerto    = (int)willaq_env('DB_PORT', $motor === 'mysql' ? 3306 : 5432);
    $timeout   = (int)willaq_env('DB_CONNEC_TIMEOUT', 5);

    if ($timeout < 1) {
        $timeout = 5;
    }

    try {
        // 2. Armamos la cadena de conexión según el motor
        if ($motor === 'mysql') {
            $cadena_conexion = "mysql:host=" . $servidor . ";port=" . $puerto
                . ";dbname=" . $base . ";charset=utf8mb4";
        } else {
            $sslmode = strtolower(trim((string)willaq_env('DB_SSLMODE', 'prefer')));
            if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
                $sslmode = 'prefer';
            }
            $cadena_conexion = "pgsql:host=" . $servidor . ";port=" . $puerto
                . ";dbname=" . $base . ";sslmode=" . $sslmode
                . ";connect_timeout=" . $timeout;
        }

        // 3. Intentamos conectar a la base de datos
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($motor === 'mysql') {
            $opciones[PDO::ATTR_TIMEOUT] = $timeout;
        }

        $conexion = new PDO($cadena_conexion, $usuario, $password, $opciones);

        // 4. La aplicación trabaja siempre en horario de Lima
        if ($motor === 'pgsql') {
            $conexion->exec("SET TIME ZONE 'America/Lima'");
        } else {
            $conexion->exec("SET time_zone = '-05:00'");
        }

        // 5. Retornamos la conexión lista para ser usada en las consultas
        return $conexion;

    } catch (PDOException $error) {
        // Registramos el error de conexion en los logs internos del servidor sin exponer datos sensibles a la interfaz
        error_log("[WILLAQ db] No se pudo conectar (" . db_motor_nombre() . "): " . $error->getMessage());
        return null;
    }
}

/**
 * Indica si la aplicación está usando base de datos o el modo JSON.
 * @return bool
 */
function db_conectada() {
    return conectar_db() !== null;
}
