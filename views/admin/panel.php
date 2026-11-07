<?php
/**
 * VISTA — CONSOLA DE ADMINISTRACIÓN
 *
 * Solo accesible con rol admin. Concentra el estado técnico que el jurado
 * suele preguntar: ¿de dónde salen los datos?, ¿qué pasa si se cae internet?,
 * ¿cuánto tarda?, ¿quién puede emitir?
 */
if (!usuario_es_admin()) {
    echo '<div class="wq-panel-dark p-8 text-center max-w-lg mx-auto mt-10">'
        . '<i class="fa-solid fa-lock text-2xl text-slate-600 mb-3 block"></i>'
        . '<h2 class="wq-title text-xl mb-2">Acceso restringido</h2>'
        . '<p class="text-[0.8rem] text-slate-400 mb-4">Esta consola requiere rol de administrador.</p>'
        . '<button type="button" onclick="document.getElementById(\'wq-login\').classList.remove(\'hidden\')" class="btn btn--primary">Ingresar</button>'
        . '<div class="mt-3 text-[0.66rem] text-slate-600 font-mono">admin@willaq.pe · willaq2026</div>'
        . '</div>';
    return;
}

$db = function_exists('conectar_db') ? conectar_db() : null;
$dbMotor = function_exists('db_motor_nombre') ? db_motor_nombre() : 'PostgreSQL';
$modoOffline = willaq_env_bool('WILLAQ_MODO_OFFLINE');
$dirs = [
    'data/cache' => is_dir(__DIR__ . '/../../data/cache') && is_writable(__DIR__ . '/../../data/cache'),
    'data/snapshots' => is_dir(__DIR__ . '/../../data/snapshots') && is_writable(__DIR__ . '/../../data/snapshots'),
    'data/' => is_writable(__DIR__ . '/../../data'),
];
// Extensiones REQUERIDAS por el código (se usan en models/ y scripts/).
// `pdo_pgsql` es necesaria solo para las cuentas de usuario y el historial de
// alertas; sin ella el sistema entra en modo demostración y sigue funcionando.
$extensiones = ['curl', 'json', 'mbstring'];
$extensionesOpcionales = ['pdo_pgsql', 'pdo_mysql'];
$snap = $snapshot ?? null;
$snapshots = $snapshots ?? [];
$nTerritorios = count($territorios ?? []);
$snapRiesgos = is_array($snap) ? count($snap['riesgos'] ?? []) : 0;

// Resumen del overlay de exposición oficial (procedencia campo por campo).
$expResumen = exposicion_resumen();

// Cobertura de estaciones hidrometeorológicas (fase 1: registro + series).
// `cumple_medible` y `habilitada` se muestran SEPARADOS a propósito: una serie
// puede cumplir los criterios medibles y seguir sin habilitar la fase 2.
$estResumen = function_exists('estacion_resumen') ? estacion_resumen() : null;
$estTablero = function_exists('estacion_tablero') ? estacion_tablero() : [];
$estConSerie = array_values(array_filter($estTablero, function ($f) {
    return !empty($f['serie_cargada']);
}));
$estPrioridadEtiqueta = [
    'critica' => 'crítica',
    'alta'    => 'alta',
    'media'   => 'media',
    'baja'    => 'baja',
];
?>

<header class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
    <div>
        <div class="wq-eyebrow mb-2">Consola</div>
        <h1 class="wq-title text-[2rem] lg:text-[2.6rem] leading-none">Administración</h1>
        <p class="text-[0.82rem] text-slate-400 mt-2">
            Estado de fuentes, caché, snapshots y permisos. Visible solo para el rol
            <strong class="text-white">admin</strong>.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e(url('actualizar_datos')) ?>" class="btn btn--primary !py-2 !px-4 !text-[0.66rem]">
            <i class="fa-solid fa-rotate mr-2"></i> Recalcular snapshot
        </a>
        <a href="<?= e(url('home')) ?>" class="btn btn--outline !py-2 !px-4 !text-[0.66rem]">
            Volver a la sala
        </a>
    </div>
