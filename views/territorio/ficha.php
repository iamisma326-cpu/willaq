<?php
/**
 * VISTA — FICHA DE DISTRITO CON EXPLICABILIDAD
 * Es la pantalla que responde "¿por qué este distrito está en rojo?".
 */
$n = $r['nivel']['id'];
$principal = $r['amenazas'][$r['principal']] ?? reset($r['amenazas']);

$iconos = [
    'lluvia' => 'fa-cloud-showers-heavy',
    'inundacion' => 'fa-water',
    'huaico' => 'fa-mountain',
    'sequia' => 'fa-sun',
    'calor' => 'fa-temperature-high',
    'agro' => 'fa-seedling',
];

$etiquetasNivel = ['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'];

// Serie del gráfico (7 días)
$serieDias = $forecast['dias'] ?? [];
$serieLluvia = $forecast['precipitacion'] ?? [];
$serieTemp = $forecast['stmax'] ?? [];
$umbralLluvia = $r['datos']['lluvia_threshold_p95'] ?? null;

$labels = [];
$datosLluvia = [];
$datosTemp = [];
foreach ($serieDias as $i => $fecha) {
    $labels[] = date('d/m', strtotime($fecha));
    $datosLluvia[] = round((float)($serieLluvia[$i] ?? 0), 1);
    $datosTemp[] = isset($serieTemp[$i]) ? round((float)$serieTemp[$i], 1) : null;
}
?>

<!-- ===================== CABECERA ===================== -->
<nav class="flex items-center gap-2 text-[0.66rem] uppercase tracking-[0.14em] text-slate-500 mb-4">
    <a href="<?= e(url('home')) ?>" class="hover:text-brand-gold">Sala de situación</a>
    <span class="text-slate-700">/</span>
    <a href="<?= e(url('mapa')) ?>" class="hover:text-brand-gold">Mapa</a>
    <span class="text-slate-700">/</span>
    <span class="text-slate-300"><?= e($t['distrito']) ?></span>
</nav>

<header class="flex flex-col lg:flex-row lg:items-end justify-between gap-5 mb-6">
    <div>
        <div class="flex items-center gap-2.5 mb-2">
            <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
            <span class="wq-eyebrow"><?= e($t['region']) ?> · <?= e($t['provincia']) ?></span>
        </div>
        <h1 class="wq-title text-[2rem] lg:text-[2.6rem] leading-none"><?= e($t['distrito']) ?></h1>
        <p class="text-[0.72rem] text-slate-500 mt-2">
            <?= number_format($t['lat'], 4, '.', '') ?>, <?= number_format($t['lon'], 4, '.', '') ?>
            · <?= (int)$t['altitud'] ?> m s. n. m.
            · zona <?= e(str_replace('_', ' ', $t['zona'])) ?>
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <?php foreach ([24, 48, 72] as $v): ?>
            <a href="<?= e(url('territorio', ['id' => $t['id'], 'ventana' => $v])) ?>"
               class="wq-tab <?= $ventana === $v ? 'is-active' : '' ?>"><?= $v ?> h</a>
        <?php endforeach; ?>
        <a href="<?= e(url('reporte', ['id' => $t['id'], 'ventana' => $ventana])) ?>"
           target="_blank" rel="noopener"
           class="btn btn--outline !py-2 !px-4 !text-[0.64rem]">
            <i class="fa-solid fa-file-pdf mr-2"></i> Reporte PDF
        </a>
    </div>
</header>

