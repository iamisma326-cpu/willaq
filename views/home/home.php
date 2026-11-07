<?php
/**
 * VISTA — SALA DE SITUACIÓN
 * Lo primero que ve el jurado: estado nacional, KPIs y distritos críticos.
 */
$total = $resumen['total'] ?? count($riesgos);
$conteo = $resumen['conteo'] ?? ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
$nivelNacional = $resumen['nivel_nacional'] ?? 'verde';
$peor = $resumen['peor'] ?? null;
$top = array_slice($riesgos, 0, 10);

$etiquetaNacional = [
    'verde' => 'Sin condiciones de riesgo relevantes',
    'amarillo' => 'Condiciones que requieren seguimiento',
    'naranja' => 'Riesgo alto en al menos un distrito',
    'rojo' => 'Riesgo muy alto: se requiere acción preventiva',
][$nivelNacional] ?? '';

// Hechos calculados (no inventados) para el panel de vigilancia
$maxLluvia = null;
$sobreP95 = 0;          // distritos que superan su p95 local de lluvia
$lluviaMaterial = 0;    // distritos donde la lluvia constituye un evento material
$conAlerta = 0;
foreach ($riesgos as $r) {
    $ll = $r['datos']['lluvia_ventana'] ?? null;
    if ($ll !== null && ($maxLluvia === null || $ll > $maxLluvia['valor'])) {
        $maxLluvia = ['valor' => $ll, 'distrito' => $r['territorio']['distrito'], 'region' => $r['territorio']['region']];
    }
    if (($r['datos']['lluvia_ventana'] ?? 0) >= ($r['datos']['lluvia_threshold_p95'] ?? PHP_INT_MAX)) {
        $sobreP95++;
    }
    // Un valor puede superar el p95 local y aun así no ser un evento:
    // 1.3 mm en Lima no inunda nada. Decide el factor de materialidad.
    if (($r['materialidad']['lluvia']['factor'] ?? 0) > 0) {
        $lluviaMaterial++;
    }
    if (in_array($r['nivel']['id'], ['naranja', 'rojo'], true)) {
        $conAlerta++;
    }
}

$generado = $snapshot['generado_en'] ?? null;
$fuenteClima = $riesgos[0]['fuentes']['pronostico'] ?? 'Open-Meteo';
$confianza = $riesgos[0]['confianza'] ?? 'media';
?>

