<?php
/**
 * VISTA — DETALLE DE ALERTA
 */
$etiquetas = ['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'];
$n = $a['nivel'];
?>

<nav class="flex items-center gap-2 text-[0.66rem] uppercase tracking-[0.14em] text-slate-500 mb-4">
    <a href="<?= e(url('alertas')) ?>" class="hover:text-brand-gold">Alertas</a>
    <span class="text-slate-700">/</span>
    <span class="text-slate-300 font-mono"><?= e($a['codigo']) ?></span>
</nav>

<div class="grid grid-cols-1 lg:grid-cols-[1.5fr_1fr] gap-5">

    <div class="space-y-5">
        <!-- Cabecera -->
        <section class="wq-panel-dark p-6 relative overflow-hidden">
            <div class="absolute -right-16 -top-16 w-56 h-56 rounded-full blur-3xl opacity-20 wq-level-bg--<?= e($n) ?>"></div>
            <div class="relative">
                <div class="flex items-center gap-2 mb-3">
                    <span class="wq-badge wq-badge--<?= e($n) ?>">
                        <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                        <?= e($etiquetas[$n] ?? $n) ?>
                    </span>
                    <span class="wq-chip"><?= e($a['amenaza']) ?></span>
                    <?php if (($a['estado'] ?? '') === 'acusada'): ?>
                        <span class="wq-badge wq-badge--verde"><i class="fa-solid fa-check"></i> acusada</span>
                    <?php else: ?>
                        <span class="wq-badge wq-badge--amarillo">pendiente</span>
                    <?php endif; ?>
                </div>

                <h1 class="wq-title text-[1.7rem] lg:text-[2.1rem] leading-tight"><?= e($a['titulo']) ?></h1>

                <p class="mt-3 text-[0.88rem] text-slate-300 leading-relaxed"><?= e($a['mensaje']) ?></p>

                <div class="mt-5 pt-4 border-t border-white/10 grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.7rem]">
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Código</div>
                        <div class="font-mono text-white"><?= e($a['codigo']) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Distrito</div>
                        <div class="text-white"><?= e($a['distrito']) ?>, <?= e($a['region']) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Emitida</div>
                        <div class="text-white"><?= e(fecha_legible($a['emitida_en'])) ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Emisor</div>
                        <div class="text-white"><?= e($a['emitida_por']) ?></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Protocolo -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Protocolo de acción sugerido</h2>
                <span class="wq-chip"><?= count($a['acciones'] ?? []) ?> acciones</span>
            </div>
            <div class="wq-card__body">
                <ol class="space-y-3">
                    <?php foreach (($a['acciones'] ?? []) as $i => $accion): ?>
                        <li class="flex gap-3">
                            <span class="shrink-0 w-6 h-6 rounded-full border border-brand-gold/40 text-brand-gold text-[0.66rem] flex items-center justify-center wq-num">
                                <?= $i + 1 ?>
                            </span>
                            <span class="text-[0.82rem] text-slate-300 leading-relaxed pt-0.5"><?= e($accion) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>

        <!-- Acuse -->
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Acuse de recibo</h2>
            </div>
            <div class="wq-card__body">
                <?php if (($a['estado'] ?? '') === 'acusada'): ?>
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-circle-check text-risk-green mt-0.5"></i>
                        <div>
                            <div class="text-[0.82rem] text-white">
                                Acusada por <?= e($a['acuse_por']) ?>
                            </div>
                            <div class="text-[0.68rem] text-slate-500 mt-0.5">
                                <?= e(fecha_legible($a['acuse_en'])) ?>
                            </div>
                            <p class="text-[0.72rem] text-slate-400 mt-2">
                                El acuse no implica conformidad con el nivel de riesgo: certifica que la
                                recomendación fue recibida por la autoridad local.
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-[0.78rem] text-slate-400 mb-3">
                        Aún no se registró la recepción de esta recomendación por la autoridad local.
                    </p>
                    <a href="<?= e(url('acusar', ['codigo' => $a['codigo']])) ?>"
                       class="btn btn--primary !py-2.5 !px-5 !text-[0.66rem]">
                        <i class="fa-solid fa-check mr-2"></i> Registrar acuse
                    </a>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Lateral -->
    <div class="space-y-5">
        <section class="wq-panel-dark p-5">
            <div class="wq-eyebrow mb-2">Nota legal</div>
            <p class="text-[0.72rem] text-slate-400 leading-relaxed">
                <?= e($a['nota_legal']) ?>
            </p>
        </section>

        <?php $t = territorio_por_id($a['territorio_id']); ?>
        <?php if ($t): ?>
            <section class="wq-card">
                <div class="wq-card__head">
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Contexto del territorio</h2>
                </div>
                <div class="wq-card__body space-y-2.5 text-[0.76rem]">
                    <div class="flex justify-between">
                        <span class="text-slate-400">Población</span>
                        <span class="text-white"><?= numero_legible($t['poblacion']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Colegios</span>
                        <span class="text-white"><?= (int)$t['escuelas'] ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Centros de salud</span>
                        <span class="text-white"><?= (int)$t['centros_salud'] ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Capacidad de albergues</span>
                        <span class="text-white"><?= numero_legible($t['albergues_capacidad']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Susceptibilidad a inundación</span>
                        <span class="text-white"><?= number_format($t['susc_inundacion'], 2, '.', '') ?></span>
                    </div>
                </div>
                <div class="px-5 pb-4">
                    <a href="<?= e(url('territorio', ['id' => $t['id']])) ?>"
                       class="btn btn--outline w-full !py-2 !text-[0.64rem]">
                        Ver ficha del distrito
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Trazabilidad</h2>
            </div>
            <div class="wq-card__body text-[0.7rem] text-slate-400 space-y-2">
                <p>Esta recomendación se generó a partir del índice de riesgo WILLAQ para la ventana vigente.</p>
                <a class="wq-link block" href="<?= e(url('alertas')) ?>">← Volver al historial</a>
            </div>
        </section>
    </div>
</div>