<!-- ===================== BLOQUE PRINCIPAL ===================== -->
<div class="grid grid-cols-1 lg:grid-cols-[1fr_1.15fr] gap-5 mb-5">

    <!-- Índice -->
    <section class="wq-panel-dark p-6 relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-52 h-52 rounded-full blur-3xl opacity-20 wq-level-bg--<?= e($n) ?>"></div>

        <div class="relative">
            <div class="wq-eyebrow mb-3">Índice de riesgo · ventana <?= (int)$ventana ?> h</div>

            <div class="flex items-end gap-4">
                <div class="wq-num text-[5.5rem] leading-[0.85] wq-level-text--<?= e($n) ?>">
                    <?= (int)round($r['riesgo']) ?>
                </div>
                <div class="pb-2">
                    <div class="text-[0.62rem] uppercase tracking-[0.16em] text-slate-500 mb-1">de 100</div>
                    <span class="wq-badge wq-badge--<?= e($n) ?>">
                        <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                        <?= e($etiquetasNivel[$n] ?? $n) ?>
                    </span>
                </div>
            </div>

            <div class="mt-5">
                <div class="wq-bar h-2">
                    <div class="wq-bar__fill wq-bar__fill--<?= e($n) ?>" style="width: <?= max(2, (float)$r['riesgo']) ?>%"></div>
                </div>
                <div class="flex justify-between text-[0.56rem] uppercase tracking-[0.12em] text-slate-600 mt-1.5">
                    <span>0</span><span>25</span><span>50</span><span>75</span><span>100</span>
                </div>
            </div>

            <p class="mt-5 text-[0.82rem] text-slate-300 leading-relaxed">
                <?= e($r['resumen']) ?>
            </p>

            <div class="mt-5 pt-4 border-t border-white/10 grid grid-cols-3 gap-3">
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">Peligro</div>
                    <div class="wq-num text-lg text-white"><?= number_format($principal['peligro'], 3, '.', '') ?></div>
                </div>
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">Exposición</div>
                    <div class="wq-num text-lg text-white"><?= number_format($principal['exposicion'], 3, '.', '') ?></div>
                </div>
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">Vulnerab.</div>
                    <div class="wq-num text-lg text-white"><?= number_format($principal['vulnerabilidad'], 3, '.', '') ?></div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap gap-1.5">
                <span class="wq-chip">confianza <?= e($r['confianza']) ?></span>
                <span class="wq-chip">ámbito <?= e($r['fuente_climatologia']) ?></span>
            </div>
        </div>
    </section>

    <!-- Explicabilidad -->
    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">
                    ¿Por qué? Contribución de variables
                </h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">
                    Amenaza dominante: <?= e($principal['nombre']) ?>
                </p>
            </div>
            <i class="fa-solid fa-magnifying-glass-chart text-brand-gold/60"></i>
        </div>

        <div class="wq-card__body space-y-4">
            <?php foreach ($principal['detalle'] as $d): ?>
                <?php
                $valor = $d['valor'];
                $umbral = $d['umbral'];
                $ratio = (is_numeric($umbral) && (float)$umbral > 0 && is_numeric($valor))
                    ? (float)$valor / (float)$umbral : 0;
                $pct = min(100, $ratio * 50);
                $colorBarra = $ratio >= 1.5 ? 'rojo' : ($ratio >= 1.0 ? 'naranja' : ($ratio >= 0.6 ? 'amarillo' : 'verde'));
                ?>
                <div>
                    <div class="flex items-baseline justify-between gap-3 mb-1.5">
                        <span class="text-[0.78rem] text-slate-200"><?= e($d['variable']) ?></span>
                        <span class="text-[0.72rem] text-right wq-nowrap">
                            <span class="wq-num text-white"><?= $valor === null ? '—' : number_format((float)$valor, is_float($valor) && $valor < 10 ? 2 : 1, '.', '') ?></span>
                            <span class="text-slate-500 ml-1"><?= e($d['unidad']) ?></span>
                        </span>
                    </div>
                    <div class="wq-bar">
                        <div class="wq-bar__fill wq-bar__fill--<?= $colorBarra ?>"
                             style="width: <?= max(2, $pct) ?>%"></div>
                    </div>
                    <div class="flex items-center justify-between mt-1 text-[0.6rem] text-slate-600">
                        <span><?= e($d['fuente']) ?></span>
                        <span>
                            <?= $umbral === null ? 'sin umbral' : 'umbral ' . number_format((float)$umbral, 1, '.', '') . ' ' . e($d['unidad']) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>

            <p class="text-[0.66rem] text-slate-500 leading-relaxed pt-2 border-t border-white/[0.07]">
                La barra compara el valor observado/pronosticado contra su umbral de referencia.
                Al 100 % de la barra el valor duplica el umbral. Todos los pesos y umbrales están
                documentados en <span class="wq-kbd">config/umbrales.php</span>.
            </p>
        </div>
    </section>
