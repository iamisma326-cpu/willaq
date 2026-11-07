<?php
/**
 * VISTA — SIMULADOR DE ESCENARIOS (Pilar 3)
 *
 * Tres modos sobre el mismo motor de riesgo:
 *   A. Climático  : "¿qué pasa si llueve el doble?"  (contrafactual)
 *   B. Evacuación : "¿cuánta gente hay que sacar y cuánto falta?" (operativo)
 *   C. Reubicación: "¿este terreno seguirá siendo seguro en el clima futuro?"
 *
 * Todo el cálculo es determinista: el jurado puede repetir el escenario y
 * obtener exactamente el mismo resultado.
 */
$modoTabs = [
    'clima' => ['Clima extremo', 'fa-cloud-showers-water', '¿Qué pasa si llueve más?'],
    'evacuacion' => ['Evacuación', 'fa-people-group', '¿Cuánta gente hay que evacuar?'],
    'reubicacion' => ['Reubicación', 'fa-arrows-turn-to-dots', '¿Seguirá siendo seguro?'],
];
$etiquetasNivel = ['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'];
$esClima = $modo === 'clima';
$esEvac = $modo === 'evacuacion';
$esReub = $modo === 'reubicacion';

$factorPct = (int)round(($factor - 1) * 100);
$factorTexto = ($factorPct >= 0 ? '+' : '') . $factorPct . ' %';
?>

<header class="mb-6">
    <div class="wq-eyebrow mb-2">Pilar 3 · Simular</div>
    <h1 class="wq-title text-[2rem] lg:text-[2.6rem] leading-none">
        ¿Qué pasaría si…?
    </h1>
    <p class="text-[0.85rem] text-slate-400 mt-3 max-w-3xl leading-relaxed">
        Un plan se prueba antes de que ocurra la emergencia. Mueva los controles y observe
        cómo cambia el riesgo, la población expuesta y la capacidad de respuesta.
        El simulador no predice el futuro: <strong class="text-white font-normal">explora escenarios</strong>
        con el mismo motor que calcula el riesgo de hoy.
    </p>
</header>

