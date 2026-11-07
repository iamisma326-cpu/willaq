<?php
/**
 * VISTA — LISTA DE ALERTAS Y PROTOCOLOS
 */
$etiquetas = ['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'];
$est = $estadisticas ?? ['total' => 0, 'por_nivel' => [], 'acusadas' => 0, 'pendientes' => 0];

$amenazas = [
    'lluvia' => 'Lluvia extrema',
    'inundacion' => 'Inundación fluvial',
    'huaico' => 'Huaico / deslizamiento',
    'sequia' => 'Sequía',
    'calor' => 'Calor extremo',
    'agro' => 'Riesgo agropecuario',
];
?>

<header class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
    <div>
        <div class="wq-eyebrow mb-2">Pilar 1 · Vigilar</div>
        <h1 class="wq-title text-[2rem] lg:text-[2.6rem] leading-none">Alertas y protocolos</h1>
        <p class="text-[0.82rem] text-slate-400 mt-2 max-w-2xl">
            Recomendaciones de apoyo a la decisión con acciones concretas por nivel.
            Cada emisión queda registrada con código, emisor y acuse de recibo.
        </p>
    </div>
</header>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-6">
    <div class="wq-kpi">
        <div class="wq-kpi__value"><?= (int)($est['total'] ?? 0) ?></div>
        <div class="wq-kpi__label">Emitidas</div>
    </div>
    <div class="wq-kpi wq-kpi--rojo">
        <div class="wq-kpi__value"><?= (int)($est['por_nivel']['rojo'] ?? 0) + (int)($est['por_nivel']['naranja'] ?? 0) ?></div>
        <div class="wq-kpi__label">Alto / muy alto</div>
    </div>
    <div class="wq-kpi wq-kpi--verde">
        <div class="wq-kpi__value"><?= (int)($est['acusadas'] ?? 0) ?></div>
        <div class="wq-kpi__label">Con acuse</div>
    </div>
    <div class="wq-kpi wq-kpi--amarillo">
        <div class="wq-kpi__value"><?= (int)($est['pendientes'] ?? 0) ?></div>
        <div class="wq-kpi__label">Pendientes de acuse</div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1.5fr_1fr] gap-5">

    <!-- Lista -->
    <section class="wq-card">
        <div class="wq-card__head">
            <h2 class="text-[0.95rem] font-normal tracking-wide">Historial</h2>
            <div class="flex gap-1">
                <button type="button" class="wq-tab is-active" data-filtro="todos">Todas</button>
                <button type="button" class="wq-tab" data-filtro="rojo">Rojo</button>
                <button type="button" class="wq-tab" data-filtro="naranja">Naranja</button>
                <button type="button" class="wq-tab" data-filtro="amarillo">Amarillo</button>
            </div>
        </div>

        <div class="divide-y divide-white/[0.05]" id="wq-lista-alertas">
            <?php foreach ($alertas as $a): ?>
                <a href="<?= e(url('alerta', ['codigo' => $a['codigo']])) ?>"
                   class="wq-alerta block px-5 py-3.5 hover:bg-white/[0.03] transition-colors"
                   data-nivel="<?= e($a['nivel']) ?>">
                    <div class="flex items-start gap-3">
                        <span class="wq-dot wq-dot--<?= e($a['nivel']) ?> mt-1.5"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-[0.84rem] text-white/95 leading-snug"><?= e($a['titulo']) ?></span>
                                <span class="wq-badge wq-badge--<?= e($a['nivel']) ?> shrink-0">
                                    <?= e($etiquetas[$a['nivel']] ?? $a['nivel']) ?>
                                </span>
                            </div>
                            <div class="mt-1 text-[0.68rem] text-slate-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-mono"><?= e($a['codigo']) ?></span>
                                <span><?= e(fecha_legible($a['emitida_en'])) ?></span>
                                <span><?= e($a['emitida_por']) ?></span>
                                <?php if (($a['estado'] ?? '') === 'acusada'): ?>
                                    <span class="text-risk-green"><i class="fa-solid fa-check mr-1"></i>acusada</span>
                                <?php else: ?>
                                    <span class="text-risk-yellow">pendiente de acuse</span>
                                <?php endif; ?>
                            </div>
                            <p class="mt-1.5 text-[0.72rem] text-slate-400 line-clamp-2"><?= e($a['mensaje']) ?></p>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php if (empty($alertas)): ?>
                <div class="px-5 py-14 text-center">
                    <i class="fa-regular fa-bell-slash text-3xl text-slate-700 mb-3 block"></i>
                    <p class="text-[0.8rem] text-slate-500">
                        Aún no se emitieron recomendaciones.
                    </p>
                    <p class="text-[0.7rem] text-slate-600 mt-1">
                        Abra la ficha de un distrito y emita una recomendación desde ahí.
                    </p>
                    <a href="<?= e(url('home')) ?>" class="wq-link text-[0.7rem] inline-block mt-3">
                        Ir a la sala de situación
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Emisión rápida -->
    <div class="space-y-5">
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Emisión rápida</h2>
                <i class="fa-solid fa-bullhorn text-brand-gold/60"></i>
            </div>
            <div class="wq-card__body">
                <?php if (usuario_es_autoridad()): ?>
                    <form method="post" action="index.php" class="space-y-3">
                        <input type="hidden" name="action" value="emitir_alerta">
                        <div>
                            <label class="wq-label" for="qa-territorio">Distrito</label>
                            <select class="wq-select" id="qa-territorio" name="territorio_id" required>
                                <?php foreach (territorios_todos() as $t): ?>
                                    <option value="<?= e($t['id']) ?>">
                                        <?= e($t['distrito']) ?> — <?= e($t['region']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="wq-label" for="qa-amenaza">Amenaza</label>
                            <select class="wq-select" id="qa-amenaza" name="amenaza">
                                <?php foreach ($amenazas as $id => $nombre): ?>
                                    <option value="<?= e($id) ?>"><?= e($nombre) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="wq-label" for="qa-nivel">Nivel</label>
                            <select class="wq-select" id="qa-nivel" name="nivel">
                                <option value="amarillo">Medio · amarillo</option>
                                <option value="naranja" selected>Alto · naranja</option>
                                <option value="rojo">Muy alto · rojo</option>
                                <option value="verde">Bajo · verde</option>
                            </select>
                        </div>
                        <div>
                            <label class="wq-label" for="qa-mensaje">Mensaje</label>
                            <textarea class="wq-input" id="qa-mensaje" name="mensaje" rows="2"
                                      placeholder="Acción concreta para la población..."></textarea>
                        </div>
                        <button type="submit" class="btn btn--primary w-full !py-2.5 !text-[0.66rem]">
                            Emitir recomendación
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-[0.76rem] text-slate-400 mb-3">
                        La emisión está reservada a usuarios con rol de <strong class="text-white">autoridad</strong>
                        o <strong class="text-white">administrador</strong>.
                    </p>
                    <button type="button" onclick="document.getElementById('wq-login').classList.remove('hidden')"
                            class="btn btn--outline w-full !py-2 !text-[0.64rem]">
                        Ingresar como defensa@willaq.pe
                    </button>
                    <div class="mt-3 text-[0.66rem] text-slate-600 font-mono">contraseña: willaq2026</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="wq-panel-dark p-5">
            <div class="wq-eyebrow mb-2">Protocolo por nivel</div>
            <div class="space-y-2 text-[0.72rem] text-slate-400">
                <div class="flex gap-2"><span class="wq-dot wq-dot--verde mt-1.5"></span><span><strong class="text-slate-200">Bajo:</strong> monitoreo y mantenimiento preventivo.</span></div>
                <div class="flex gap-2"><span class="wq-dot wq-dot--amarillo mt-1.5"></span><span><strong class="text-slate-200">Medio:</strong> limpieza de drenajes, verificación de rutas.</span></div>
                <div class="flex gap-2"><span class="wq-dot wq-dot--naranja mt-1.5"></span><span><strong class="text-slate-200">Alto:</strong> activación del COE, albergues preparados.</span></div>
                <div class="flex gap-2"><span class="wq-dot wq-dot--rojo mt-1.5"></span><span><strong class="text-slate-200">Muy alto:</strong> evacuación preventiva y suspensión de clases.</span></div>
            </div>
            <p class="wq-legal mt-4">
                Recomendaciones de apoyo a la decisión. No sustituyen una alerta oficial.
            </p>
        </section>
    </div>
</div>

<script>
document.querySelectorAll('[data-filtro]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('[data-filtro]').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        const f = btn.dataset.filtro;
        document.querySelectorAll('.wq-alerta').forEach(el => {
            el.style.display = (f === 'todos' || el.dataset.nivel === f) ? '' : 'none';
        });
    });
});
</script>