<!-- ===================== HERO ===================== -->
<section class="wq-grid-bg relative overflow-hidden rounded-2xl border border-brand-gold/15 mb-7">
    <div class="relative z-10 px-6 py-9 lg:px-10 lg:py-11 grid lg:grid-cols-[1.35fr_1fr] gap-8 items-end">
        <div>
            <div class="flex items-center gap-2.5 mb-4">
                <span class="wq-dot wq-dot--<?= e($nivelNacional) ?>"></span>
                <span class="wq-eyebrow">Sala de situación · Perú</span>
                <span class="wq-chip ml-1"><?= e(fecha_legible($generado ?? time(), false)) ?></span>
            </div>

            <h1 class="wq-title text-[2.1rem] lg:text-[3.2rem] leading-[1.02]">
                El Niño no avisa.<br>
                <span class="text-brand-gold">WILLAQ sí.</span>
            </h1>

            <p class="mt-4 text-[0.92rem] text-slate-300 max-w-2xl leading-relaxed">
                Convertimos pronóstico, caudal de río y territorio en una decisión concreta:
                <strong class="text-white font-normal">qué distrito vigilar, dónde no construir y qué pasaría si llueve el doble.</strong>
                Sin cajas negras: cada índice muestra su fórmula, su fuente y su fecha.
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-2.5">
                <a href="<?= e(url('mapa')) ?>" class="btn btn--primary !py-2.5 !px-5 !text-[0.7rem]">
                    <i class="fa-solid fa-map mr-2"></i> Abrir mapa
                </a>
                <a href="<?= e(url('simulador')) ?>" class="btn btn--outline !py-2.5 !px-5 !text-[0.7rem]">
                    <i class="fa-solid fa-flask mr-2"></i> Simular escenario
                </a>
                <a href="<?= e(url('planificacion')) ?>" class="btn btn--ghost !py-2.5 !px-4 !text-[0.7rem]">
                    <i class="fa-solid fa-draw-polygon mr-2"></i> Evaluar un terreno
                </a>
            </div>
        </div>

        <!-- Termómetro nacional -->
        <div class="wq-panel-dark p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="wq-eyebrow mb-1">Nivel nacional</div>
                    <div class="text-[1.5rem] font-light leading-tight wq-level-text--<?= e($nivelNacional) ?>">
                        <?= e([
                            'verde' => 'BAJO',
                            'amarillo' => 'MEDIO',
                            'naranja' => 'ALTO',
                            'rojo' => 'MUY ALTO',
                        ][$nivelNacional] ?? 'BAJO') ?>
                    </div>
                    <div class="text-[0.7rem] text-slate-400 mt-1 max-w-[16rem]">
                        <?= e($etiquetaNacional) ?>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <div class="wq-num text-4xl text-white"><?= (int)$conAlerta ?></div>
                    <div class="text-[0.58rem] uppercase tracking-[0.16em] text-slate-500">distritos en<br>alto / muy alto</div>
                </div>
            </div>

            <!-- Distribución -->
            <div class="mt-5">
                <div class="flex h-2.5 rounded-full overflow-hidden bg-white/5">
                    <?php foreach (['rojo', 'naranja', 'amarillo', 'verde'] as $n): ?>
                        <?php $pct = $total > 0 ? ($conteo[$n] / $total) * 100 : 0; ?>
                        <?php if ($pct > 0): ?>
                            <div class="h-full wq-level-bg--<?= e($n) ?>" style="width: <?= round($pct, 2) ?>%"
                                 title="<?= e($n) ?>: <?= (int)$conteo[$n] ?> distritos"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="grid grid-cols-4 gap-1.5 mt-3 text-center">
                    <?php foreach (['rojo', 'naranja', 'amarillo', 'verde'] as $n): ?>
                        <div>
                            <div class="wq-num text-base wq-level-text--<?= e($n) ?>"><?= (int)$conteo[$n] ?></div>
                            <div class="text-[0.55rem] uppercase tracking-[0.12em] text-slate-500"><?= e($n) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-white/10 flex items-center justify-between text-[0.62rem] text-slate-500">
                <span><i class="fa-solid fa-database mr-1"></i> <?= e($fuenteClima) ?></span>
                <span>confianza <?= e($confianza) ?></span>
            </div>
        </div>
    </div>
</section>

<!-- ===================== KPIs ===================== -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-7">
    <div class="wq-kpi wq-kpi--<?= e($nivelNacional) ?>">
        <div class="wq-kpi__value"><?= (int)$total ?></div>
        <div class="wq-kpi__label">Distritos monitoreados</div>
        <div class="text-[0.65rem] text-slate-500 mt-1.5">7 regiones piloto</div>
    </div>
    <div class="wq-kpi wq-kpi--naranja">
        <div class="wq-kpi__value"><?= numero_legible($resumen['poblacion_total_riesgo_alto'] ?? 0) ?></div>
        <div class="wq-kpi__label">Población en riesgo alto</div>
        <div class="text-[0.65rem] text-slate-500 mt-1.5">
            total de distritos alto/muy alto · <?= e(exposicion_etiqueta_campo('poblacion')) ?>
        </div>
    </div>
    <div class="wq-kpi wq-kpi--rojo">
        <div class="wq-kpi__value"><?= (int)($resumen['escuelas_totales_riesgo_alto'] ?? 0) ?></div>
        <div class="wq-kpi__label">Colegios en riesgo alto</div>
        <div class="text-[0.65rem] text-slate-500 mt-1.5">
            <?= (int)($resumen['centros_salud_totales_riesgo_alto'] ?? 0) ?> centros de salud
            <span class="text-slate-600">(total, no expuestos)</span><br>
            <?= e(exposicion_etiqueta_campo('escuelas')) ?>
        </div>
    </div>
    <div class="wq-kpi">
        <div class="wq-kpi__value">
            <?= $maxLluvia ? number_format($maxLluvia['valor'], 1) : '—' ?>
            <span class="text-base text-slate-400">mm</span>
        </div>
        <div class="wq-kpi__label">Máx. lluvia 24 h</div>
        <div class="text-[0.65rem] text-slate-500 mt-1.5 wq-truncate">
            <?= $maxLluvia ? e($maxLluvia['distrito']) : 'sin dato' ?>
        </div>
    </div>