</div>

<!-- ===================== SEIS AMENAZAS ===================== -->
<section class="mb-5">
    <div class="flex items-end justify-between mb-3">
        <h2 class="text-[0.95rem] font-normal tracking-wide">Las seis amenazas</h2>
        <span class="text-[0.66rem] text-slate-500">Mismo índice, distinto disparador</span>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
        <?php foreach ($r['amenazas'] as $id => $a): ?>
            <?php $na = $a['nivel']['id']; ?>
            <div class="wq-card p-4 <?= $id === $r['principal'] ? '!border-brand-gold/45' : '' ?>">
                <div class="flex items-center justify-between mb-3">
                    <i class="fa-solid <?= e($iconos[$id] ?? 'fa-circle') ?> text-slate-400 text-sm"></i>
                    <span class="wq-dot wq-dot--<?= e($na) ?>"></span>
                </div>
                <div class="wq-num text-2xl wq-level-text--<?= e($na) ?>"><?= (int)round($a['riesgo']) ?></div>
                <div class="text-[0.68rem] text-slate-400 mt-1 leading-tight min-h-[2rem]"><?= e($a['nombre']) ?></div>
                <div class="wq-bar mt-2.5 h-1">
                    <div class="wq-bar__fill wq-bar__fill--<?= e($na) ?>" style="width: <?= max(2, (float)$a['riesgo']) ?>%"></div>
                </div>
                <div class="text-[0.56rem] uppercase tracking-[0.12em] text-slate-600 mt-2">
                    H <?= number_format($a['peligro'], 2, '.', '') ?>
                    · E <?= number_format($a['exposicion'], 2, '.', '') ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ===================== SERIE + EXPOSICIÓN ===================== -->
