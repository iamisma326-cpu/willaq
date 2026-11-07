<?php
/**
 * VISTA — METODOLOGÍA, FUENTES Y LÍMITES
 *
 * Esta página es el antídoto contra la pregunta más difícil del jurado:
 * "¿y de dónde sale ese número?". Todo lo que la interfaz muestra está aquí,
 * con fórmula, fuente, versión y límites declarados.
 */
$secciones = [
    ['metodologia', 'Metodología'],
    ['amenazas', 'Amenazas'],
    ['fuentes', 'Fuentes de datos'],
    ['offline', 'Producción y local'],
    ['limites', 'Límites y alcance'],
    ['reutilizacion', 'Qué es nuevo'],
    ['arquitectura', 'Arquitectura'],
    ['glosario', 'Glosario'],
];
?>

<header class="mb-8">
    <div class="wq-eyebrow mb-2">Transparencia</div>
    <h1 class="wq-title text-[2rem] lg:text-[2.7rem] leading-none">Cómo funciona WILLAQ</h1>
    <p class="text-[0.88rem] text-slate-400 mt-3 max-w-3xl leading-relaxed">
        Cada número de la plataforma es reproducible: fórmula publicada, datos citados,
        supuestos declarados y límites explícitos. Si un dato no está disponible, se dice;
        no se inventa.
    </p>
</header>