</header>

<!-- KPIs técnicos -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-6">
    <div class="wq-kpi <?= $db ? 'wq-kpi--verde' : 'wq-kpi--amarillo' ?>">
        <div class="wq-kpi__value text-[1.4rem] <?= $db ? 'text-risk-green' : 'text-risk-yellow' ?>">
            <?= $db ? e($dbMotor) : 'JSON' ?>
        </div>
        <div class="wq-kpi__label">Persistencia</div>
        <div class="text-[0.62rem] text-slate-600 mt-1">
            <?= $db ? 'Base de datos conectada' : 'Modo demostración (archivos JSON)' ?>
        </div>
    </div>
    <div class="wq-kpi wq-kpi--<?= $modoOffline ? 'amarillo' : 'verde' ?>">
        <div class="wq-kpi__value text-[1.4rem]"><?= $modoOffline ? 'OFFLINE' : 'ONLINE' ?></div>
        <div class="wq-kpi__label">Modo de datos</div>
        <div class="text-[0.62rem] text-slate-600 mt-1"><?= $modoOffline ? 'Snapshot precalculado' : 'Consultas en vivo con caché' ?></div>
    </div>
    <div class="wq-kpi">
        <div class="wq-kpi__value"><?= (int)($cache['archivos'] ?? 0) ?></div>
        <div class="wq-kpi__label">Ítems en caché</div>
        <div class="text-[0.62rem] text-slate-600 mt-1">
            <?= (int)($cache['vigentes'] ?? 0) ?> vigentes · <?= (int)($cache['expirados'] ?? 0) ?> expirados
        </div>
    </div>
    <div class="wq-kpi">
        <div class="wq-kpi__value"><?= number_format((float)($cache['mb'] ?? 0), 2) ?><span class="text-sm text-slate-500"> MB</span></div>
        <div class="wq-kpi__label">Peso de caché</div>
        <div class="text-[0.62rem] text-slate-600 mt-1"><?= $nTerritorios ?> distritos monitoreados</div>
    </div>
</section>