<div class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-5 mb-5">

    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">Pronóstico de 7 días</h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">
                    Lluvia diaria vs. umbral p95 · temperatura aparente
                </p>
            </div>
            <span class="wq-chip"><?= e($forecast['fuente'] ?? 'Open-Meteo') ?></span>
        </div>
        <div class="wq-card__body">
            <div class="h-[260px]"><canvas id="chart-clima"></canvas></div>
            <?php if ($umbralLluvia !== null): ?>
                <p class="text-[0.62rem] text-slate-600 mt-3">
                    Línea punteada: umbral de referencia p95 = <?= number_format((float)$umbralLluvia, 1, '.', '') ?> mm.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">Exposición del territorio</h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">
                    Totales del distrito y de dónde sale cada uno
                </p>
            </div>
            <?php
            $estadoExp = $t['exposicion']['estado'] ?? 'estimada';
            $etiquetaEstado = [
                'oficial'  => 'totales oficiales',
                'mixta'    => 'datos mixtos',
                'estimada' => 'estimación del catálogo',
            ][$estadoExp] ?? $estadoExp;
            ?>
            <span class="wq-chip <?= $estadoExp === 'oficial' ? 'wq-chip--verde' : ($estadoExp === 'mixta' ? 'wq-chip--ambar' : '') ?>">
                <?= e($etiquetaEstado) ?>
            </span>
        </div>
        <div class="wq-card__body">
            <?php
            $proc = $t['exposicion']['campos'] ?? [];
            $filasExp = [
                ['poblacion',     'Población',        numero_legible($t['poblacion']),  'habitantes'],
                ['viviendas',     'Viviendas',        numero_legible($t['viviendas']),  'unidades'],
                ['escuelas',      'Colegios',         (string)$t['escuelas'],           'instituciones'],
                ['centros_salud', 'Centros de salud', (string)$t['centros_salud'],     'establecimientos'],
            ];
            ?>
            <div class="grid grid-cols-2 gap-x-4 gap-y-3.5">
                <?php foreach ($filasExp as [$campo, $lbl, $val, $uni]): ?>
                    <?php $p = $proc[$campo] ?? null; $oficial = !empty($p['oficial']); ?>
                    <div>
                        <div class="flex items-baseline gap-2 flex-wrap">
                            <span class="wq-num text-xl text-white"><?= e($val) ?></span>
                            <span class="wq-proc <?= $oficial ? 'wq-proc--oficial' : 'wq-proc--estimada' ?>">
                                <?= $oficial ? 'oficial' : 'estimada' ?>
                            </span>
                        </div>
                        <div class="text-[0.58rem] uppercase tracking-[0.12em] text-slate-500 mt-0.5"><?= e($lbl) ?></div>
                        <div class="text-[0.58rem] text-slate-600 leading-snug mt-0.5">
                            <?php if ($oficial): ?>
                                <?= e($p['fuente']) ?><?= !empty($p['anio']) ? ' · ' . e((string)$p['anio']) : '' ?>
                            <?php else: ?>
                                estimación de referencia del catálogo piloto
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div>
                    <div class="flex items-baseline gap-2 flex-wrap">
                        <span class="wq-num text-xl text-white"><?= numero_legible($t['km_vias']) ?> km</span>
                        <span class="wq-proc wq-proc--estimada">estimada</span>
                    </div>
                    <div class="text-[0.58rem] uppercase tracking-[0.12em] text-slate-500 mt-0.5">Red vial</div>
                    <div class="text-[0.58rem] text-slate-600 leading-snug mt-0.5">fuera de la lista oficial importable</div>
                </div>

                <div>
                    <div class="flex items-baseline gap-2 flex-wrap">
                        <span class="wq-num text-xl text-white"><?= numero_legible($t['hectareas_cultivo']) ?></span>
                        <span class="wq-proc wq-proc--estimada">estimada</span>
                    </div>
                    <div class="text-[0.58rem] uppercase tracking-[0.12em] text-slate-500 mt-0.5">Cultivos</div>
                    <div class="text-[0.58rem] text-slate-600 leading-snug mt-0.5">fuera de la lista oficial importable</div>
                </div>
            </div>

            <div class="mt-5 pt-4 border-t border-white/[0.07]">
                <div class="wq-eyebrow mb-2.5">Población expuesta · estimación</div>
                <div class="space-y-2 text-[0.72rem]">
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">En zona inundable</span>
                        <span class="text-white"><?= numero_legible($t['poblacion_expuesta_inundacion']) ?></span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">En zona de huaico</span>
                        <span class="text-white"><?= numero_legible($t['poblacion_expuesta_huaico']) ?></span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">Vulnerable a sequía</span>
                        <span class="text-white"><?= numero_legible($t['poblacion_expuesta_sequia']) ?></span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">Expuesta a calor</span>
                        <span class="text-white"><?= numero_legible($t['poblacion_expuesta_calor']) ?></span>
                    </div>
                    <div class="flex justify-between gap-3 pt-1">
                        <span class="text-slate-400">Vulnerabilidad estructural</span>
                        <span class="text-white"><?= number_format($t['vulnerabilidad'], 2, '.', '') ?></span>
                    </div>
                </div>

                <p class="text-[0.62rem] text-slate-600 leading-relaxed mt-3 pt-3 border-t border-white/[0.07]">
                    Los totales de arriba y la fracción expuesta son
                    <strong class="text-slate-400">dos cosas distintas</strong>.
                    El total puede venir de una fuente oficial; la fracción expuesta siempre es una
                    estimación: <span class="wq-kbd">total × susceptibilidad</span>. Saber cuántos colegios
                    hay no dice cuántos están en zona inundable: eso exige cruzar cada uno con una capa
                    de peligro, fuera del alcance de este piloto.
                </p>
            </div>
        </div>
    </section>
</div>

