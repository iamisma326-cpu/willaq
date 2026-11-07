<?php
/**
 * WILLAQ — ENRUTADOR PRINCIPAL
 * Centinela del Fenómeno El Niño · Vigilar · Planificar · Simular
 *
 * Mantiene el patrón procedural del proyecto original (index.php + switch)
 * para que el equipo pueda explicarlo línea por línea en la sustentación.
 */

require_once __DIR__ . '/models/Usuario.php';
require_once __DIR__ . '/models/Territorio.php';
require_once __DIR__ . '/models/Clima.php';
require_once __DIR__ . '/models/Riesgo.php';
require_once __DIR__ . '/models/Zonificacion.php';
require_once __DIR__ . '/models/Simulador.php';
require_once __DIR__ . '/models/Alerta.php';
require_once __DIR__ . '/models/Cache.php';
require_once __DIR__ . '/models/Estacion.php';

usuario_iniciar_sesion();

$accion = $_GET['action'] ?? $_POST['action'] ?? 'home';

/** Escapa texto para HTML. */
function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

/** Construye una URL interna conservando la acción. */
function url($accion, $params = []) {
    $params = array_merge(['action' => $accion], $params);
    return '?' . http_build_query($params);
}

/** Fecha legible en español sin depender de setlocale. */
function fecha_legible($ts, $conHora = true) {
    if (!$ts) {
        return '—';
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
    $d = new DateTime('@' . (int)$ts);
    $d->setTimezone(new DateTimeZone('America/Lima'));
    $texto = $d->format('j') . ' ' . $meses[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    return $conHora ? $texto . ' · ' . $d->format('H:i') : $texto;
}

/** "hace 12 min" para mostrar frescura del dato. */
function hace($ts) {
    if (!$ts) {
        return 'sin dato';
    }
    $seg = time() - (int)$ts;
    if ($seg < 60) return 'ahora';
    if ($seg < 3600) return 'hace ' . floor($seg / 60) . ' min';
    if ($seg < 86400) return 'hace ' . floor($seg / 3600) . ' h';
    return 'hace ' . floor($seg / 86400) . ' d';
}

/** Renderiza una vista con el layout común. */
function render($vista, $vars = []) {
    extract($vars, EXTR_SKIP);
    $accionActual = $GLOBALS['accion'] ?? 'home';
    require __DIR__ . '/views/layout/header.php';
    require __DIR__ . '/views/' . $vista . '.php';
    require __DIR__ . '/views/layout/footer.php';
}

/** Datos de riesgo para las vistas: usa snapshot si existe (rápido y offline). */
function riesgos_para_vista($ventana = 24) {
    $snap = riesgo_snapshot_leer($ventana);
    if ($snap && (int)$snap['ventana'] === (int)$ventana && !empty($snap['riesgos'])) {
        return $snap['riesgos'];
    }
    return riesgo_nacional($ventana);
}

function json_salida($datos, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function redirigir($url) {
    header('Location: ' . $url);
    exit;
}

switch ($accion) {

    // ===================== API JSON =====================
    case 'cron_actualizar':
        // Endpoint para regenerar los snapshots desde un cron externo
        // (cron-job.org, GitHub Actions...). Railway no ofrece cron, así que
        // la actualización programada vive fuera del contenedor.
        //
        // Protegido por WILLAQ_CRON_TOKEN: sin token configurado el endpoint
        // queda deshabilitado. La comparación es en tiempo constante y solo
        // acepta el token por cabecera o por querystring.
        $tokenEsperado = (string)(willaq_env('WILLAQ_CRON_TOKEN') ?? '');
        if ($tokenEsperado === '') {
            json_salida([
                'ok' => false,
                'error' => 'cron deshabilitado: falta WILLAQ_CRON_TOKEN',
            ], 503);
        }

        $enviado = (string)($_SERVER['HTTP_X_WILLAQ_TOKEN'] ?? ($_GET['token'] ?? ''));
        if (!hash_equals($tokenEsperado, $enviado)) {
            json_salida(['ok' => false, 'error' => 'token inválido'], 401);
        }

        // Una ejecución completa (104 territorios × 3 ventanas) tarda varios
        // minutos. Un cron externo espera como máximo 30-60 s, así que no se
        // puede responder con el resultado: se lanza en segundo plano y se
        // responde de inmediato con 202 Accepted.
        //
        // El progreso queda en data/snapshots/.ultima_actualizacion.json y se
        // consulta con ?action=cron_estado.
        $solo = in_array($_GET['solo'] ?? '', ['cache', 'snapshot'], true) ? $_GET['solo'] : null;

        $marca = __DIR__ . '/data/snapshots/.ultima_actualizacion.json';
        @mkdir(dirname($marca), 0775, true);

        // Evita solapamientos: si hay una ejecución reciente en curso, no se
        // lanza otra (dos procesos a la vez duplicarían las llamadas a la API).
        $previo = is_file($marca) ? json_decode(file_get_contents($marca), true) : null;
        if (is_array($previo) && ($previo['estado'] ?? '') === 'corriendo') {
            $edad = time() - (int)($previo['inicio'] ?? 0);
            if ($edad < 900) {
                json_salida([
                    'ok' => true,
                    'estado' => 'corriendo',
                    'mensaje' => 'ya hay una actualización en curso',
                    'inicio' => $previo['inicio'] ?? null,
                    'edad_s' => $edad,
                ], 202);
            }
        }

        @file_put_contents($marca, json_encode([
            'estado' => 'corriendo',
            'inicio' => time(),
            'solo' => $solo,
        ], JSON_UNESCAPED_UNICODE));

        // Lanza el script CLI en segundo plano. Se usa PHP CLI porque el
        // proceso web moriría al cerrar la conexión.
        $php = PHP_BINARY ?: 'php';
        $script = __DIR__ . '/scripts/actualizar.php';
        $args = $solo === null ? '--ventana=all' : '';
        $log = __DIR__ . '/data/snapshots/.cron.log';
        $cmd = sprintf(
            '%s %s %s >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($script),
            $args,
            escapeshellarg($log)
        );
        @exec($cmd);

        json_salida([
            'ok' => true,
            'estado' => 'lanzado',
            'mensaje' => 'actualización iniciada en segundo plano',
            'consulta_estado' => '?action=cron_estado',
            'log' => 'data/snapshots/.cron.log',
        ], 202);

    case 'cron_estado':
        // Estado de la última actualización programada (solo lectura).
        $tokenEsperado = (string)(willaq_env('WILLAQ_CRON_TOKEN') ?? '');
        if ($tokenEsperado !== '') {
            $enviado = (string)($_SERVER['HTTP_X_WILLAQ_TOKEN'] ?? ($_GET['token'] ?? ''));
            if (!hash_equals($tokenEsperado, $enviado)) {
                json_salida(['ok' => false, 'error' => 'token inválido'], 401);
            }
        }

        $marca = __DIR__ . '/data/snapshots/.ultima_actualizacion.json';
        $previo = is_file($marca) ? json_decode(file_get_contents($marca), true) : null;
        $disponibles = [];
        foreach (riesgo_snapshots_disponibles() as $v => $s) {
            $disponibles[$v] = [
                'total' => count($s['riesgos'] ?? []),
                'generado_en' => $s['generado_en'] ?? null,
            ];
        }

        json_salida([
            'ok' => true,
            'ultima_ejecucion' => $previo,
            'snapshots' => $disponibles,
            'cache' => cache_estadisticas(),
        ]);

    case 'api_territorios':
        json_salida([
            'meta' => array_merge(territorios_meta(), [
                // Procedencia de la exposición: si hay overlay cargado, se
                // declara aquí. Cada territorio trae además su bloque
                // `exposicion` con la procedencia campo por campo.
                'exposicion_oficial' => exposicion_resumen(),
            ]),
            'total' => count(territorios_todos()),
            'territorios' => territorios_todos(),
        ]);

    case 'api_riesgo':
        $id = $_GET['id'] ?? null;
        $ventana = (int)($_GET['ventana'] ?? 24);
        if ($id) {
            $t = territorio_por_id($id);
            if (!$t) {
                json_salida(['error' => 'Territorio no encontrado'], 404);
            }
            $salida = riesgo_calcular($t, $ventana);
            $salida['exposicion'] = [
                'estado' => $t['exposicion']['estado'],
                'campos' => $t['exposicion']['campos'],
                'expuestos' => $t['exposicion']['expuestos'],
                'overlay' => exposicion_resumen(),
            ];
            json_salida($salida);
        }
        json_salida([
            'ventana' => $ventana,
            'generado_en' => time(),
            'exposicion' => [
                'estado_global' => exposicion_resumen(),
            ],
            'riesgos' => riesgos_para_vista($ventana),
        ]);

    case 'api_simular':
        $id = $_GET['id'] ?? '';
        $t = territorio_por_id($id);
        if (!$t) {
            json_salida(['error' => 'Territorio no encontrado'], 404);
        }
        $factor = (float)($_GET['lluvia'] ?? 1.5);
        $delta = (float)($_GET['temp'] ?? 0.0);
        $modo = $_GET['modo'] ?? 'clima';
        if ($modo === 'evacuacion') {
            json_salida(simular_evacuacion($t, $factor));
        }
        json_salida(simular_clima($t, $factor, $delta, (int)($_GET['ventana'] ?? 24)));

    case 'api_evaluar':
        $lat = (float)($_GET['lat'] ?? 0);
        $lon = (float)($_GET['lon'] ?? 0);
        $tipo = $_GET['tipo'] ?? 'colegio';
        if (!$lat || !$lon) {
            json_salida(['error' => 'Se requieren lat y lon'], 400);
        }
        $resultado = zonificacion_evaluar($lat, $lon, $tipo);
        if (!$resultado) {
            json_salida(['error' => 'No se pudo evaluar el sitio'], 500);
        }
        json_salida($resultado);

    // ===================== AUTENTICACIÓN =====================
    case 'login':
        $u = usuario_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($u) {
            redirigir('?action=home&login=success');
        }
        redirigir('?action=home&login=error');

    case 'logout':
        usuario_salir();
        redirigir('?action=home');

    case 'registro':
        $r = usuario_registrar(
            $_POST['nombre'] ?? '',
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_POST['organizacion'] ?? ''
        );
        if ($r['ok']) {
            redirigir('?action=home&registro=success');
        }
        redirigir('?action=home&registro=error');

    // ===================== ALERTAS =====================
    case 'emitir_alerta':
        if (!usuario_es_autoridad()) {
            redirigir('?action=home&login=required');
        }
        $t = territorio_por_id($_POST['territorio_id'] ?? '');
        if (!$t) {
            redirigir('?action=alertas&error=territorio');
        }
        $alerta = alerta_crear(
            $t,
            $_POST['amenaza'] ?? 'lluvia',
            $_POST['nivel'] ?? 'amarillo',
            $_POST['mensaje'] ?? '',
            usuario_actual()['nombre'] ?? 'Usuario'
        );
        redirigir('?action=alerta&codigo=' . urlencode($alerta['codigo']));

    case 'acusar':
        $a = alerta_acusar($_GET['codigo'] ?? '', usuario_actual()['nombre'] ?? 'Autoridad local');
        redirigir('?action=alerta&codigo=' . urlencode($_GET['codigo'] ?? '') . '&acuse=ok');

    // ===================== MANTENIMIENTO =====================
    case 'actualizar_datos':
        if (!usuario_es_admin()) {
            redirigir('?action=home&login=required');
        }
        $ok = riesgo_snapshot_guardar((int)($_GET['ventana'] ?? 24));
        redirigir('?action=home&actualizado=' . ($ok ? 'ok' : 'error'));

    case 'limpiar_cache':
        if (!usuario_es_admin()) {
            redirigir('?action=home&login=required');
        }
        $n = cache_limpiar($_GET['patron'] ?? null);
        redirigir('?action=admin&cache=' . $n);

    // ===================== VISTAS =====================
    case 'mapa':
        $ventana = (int)($_GET['ventana'] ?? 24);
        render('mapa/mapa', [
            'titulo' => 'Mapa de riesgo',
            'riesgos' => riesgos_para_vista($ventana),
            'ventana' => $ventana,
            'sinContenedor' => true,
            'requiereMapa' => true,
        ]);
        break;

    case 'territorio':
        $t = territorio_por_id($_GET['id'] ?? '');
        if (!$t) {
            redirigir('?action=home');
        }
        $ventana = (int)($_GET['ventana'] ?? 24);
        render('territorio/ficha', [
            'titulo' => $t['distrito'] . ' — Ficha de riesgo',
            't' => $t,
            'r' => riesgo_calcular($t, $ventana),
            'ventana' => $ventana,
            'ise' => zonificacion_ise_territorio($t),
            'clase' => zonificacion_clase(zonificacion_ise_territorio($t)),
            'alerta_sugerida' => alerta_sugerir_desde_riesgo(riesgo_calcular($t, $ventana)),
            'forecast' => clima_forecast($t['lat'], $t['lon']),
            'flood' => clima_flood($t['lat'], $t['lon']),
        ]);
        break;

    case 'alertas':
        render('alertas/lista', [
            'titulo' => 'Alertas y protocolos',
            'alertas' => alertas_todas(),
            'estadisticas' => alerta_estadisticas(),
        ]);
        break;

    case 'alerta':
        $a = alerta_buscar($_GET['codigo'] ?? '');
        if (!$a) {
            redirigir('?action=alertas');
        }
        render('alertas/detalle', [
            'titulo' => $a['titulo'],
            'a' => $a,
        ]);
        break;

    case 'planificacion':
        $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
        $lon = isset($_GET['lon']) && $_GET['lon'] !== '' ? (float)$_GET['lon'] : null;
        $tipo = $_GET['tipo'] ?? 'colegio';

        // Selección por desplegables: departamento → distrito.
        // Si llega un distrito, sus coordenadas mandan sobre lat/lon sueltas.
        $departamento = trim((string)($_GET['departamento'] ?? ''));
        $distritoId = trim((string)($_GET['distrito'] ?? ''));
        $distritoSel = $distritoId !== '' ? territorio_por_id($distritoId) : null;
        if ($distritoSel !== null) {
            $lat = (float)$distritoSel['lat'];
            $lon = (float)$distritoSel['lon'];
            $departamento = $distritoSel['region'];
        }

        $evaluacion = ($lat !== null && $lon !== null) ? zonificacion_evaluar($lat, $lon, $tipo) : null;
        $futuro = ($lat !== null && $lon !== null) ? zonificacion_evaluar_futuro($lat, $lon, $tipo, 1.2) : null;
        render('planificacion/planificacion', [
            'titulo' => 'Zonificación territorial',
            'ranking' => zonificacion_ranking(),
            'evaluacion' => $evaluacion,
            'futuro' => $futuro,
            'lat' => $lat,
            'lon' => $lon,
            'tipo' => $tipo,
            'departamento' => $departamento,
            'distritoId' => $distritoSel['id'] ?? '',
            'porRegion' => territorios_por_region(),
            'requiereMapa' => true,
        ]);
        break;

    case 'simulador':
        $t = territorio_por_id($_GET['id'] ?? 'piu-catacaos') ?? territorio_por_id('piu-catacaos');
        $factor = (float)($_GET['lluvia'] ?? 1.5);
        $delta = (float)($_GET['temp'] ?? 0.0);
        $ventana = (int)($_GET['ventana'] ?? 24);
        $modo = $_GET['modo'] ?? 'clima';
        $tipo = $_GET['tipo'] ?? 'colegio';

        if ($modo === 'evacuacion') {
            $simulacion = simular_evacuacion($t, $factor, $ventana);
        } elseif ($modo === 'reubicacion') {
            $latSitio = isset($_GET['lat']) ? (float)$_GET['lat'] : (float)$t['lat'];
            $lonSitio = isset($_GET['lon']) ? (float)$_GET['lon'] : (float)$t['lon'];
            $simulacion = simular_reubicacion($latSitio, $lonSitio, $tipo, $factor);
        } else {
            $simulacion = simular_clima($t, $factor, $delta, $ventana);
        }

        render('simulador/simulador', [
            'titulo' => 'Simulador de escenarios',
            'territorios' => territorios_todos(),
            't' => $t,
            'simulacion' => $simulacion,
            'factor' => $factor,
            'delta' => $delta,
            'ventana' => $ventana,
            'modo' => $modo,
            'tipo' => $tipo,
        ]);
        break;

    case 'reporte':
        $t = territorio_por_id($_GET['id'] ?? '');
        if (!$t) {
            redirigir('?action=home');
        }
        $ventana = (int)($_GET['ventana'] ?? 24);
        $r = riesgo_calcular($t, $ventana);
        $ise = zonificacion_ise_territorio($t);
        $clase = zonificacion_clase($ise);
        require __DIR__ . '/views/reportes/reporte.php';
        break;

    case 'admin':
        render('admin/panel', [
            'titulo' => 'Consola de administración',
            'cache' => cache_estadisticas(),
            'alertas' => alerta_estadisticas(),
            'territorios' => territorios_todos(),
            'snapshot' => riesgo_snapshot_leer(24),
            'snapshots' => riesgo_snapshots_disponibles(),
        ]);
        break;

    case 'ayuda':
        render('ayuda/ayuda', ['titulo' => 'Metodología y ayuda']);
        break;

    case 'home':
    default:
        $riesgos = riesgos_para_vista(24);
        render('home/home', [
            'titulo' => 'Sala de situación',
            'riesgos' => $riesgos,
            'resumen' => riesgo_resumen_nacional_para($riesgos),
            'alertas' => array_slice(alertas_todas(), 0, 5),
            'snapshot' => riesgo_snapshot_leer(24),
            'territorios' => territorios_todos(),
        ]);
        break;
}

/** Variante de riesgo_resumen_nacional que reutiliza la lista ya calculada. */
function riesgo_resumen_nacional_para($lista) {
    $conteo = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
    $poblacionExpuesta = 0;
    $escuelas = 0;
    $salud = 0;

    foreach ($lista as $r) {
        $conteo[$r['nivel']['id']] = ($conteo[$r['nivel']['id']] ?? 0) + 1;
        if (in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
            $poblacionExpuesta += $r['territorio']['poblacion'];
            $escuelas += $r['territorio']['escuelas'];
            $salud += $r['territorio']['centros_salud'];
        }
    }

    $orden = ['verde' => 0, 'amarillo' => 1, 'naranja' => 2, 'rojo' => 3];
    $nivelNacional = 'verde';
    foreach ($lista as $r) {
        if ($orden[$r['nivel']['id']] > $orden[$nivelNacional]) {
            $nivelNacional = $r['nivel']['id'];
        }
    }

    return [
        'total' => count($lista),
        'conteo' => $conteo,
        'nivel_nacional' => $nivelNacional,
        // Claves renombradas (mismo cambio que en models/Riesgo.php): suman
        // el TOTAL de los distritos en riesgo alto, no la fracción expuesta.
        'poblacion_total_riesgo_alto'       => $poblacionExpuesta,
        'escuelas_totales_riesgo_alto'      => $escuelas,
        'centros_salud_totales_riesgo_alto' => $salud,
        'peor' => $lista[0] ?? null,
    ];
}
