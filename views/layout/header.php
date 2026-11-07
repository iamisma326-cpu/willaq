<?php
/**
 * LAYOUT — CABECERA
 * Hereda la identidad del proyecto original (azul estratósfera + dorado +
 * Montserrat) y la adapta a una sala de operaciones.
 *
 * Las librerías críticas (Tailwind, MapLibre, Chart.js) se cargan en local para
 * que la demo funcione SIN INTERNET. Los iconos son decorativos y vienen de un
 * CDN: si no hay red, cada acción conserva su texto y sigue siendo legible.
 */
$accionActual = $accionActual ?? ($GLOBALS['accion'] ?? 'home');
$titulo = $titulo ?? 'WILLAQ';
$sinContenedor = $sinContenedor ?? false;
$usuario = usuario_actual();
$modoOffline = willaq_env_bool('WILLAQ_MODO_OFFLINE');
$ventanaActual = (int)($_GET['ventana'] ?? 24);

$navItems = [
    'home'          => ['Sala de situación', 'fa-satellite-dish'],
    'mapa'          => ['Mapa', 'fa-map'],
    'alertas'       => ['Alertas', 'fa-bell'],
    'planificacion' => ['Planificar', 'fa-draw-polygon'],
    'simulador'     => ['Simular', 'fa-flask'],
    'ayuda'         => ['Ayuda', 'fa-book'],
];
if (usuario_es_admin()) {
    $navItems['admin'] = ['Consola', 'fa-sliders'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="WILLAQ — Inteligencia territorial y alerta temprana del Fenómeno El Niño. Vigilar, planificar, simular.">
    <meta name="theme-color" content="#0A1628">
    <title><?= e($titulo) ?> · WILLAQ</title>

    <!-- Tailwind local (funciona sin conexión) -->
    <script src="assets/vendor/tailwind.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            gold: '#C5A880',
                            goldlight: '#E7D3B0',
                            purple: '#48324F',
                            rose: '#9C694C',
                            blue: '#0A1628',
                            surface: '#132238',
                        },
                        risk: {
                            green: '#22c55e',
                            yellow: '#eab308',
                            orange: '#f97316',
                            red: '#ef4444',
                        }
                    },
                    fontFamily: {
                        sans: ['Montserrat', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Iconos (Font Awesome local vía CDN con fallback: los iconos son decorativos) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          onerror="document.documentElement.classList.add('sin-iconos')">

    <link rel="stylesheet" href="assets/css/core/variables.css">
    <link rel="stylesheet" href="assets/css/core/base.css">
    <link rel="stylesheet" href="assets/css/components/buttons.css">
    <link rel="stylesheet" href="assets/css/components/cards.css">
    <link rel="stylesheet" href="assets/css/components/forms.css">
    <link rel="stylesheet" href="assets/css/views/willaq.css">
    <link rel="icon" href="assets/img/logo-willaq.svg" type="image/svg+xml">

    <?php if (!empty($requiereMapa)): ?>
        <!-- MapLibre se carga ANTES del script de la vista: la vista lo usa al
             ejecutarse. Todo es local, así que funciona sin internet. -->
        <link rel="stylesheet" href="assets/vendor/maplibre-gl.css">
        <script src="assets/vendor/maplibre-gl.js"></script>
    <?php endif; ?>
</head>
<body class="font-sans text-white antialiased">

<!-- ===================== NAVEGACIÓN ===================== -->
<header class="wq-nav">
    <div class="max-w-[1600px] mx-auto px-4 lg:px-6">
        <div class="flex items-center justify-between h-16 gap-4">

            <!-- Marca -->
            <a href="<?= e(url('home')) ?>" class="flex items-center gap-3 shrink-0 group">
                <img src="assets/img/logo-willaq.svg" alt="" width="38" height="38"
                     class="rounded-xl transition-transform duration-300 group-hover:scale-105">
                <div class="leading-none">
                    <div class="text-[1.05rem] tracking-[0.30em] font-light text-white">WILLAQ</div>
                    <div class="text-[0.55rem] tracking-[0.22em] uppercase text-brand-gold/80 mt-1">
                        Vigilar · Planificar · Simular
                    </div>
                </div>
            </a>

            <!-- Navegación principal -->
            <nav class="hidden xl:flex items-center gap-7" aria-label="Navegación principal">
                <?php foreach ($navItems as $id => $item): ?>
                    <a href="<?= e(url($id)) ?>"
                       class="wq-nav__link <?= $accionActual === $id ? 'is-active' : '' ?>">
                        <?= e($item[0]) ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Estado y sesión -->
            <div class="flex items-center gap-3">
                <div class="hidden lg:flex items-center gap-2 text-[0.62rem] tracking-[0.16em] uppercase text-[#8a97ac]">
                    <span class="wq-dot wq-dot--<?= $modoOffline ? 'amarillo' : 'verde' ?>"></span>
                    <?= $modoOffline ? 'Modo offline' : 'Datos en línea' ?>
                </div>

                <?php if ($usuario): ?>
                    <div class="hidden sm:flex items-center gap-3 pl-3 border-l border-white/10">
                        <div class="text-right leading-tight">
                            <div class="text-[0.72rem] text-white/90"><?= e($usuario['nombre']) ?></div>
                            <div class="text-[0.58rem] uppercase tracking-[0.16em] text-brand-gold/80">
                                <?= e($usuario['rol']) ?>
                            </div>
                        </div>
                        <a href="<?= e(url('logout')) ?>"
                           class="wq-chip hover:border-brand-gold/50 hover:text-white" title="Cerrar sesión">
                            <i class="fa-solid fa-arrow-right-from-bracket text-[0.6rem]"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <button type="button" onclick="document.getElementById('wq-login').classList.remove('hidden')"
                            class="btn btn--primary !py-2 !px-4 !text-[0.66rem]">
                        Ingresar
                    </button>
                <?php endif; ?>

                <!-- Menú móvil -->
                <button type="button" aria-label="Abrir menú"
                        onclick="document.getElementById('wq-mobile-nav').classList.toggle('hidden')"
                        class="xl:hidden wq-chip !p-2">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Menú móvil -->
    <div id="wq-mobile-nav" class="hidden xl:hidden border-t border-white/10 bg-[#081120]">
        <nav class="max-w-[1600px] mx-auto px-4 py-3 flex flex-col gap-1">
            <?php foreach ($navItems as $id => $item): ?>
                <a href="<?= e(url($id)) ?>"
                   class="px-3 py-2 rounded-lg text-sm <?= $accionActual === $id ? 'bg-brand-gold/10 text-brand-gold' : 'text-white/75' ?>">
                    <?= e($item[0]) ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$usuario): ?>
                <button type="button"
                        onclick="document.getElementById('wq-login').classList.remove('hidden')"
                        class="mt-2 btn btn--primary">Ingresar</button>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- ===================== AVISOS ===================== -->
<?php
$avisos = [];
if (isset($_GET['login'])) {
    $avisos[] = match ($_GET['login']) {
        'success' => ['ok', 'Sesión iniciada correctamente.'],
        'error' => ['error', 'Credenciales incorrectas. Use una cuenta de demostración si no hay base de datos.'],
        'required' => ['warn', 'Esa acción requiere iniciar sesión con rol de autoridad o administrador.'],
        default => null,
    };
}
if (isset($_GET['registro'])) {
    $avisos[] = match ($_GET['registro']) {
        'success' => ['ok', 'Cuenta creada. Bienvenido a WILLAQ.'],
        default => ['error', 'No se pudo completar el registro.'],
    };
}
if (isset($_GET['actualizado'])) {
    $avisos[] = $_GET['actualizado'] === 'ok'
        ? ['ok', 'Snapshot de riesgo recalculado con los datos más recientes.']
        : ['error', 'No se pudo recalcular el snapshot.'];
}
if (isset($_GET['acuse'])) {
    $avisos[] = ['ok', 'Acuse de recibo registrado.'];
}
if (isset($_GET['cache'])) {
    $avisos[] = ['ok', 'Se eliminaron ' . (int)$_GET['cache'] . ' archivos de caché.'];
}
$avisos = array_values(array_filter($avisos));
?>
<?php if ($avisos): ?>
    <div class="max-w-[1600px] mx-auto px-4 lg:px-6 pt-4 no-print">
        <?php foreach ($avisos as [$tipo, $texto]): ?>
            <?php
            $clases = [
                'ok' => 'border-risk-green/40 bg-risk-green/10 text-risk-green',
                'warn' => 'border-risk-yellow/40 bg-risk-yellow/10 text-risk-yellow',
                'error' => 'border-risk-red/40 bg-risk-red/10 text-risk-red',
            ][$tipo] ?? 'border-white/20 bg-white/5 text-white';
            ?>
            <div class="mb-2 px-4 py-2.5 rounded-lg border text-[0.8rem] <?= $clases ?>">
                <?= e($texto) ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($modoOffline): ?>
    <div class="bg-risk-yellow/10 border-y border-risk-yellow/25 no-print">
        <div class="max-w-[1600px] mx-auto px-4 lg:px-6 py-1.5 flex items-center gap-2 text-[0.7rem] text-risk-yellow">
            <i class="fa-solid fa-plug-circle-xmark"></i>
            <span><strong>Modo feria offline.</strong> Se muestran snapshots precalculados. Los valores no reflejan el clima en tiempo real.</span>
        </div>
    </div>
<?php endif; ?>

<!-- ===================== CONTENIDO ===================== -->
<?php if ($sinContenedor): ?>
<main class="relative">
<?php else: ?>
<main class="relative z-10 max-w-[1600px] mx-auto px-4 lg:px-6 py-8 pb-20">
<?php endif; ?>