<!-- ===================== ZONIFICACIÓN + ALERTA ===================== -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">

    <!-- Pilar 2 -->
    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">Aptitud del territorio para construir</h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">Pilar 2 · Índice de Seguridad de Emplazamiento</p>
            </div>
            <i class="fa-solid fa-draw-polygon text-brand-gold/60"></i>
        </div>
        <div class="wq-card__body">
            <div class="flex items-center gap-4">
                <div class="wq-num text-[3rem] leading-none" style="color: <?= e($clase['color']) ?>">
                    <?= (int)round($ise) ?>
                </div>
                <div>
                    <div class="text-[0.62rem] uppercase tracking-[0.16em] text-slate-500">Clase</div>
                    <div class="text-lg font-light" style="color: <?= e($clase['color']) ?>">
                        <?= e($clase['id']) ?> · <?= e($clase['nombre']) ?>
                    </div>
                </div>
            </div>

            <div class="mt-4 wq-bar h-2">
                <div class="wq-bar__fill" style="width: <?= max(2, (float)$ise) ?>%; background: <?= e($clase['color']) ?>"></div>
            </div>

            <p class="mt-4 text-[0.76rem] text-slate-400 leading-relaxed">
                <?php if ($clase['id'] === 'D'): ?>
                    Este distrito no debería albergar <strong class="text-white">equipamientos críticos</strong>
                    (colegios, hospitales) sin obras mayores de mitigación.
                <?php elseif ($clase['id'] === 'C'): ?>
                    Emplazamiento restringido: exige estudio de riesgo específico y obras antes de otorgar licencia.
                <?php elseif ($clase['id'] === 'B'): ?>
                    Apto con condiciones: requiere medidas de mitigación documentadas.
                <?php else: ?>
                    Sin restricciones relevantes para equipamientos, sujeto a estudios específicos de suelos.
                <?php endif; ?>
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <a href="<?= e(url('planificacion', ['lat' => $t['lat'], 'lon' => $t['lon'], 'tipo' => 'colegio'])) ?>"
                   class="btn btn--outline !py-2 !px-4 !text-[0.64rem]">Evaluar un colegio aquí</a>
                <a href="<?= e(url('planificacion', ['lat' => $t['lat'], 'lon' => $t['lon'], 'tipo' => 'hospital'])) ?>"
                   class="btn btn--ghost !py-2 !px-4 !text-[0.64rem]">Evaluar un hospital</a>
            </div>
        </div>
    </section>

    <!-- Alertas -->
    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">Recomendación y protocolo</h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">Acciones sugeridas para el nivel actual</p>
            </div>
            <i class="fa-solid fa-bell text-brand-gold/60"></i>
        </div>
        <div class="wq-card__body">
            <div class="flex items-center gap-2 mb-3">
                <span class="wq-badge wq-badge--<?= e($n) ?>"><?= e($etiquetasNivel[$n] ?? $n) ?></span>
                <span class="text-[0.68rem] text-slate-500"><?= e($principal['nombre']) ?></span>
            </div>

            <ol class="space-y-2 mb-4">
                <?php foreach (($alerta_sugerida['acciones'] ?? []) as $i => $accion): ?>
                    <li class="flex gap-2.5 text-[0.76rem] text-slate-300">
                        <span class="wq-num text-brand-gold/70 text-[0.7rem] mt-0.5"><?= $i + 1 ?></span>
                        <span><?= e($accion) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if (usuario_es_autoridad()): ?>
                <form method="post" action="index.php" class="space-y-2.5 pt-3 border-t border-white/[0.07]">
                    <input type="hidden" name="action" value="emitir_alerta">
                    <input type="hidden" name="territorio_id" value="<?= e($t['id']) ?>">
                    <input type="hidden" name="amenaza" value="<?= e($r['principal']) ?>">
                    <input type="hidden" name="nivel" value="<?= e($n) ?>">
                    <label class="wq-label" for="mensaje-alerta">Mensaje para la población (opcional)</label>
                    <textarea id="mensaje-alerta" name="mensaje" rows="2" class="wq-input"
                              placeholder="Ej.: evacuar la zona baja del río en las próximas 6 horas."></textarea>
                    <button type="submit" class="btn btn--primary w-full !py-2.5 !text-[0.66rem]">
                        <i class="fa-solid fa-bullhorn mr-2"></i> Emitir recomendación
                    </button>
                </form>
            <?php else: ?>
                <div class="pt-3 border-t border-white/[0.07]">
                    <p class="text-[0.7rem] text-slate-500 mb-2">
                        Emitir recomendaciones requiere rol de autoridad o administrador.
                    </p>
                    <button type="button"
                            onclick="document.getElementById('wq-login').classList.remove('hidden')"
                            class="btn btn--outline w-full !py-2 !text-[0.64rem]">Ingresar como autoridad</button>
                </div>
            <?php endif; ?>

            <p class="wq-legal mt-4">
                WILLAQ no emite alertas oficiales. Estas son recomendaciones de apoyo a la decisión.
                La alerta oficial corresponde a SENAMHI, INDECI y ENFEN.
            </p>
        </div>
    </section>