<div class="grid grid-cols-1 xl:grid-cols-[1.35fr_1fr] gap-5">

    <div class="space-y-5">

        <!-- Snapshot -->
        <section class="wq-card">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Snapshot de riesgo nacional</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">
                        Archivo precalculado que permite operar la feria sin internet
                    </p>
                </div>
                <span class="wq-chip">data/snapshots/riesgo_nacional_{24,48,72}h.json</span>
            </div>
            <div class="wq-card__body">
                <?php if (!empty($snapshots)): ?>
                    <div class="space-y-2.5">
                        <?php foreach ($snapshots as $v => $s): ?>
                            <?php
                            $conteo = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
                            foreach (($s['riesgos'] ?? []) as $r) {
                                $conteo[$r['nivel']['id']] = ($conteo[$r['nivel']['id']] ?? 0) + 1;
                            }
                            ?>
                            <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.06] text-[0.74rem]">
                                <div class="flex items-center gap-3">
                                    <span class="wq-kbd"><?= (int)$v ?> h</span>
                                    <span class="text-slate-400"><?= e(hace($s['generado_en'] ?? 0)) ?></span>
                                    <span class="text-slate-600">·</span>
                                    <span class="text-slate-400"><?= count($s['riesgos'] ?? []) ?> distritos</span>
                                </div>
                                <div class="flex items-center gap-2 text-[0.68rem]">
                                    <?php if ($conteo['rojo'] > 0): ?>
                                        <span class="text-risk-red"><?= (int)$conteo['rojo'] ?> muy alto</span>
                                    <?php endif; ?>
                                    <?php if ($conteo['naranja'] > 0): ?>
                                        <span class="text-risk-orange"><?= (int)$conteo['naranja'] ?> alto</span>
                                    <?php endif; ?>
                                    <?php if ($conteo['amarillo'] > 0): ?>
                                        <span class="text-risk-yellow"><?= (int)$conteo['amarillo'] ?> medio</span>
                                    <?php endif; ?>
                                    <?php if ($conteo['verde'] > 0): ?>
                                        <span class="text-slate-400"><?= (int)$conteo['verde'] ?> bajo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="mt-3 text-[0.68rem] text-slate-500">
                        Un archivo por ventana: el modo offline puede responder con cualquiera
                        de las tres sin recalcular.
                    </p>
                <?php elseif ($snap): ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.74rem]">
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Generado</div>
                            <div class="text-white"><?= e(fecha_legible($snap['generado_en'] ?? 0)) ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Ventana</div>
                            <div class="text-white"><?= (int)($snap['ventana'] ?? 24) ?> h</div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Distritos</div>
                            <div class="text-white"><?= $snapRiesgos ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Antigüedad</div>
                            <div class="text-white"><?= e(hace($snap['generado_en'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <?php if ($snapRiesgos > 0 && isset($snap['riesgos'][0])): ?>
                        <div class="mt-4 pt-4 border-t border-white/[0.07] text-[0.72rem] text-slate-400">
                            Distrito con mayor riesgo:
                            <strong class="text-white"><?= e($snap['riesgos'][0]['territorio']['distrito'] ?? '—') ?></strong>
                            (<?= (int)round($snap['riesgos'][0]['riesgo'] ?? 0) ?>/100).
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="flex items-start gap-3 text-[0.76rem] text-slate-400">
                        <i class="fa-solid fa-circle-info text-brand-gold/70 mt-0.5"></i>
                        <p>
                            No existe snapshot. Genere uno con
                            <span class="wq-kbd">php scripts/actualizar.php</span>
                            o desde el botón <em>Recalcular snapshot</em>.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="px-5 py-3 border-t border-white/[0.06] flex flex-wrap gap-2">
                <a href="<?= e(url('actualizar_datos', ['ventana' => 24])) ?>" class="wq-chip hover:border-brand-gold/50">
                    <i class="fa-solid fa-clock text-[0.6rem]"></i> Recalcular 24 h
                </a>
                <a href="<?= e(url('actualizar_datos', ['ventana' => 48])) ?>" class="wq-chip hover:border-brand-gold/50">
                    <i class="fa-solid fa-clock text-[0.6rem]"></i> Recalcular 48 h
                </a>
                <a href="<?= e(url('actualizar_datos', ['ventana' => 72])) ?>" class="wq-chip hover:border-brand-gold/50">
                    <i class="fa-solid fa-clock text-[0.6rem]"></i> Recalcular 72 h
                </a>
            </div>
        </section>

        <!-- Caché -->
        <section class="wq-card">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Caché multinivel</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">
                        Memoria → archivo JSON → respaldo expirado
                    </p>
                </div>
            </div>
            <div class="wq-card__body">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.74rem] mb-4">
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Archivos</div>
                        <div class="text-white"><?= (int)($cache['archivos'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Vigentes</div>
                        <div class="text-risk-green"><?= (int)($cache['vigentes'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Expirados</div>
                        <div class="text-risk-yellow"><?= (int)($cache['expirados'] ?? 0) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Peso</div>
                        <div class="text-white"><?= number_format((float)($cache['mb'] ?? 0), 2) ?> MB</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="<?= e(url('limpiar_cache')) ?>"
                       onclick="return confirm('¿Eliminar TODA la caché? La siguiente carga será más lenta.');"
                       class="btn btn--outline !py-1.5 !px-3 !text-[0.62rem]">
                        Limpiar todo
                    </a>
                    <a href="<?= e(url('limpiar_cache', ['patron' => 'forecast'])) ?>"
                       class="wq-chip hover:border-brand-gold/50">forecast</a>
                    <a href="<?= e(url('limpiar_cache', ['patron' => 'flood'])) ?>"
                       class="wq-chip hover:border-brand-gold/50">flood</a>
                    <a href="<?= e(url('limpiar_cache', ['patron' => 'archivo'])) ?>"
                       class="wq-chip hover:border-brand-gold/50">archivo</a>
                    <a href="<?= e(url('limpiar_cache', ['patron' => 'climatologia'])) ?>"
                       class="wq-chip hover:border-brand-gold/50">climatología</a>
                </div>

                <p class="wq-legal mt-4">
                    La primera carga sin caché realiza ~90 llamadas a Open-Meteo y puede tardar
                    más de un minuto. Ejecute <span class="wq-kbd">php scripts/actualizar.php</span>
                    antes de la demostración para dejarla precalentada.
                </p>
            </div>
        </section>

        <!-- Territorios -->
        <section class="wq-card">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Catálogo de territorios</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">
                        <?= $nTerritorios ?> distritos piloto · editar en <span class="wq-kbd">config/territorios.json</span>
                    </p>
                </div>
            </div>
            <div class="max-h-[420px] overflow-y-auto wq-scroll">
                <table class="wq-table">
                    <thead class="sticky top-0 bg-[#0d1a2d] z-10">
                        <tr>
                            <th class="!pl-5">Distrito</th>
                            <th>Región</th>
                            <th>Zona</th>
                            <th class="text-right">Población</th>
                            <th class="!pr-5 text-right">Ficha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($territorios as $ter): ?>
                            <tr>
                                <td class="!pl-5 text-white/90"><?= e($ter['distrito']) ?></td>
                                <td class="text-[0.74rem] text-slate-400"><?= e($ter['region']) ?></td>
                                <td class="text-[0.66rem] text-slate-500"><?= e(str_replace('_', ' ', $ter['zona'])) ?></td>
                                <td class="text-right text-[0.74rem] text-slate-300"><?= numero_legible($ter['poblacion']) ?></td>
                                <td class="!pr-5 text-right">
                                    <a href="<?= e(url('territorio', ['id' => $ter['id']])) ?>"
                                       class="text-[0.64rem] uppercase tracking-[0.12em] text-brand-gold hover:text-brand-goldlight">
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Procedencia de exposición -->
        <section class="wq-card">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Procedencia de exposición</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">
                        De dónde sale cada total de población, viviendas, escuelas y centros de salud
                    </p>
                </div>
                <?php if ($expResumen['con_datos_oficiales'] > 0): ?>
                    <span class="wq-chip wq-chip--verde"><?= (int)$expResumen['con_datos_oficiales'] ?> distritos con dato oficial</span>
                <?php else: ?>
                    <span class="wq-chip">sin overlay</span>
                <?php endif; ?>
            </div>
            <div class="wq-card__body">
                <?php if ($expResumen['con_datos_oficiales'] === 0): ?>
                    <div class="flex items-start gap-3 text-[0.76rem] text-slate-400">
                        <i class="fa-solid fa-circle-info text-brand-gold/70 mt-0.5"></i>
                        <div>
                            <p class="mb-2">
                                No hay datos oficiales cargados. Los 29 distritos usan hoy la
                                <strong class="text-white">estimación de referencia del catálogo piloto</strong>
                                (<span class="wq-kbd">estimacion_ratio_poblacional</span>).
                            </p>
                            <p class="text-slate-500">
                                Para cargar datos de INEI, MINEDU o MINSA, importe un CSV con el
                                importador. Sin <span class="wq-kbd">--aplicar</span> solo revisa:
                            </p>
                            <pre class="mt-2 p-3 rounded-lg bg-black/30 border border-white/[0.06] text-[0.66rem] text-slate-300 overflow-x-auto">php scripts/importar_exposicion.php ARCHIVO.csv
php scripts/importar_exposicion.php ARCHIVO.csv --aplicar</pre>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.74rem] mb-4">
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Overlay</div>
                            <div class="text-white">v<?= (int)($expResumen['version'] ?? 0) ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Territorios</div>
                            <div class="text-white"><?= (int)$expResumen['con_datos_oficiales'] ?> de <?= $nTerritorios ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Generado</div>
                            <div class="text-white"><?= e($expResumen['generado_en'] ? fecha_legible(strtotime($expResumen['generado_en'])) : '—') ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Campos oficiales</div>
                            <div class="text-white"><?= array_sum($expResumen['por_campo'] ?? []) ?></div>
                        </div>
                    </div>

                    <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5">Campos por indicador</div>
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        <?php foreach (($expResumen['por_campo'] ?? []) as $campo => $n): ?>
                            <span class="wq-chip <?= $n > 0 ? 'wq-chip--verde' : '' ?>">
                                <?= e(exposicion_campo_etiqueta($campo)) ?> · <?= (int)$n ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($expResumen['archivos'])): ?>
                        <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5">Archivos importados</div>
                        <div class="space-y-2 mb-4">
                            <?php foreach ($expResumen['archivos'] as $arch): ?>
                                <div class="px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.06] text-[0.72rem]">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-white font-mono text-[0.68rem]"><?= e($arch['archivo'] ?? '?') ?></span>
                                        <span class="text-slate-500 text-[0.64rem]">
                                            <?= (int)($arch['aceptadas'] ?? 0) ?> aceptadas · <?= (int)($arch['rechazadas'] ?? 0) ?> rechazadas
                                        </span>
                                    </div>
                                    <div class="text-[0.6rem] text-slate-600 font-mono mt-1 break-all">
                                        sha256 <?= e($arch['sha256'] ?? '—') ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5">Fuentes declaradas</div>
                    <div class="space-y-2">
                        <?php foreach (($expResumen['fuentes'] ?? []) as $fuente): ?>
                            <div class="px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.06] text-[0.72rem]">
                                <div class="text-white"><?= e($fuente['fuente']) ?></div>
                                <div class="text-[0.62rem] text-slate-500 mt-0.5">
                                    <?= (int)$fuente['campos'] ?> campos · <?= e(implode(', ', $fuente['archivos'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="wq-legal mt-4">
                        El total puede ser oficial; la fracción expuesta sigue siendo una estimación
                        (<span class="wq-kbd">total × susceptibilidad</span>). Borrar
                        <span class="wq-kbd">data/exposicion_oficial.json</span> devuelve el sistema a
                        las estimaciones del catálogo piloto.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Cobertura de estaciones -->
        <section class="wq-card">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Cobertura de estaciones</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">
                        Registro SENAMHI, series descargadas con Garúa y estado de la compuerta de la fase 2
                    </p>
                </div>
                <?php if ($estResumen !== null && $estResumen['con_serie'] > 0): ?>
                    <span class="wq-chip wq-chip--verde"><?= (int)$estResumen['con_serie'] ?> series cargadas</span>
                <?php else: ?>
                    <span class="wq-chip">sin series</span>
                <?php endif; ?>
            </div>
            <div class="wq-card__body">
                <?php if ($estResumen === null): ?>
                    <p class="text-[0.76rem] text-slate-400">
                        No se pudo cargar la capa de estaciones. El motor de riesgo sigue funcionando
                        sin ellas.
                    </p>
                <?php else: ?>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.74rem] mb-4">
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Registro</div>
                            <div class="text-white"><?= (int)$estResumen['total_estaciones'] ?> estaciones</div>
                            <div class="text-[0.6rem] text-slate-600 mt-0.5">
                                <?= (int)$estResumen['distritos_cubiertos'] ?> de <?= $nTerritorios ?> distritos cubiertos
                            </div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Con serie</div>
                            <div class="text-white"><?= (int)$estResumen['con_serie'] ?> de <?= (int)$estResumen['total_estaciones'] ?></div>
                            <div class="text-[0.6rem] text-slate-600 mt-0.5">
                                <?= (int)$estResumen['sin_serie'] ?> sin descargar
                            </div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Criterios medibles</div>
                            <div class="text-white"><?= (int)$estResumen['cumplen_criterios_medibles'] ?></div>
                            <div class="text-[0.6rem] text-slate-600 mt-0.5">
                                ≥<?= (int)$estResumen['compuerta']['anios_minimos'] ?> años y ≤<?= (int)$estResumen['compuerta']['faltantes_max_pct'] ?> % faltantes
                            </div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Habilitadas fase 2</div>
                            <div class="text-white"><?= (int)$estResumen['habilitadas'] ?></div>
                            <div class="text-[0.6rem] text-slate-600 mt-0.5">
                                motor_usa_estaciones = false
                            </div>
                        </div>
                    </div>

                    <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5">Prioridad de descarga</div>
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        <?php foreach (['critica', 'alta', 'media', 'baja'] as $prio): ?>
                            <span class="wq-chip">
                                <?= e($estPrioridadEtiqueta[$prio]) ?> · <?= (int)($estResumen['por_prioridad'][$prio] ?? 0) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$estConSerie): ?>
                        <div class="flex items-start gap-3 text-[0.76rem] text-slate-400">
                            <i class="fa-solid fa-circle-info text-brand-gold/70 mt-0.5"></i>
                            <div>
                                <p class="mb-2">
                                    El registro está armado, pero todavía no hay series normalizadas en
                                    <span class="wq-kbd">data/estaciones/</span>.
                                </p>
                                <pre class="mt-2 p-3 rounded-lg bg-black/30 border border-white/[0.06] text-[0.66rem] text-slate-300 overflow-x-auto">bash scripts/descargar_estaciones_garua.sh --critica
php scripts/importar_estaciones.php --garua --aplicar</pre>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="wq-table w-full text-[0.72rem]">
                                <thead>
                                    <tr>
                                        <th>Estación</th>
                                        <th>Prioridad</th>
                                        <th>Serie</th>
                                        <th class="text-right">Días</th>
                                        <th class="text-right">Faltantes</th>
                                        <th class="text-right">Años</th>
                                        <th>Criterios</th>
                                        <th>Fase 2</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estConSerie as $f): ?>
                                        <tr>
                                            <td>
                                                <div class="text-white"><?= e($f['nombre']) ?></div>
                                                <div class="text-[0.62rem] text-slate-500 font-mono">
                                                    <?= e($f['cod']) ?> · <?= e($f['tipo']) ?>
                                                    <?php if ($f['altitud_msnm'] !== null): ?>
                                                        · <?= (int)$f['altitud_msnm'] ?> msnm
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="wq-chip <?= $f['prioridad'] === 'critica' ? 'wq-chip--ambar' : '' ?>">
                                                    <?= e($estPrioridadEtiqueta[$f['prioridad']] ?? $f['prioridad']) ?>
                                                </span>
                                            </td>
                                            <td class="font-mono text-[0.66rem] text-slate-400">
                                                <?= e($f['primera_fecha'] ?? '—') ?> → <?= e($f['ultima_fecha'] ?? '—') ?>
                                            </td>
                                            <td class="text-right text-slate-300"><?= $f['dias_con_dato'] !== null ? number_format((int)$f['dias_con_dato']) : '—' ?></td>
                                            <td class="text-right <?= ($f['pct_faltantes'] !== null && $f['pct_faltantes'] > 10) ? 'text-amber-300' : 'text-slate-300' ?>">
                                                <?= $f['pct_faltantes'] !== null ? number_format((float)$f['pct_faltantes'], 1, ',', '.') . ' %' : '—' ?>
                                            </td>
                                            <td class="text-right text-slate-300">
                                                <span title="Años calendario tocados por la serie"><?= $f['anios_cobertura'] !== null ? number_format((float)$f['anios_cobertura'], 0, ',', '.') : '—' ?></span>
                                                <?php if ($f['anios_span'] !== null): ?>
                                                    <div class="text-[0.56rem] text-slate-600" title="Extensión real = días calendario / 365,25. Años completos = años con ≤10 % de días faltantes.">
                                                        <?= number_format((float)$f['anios_span'], 1, ',', '.') ?> real · <?= (int)$f['anios_completos'] ?> compl.
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($f['pasa_compuerta'])): ?>
                                                    <span class="wq-proc wq-proc--oficial" title="Cumple los criterios medibles; NO está habilitada para el motor.">cumple</span>
                                                <?php else: ?>
                                                    <span class="wq-proc wq-proc--estimada" title="<?= e(implode(' ', $f['motivos'] ?? [])) ?>">no cumple</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="wq-chip" title="La fase 2 exige medir falsos positivos en el backtest.">bloqueada</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <p class="wq-legal mt-4">
                            <strong class="text-slate-300">Cumplir los criterios medibles no habilita la fase 2.</strong>
                            El motor de riesgo sigue sin usar estas series
                            (<span class="wq-kbd">motor_usa_estaciones = false</span>) hasta medir
                            falsos positivos en el backtest sobre los casos ya detectados.
                            La precipitación de la estación es una medición; su traslado a un
                            distrito sigue siendo una estimación con supuestos declarados.
                        </p>
                    <?php endif; ?>

                    <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5 mt-4">Fuentes del registro</div>
                    <div class="space-y-1.5">
                        <?php foreach (($estResumen['fuentes'] ?? []) as $fuente): ?>
                            <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.06] text-[0.7rem]">
                                <span class="text-slate-300"><?= e($fuente['fuente']) ?></span>
                                <span class="text-slate-500 text-[0.64rem]"><?= (int)$fuente['estaciones'] ?> estaciones</span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="text-[0.62rem] text-slate-600 font-mono mt-3 break-all">
                        catálogo sha256 <?= e($estResumen['catalogo_sha256'] ?: '—') ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>    </div>

    <!-- Columna lateral -->
    <div class="space-y-5">

        <!-- Entorno -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Entorno de ejecución</h2>
                <i class="fa-solid fa-server text-brand-gold/60"></i>
            </div>
            <div class="wq-card__body space-y-2.5 text-[0.72rem]">
                <div class="flex justify-between">
                    <span class="text-slate-400">PHP</span>
                    <span class="text-white font-mono"><?= e(PHP_VERSION) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Servidor</span>
                    <span class="text-white font-mono text-[0.66rem]"><?= e($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Zona horaria</span>
                    <span class="text-white font-mono text-[0.66rem]"><?= e(date_default_timezone_get()) ?></span>
                </div>
                <div class="wq-divider"></div>
                <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500">Extensiones</div>
                <div class="flex flex-wrap gap-1.5 mb-1.5">
                    <?php foreach ($extensiones as $ext): ?>
                        <?php $ok = extension_loaded($ext); ?>
                        <span class="wq-chip <?= $ok ? '!border-risk-green/30 !text-risk-green' : '!border-risk-red/30 !text-risk-red' ?>">
                            <span class="wq-dot wq-dot--<?= $ok ? 'verde' : 'rojo' ?>" style="width:.4rem;height:.4rem"></span>
                            <?= e($ext) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <div class="text-[0.56rem] text-slate-600 mb-1">Opcionales</div>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach ($extensionesOpcionales as $ext): ?>
                        <?php $ok = extension_loaded($ext); ?>
                        <span class="wq-chip <?= $ok ? '!border-risk-green/30 !text-risk-green' : '!border-white/10 !text-slate-500' ?>">
                            <span class="wq-dot wq-dot--<?= $ok ? 'verde' : 'gris' ?>" style="width:.4rem;height:.4rem"></span>
                            <?= e($ext) ?>
                        </span>
                    <?php endforeach; ?>
                    <span class="text-[0.6rem] text-slate-600 self-center">
                        (sin <span class="font-mono">pdo_pgsql</span> el sistema funciona en modo demostración)
                    </span>
                </div>
                <div class="wq-divider"></div>
                <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500">Directorios escribibles</div>
                <?php foreach ($dirs as $ruta => $ok): ?>
                    <div class="flex justify-between">
                        <span class="text-slate-400 font-mono text-[0.66rem]"><?= e($ruta) ?></span>
                        <span class="<?= $ok ? 'text-risk-green' : 'text-risk-red' ?>">
                            <?= $ok ? 'sí' : 'no' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
                <p class="text-[0.64rem] text-slate-600 leading-relaxed pt-2">
                    Los directorios deben tener permiso de escritura para guardar caché y snapshot.
                </p>
            </div>
        </section>

        <!-- Roles -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Roles y permisos</h2>
                <i class="fa-solid fa-user-shield text-brand-gold/60"></i>
            </div>
            <div class="wq-card__body space-y-3 text-[0.72rem]">
                <div>
                    <div class="text-white mb-1">Ciudadano</div>
                    <p class="text-slate-400 leading-relaxed">Consulta mapa, fichas, alertas y simulador. No emite recomendaciones.</p>
                </div>
                <div class="wq-divider"></div>
                <div>
                    <div class="text-white mb-1">Autoridad</div>
                    <p class="text-slate-400 leading-relaxed">Todo lo anterior + emitir recomendaciones y registrar acuses.</p>
                </div>
                <div class="wq-divider"></div>
                <div>
                    <div class="text-white mb-1">Administrador</div>
                    <p class="text-slate-400 leading-relaxed">Todo lo anterior + consola, caché y snapshots.</p>
                </div>
                <div class="pt-2">
                    <div class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500 mb-1.5">Cuentas demo</div>
                    <div class="font-mono text-[0.66rem] text-slate-400 space-y-0.5">
                        <div>admin@willaq.pe</div>
                        <div>defensa@willaq.pe</div>
                        <div>ciudadano@willaq.pe</div>
                        <div class="text-slate-600 pt-1">willaq2026</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- API -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">API pública</h2>
                <span class="wq-chip">JSON</span>
            </div>
            <div class="wq-card__body space-y-2 text-[0.7rem]">
                <?php foreach ([
                    ['api_territorios', 'Catálogo de distritos', null],
                    ['api_riesgo', 'Riesgo nacional o por distrito', 'id'],
                    ['api_simular', 'Escenario climático / evacuación', 'id'],
                    ['api_evaluar', 'Evaluar un sitio (lat, lon)', 'lat'],
                ] as [$endpoint, $desc, $param]): ?>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a class="wq-link font-mono text-[0.66rem]" target="_blank" rel="noopener"
                               href="<?= e(url($endpoint, $param === 'id' ? ['id' => $territorios[0]['id'] ?? ''] : ($param === 'lat' ? ['lat' => -5.2667, 'lon' => -80.6833] : []))) ?>">
                                ?action=<?= e($endpoint) ?>
                            </a>
                            <div class="text-[0.62rem] text-slate-500 mt-0.5"><?= e($desc) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="text-[0.64rem] text-slate-600 leading-relaxed pt-2 border-t border-white/[0.06]">
                    Sin autenticación y con CORS abierto: cualquier sistema de la región puede
                    consumir los índices.
                </p>
            </div>
        </section>

        <!-- Base de datos -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Base de datos</h2>
                <span class="wq-chip">opcional</span>
            </div>
            <div class="wq-card__body text-[0.72rem] text-slate-400 space-y-2">
                <p>
                    WILLAQ funciona sin base de datos. El motor principal es
                    <strong class="text-white">PostgreSQL</strong>: el esquema está en
                    <span class="wq-kbd">database/schema_postgresql.sql</span> y se activa
                    definiendo <span class="wq-kbd">DB_DRIVER</span>,
                    <span class="wq-kbd">DB_HOST</span>, <span class="wq-kbd">DB_NAME</span>,
                    <span class="wq-kbd">DB_USER</span> y <span class="wq-kbd">DB_PASS</span>.
                    La guía está en <span class="wq-kbd">database/README_POSTGRESQL.md</span>.
                </p>
                <p>
                    Estado actual:
                    <strong class="<?= $db ? 'text-risk-green' : 'text-risk-yellow' ?>">
                        <?= $db ? 'conectado (' . e($dbMotor) . ')' : 'usando JSON' ?>
                    </strong>.
                </p>
            </div>
        </section>
    </div>
</div>