<div class="grid grid-cols-1 xl:grid-cols-[240px_1fr] gap-6">

    <!-- Índice -->
    <aside class="hidden xl:block">
        <div class="sticky top-24 wq-panel-dark p-4">
            <div class="wq-eyebrow mb-3">En esta página</div>
            <nav class="space-y-1.5">
                <?php foreach ($secciones as [$id, $nombre]): ?>
                    <a href="#<?= e($id) ?>"
                       class="block text-[0.74rem] text-slate-400 hover:text-brand-gold transition-colors py-0.5">
                        <?= e($nombre) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="mt-4 pt-4 border-t border-white/10">
                <a href="<?= e(url('api_riesgo')) ?>" target="_blank" rel="noopener"
                   class="text-[0.66rem] uppercase tracking-[0.14em] text-brand-gold">
                    API JSON →
                </a>
            </div>
        </div>
    </aside>

    <div class="space-y-6 min-w-0">

        <!-- ===================== METODOLOGÍA ===================== -->
        <section id="metodologia" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Metodología del índice</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Versión 1 · Riesgo multi-amenaza explicable</p>
                </div>
                <span class="wq-chip">R = 100 · H · (0.40 + 0.35·E + 0.25·V)</span>
            </div>

            <div class="wq-card__body space-y-5">
                <div>
                    <h3 class="text-[0.82rem] text-white mb-2">La idea en una frase</h3>
                    <p class="text-[0.82rem] text-slate-300 leading-relaxed">
                        El <strong class="text-white">peligro (H)</strong> es la condición necesaria: si no llueve
                        ni sube el río, no hay riesgo, por mucha población que haya.
                        La <strong class="text-white">exposición (E)</strong> y la
                        <strong class="text-white">vulnerabilidad (V)</strong> no crean el evento:
                        lo <em>amplifican</em>. Por eso H multiplica y E/V suman dentro del paréntesis.
                    </p>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <?php foreach ([
                        ['H', 'Peligro', 'Intensidad del evento comparada con su propio percentil histórico.', '0.40'],
                        ['E', 'Exposición', 'Fracción de población, colegios y salud dentro de la zona afectable.', '0.35'],
                        ['V', 'Vulnerabilidad', 'Capacidad de respuesta: infraestructura, pobreza, accesibilidad.', '0.25'],
                    ] as [$sigla, $nombre, $desc, $peso]): ?>
                        <div class="wq-panel-dark p-4">
                            <div class="flex items-baseline justify-between mb-1">
                                <span class="wq-num text-2xl text-brand-gold"><?= e($sigla) ?></span>
                                <span class="text-[0.58rem] uppercase tracking-[0.14em] text-slate-500">peso <?= e($peso) ?></span>
                            </div>
                            <div class="text-[0.78rem] text-white mb-1"><?= e($nombre) ?></div>
                            <p class="text-[0.68rem] text-slate-400 leading-relaxed"><?= e($desc) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div>
                    <h3 class="text-[0.82rem] text-white mb-2">Normalización del peligro (H)</h3>
                    <p class="text-[0.78rem] text-slate-400 leading-relaxed mb-3">
                        No usamos umbrales absolutos: el mismo caudal significa cosas distintas en el río Piura
                        y en el Rímac. H se calcula contra la distribución local de los últimos 11 años.
                    </p>
                    <div class="wq-panel-dark p-4 font-mono text-[0.72rem] text-slate-300 space-y-2 overflow-x-auto">
                        <div>Si v ≤ p95:&nbsp;&nbsp;H = 0.75 · (v − p50) / (p95 − p50)</div>
                        <div>Si v &gt; p95:&nbsp;&nbsp;H = 0.75 + 0.25 · min(1, (v − p95) / (p99 − p95))</div>
                    </div>
                    <p class="text-[0.7rem] text-slate-500 mt-2 leading-relaxed">
                        La primera rama reparte 0–0.75 entre la mediana y el percentil 95.
                        La segunda satura 0.25 adicionales entre p95 y p99. Así el índice distingue
                        "evento frecuente" de "evento extraordinario" sin explotar ante un solo valor atípico.
                    </p>
                </div>

                <div>
                    <h3 class="text-[0.82rem] text-white mb-2">Materialidad: cuando "extremo" no significa "peligroso"</h3>
                    <p class="text-[0.78rem] text-slate-400 leading-relaxed mb-3">
                        El percentil mide <em>rareza</em>, no <em>impacto</em>. En Lima el p99 diario de lluvia
                        es ~1 mm: 1.3 mm de llovizna sería "estadísticamente extremo" y a la vez una falta de
                        criterio. Por eso cada amenaza declara un <strong class="text-slate-200">umbral de
                        materialidad</strong> y el peligro final multiplica ambos factores:
                        <span class="text-brand-gold">H = H_percentil × factor_materialidad</span>.
                    </p>
                    <div class="wq-panel-dark p-4 font-mono text-[0.72rem] text-slate-300 space-y-2 overflow-x-auto">
                        <div>M = clamp( (v − min) / (signo − min), 0, 1 )</div>
                        <div class="text-slate-500">min = máximo(umbral absoluto, p95 local)</div>
                    </div>
                    <div class="overflow-x-auto mt-3">
                        <table class="wq-table">
                            <thead>
                                <tr>
                                    <th class="!pl-5">Amenaza</th>
                                    <th>Desde (min)</th>
                                    <th class="!pr-5">Evento pleno (signo)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ([
                                    ['Lluvia 24 h', '3 mm', '15 mm'],
                                    ['Lluvia 48 h', '5 mm', '25 mm'],
                                    ['Lluvia 72 h', '7 mm', '35 mm'],
                                    ['Intensidad 3 h (huaico)', '4 mm', '15 mm'],
                                    ['Calor (temperatura aparente)', '28 °C', '35 °C'],
                                    ['Sequía (déficit)', '15 %', '50 %'],
                                ] as [$amenaza, $min, $signo]): ?>
                                    <tr>
                                        <td class="!pl-5 text-white/90 whitespace-nowrap"><?= e($amenaza) ?></td>
                                        <td class="text-[0.74rem] text-slate-400"><?= e($min) ?></td>
                                        <td class="!pr-5 text-[0.74rem] text-slate-400"><?= e($signo) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-[0.7rem] text-slate-500 mt-2 leading-relaxed">
                        El factor resultante se muestra en cada ficha y en el reporte PDF: la decisión es
                        auditable, no una caja negra.
                    </p>
                </div>

                <div>
                    <h3 class="text-[0.82rem] text-white mb-2">Cortes de nivel</h3>
                    <div class="overflow-x-auto">
                        <table class="wq-table">
                            <thead>
                                <tr>
                                    <th class="!pl-5">Índice</th>
                                    <th>Nivel</th>
                                    <th>Lectura operativa</th>
                                    <th class="!pr-5">Acción sugerida</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ([
                                    ['0 – 25', 'verde', 'Bajo', 'Monitoreo y mantenimiento preventivo.'],
                                    ['26 – 50', 'amarillo', 'Medio', 'Limpieza de drenajes y verificación de rutas.'],
                                    ['51 – 75', 'naranja', 'Alto', 'Activación del COE y albergues preparados.'],
                                    ['76 – 100', 'rojo', 'Muy alto', 'Evacuación preventiva y suspensión de actividades.'],
                                ] as [$rango, $nivel, $etiqueta, $accion]): ?>
                                    <tr>
                                        <td class="!pl-5 wq-num text-white"><?= e($rango) ?></td>
                                        <td>
                                            <span class="wq-badge wq-badge--<?= e($nivel) ?>">
                                                <span class="wq-dot wq-dot--<?= e($nivel) ?>"></span>
                                                <?= e($etiqueta) ?>
                                            </span>
                                        </td>
                                        <td class="text-[0.74rem] text-slate-400"><?= e($etiqueta) ?></td>
                                        <td class="!pr-5 text-[0.72rem] text-slate-400"><?= e($accion) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===================== AMENAZAS ===================== -->
        <section id="amenazas" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Las seis amenazas</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Cada una con su propio disparador físico</p>
                </div>
            </div>
            <div class="wq-card__body">
                <div class="overflow-x-auto">
                    <table class="wq-table">
                        <thead>
                            <tr>
                                <th class="!pl-5">Amenaza</th>
                                <th>Disparador</th>
                                <th>Fuente principal</th>
                                <th class="!pr-5">Qué mide</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ([
                                ['Lluvia extrema', 'Precipitación acumulada en 24/48/72 h', 'Open-Meteo Forecast', 'Probabilidad e intensidad de lluvia frente al p95 local.'],
                                ['Inundación fluvial', 'Caudal máximo pronosticado vs. p95 de 31 días previos', 'Open-Meteo Flood (GloFAS)', 'Anomalía relativa del río, no un umbral absoluto.'],
                                ['Huaico / deslizamiento', 'Intensidad máxima en 3 h × susceptibilidad de cuenca', 'Open-Meteo horario', 'Lluvia concentrada sobre quebradas con pendiente.'],
                                ['Sequía', 'Déficit de lluvia a 90 días y días secos', 'Open-Meteo Archive (ERA5)', 'Estrés hídrico estructural, no evento puntual.'],
                                ['Calor extremo', 'Temperatura aparente máxima vs. p95 local', 'Open-Meteo Forecast', 'Riesgo para la salud, especialmente en costa norte.'],
                                ['Riesgo agropecuario', 'Estrés hídrico + térmico × sensibilidad del cultivo', 'ERA5 + Open-Meteo', 'Impacto sobre arroz, banano, maíz y otros cultivos.'],
                            ] as [$nombre, $disparador, $fuente, $mide]): ?>
                                <tr>
                                    <td class="!pl-5 text-white/90 whitespace-nowrap"><?= e($nombre) ?></td>
                                    <td class="text-[0.72rem] text-slate-400"><?= e($disparador) ?></td>
                                    <td class="text-[0.7rem] text-slate-400"><?= e($fuente) ?></td>
                                    <td class="!pr-5 text-[0.72rem] text-slate-400"><?= e($mide) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-[0.68rem] text-slate-500 mt-3 leading-relaxed">
                    La amenaza dominante es la de mayor índice. Las demás siguen visibles en la ficha:
                    un distrito puede tener lluvia baja y sequía alta al mismo tiempo.
                </p>
            </div>
        </section>

        <!-- ===================== FUENTES ===================== -->
        <section id="fuentes" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Fuentes de datos</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Abiertas, citables y sin costo para el piloto</p>
                </div>
            </div>
            <div class="wq-card__body space-y-4">
                <?php foreach ([
                    [
                        'Open-Meteo Forecast',
                        'https://open-meteo.com/',
                        'Pronóstico diario y horario: precipitación, probabilidad, temperatura aparente, viento y evapotranspiración. Modelos ERA5/ICON.',
                    ],
                    [
                        'Open-Meteo Flood (GloFAS)',
                        'https://open-meteo.com/en/docs/flood-api',
                        'Caudal de río a 5 km de resolución. Se usan 31 días de pasado para calcular la anomalía local.',
                    ],
                    [
                        'Open-Meteo Archive (ERA5)',
                        'https://open-meteo.com/en/docs/historical-weather-api',
                        'Serie histórica para climatología (p50/p95/p99) y validación con El Niño 2017 y 2023.',
                    ],
                    [
                        'NASA GIBS',
                        'https://www.earthdata.nasa.gov/eosdis/science-system-description/eosdis-components/gibs',
                        'Capas satelitales opcionales: IMERG Precipitation Rate y VIIRS True Color. Requieren internet; fallan en silencio.',
                    ],
                    [
                        'SENAMHI — Avisos meteorológicos',
                        'https://www.senamhi.gob.pe/?p=aviso-meteorologico',
                        'Avisos oficiales de carácter preventivo ante eventos severos, con las áreas afectadas y el nivel de peligrosidad. WILLAQ NO reemplaza sus avisos.',
                    ],
                    [
                        'ENFEN — Estado oficial de El Niño',
                        'https://enfen.imarpe.gob.pe/',
                        'Comisión Multisectorial que caracteriza el fenómeno y publica comunicados oficiales. Su comunicado en curso recomienda a los tomadores de decisiones considerar estudios de escenarios de riesgo.',
                    ],
                    [
                        'INDECI — Visor y SINPAD',
                        'https://geo.indeci.gob.pe/visorindeci/',
                        'Herramienta oficial de soporte técnico para la gestión de emergencias: rutas de evacuación, zonas seguras y reportes. WILLAQ entrega recomendaciones, no respuestas a emergencias.',
                    ],
                    [
                        'Google Flood Hub — Pronóstico global de crecidas',
                        'https://sites.research.google/floodforecasting/',
                        'Predecesor técnico de referencia: pronóstico de crecidas a 7 días y aniegos urbanos a 24 h con IA. Complementa, no sustituye, a los sistemas oficiales.',
                    ],
                    [
                        'OpenStreetMap / CARTO',
                        'https://www.openstreetmap.org/',
                        'Mapa base opcional para contexto geográfico. El mapa de riesgo funciona sin teselas.',
                    ],
                ] as [$nombre, $url, $desc]): ?>
                    <div class="flex items-start gap-3 pb-4 border-b border-white/[0.06] last:border-0 last:pb-0">
                        <i class="fa-solid fa-database text-brand-gold/50 mt-0.5 text-[0.7rem]"></i>
                        <div class="min-w-0">
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener"
                               class="text-[0.82rem] text-white hover:text-brand-gold transition-colors">
                                <?= e($nombre) ?>
                            </a>
                            <p class="text-[0.72rem] text-slate-400 mt-1 leading-relaxed"><?= e($desc) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="wq-panel-dark p-4">
                    <div class="wq-eyebrow mb-2">Exposición y territorio</div>
                    <p class="text-[0.72rem] text-slate-400 leading-relaxed">
                        <?= e(exposicion_texto_breve()) ?>
                    </p>
                    <?php $expEstado = exposicion_campos_estado(); ?>
                    <?php if ($expEstado['estado'] !== 'estimada'): ?>
                        <p class="text-[0.68rem] text-slate-500 mt-2 leading-relaxed">
                            La procedencia se declara <strong class="text-slate-300">campo por campo</strong>
                            en cada ficha, con fuente, año y <span class="wq-kbd">sha256</span> del archivo
                            de origen. El overlay se aplica con
                            <span class="wq-kbd">scripts/importar_exposicion.php --aplicar</span> y nunca
                            reescribe el catálogo base.
                        </p>
                    <?php else: ?>
                        <p class="text-[0.68rem] text-slate-500 mt-2 leading-relaxed">
                            El importador <span class="wq-kbd">scripts/importar_exposicion.php</span>
                            escribe un overlay con procedencia por campo cuando se cargan los padrones
                            oficiales de INEI, MINEDU y MINSA; el catálogo base nunca se reescribe.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ===================== VALIDACIÓN ===================== -->
        <section id="validacion" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Validación histórica y sensibilidad</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">El motor puesto a prueba contra la historia</p>
                </div>
                <span class="wq-chip">scripts/validar_historico.php · scripts/sensibilidad.php</span>
            </div>
            <div class="wq-card__body space-y-4">
                <p class="text-[0.8rem] text-slate-300 leading-relaxed">
                    Un índice de riesgo que nunca se pone a prueba es una fórmula bonita. WILLAQ corre
                    <strong class="text-white">el mismo motor de producción</strong> sobre la serie ERA5 de
                    11 años y lo contrasta con los episodios de El Niño costero de 2017, 2017–2018 y 2023.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-[0.72rem]">
                        <thead>
                            <tr class="text-slate-500 text-left">
                                <th class="pb-2 font-normal">Episodio</th>
                                <th class="pb-2 font-normal text-right">Acierto</th>
                                <th class="pb-2 font-normal text-right">Con señal clara</th>
                                <th class="pb-2 font-normal text-right">Fallas del modelo</th>
                            </tr>
                        </thead>
                        <tbody class="text-slate-300">
                            <tr class="border-t border-white/[0.06]">
                                <td class="py-1.5">2017</td>
                                <td class="py-1.5 text-right text-white">6/6 · 100 %</td>
                                <td class="py-1.5 text-right text-white">6/6 · 100 %</td>
                                <td class="py-1.5 text-right text-risk-green font-medium">0</td>
                            </tr>
                            <tr class="border-t border-white/[0.06]">
                                <td class="py-1.5">2017–2018</td>
                                <td class="py-1.5 text-right text-white">1/5 · 20 %</td>
                                <td class="py-1.5 text-right text-white">1/1 · 100 %</td>
                                <td class="py-1.5 text-right text-risk-green font-medium">0</td>
                            </tr>
                            <tr class="border-t border-white/[0.06]">
                                <td class="py-1.5">2023</td>
                                <td class="py-1.5 text-right text-white">6/7 · 86 %</td>
                                <td class="py-1.5 text-right text-white">6/6 · 100 %</td>
                                <td class="py-1.5 text-right text-risk-green font-medium">0</td>
                            </tr>
                            <tr class="border-t border-white/[0.10] bg-white/[0.02]">
                                <td class="py-1.5 text-white font-medium">Total</td>
                                <td class="py-1.5 text-right text-white font-medium">13/18 · 72 %</td>
                                <td class="py-1.5 text-right text-white font-medium">13/13 · 100 %</td>
                                <td class="py-1.5 text-right text-risk-green font-medium">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="wq-panel-dark p-4">
                    <div class="wq-eyebrow mb-2">Por qué el 2017–2018 no se detecta</div>
                    <p class="text-[0.72rem] text-slate-400 leading-relaxed">
                        Cuando un distrito con emergencia documentada no es detectado, el sistema clasifica
                        la causa con reglas fijadas por adelantado:
                        <strong class="text-slate-200">evento ausente en los datos</strong>,
                        <strong class="text-slate-200">evento débil en los datos</strong> o
                        <strong class="text-slate-200">fallo del modelo</strong>.
                        En los 18 casos evaluados, <strong class="text-risk-green">ninguno fue fallo del modelo</strong>:
                        ERA5 a 25 km no registra los extremos orográficos de la costa norte en ese episodio.
                        Distinguir una cosa de la otra es lo que permite decir con honestidad qué hay que
                        corregir: el modelo, o la fuente de datos.
                    </p>
                </div>

                <div>
                    <div class="wq-eyebrow mb-2">Sensibilidad de los pesos</div>
                    <p class="text-[0.72rem] text-slate-400 leading-relaxed mb-3">
                        Los pesos del índice son una decisión de diseño, así que se someten a 40 escenarios
                        (cada peso en ±10 % y ±20 %, las combinaciones simultáneas y dos casos límite).
                    </p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
                        <div class="wq-panel-dark p-3">
                            <div class="text-[1.3rem] text-white leading-none">0.9286</div>
                            <div class="text-[0.6rem] text-slate-500 mt-1">Spearman mínimo</div>
                        </div>
                        <div class="wq-panel-dark p-3">
                            <div class="text-[1.3rem] text-white leading-none">0.992</div>
                            <div class="text-[0.6rem] text-slate-500 mt-1">Top-10 medio</div>
                        </div>
                        <div class="wq-panel-dark p-3">
                            <div class="text-[1.3rem] text-white leading-none">39/40</div>
                            <div class="text-[0.6rem] text-slate-500 mt-1">Top-10 idéntico</div>
                        </div>
                        <div class="wq-panel-dark p-3">
                            <div class="text-[1.3rem] text-white leading-none">0</div>
                            <div class="text-[0.6rem] text-slate-500 mt-1">Diferencia vs motor real</div>
                        </div>
                    </div>
                    <p class="text-[0.7rem] text-slate-500 leading-relaxed mt-3">
                        El orden de prioridad es estable ante ±20 % en los pesos. El cálculo rápido del
                        análisis se contrasta con el motor real mediante
                        <span class="wq-kbd">php scripts/sensibilidad.php --verificar</span>, que debe
                        reportar diferencia máxima 0.
                    </p>
                </div>

                <p class="text-[0.7rem] text-slate-500 leading-relaxed">
                    Informe completo en <span class="wq-kbd">docs/VALIDACION_HISTORICA.md</span> ·
                    datos reproducibles en <span class="wq-kbd">data/validacion_historica.json</span> y
                    <span class="wq-kbd">data/sensibilidad.json</span>.
                </p>
            </div>
        </section>

        <!-- ===================== OFFLINE ===================== -->
        <section id="offline" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Dónde vive el sistema</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Producción, base de datos y ejecución local</p>
                </div>
            </div>
            <div class="wq-card__body space-y-4">
                <p class="text-[0.8rem] text-slate-300 leading-relaxed">
                    El prototipo está <strong class="text-white">desplegado en producción</strong> y la
                    base de datos PostgreSQL corre como servicio gestionado en Neon. Ambos entornos son
                    gratuitos y no requieren tarjeta.
                </p>

                <div class="grid sm:grid-cols-2 gap-3">
                    <div class="wq-panel-dark p-4">
                        <div class="wq-label mb-1.5">Prototipo en producción</div>
                        <div class="text-[0.78rem] text-brand-gold break-all">willaq-production.up.railway.app</div>
                        <div class="text-[0.66rem] text-slate-500 mt-1">Railway · contenedor Docker · PHP 8.3</div>
                    </div>
                    <div class="wq-panel-dark p-4">
                        <div class="wq-label mb-1.5">Base de datos en producción</div>
                        <div class="text-[0.78rem] text-brand-gold">Neon · PostgreSQL 18</div>
                        <div class="text-[0.66rem] text-slate-500 mt-1">Serverless · región aws-us-east-1</div>
                    </div>
                </div>

                <p class="text-[0.8rem] text-slate-300 leading-relaxed">
                    El proyecto completo <strong class="text-white">también se ejecuta en local</strong>
                    sin depender de Railway ni de Neon: basta con PHP y un PostgreSQL local.
                </p>
                <div class="wq-panel-dark p-4 font-mono text-[0.7rem] text-slate-300 space-y-1.5 overflow-x-auto">
                    <div class="text-slate-500"># Local: crear la base y cargar los territorios</div>
                    <div>psql -U willaq -d willaq_db -f database/schema_postgresql.sql</div>
                    <div>psql -U willaq -d willaq_db -f database/seed_territorios.sql</div>
                    <div class="text-slate-500 pt-2"># Levantar la aplicación</div>
                    <div>php -S localhost:8000</div>
                </div>

                <div class="pt-3 border-t border-white/[0.07]">
                    <h3 class="text-[0.82rem] text-white mb-2">Base de datos obligatoria, respaldo JSON opcional</h3>
                    <p class="text-[0.78rem] text-slate-300 leading-relaxed">
                        La base de datos <strong class="text-white">ya no es opcional</strong>: guarda usuarios,
                        alertas, los 104 territorios, la caché de geodatos y los snapshots de riesgo.
                        Lo que <em>sí</em> es opcional es el <strong class="text-white">respaldo en archivos
                        JSON</strong>. Si el sistema debe operar sin base de datos, cae automáticamente a
                        <span class="wq-kbd">config/territorios.json</span> y a los snapshots en
                        <span class="wq-kbd">data/snapshots/</span>, y sigue funcionando en modo de solo
                        lectura sobre datos pregenerados.
                    </p>
                </div>

                <div class="pt-3 border-t border-white/[0.07]">
                    <h3 class="text-[0.82rem] text-white mb-2">Actualización automática cada 6 horas</h3>
                    <p class="text-[0.78rem] text-slate-300 leading-relaxed mb-3">
                        Un cron externo regenera los snapshots de riesgo, porque Railway no ofrece
                        tareas programadas. El endpoint está protegido por token
                        (<span class="wq-kbd">WILLAQ_CRON_TOKEN</span>).
                    </p>
                    <div class="wq-panel-dark p-4 font-mono text-[0.7rem] text-slate-300 space-y-1.5 overflow-x-auto">
                        <div class="text-slate-500"># Lanzar la actualización (responde 202 al instante)</div>
                        <div>/?action=cron_actualizar</div>
                        <div class="text-slate-500 pt-2"># Consultar el estado de los snapshots</div>
                        <div>/?action=cron_estado</div>
                    </div>
                </div>

                <p class="text-[0.7rem] text-slate-500 leading-relaxed">
                    La plataforma <strong class="text-slate-400">siempre</strong> intenta leer el snapshot
                    de riesgo antes de recalcular: la sala de situación responde en ~2 s en vez de esperar
                    ~70 s de llamadas a Open-Meteo. Si no hay internet, usa ese mismo snapshot y lo declara
                    con una franja visible en pantalla.
                </p>
            </div>
        </section>

        <!-- ===================== LÍMITES ===================== -->
        <section id="limites" class="wq-card scroll-mt-24 !border-risk-yellow/25">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Límites y alcance</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Lo que WILLAQ no hace, dicho sin ambigüedad</p>
                </div>
                <i class="fa-solid fa-triangle-exclamation text-risk-yellow/70"></i>
            </div>
            <div class="wq-card__body space-y-3">
                <?php
                // El límite de exposición se redacta desde el overlay cargado:
                // si mañana se aplican colegios y centros de salud oficiales,
                // esta línea cambia sola (y no se queda mintiendo).
                $limiteExposicion = exposicion_texto_limite();
                ?>
                <?php foreach ([
                    ['No emite alertas oficiales.', 'Entrega recomendaciones de apoyo a la decisión. La alerta oficial corresponde a SENAMHI, INDECI y ENFEN.'],
                    ['No predice terremotos ni erupciones.', 'El alcance es hidrometeorológico: lluvia, inundación, huaico, sequía, calor y agro.'],
                    ['No reemplaza un estudio de suelos.', 'El ISE es un filtro previo de aptitud, no un informe de mecánica de suelos ni un estudio de riesgo.'],
                    [$limiteExposicion[0], $limiteExposicion[1]],
                    ['El snapshot no es tiempo real.', 'La sala de situación lee el último snapshot generado y lo declara con una franja visible; el cron lo refresca cada 6 h.'],
                    ['No tiene cobertura distrital exhaustiva.', 'El catálogo cubre 104 distritos de los 24 departamentos y el Callao, priorizando capitales provinciales y zonas expuestas.'],
                    ['No sustituye al COE.', 'Es una capa de inteligencia que alimenta la decisión humana, no un sistema de mando.'],
                ] as [$titulo, $texto]): ?>
                    <div class="flex items-start gap-3">
                        <span class="wq-dot wq-dot--amarillo mt-1.5"></span>
                        <p class="text-[0.76rem] text-slate-400 leading-relaxed">
                            <strong class="text-slate-200"><?= e($titulo) ?></strong>
                            <?= e($texto) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ===================== REUTILIZACIÓN ===================== -->
        <section id="reutilizacion" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Qué se reutilizó y qué es nuevo</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">Trazabilidad del desarrollo durante el concurso</p>
                </div>
                <span class="wq-chip">cláusula 6.3</span>
            </div>
            <div class="wq-card__body">
                <div class="overflow-x-auto">
                    <table class="wq-table">
                        <thead>
                            <tr>
                                <th class="!pl-5">Componente</th>
                                <th>Origen</th>
                                <th class="!pr-5">Descripción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ([
                                ['Identidad visual', 'Reutilizado', 'Paleta azul estratósfera + dorado y tipografía Montserrat del proyecto base.'],
                                ['Enrutador por acciones', 'Reutilizado', 'Patrón index.php + switch, para que el equipo lo explique línea por línea.'],
                                ['Login y roles', 'Reutilizado y extendido', 'Se conserva la sesión y se añaden roles admin/autoridad/ciudadano.'],
                                ['Caché y cliente HTTP', 'Reutilizado y extendido', 'Se añade caché multinivel con respaldo expirado para operar sin internet.'],
                                ['Motor de riesgo', 'Nuevo', 'Fórmula, normalización por percentiles y seis amenazas.'],
                                ['Zonificación (ISE)', 'Nuevo', 'Evaluador de sitios con veredicto y razones.'],
                                ['Simulador', 'Nuevo', 'Tres modos: clima, evacuación y reubicación.'],
                                ['Mapa propio offline', 'Nuevo', 'MapLibre con estilo propio y GeoJSON; sin dependencia de Google Earth Engine.'],
                                ['API JSON pública', 'Nuevo', 'Cuatro endpoints abiertos con CORS.'],
                                ['Protocolos y acuses', 'Nuevo', 'Recomendaciones con código, trazabilidad y acuse de recibo.'],
                            ] as [$comp, $origen, $desc]): ?>
                                <tr>
                                    <td class="!pl-5 text-white/90 whitespace-nowrap"><?= e($comp) ?></td>
                                    <td>
                                        <span class="wq-badge <?= str_contains($origen, 'Nuevo') ? 'wq-badge--verde' : 'wq-badge--amarillo' ?>">
                                            <?= e($origen) ?>
                                        </span>
                                    </td>
                                    <td class="!pr-5 text-[0.74rem] text-slate-400"><?= e($desc) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-[0.7rem] text-slate-500 mt-3 leading-relaxed">
                    La aplicación WILLAQ vive en su propia carpeta y no modifica el proyecto inscrito.
                    Todo el código nuevo fue desarrollado durante la segunda fase.
                </p>
            </div>
        </section>

        <!-- ===================== ARQUITECTURA ===================== -->
        <section id="arquitectura" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <div>
                    <h2 class="text-[1.05rem] font-normal tracking-wide">Arquitectura</h2>
                    <p class="text-[0.68rem] text-slate-500 mt-0.5">PHP puro, sin Composer, compatible con hosting compartido</p>
                </div>
            </div>
            <div class="wq-card__body">
                <div class="wq-panel-dark p-4 font-mono text-[0.68rem] text-slate-400 leading-relaxed overflow-x-auto">
                    <div class="text-brand-gold">index.php</div>
                    <div class="pl-4">├─ config/ &nbsp;&nbsp;territorios.json (respaldo) · umbrales.php · database.php</div>
                    <div class="pl-4">├─ models/ &nbsp;&nbsp;Clima · Riesgo · Zonificacion · Simulador · Alerta · Usuario · Cache</div>
                    <div class="pl-4">├─ views/ &nbsp;&nbsp;&nbsp;home · mapa · territorio · alertas · planificacion · simulador · admin · ayuda</div>
                    <div class="pl-4">├─ assets/ &nbsp;&nbsp;CSS heredado + capa WILLAQ · MapLibre · Chart.js locales</div>
                    <div class="pl-4">├─ data/ &nbsp;&nbsp;&nbsp;&nbsp;cache/ · snapshots/ (respaldo offline)</div>
                    <div class="pl-4">└─ database/ schema_postgresql.sql · seed_territorios.sql</div>
                </div>
                <div class="grid md:grid-cols-3 gap-3 mt-4 text-[0.7rem]">
                    <div class="wq-panel-dark p-3">
                        <div class="text-brand-gold mb-1">Sin dependencias</div>
                        <p class="text-slate-400 leading-relaxed">Ni Composer ni Node en ejecución. Las librerías JS están en local.</p>
                    </div>
                    <div class="wq-panel-dark p-3">
                        <div class="text-brand-gold mb-1">Degradación elegante</div>
                        <p class="text-slate-400 leading-relaxed">Si falla una API, se usa caché; si falla el mapa base, el riesgo sigue visible.</p>
                    </div>
                    <div class="wq-panel-dark p-3">
                        <div class="text-brand-gold mb-1">Explicabilidad</div>
                        <p class="text-slate-400 leading-relaxed">Cada índice expone H, E, V, fuente, umbral y fecha de cálculo.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ===================== GLOSARIO ===================== -->
        <section id="glosario" class="wq-card scroll-mt-24">
            <div class="wq-card__head">
                <h2 class="text-[1.05rem] font-normal tracking-wide">Glosario</h2>
            </div>
            <div class="wq-card__body grid md:grid-cols-2 gap-x-6 gap-y-4">
                <?php foreach ([
                    ['p50 / p95 / p99', 'Percentiles de la serie histórica local. El p95 es el valor que solo se supera 5 de cada 100 días.'],
                    ['H · E · V', 'Peligro, exposición y vulnerabilidad. Los tres componentes del índice.'],
                    ['ISE', 'Índice de Seguridad de Emplazamiento (0–100). Mayor es mejor.'],
                    ['GloFAS', 'Sistema mundial de previsión de inundaciones que alimenta el caudal de Open-Meteo.'],
                    ['ERA5', 'Reanálisis climático del ECMWF usado para la serie histórica.'],
                    ['Huaico', 'Flujo de lodo y rocas que baja por una quebrada tras lluvia intensa.'],
                    ['COE', 'Centro de Operaciones de Emergencia.'],
                    ['Acuse', 'Confirmación de que una autoridad local recibió la recomendación.'],
                ] as [$term, $def]): ?>
                    <div>
                        <div class="text-[0.78rem] text-white mb-0.5"><?= e($term) ?></div>
                        <p class="text-[0.7rem] text-slate-400 leading-relaxed"><?= e($def) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Aviso final -->
        <section class="wq-panel-dark p-5">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-scale-balanced text-brand-gold/60 mt-0.5"></i>
                <p class="text-[0.72rem] text-slate-400 leading-relaxed">
                    <strong class="text-slate-200">Aviso.</strong>
                    WILLAQ es una herramienta académica de apoyo a la decisión. No emite alertas oficiales
                    ni sustituye a SENAMHI, INDECI, ENFEN ni a las autoridades competentes.
                    Ante una emergencia, siga los canales oficiales.
                </p>
            </div>
        </section>
    </div>
</div>