<!-- ===================== SELECTOR DE MODO ===================== -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
    <?php foreach ($modoTabs as $id => [$nombre, $icono, $pregunta]): ?>
        <a href="<?= e(url('simulador', ['modo' => $id, 'id' => $t['id'], 'lluvia' => $factor, 'temp' => $delta, 'tipo' => $tipo])) ?>"
           class="wq-card wq-card--hover p-4 <?= $modo === $id ? '!border-brand-gold/60 bg-brand-gold/[0.06]' : '' ?>">
            <div class="flex items-center justify-between mb-2">
                <i class="fa-solid <?= e($icono) ?> <?= $modo === $id ? 'text-brand-gold' : 'text-slate-500' ?>"></i>
                <span class="wq-badge <?= $modo === $id ? 'wq-badge--amarillo' : '!text-slate-500 !bg-white/5 !border-white/10' ?>">
                    <?= $modo === $id ? 'activo' : 'modo' ?>
                </span>
            </div>
            <div class="text-[0.92rem] font-normal <?= $modo === $id ? 'text-white' : 'text-slate-300' ?>">
                <?= e($nombre) ?>
            </div>
            <div class="text-[0.68rem] text-slate-500 mt-1 leading-snug"><?= e($pregunta) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[340px_1fr] gap-5">

    <!-- ===================== CONTROLES ===================== -->
    <aside class="space-y-5">
        <section class="wq-card">
            <div class="wq-card__head">
                <h2 class="text-[0.95rem] font-normal tracking-wide">Parámetros</h2>
                <i class="fa-solid fa-sliders text-brand-gold/60"></i>
            </div>

            <form method="get" action="index.php" class="wq-card__body space-y-4">
                <input type="hidden" name="action" value="simulador">
                <input type="hidden" name="modo" value="<?= e($modo) ?>">

                <div>
                    <label class="wq-label" for="sm-territorio">Distrito</label>
                    <select class="wq-select" id="sm-territorio" name="id">
                        <?php foreach ($territorios as $ter): ?>
                            <option value="<?= e($ter['id']) ?>" <?= $ter['id'] === $t['id'] ? 'selected' : '' ?>>
                                <?= e($ter['distrito']) ?> — <?= e($ter['region']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="wq-label" for="sm-lluvia">
                        Lluvia del escenario
                        <span class="text-brand-gold normal-case tracking-normal"><?= e($factorTexto) ?></span>
                    </label>
                    <input class="wq-range" id="sm-lluvia" name="lluvia" type="range"
                           min="0.5" max="3" step="0.1" value="<?= e($factor) ?>"
                           oninput="document.getElementById('sm-lluvia-out').textContent = (this.value >= 1 ? '+' : '') + Math.round((this.value - 1) * 100) + ' %';">
                    <div class="flex justify-between text-[0.58rem] uppercase tracking-[0.1em] text-slate-600 mt-1.5">
                        <span>−50 %</span>
                        <span id="sm-lluvia-out" class="text-brand-gold"><?= e($factorTexto) ?></span>
                        <span>+200 %</span>
                    </div>
                </div>

                <?php if ($esClima): ?>
                    <div>
                        <label class="wq-label" for="sm-temp">
                            Cambio de temperatura aparente
                            <span class="text-brand-gold normal-case tracking-normal"><?= ($delta >= 0 ? '+' : '') . number_format($delta, 1) ?> °C</span>
                        </label>
                        <input class="wq-range" id="sm-temp" name="temp" type="range"
                               min="-1" max="4" step="0.5" value="<?= e($delta) ?>"
                               oninput="document.getElementById('sm-temp-out').textContent = (this.value >= 0 ? '+' : '') + Number(this.value).toFixed(1) + ' °C';">
                        <div class="flex justify-between text-[0.58rem] uppercase tracking-[0.1em] text-slate-600 mt-1.5">
                            <span>−1 °C</span>
                            <span id="sm-temp-out" class="text-brand-gold"><?= ($delta >= 0 ? '+' : '') . number_format($delta, 1) ?> °C</span>
                            <span>+4 °C</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($esReub): ?>
                    <div>
                        <label class="wq-label" for="sm-tipo">Equipamiento a reubicar</label>
                        <select class="wq-select" id="sm-tipo" name="tipo">
                            <?php foreach ([
                                'colegio' => 'Colegio / institución educativa',
                                'hospital' => 'Hospital / centro de salud',
                                'vivienda' => 'Vivienda / conjunto residencial',
                                'planta_agua' => 'Planta de tratamiento de agua',
                                'albergue' => 'Albergue / centro de evacuación',
                                'carretera' => 'Carretera / puente',
                            ] as $idTipo => $nombreTipo): ?>
                                <option value="<?= e($idTipo) ?>" <?= $tipo === $idTipo ? 'selected' : '' ?>>
                                    <?= e($nombreTipo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="text-[0.64rem] text-slate-600 leading-relaxed">
                        La reubicación evalúa el centro del distrito seleccionado como sitio candidato.
                        Para evaluar un punto exacto use <a class="wq-link" href="<?= e(url('planificacion')) ?>">Planificar</a>.
                    </p>
                <?php endif; ?>

                <div>
                    <label class="wq-label" for="sm-ventana">Ventana de análisis</label>
                    <div class="flex gap-1.5">
                        <?php foreach ([24, 48, 72] as $v): ?>
                            <label class="flex-1">
                                <input type="radio" name="ventana" value="<?= $v ?>" class="peer sr-only"
                                       <?= $ventana === $v ? 'checked' : '' ?>>
                                <span class="block text-center text-[0.66rem] uppercase tracking-[0.1em] py-1.5 rounded-lg border cursor-pointer transition-colors
                                             peer-checked:border-brand-gold/60 peer-checked:text-brand-gold peer-checked:bg-brand-gold/10
                                             border-white/10 text-slate-400 hover:border-white/25">
                                    <?= $v ?> h
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary w-full !py-2.5 !text-[0.66rem]">
                    <i class="fa-solid fa-play mr-2"></i> Ejecutar simulación
                </button>
            </form>
        </section>

        <section class="wq-panel-dark p-5">
            <div class="wq-eyebrow mb-2">Cómo leer esto</div>
            <p class="text-[0.7rem] text-slate-400 leading-relaxed">
                Los resultados son <strong class="text-slate-200">contrafactuales</strong>: no son un pronóstico,
                sino la respuesta del modelo ante un cambio de sus entradas. Sirven para comparar
                decisiones, no para anunciar eventos.
            </p>
            <p class="wq-legal mt-3">
                Recomendación de apoyo a la decisión. No es una alerta oficial.
            </p>
        </section>
    </aside>

    <!-- ===================== RESULTADOS ===================== -->
    <div class="space-y-5">

        <!-- Encabezado del resultado -->
        <section class="wq-panel-dark px-6 py-5 flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="wq-eyebrow mb-1"><?= e($modoTabs[$modo][0] ?? 'Simulación') ?></div>
                <h2 class="wq-title text-2xl"><?= e($t['distrito']) ?> <span class="text-slate-500 text-base">· <?= e($t['region']) ?></span></h2>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <span class="wq-chip">lluvia <?= e($factorTexto) ?></span>
                <?php if ($esClima): ?><span class="wq-chip">temp <?= ($delta >= 0 ? '+' : '') . number_format($delta, 1) ?> °C</span><?php endif; ?>
                <span class="wq-chip">ventana <?= (int)$ventana ?> h</span>
                <span class="wq-chip"><?= e(date('d/m/Y H:i')) ?></span>
            </div>
        </section>

        <?php if ($esClima && isset($simulacion['base'])): ?>
            <?php
            $base = $simulacion['base'];
            $sim = $simulacion['simulado'];
            $d = $simulacion['deltas'];
            $nBase = $base['nivel']['id'];
            $nSim = $sim['nivel']['id'];
            ?>
            <!-- Riesgo hoy vs escenario -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ([
                    ['Hoy', $base, 'bg-white/[0.02]'],
                    ['Escenario', $sim, 'bg-brand-gold/[0.05] !border-brand-gold/30'],
                ] as [$rotulo, $res, $extra]): ?>
                    <?php $nivelId = $res['nivel']['id']; ?>
                    <section class="wq-card p-5 <?= $extra ?>">
                        <div class="flex items-center justify-between mb-3">
                            <span class="wq-eyebrow"><?= e($rotulo) ?></span>
                            <span class="wq-badge wq-badge--<?= e($nivelId) ?>">
                                <span class="wq-dot wq-dot--<?= e($nivelId) ?>"></span>
                                <?= e($etiquetasNivel[$nivelId] ?? $nivelId) ?>
                            </span>
                        </div>
                        <div class="flex items-end gap-3">
                            <span class="wq-num text-[3.2rem] leading-none wq-level-text--<?= e($nivelId) ?>">
                                <?= (int)round($res['riesgo']) ?>
                            </span>
                            <span class="text-[0.66rem] uppercase tracking-[0.14em] text-slate-500 pb-2">/ 100</span>
                        </div>
                        <div class="wq-bar h-2 mt-3">
                            <div class="wq-bar__fill wq-bar__fill--<?= e($nivelId) ?>" style="width: <?= max(2, (float)$res['riesgo']) ?>%"></div>
                        </div>
                        <div class="mt-3 flex items-center justify-between text-[0.7rem]">
                            <span class="text-slate-400"><?= e($res['amenazas'][$res['principal']]['nombre'] ?? '—') ?></span>
                            <span class="text-slate-400"><?= number_format((float)($res['datos']['lluvia_ventana'] ?? 0), 1) ?> mm</span>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <!-- Deltas -->
            <section class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                <?php
                $deltas = [
                    ['Índice de riesgo', ($d['riesgo'] >= 0 ? '+' : '') . $d['riesgo'], 'puntos', $d['riesgo'] > 0 ? 'rojo' : ($d['riesgo'] < 0 ? 'verde' : 'amarillo')],
                    ['Población en alto riesgo', ($d['poblacion'] >= 0 ? '+' : '') . numero_legible($d['poblacion']), 'habitantes', $d['poblacion'] > 0 ? 'rojo' : 'verde'],
                    ['Colegios afectados', ($d['escuelas'] >= 0 ? '+' : '') . (int)$d['escuelas'], 'instituciones', $d['escuelas'] > 0 ? 'rojo' : 'verde'],
                    ['Lluvia acumulada', ($d['lluvia_mm'] >= 0 ? '+' : '') . number_format($d['lluvia_mm'], 1), 'mm', $d['lluvia_mm'] > 0 ? 'naranja' : 'verde'],
                ];
                foreach ($deltas as [$lbl, $val, $uni, $color]): ?>
                    <div class="wq-kpi wq-kpi--<?= e($color) ?>">
                        <div class="wq-kpi__value wq-level-text--<?= e($color) ?>"><?= e($val) ?></div>
                        <div class="wq-kpi__label"><?= e($lbl) ?></div>
                        <div class="text-[0.62rem] text-slate-600 mt-1"><?= e($uni) ?></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Amenazas comparadas -->
            <section class="wq-card">
                <div class="wq-card__head">
                    <div>
                        <h2 class="text-[0.95rem] font-normal tracking-wide">Las seis amenazas bajo el escenario</h2>
                        <p class="text-[0.68rem] text-slate-500 mt-0.5">Barra clara: hoy · barra dorada: escenario</p>
                    </div>
                </div>
                <div class="wq-card__body space-y-3.5">
                    <?php foreach ($base['amenazas'] as $id => $ab): ?>
                        <?php $as = $sim['amenazas'][$id]; ?>
                        <div>
                            <div class="flex items-center justify-between text-[0.76rem] mb-1">
                                <span class="text-slate-200"><?= e($ab['nombre']) ?></span>
                                <span class="wq-num text-slate-400">
                                    <?= (int)round($ab['riesgo']) ?>
                                    <i class="fa-solid fa-arrow-right text-[0.55rem] text-slate-600 mx-1"></i>
                                    <span class="wq-level-text--<?= e($as['nivel']['id']) ?>"><?= (int)round($as['riesgo']) ?></span>
                                </span>
                            </div>
                            <div class="relative h-2 rounded-full bg-white/[0.06] overflow-hidden">
                                <div class="absolute inset-y-0 left-0 rounded-full bg-white/25"
                                     style="width: <?= max(1, (float)$ab['riesgo']) ?>%"></div>
                                <div class="absolute inset-y-0 left-0 rounded-full border-t border-brand-gold/80"
                                     style="width: <?= max(1, (float)$as['riesgo']) ?>%; background: linear-gradient(90deg, rgba(197,168,128,.35), rgba(197,168,128,.75));"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="px-5 py-3 border-t border-white/[0.06] text-[0.64rem] text-slate-500">
                    Escenario: lluvia ×<?= number_format($factor, 2, '.', '') ?> y temperatura aparente
                    <?= ($delta >= 0 ? '+' : '') . number_format($delta, 1) ?> °C. Mismo motor, distintas entradas.
                </div>
            </section>

        <?php elseif ($esEvac && isset($simulacion['resultado'])): ?>
            <?php
            $res = $simulacion['resultado'];
            $esc = $simulacion['escenario'];
            $nEsc = $esc['nivel']['id'];
            $deficitColor = $res['deficit'] > 0 ? 'rojo' : 'verde';
            ?>
            <section class="wq-panel-dark p-6 relative overflow-hidden">
                <div class="absolute -right-16 -top-16 w-56 h-56 rounded-full blur-3xl opacity-20 wq-level-bg--<?= e($nEsc) ?>"></div>
                <div class="relative grid grid-cols-1 md:grid-cols-2 gap-6">

                    <div>
                        <div class="wq-eyebrow mb-2">Población a evacuar</div>
                        <div class="wq-num text-[3.6rem] leading-none text-white">
                            <?= numero_legible($res['poblacion_evacuar']) ?>
                        </div>
                        <div class="text-[0.7rem] text-slate-400 mt-1">habitantes en el escenario simulado</div>

                        <div class="mt-5 space-y-2.5 text-[0.76rem]">
                            <div class="flex justify-between">
                                <span class="text-slate-400">Capacidad de albergues</span>
                                <span class="text-white"><?= numero_legible($res['capacidad_albergues']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Déficit</span>
                                <span class="wq-level-text--<?= e($deficitColor) ?> font-medium">
                                    <?= numero_legible($res['deficit']) ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-400">Cobertura</span>
                                <span class="text-white"><?= number_format($res['cobertura_pct'], 1, '.', '') ?> %</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col justify-between">
                        <div>
                            <div class="wq-eyebrow mb-2">Tiempo estimado de evacuación</div>
                            <div class="flex items-end gap-2">
                                <span class="wq-num text-[2.6rem] leading-none text-brand-gold"><?= number_format($res['horas_estimadas'], 1, '.', '') ?></span>
                                <span class="text-[0.72rem] text-slate-400 pb-2">horas</span>
                            </div>
                            <p class="text-[0.66rem] text-slate-500 mt-2 leading-relaxed">
                                Supuesto: <?= numero_legible($res['personas_por_hora']) ?> personas por hora por la vía principal.
                                Es un supuesto declarado, no un dato medido.
                            </p>
                        </div>

                        <div class="mt-4">
                            <div class="flex justify-between text-[0.58rem] uppercase tracking-[0.12em] text-slate-500 mb-1.5">
                                <span>Cobertura de albergues</span>
                                <span><?= number_format($res['cobertura_pct'], 0, '.', '') ?> %</span>
                            </div>
                            <div class="wq-bar h-2">
                                <div class="wq-bar__fill wq-bar__fill--<?= $res['cobertura_pct'] >= 80 ? 'verde' : ($res['cobertura_pct'] >= 50 ? 'amarillo' : 'rojo') ?>"
                                     style="width: <?= max(2, (float)$res['cobertura_pct']) ?>%"></div>
                            </div>
                        </div>

                        <div class="mt-4 pt-4 border-t border-white/10 flex items-center justify-between text-[0.7rem]">
                            <span class="text-slate-400">Riesgo del escenario</span>
                            <span class="wq-badge wq-badge--<?= e($nEsc) ?>">
                                <?= (int)round($esc['riesgo']) ?> · <?= e($etiquetasNivel[$nEsc] ?? $nEsc) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($res['deficit'] > 0): ?>
                <section class="wq-panel-dark p-5 border-risk-red/30">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-risk-red mt-0.5"></i>
                        <div>
                            <div class="text-[0.85rem] text-white mb-1">
                                Faltan <?= numero_legible($res['deficit']) ?> plazas de albergue
                            </div>
                            <p class="text-[0.74rem] text-slate-400 leading-relaxed">
                                Bajo este escenario, la capacidad instalada no cubre a la población que
                                necesita evacuación. La brecha debe cerrarse con albergues temporales,
                                traslado a distritos vecinos o evacuación preventiva anticipada.
                            </p>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <section class="wq-panel-dark p-5 border-risk-green/30">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-circle-check text-risk-green mt-0.5"></i>
                        <div>
                            <div class="text-[0.85rem] text-white mb-1">Capacidad suficiente en este escenario</div>
                            <p class="text-[0.74rem] text-slate-400 leading-relaxed">
                                La capacidad de albergues cubre la población a evacuar. Verifique igualmente
                                rutas, accesibilidad y tiempos reales de traslado.
                            </p>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="wq-card">
                <div class="wq-card__head">
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Detalle del escenario</h2>
                    <span class="wq-chip"><?= number_format($factor, 2, '.', '') ?>× lluvia</span>
                </div>
                <div class="wq-card__body grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.74rem]">
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Riesgo</div>
                        <div class="text-white"><?= (int)round($esc['riesgo']) ?> / 100</div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Amenaza dominante</div>
                        <div class="text-white"><?= e($esc['amenazas'][$esc['principal']]['nombre'] ?? '—') ?></div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Lluvia acumulada</div>
                        <div class="text-white"><?= number_format((float)($esc['datos']['lluvia_ventana'] ?? 0), 1) ?> mm</div>
                    </div>
                    <div>
                        <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Población total</div>
                        <div class="text-white"><?= numero_legible($t['poblacion']) ?></div>
                    </div>
                </div>
            </section>

        <?php elseif ($esReub && $simulacion): ?>
            <?php
            $base = $simulacion['base'];
            $fut = $simulacion['futuro'];
            ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <section class="wq-card p-5">
                    <div class="wq-eyebrow mb-3">Aptitud hoy</div>
                    <div class="flex items-end gap-3">
                        <span class="wq-num text-[3.2rem] leading-none" style="color: <?= e($base['clase']['color']) ?>">
                            <?= (int)round($base['ise']) ?>
                        </span>
                        <div class="pb-1.5">
                            <div class="text-[0.62rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">ISE</div>
                            <div class="text-[0.82rem]" style="color: <?= e($base['clase']['color']) ?>">
                                <?= e($base['clase']['id']) ?> · <?= e($base['clase']['nombre']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="wq-bar h-2 mt-4">
                        <div class="wq-bar__fill" style="width: <?= max(2, (float)$base['ise']) ?>%; background: <?= e($base['clase']['color']) ?>"></div>
                    </div>
                    <p class="mt-3 text-[0.74rem] text-slate-400">
                        <?= e($base['tipo_etiqueta']) ?> en <?= e($base['territorio']['distrito']) ?>
                    </p>
                </section>

                <section class="wq-card p-5 !border-brand-gold/35 bg-brand-gold/[0.04]">
                    <div class="wq-eyebrow mb-3">Aptitud con +<?= (int)round(($fut['factor_lluvia'] - 1) * 100) ?> % de lluvia</div>
                    <div class="flex items-end gap-3">
                        <span class="wq-num text-[3.2rem] leading-none" style="color: <?= e($fut['clase']['color']) ?>">
                            <?= (int)round($fut['ise']) ?>
                        </span>
                        <div class="pb-1.5">
                            <div class="text-[0.62rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">ISE</div>
                            <div class="text-[0.82rem]" style="color: <?= e($fut['clase']['color']) ?>">
                                <?= e($fut['clase']['id']) ?> · <?= e($fut['clase']['nombre']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="wq-bar h-2 mt-4">
                        <div class="wq-bar__fill" style="width: <?= max(2, (float)$fut['ise']) ?>%; background: <?= e($fut['clase']['color']) ?>"></div>
                    </div>
                    <p class="mt-3 text-[0.74rem] text-slate-400">
                        Cambio: <strong class="<?= $simulacion['delta'] < 0 ? 'text-risk-red' : 'text-risk-green' ?>">
                            <?= $simulacion['delta'] > 0 ? '+' : '' ?><?= $simulacion['delta'] ?>
                        </strong> puntos de ISE
                    </p>
                </section>
            </div>

            <section class="wq-panel-dark p-5">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-arrows-turn-to-dots text-brand-gold/70 mt-0.5"></i>
                    <div>
                        <div class="text-[0.85rem] text-white mb-1">Lectura para la decisión</div>
                        <p class="text-[0.74rem] text-slate-400 leading-relaxed">
                            <?php if ($fut['clase']['id'] === 'D' && $base['clase']['id'] !== 'D'): ?>
                                El sitio es aceptable hoy, pero <strong class="text-white">dejaría de serlo</strong> bajo un
                                escenario de lluvia más intensa. Construir aquí traslada el riesgo a la siguiente generación.
                                Se recomienda evaluar un emplazamiento alternativo.
                            <?php elseif ($fut['clase']['id'] === 'C'): ?>
                                El sitio queda <strong class="text-white">restringido</strong> en el escenario futuro:
                                exigiría obras de mitigación dimensionadas para lluvias más intensas que las actuales.
                            <?php elseif ($simulacion['delta'] < 0): ?>
                                La aptitud se degrada de forma moderada pero conserva la clase. Documente las
                                medidas de mitigación en el expediente de licencia.
                            <?php else: ?>
                                El sitio mantiene su clase de aptitud en el escenario simulado.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <p class="wq-legal mt-4">
                    Proyección de sensibilidad, no pronóstico climático. No reemplaza un estudio de riesgo
                    ni una alerta oficial.
                </p>
            </section>

        <?php else: ?>
            <section class="wq-panel-dark p-8 text-center">
                <i class="fa-solid fa-flask text-3xl text-slate-700 mb-3 block"></i>
                <p class="text-[0.82rem] text-slate-400">
                    No se pudo ejecutar la simulación para este territorio.
                </p>
                <a href="<?= e(url('simulador', ['modo' => 'clima'])) ?>" class="wq-link text-[0.74rem] inline-block mt-3">
                    Volver al escenario climático
                </a>
            </section>
        <?php endif; ?>

        <!-- Trazabilidad del cálculo -->
        <section class="wq-panel-dark p-5">
            <div class="wq-eyebrow mb-3">Trazabilidad</div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-[0.7rem]">
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Motor</div>
                    <div class="text-slate-300">Riesgo multi-amenaza v1</div>
                </div>
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Fórmula</div>
                    <div class="text-slate-300 font-mono text-[0.66rem]">R = 100·H·(0.40+0.35·E+0.25·V)</div>
                </div>
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Datos</div>
                    <div class="text-slate-300">Open-Meteo · GloFAS</div>
                </div>
                <div>
                    <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">API</div>
                    <a class="wq-link text-[0.68rem]" target="_blank" rel="noopener"
                       href="<?= e(url('api_simular', ['id' => $t['id'], 'lluvia' => $factor, 'temp' => $delta, 'modo' => $modo])) ?>">
                        Ver JSON
                    </a>
                </div>
            </div>
        </section>
    </div>
</div>