</section>

<!-- ===================== CONTENIDO PRINCIPAL ===================== -->
<div class="grid grid-cols-1 xl:grid-cols-[1.6fr_1fr] gap-5">

    <!-- Ranking -->
    <section class="wq-card">
        <div class="wq-card__head">
            <div>
                <h2 class="text-[0.95rem] font-normal tracking-wide">Distritos con mayor riesgo</h2>
                <p class="text-[0.68rem] text-slate-500 mt-0.5">
                    Ventana de 24 h · ordenado por amenaza dominante
                </p>
            </div>
            <a href="<?= e(url('mapa')) ?>" class="wq-link text-[0.68rem] uppercase tracking-[0.14em]">Ver mapa</a>
        </div>

        <div class="overflow-x-auto wq-scroll">
            <table class="wq-table">
                <thead>
                    <tr>
                        <th class="!pl-5">#</th>
                        <th>Distrito</th>
                        <th>Amenaza dominante</th>
                        <th class="w-[130px]">Riesgo</th>
                        <th>Nivel</th>
                        <th class="!pr-5 text-right">Población</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $i => $r): ?>
                        <?php $t = $r['territorio']; $n = $r['nivel']['id']; ?>
                        <tr>
                            <td class="!pl-5 text-slate-500 wq-num"><?= $i + 1 ?></td>
                            <td>
                                <a href="<?= e(url('territorio', ['id' => $t['id']])) ?>" class="block group">
                                    <span class="text-white/95 group-hover:text-brand-gold transition-colors">
                                        <?= e($t['distrito']) ?>
                                    </span>
                                    <span class="block text-[0.62rem] text-slate-500 uppercase tracking-[0.1em]">
                                        <?= e($t['region']) ?>
                                    </span>
                                </a>
                            </td>
                            <td class="text-[0.76rem] text-slate-300">
                                <?= e($r['amenazas'][$r['principal']]['nombre'] ?? '—') ?>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="wq-bar flex-1">
                                        <div class="wq-bar__fill wq-bar__fill--<?= e($n) ?>"
                                             style="width: <?= max(3, (float)$r['riesgo']) ?>%"></div>
                                    </div>
                                    <span class="wq-num text-[0.8rem] w-9 text-right wq-level-text--<?= e($n) ?>">
                                        <?= (int)round($r['riesgo']) ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="wq-badge wq-badge--<?= e($n) ?>">
                                    <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                                    <?= e($r['nivel']['etiqueta']) ?>
                                </span>
                            </td>
                            <td class="!pr-5 text-right text-[0.75rem] text-slate-400 wq-nowrap">
                                <?= numero_legible($t['poblacion']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($top)): ?>
                        <tr><td colspan="6" class="text-center text-slate-500 py-10">
                            No hay datos. Ejecute <span class="wq-kbd">php scripts/actualizar.php</span>.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t border-white/[0.06] flex flex-wrap items-center gap-x-5 gap-y-1.5 text-[0.64rem] text-slate-500">
            <span><i class="fa-solid fa-circle-info mr-1 text-brand-gold/70"></i>
                El índice combina peligro, exposición y vulnerabilidad.</span>
            <a class="wq-link" href="<?= e(url('ayuda')) ?>#metodologia">Ver fórmula</a>
        </div>
    </section>

    <!-- Columna lateral -->
    <div class="space-y-5">

        <!-- Vigilancia -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Vigilancia de hoy</h2>
                <span class="wq-chip"><?= e(fecha_legible($generado ?? time())) ?></span>
            </div>
            <div class="wq-card__body space-y-3.5">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[0.78rem] text-slate-300">
                        Distritos con lluvia material
                        <span class="block text-[0.62rem] text-slate-500">
                            sobre su p95 local: <?= (int)$sobreP95 ?> de <?= (int)$total ?>
                        </span>
                    </span>
                    <span class="wq-num text-lg <?= $lluviaMaterial > 0 ? 'text-risk-orange' : 'text-slate-400' ?>">
                        <?= (int)$lluviaMaterial ?>
                    </span>
                </div>
                <div class="wq-divider"></div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[0.78rem] text-slate-300">Mayor intensidad de lluvia</span>
                    <span class="text-right">
                        <span class="wq-num text-lg text-white"><?= $maxLluvia ? number_format($maxLluvia['valor'], 1) : '—' ?></span>
                        <span class="text-[0.66rem] text-slate-500 ml-1">mm</span>
                        <span class="block text-[0.62rem] text-slate-500">
                            <?= $maxLluvia ? e($maxLluvia['distrito']) : '' ?>
                        </span>
                    </span>
                </div>
                <div class="wq-divider"></div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[0.78rem] text-slate-300">Fuente de pronóstico</span>
                    <span class="text-[0.7rem] text-slate-400 text-right"><?= e($fuenteClima) ?></span>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[0.78rem] text-slate-300">Última actualización</span>
                    <span class="text-[0.7rem] text-slate-400"><?= e(hace($generado ?? time())) ?></span>
                </div>

                <?php if (usuario_es_admin()): ?>
                    <a href="<?= e(url('actualizar_datos', ['ventana' => 24])) ?>"
                       class="mt-2 w-full flex items-center justify-center gap-2 text-[0.66rem] uppercase tracking-[0.16em] text-brand-gold border border-brand-gold/30 rounded-lg py-2 hover:bg-brand-gold/10 transition-colors">
                        <i class="fa-solid fa-rotate-right"></i> Recalcular snapshot
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <!-- Alertas recientes -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Últimas recomendaciones</h2>
                <a href="<?= e(url('alertas')) ?>" class="wq-link text-[0.66rem] uppercase tracking-[0.14em]">Todas</a>
            </div>
            <div class="divide-y divide-white/[0.05]">
                <?php foreach ($alertas as $a): ?>
                    <a href="<?= e(url('alerta', ['codigo' => $a['codigo']])) ?>"
                       class="block px-5 py-3 hover:bg-white/[0.03] transition-colors">
                        <div class="flex items-start gap-2.5">
                            <span class="wq-dot wq-dot--<?= e($a['nivel']) ?> mt-1.5"></span>
                            <div class="min-w-0">
                                <div class="text-[0.78rem] text-white/90 leading-snug"><?= e($a['titulo']) ?></div>
                                <div class="text-[0.62rem] text-slate-500 mt-0.5">
                                    <?= e($a['codigo']) ?> · <?= e(hace($a['emitida_en'])) ?>
                                    <?= ($a['estado'] ?? '') === 'acusada' ? ' · acusada' : '' ?>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($alertas)): ?>
                    <div class="px-5 py-8 text-center text-[0.74rem] text-slate-500">
                        No hay recomendaciones emitidas.
                        <a class="wq-link block mt-2" href="<?= e(url('alertas')) ?>">Emitir la primera</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<!-- ===================== TRES PILARES ===================== -->