</div>

<!-- ===================== FUENTES ===================== -->
<section class="wq-panel-dark p-5">
    <div class="wq-eyebrow mb-3">Trazabilidad del dato</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.7rem]">
        <?php foreach (($r['fuentes'] ?? []) as $clave => $fuente): ?>
            <div>
                <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-1"><?= e($clave) ?></div>
                <div class="text-slate-300"><?= e($fuente) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-4 pt-3 border-t border-white/[0.07] flex flex-wrap items-center gap-x-5 gap-y-1.5 text-[0.62rem] text-slate-600">
        <span>Calculado: <?= e(fecha_legible($r['actualizado'])) ?></span>
        <span>Escenario: lluvia ×<?= number_format($r['escenario']['factor_lluvia'] ?? 1, 2, '.', '') ?></span>
        <span>Ventana: <?= (int)$r['ventana'] ?> h</span>
        <a class="wq-link" href="<?= e(url('api_riesgo', ['id' => $t['id'], 'ventana' => $ventana])) ?>"
           target="_blank" rel="noopener">Ver JSON crudo</a>
    </div>
</section>

<script>
(function () {
    const ctx = document.getElementById('chart-clima');
    if (!ctx || typeof Chart === 'undefined') return;

    const umbral = <?= json_encode($umbralLluvia) ?>;
    const datasets = [
        {
            type: 'bar',
            label: 'Lluvia (mm)',
            data: <?= json_encode($datosLluvia) ?>,
            backgroundColor: 'rgba(56, 189, 248, 0.55)',
            borderColor: 'rgba(125, 211, 252, 0.9)',
            borderWidth: 1,
            borderRadius: 3,
            yAxisID: 'y'
        },
        {
            type: 'line',
            label: 'Temp. aparente máx. (°C)',
            data: <?= json_encode($datosTemp) ?>,
            borderColor: '#C5A880',
            backgroundColor: 'rgba(197,168,128,.12)',
            borderWidth: 1.6,
            pointRadius: 2.5,
            pointBackgroundColor: '#C5A880',
            tension: .35,
            yAxisID: 'y1'
        }
    ];

    if (umbral !== null) {
        datasets.push({
            type: 'line',
            label: 'Umbral p95 (mm)',
            data: <?= json_encode(array_fill(0, max(1, count($datosLluvia)), $umbralLluvia)) ?>,
            borderColor: 'rgba(239,68,68,.65)',
            borderDash: [5, 4],
            borderWidth: 1.2,
            pointRadius: 0,
            yAxisID: 'y'
        });
    }

    new Chart(ctx, {
        type: 'bar',
        data: { labels: <?= json_encode($labels) ?>, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: { color: '#93a1b8', boxWidth: 10, boxHeight: 10, font: { size: 10, family: 'Montserrat' } }
                },
                tooltip: {
                    backgroundColor: 'rgba(8,17,32,.95)',
                    borderColor: 'rgba(197,168,128,.35)',
                    borderWidth: 1,
                    titleFont: { size: 11, family: 'Montserrat' },
                    bodyFont: { size: 11, family: 'Montserrat' },
                    padding: 10
                }
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255,255,255,.045)' },
                    ticks: { color: '#7b8899', font: { size: 10, family: 'Montserrat' } }
                },
                y: {
                    position: 'left',
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,.045)' },
                    ticks: { color: '#7b8899', font: { size: 10, family: 'Montserrat' } },
                    title: { display: true, text: 'mm', color: '#7b8899', font: { size: 9 } }
                },
                y1: {
                    position: 'right',
                    grid: { display: false },
                    ticks: { color: '#C5A880', font: { size: 10, family: 'Montserrat' } },
                    title: { display: true, text: '°C', color: '#C5A880', font: { size: 9 } }
                }
            }
        }
    });
})();
</script>
