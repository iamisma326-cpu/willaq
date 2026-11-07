<?php
// config/env.php
// Carga el archivo .env en las variables de entorno nativas de PHP

/**
 * Vuelca las variables reales del proceso a $_ENV y $_SERVER.
 *
 * Motivo (bug real corregido): en la configuración habitual de PHP,
 * `variables_order = "GPCS"` no incluye la "E", de modo que $_ENV llega VACÍO
 * aunque la variable exista en el entorno. Consecuencia: arrancar con
 * `WILLAQ_MODO_OFFLINE=1 php -S localhost:8000` NO activaba el modo feria,
 * y el sistema seguía saliendo en línea sin avisar. Con este volcado, la
 * variable llega por el canal que PHP sí respeta.
 */
function sembrarEntorno() {
    $entorno = getenv();
    if (!is_array($entorno)) {
        return;
    }
    foreach ($entorno as $clave => $valor) {
        if (!isset($_ENV[$clave])) {
            $_ENV[$clave] = $valor;
        }
        if (!isset($_SERVER[$clave])) {
            $_SERVER[$clave] = $valor;
        }
    }
}

/**
 * Lee una variable de entorno con precedencia: .env > entorno real > defecto.
 * @return string|null
 */
function willaq_env($clave, $defecto = null) {
    if (isset($_ENV[$clave]) && $_ENV[$clave] !== '') {
        return $_ENV[$clave];
    }
    if (isset($_SERVER[$clave]) && $_SERVER[$clave] !== '') {
        return $_SERVER[$clave];
    }
    $valor = getenv($clave);
    if ($valor !== false && $valor !== '') {
        return $valor;
    }
    return $defecto;
}

/**
 * Lee una variable de entorno como booleano.
 * Acepta 1/true/yes/on/si y 0/false/no/off.
 */
function willaq_env_bool($clave, $defecto = false) {
    $valor = strtolower(trim((string)willaq_env($clave, $defecto ? '1' : '0')));
    return in_array($valor, ['1', 'true', 'yes', 'on', 'si', 'sí'], true);
}

// Función súper simple para cargar el archivo .env en las variables de entorno nativas de PHP
function cargarEnv($path) {
    if (!file_exists($path)) {
        return false; // Si no existe el archivo .env, no hacemos nada (quizás en producción las variables ya estén inyectadas en el servidor)
    }

    $lineas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        // Ignorar comentarios
        if (strpos(trim($linea), '#') === 0) {
            continue;
        }

        // Separar clave y valor
        $partes = explode('=', $linea, 2);
        if (count($partes) == 2) {
            $clave = trim($partes[0]);
            $valor = trim($partes[1]);

            // Remover comillas si las tuviera (opcional pero buena práctica)
            $valor = trim($valor, "'\"");

            // Guardar en las variables globales de entorno de PHP
            putenv(sprintf('%s=%s', $clave, $valor));
            $_ENV[$clave] = $valor;
            $_SERVER[$clave] = $valor;
        }
    }
    return true;
}

// Primero el entorno real del proceso, después el archivo .env (que tiene prioridad).
sembrarEntorno();
cargarEnv(__DIR__ . '/../.env');
?>