<section class="mt-8">
    <div class="flex items-end justify-between gap-4 mb-4">
        <div>
            <div class="wq-eyebrow mb-1">Arquitectura del producto</div>
            <h2 class="wq-title text-2xl">Tres preguntas, una sola plataforma</h2>
        </div>
        <span class="hidden md:block text-[0.68rem] text-slate-500 max-w-xs text-right">
            El mismo motor de riesgo explicable alimenta los tres pilares.
        </span>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <a href="<?= e(url('mapa')) ?>" class="wq-card wq-card--hover p-5 group">
            <div class="flex items-center justify-between mb-4">
                <span class="wq-eyebrow">Pilar 01</span>
                <i class="fa-solid fa-satellite-dish text-brand-gold/60 group-hover:text-brand-gold transition-colors"></i>
            </div>
            <h3 class="wq-title text-xl mb-2">Vigilar</h3>
            <p class="text-[0.8rem] text-slate-400 leading-relaxed">
                ¿Qué va a pasar en 24, 48 o 72 horas y a quién le afecta?
                Seis amenazas con nivel, explicación y fuente por distrito.
            </p>
            <div class="mt-4 flex flex-wrap gap-1.5">
                <span class="wq-chip">Lluvia</span>
                <span class="wq-chip">Inundación</span>
                <span class="wq-chip">Huaico</span>
                <span class="wq-chip">Sequía</span>
                <span class="wq-chip">Calor</span>
                <span class="wq-chip">Agro</span>
            </div>
        </a>

        <a href="<?= e(url('planificacion')) ?>" class="wq-card wq-card--hover p-5 group">
            <div class="flex items-center justify-between mb-4">
                <span class="wq-eyebrow">Pilar 02</span>
                <i class="fa-solid fa-draw-polygon text-brand-gold/60 group-hover:text-brand-gold transition-colors"></i>
            </div>
            <h3 class="wq-title text-xl mb-2">Planificar</h3>
            <p class="text-[0.8rem] text-slate-400 leading-relaxed">
                ¿Dónde <em>no</em> se debe construir un colegio u hospital?
                Índice de Seguridad de Emplazamiento y veredicto con razones.
            </p>
            <div class="mt-4 flex flex-wrap gap-1.5">
                <span class="wq-chip">ISE 0–100</span>
                <span class="wq-chip">4 clases</span>
                <span class="wq-chip">Veredicto</span>
            </div>
        </a>

        <a href="<?= e(url('simulador')) ?>" class="wq-card wq-card--hover p-5 group">
            <div class="flex items-center justify-between mb-4">
                <span class="wq-eyebrow">Pilar 03</span>
                <i class="fa-solid fa-flask text-brand-gold/60 group-hover:text-brand-gold transition-colors"></i>
            </div>
            <h3 class="wq-title text-xl mb-2">Simular</h3>
            <p class="text-[0.8rem] text-slate-400 leading-relaxed">
                ¿Qué pasaría si llueve el doble, si hay que evacuar o si el sitio
                se evalúa con el clima de 2040?
            </p>
            <div class="mt-4 flex flex-wrap gap-1.5">
                <span class="wq-chip">+ lluvia</span>
                <span class="wq-chip">Evacuación</span>
                <span class="wq-chip">Reubicación</span>
            </div>
        </a>
    </div>
</section>

<!-- ===================== NOTA METODOLÓGICA ===================== -->
<section class="mt-6 wq-panel-dark p-5">
    <div class="flex flex-col md:flex-row md:items-center gap-4 justify-between">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation text-brand-gold/70 mt-0.5"></i>
            <p class="text-[0.72rem] text-slate-400 leading-relaxed max-w-3xl">
                <strong class="text-slate-200">Alcance.</strong>
                <?= e(exposicion_texto_breve()) ?>
                WILLAQ no emite alertas oficiales: entrega recomendaciones de apoyo a la decisión.
            </p>
        </div>
        <a href="<?= e(url('ayuda')) ?>#limites" class="shrink-0 wq-link text-[0.68rem] uppercase tracking-[0.14em]">
            Leer límites
        </a>
    </div>
</section>
