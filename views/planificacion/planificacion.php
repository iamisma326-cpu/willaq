<?php
/**
 * VISTA — ZONIFICACIÓN TERRITORIAL (Pilar 2)
 * La pregunta que ningún servicio público responde hoy:
 *   ¿se puede construir aquí?
 */
$tipos = [
    'colegio' => 'Colegio / institución educativa',
    'hospital' => 'Hospital / centro de salud',
    'vivienda' => 'Vivienda / conjunto residencial',
    'planta_agua' => 'Planta de tratamiento de agua',
    'albergue' => 'Albergue / centro de evacuación',
    'carretera' => 'Carretera / puente',
];
$tipo = $tipo ?: 'colegio';

$puntos = [];
foreach (territorios_todos() as $t) {
    $iseT = zonificacion_ise_territorio($t);
    $cl = zonificacion_clase($iseT);
    $puntos[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$t['lon'], (float)$t['lat']]],
        'properties' => [
            'distrito' => $t['distrito'],
            'region' => $t['region'],
            'ise' => $iseT,
            'clase' => $cl['id'],
            'color' => $cl['color'],
        ],
    ];
}
$geojson = ['type' => 'FeatureCollection', 'features' => $puntos];
?>

<header class="mb-6">
    <div class="wq-eyebrow mb-2">Pilar 2 · Planificar</div>
    <h1 class="wq-title text-[2rem] lg:text-[2.6rem] leading-none">¿Dónde no se debe construir?</h1>
    <p class="text-[0.85rem] text-slate-400 mt-3 max-w-3xl leading-relaxed">
        Un colegio o un hospital mal emplazado se convierte en una tragedia repetida cada temporada.
        WILLAQ calcula el <strong class="text-white font-normal">Índice de Seguridad de Emplazamiento (ISE)</strong>
        y emite un veredicto con razones auditables antes de otorgar una licencia.
    </p>
</header>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_1.15fr] gap-5 mb-6">

    <!-- Formulario + mapa -->
    <section class="wq-card">
        <div class="wq-card__head">
            <h2 class="text-[0.95rem] font-normal tracking-wide">Evaluar un sitio</h2>
            <span class="wq-chip">clic en el mapa</span>
        </div>

        <div class="wq-map wq-map--panel" id="wq-map-plan"></div>

        <!-- Los territorios van en un bloque JSON aparte (no como atributo HTML)
             para que las comillas y acentos no rompan el marcado. -->
        <script type="application/json" id="wq-regiones"><?= json_encode(array_map('array_values', $porRegion ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

        <form method="get" action="index.php" class="wq-card__body space-y-3" id="wq-form-sitio">
            <input type="hidden" name="action" value="planificacion">

            <!-- Selección guiada: departamento → distrito.
                 Al elegir un distrito se rellenan lat/lon y el formulario se envía. -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="wq-label" for="pf-dep">Departamento</label>
                    <select class="wq-select" id="pf-dep" name="departamento">
                        <option value="">— Todos —</option>
                        <?php foreach (array_keys($porRegion ?? []) as $reg): ?>
                            <option value="<?= e($reg) ?>" <?= ($departamento ?? '') === $reg ? 'selected' : '' ?>><?= e($reg) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="wq-label" for="pf-dist">Distrito</label>
                    <select class="wq-select" id="pf-dist" name="distrito">
                        <option value="">— Elige departamento —</option>
                        <?php foreach (($porRegion[$departamento] ?? []) as $d): ?>
                            <option value="<?= e($d['id']) ?>" <?= ($distritoId ?? '') === $d['id'] ? 'selected' : '' ?>><?= e($d['distrito']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2 text-[0.6rem] uppercase tracking-[0.14em] text-slate-500">
                <span class="h-px flex-1 bg-white/[0.07]"></span>o por coordenadas<span class="h-px flex-1 bg-white/[0.07]"></span>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="wq-label" for="pf-lat">Latitud</label>
                    <input class="wq-input" id="pf-lat" name="lat" type="number" step="0.0001"
                           value="<?= $lat !== null ? e(number_format($lat, 4, '.', '')) : '' ?>"
                           placeholder="-5.2667">
                </div>
                <div>
                    <label class="wq-label" for="pf-lon">Longitud</label>
                    <input class="wq-input" id="pf-lon" name="lon" type="number" step="0.0001"
                           value="<?= $lon !== null ? e(number_format($lon, 4, '.', '')) : '' ?>"
                           placeholder="-80.6833">
                </div>
            </div>

            <div>
                <label class="wq-label" for="pf-tipo">Tipo de equipamiento</label>
                <select class="wq-select" id="pf-tipo" name="tipo">
                    <?php foreach ($tipos as $id => $nombre): ?>
                        <option value="<?= e($id) ?>" <?= $tipo === $id ? 'selected' : '' ?>>
                            <?= e($nombre) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn--primary w-full !py-2.5 !text-[0.68rem]">
                <i class="fa-solid fa-location-crosshairs mr-2"></i> Evaluar aptitud del sitio
            </button>

            <div class="pt-2 border-t border-white/[0.07]">
                <div class="wq-label mb-1.5">Casos de demostración</div>
                <div class="flex flex-wrap gap-1.5">
                    <a class="wq-chip hover:border-brand-gold/50" href="<?= e(url('planificacion', ['lat' => -5.2667, 'lon' => -80.6833, 'tipo' => 'colegio'])) ?>">Catacaos · colegio</a>
                    <a class="wq-chip hover:border-brand-gold/50" href="<?= e(url('planificacion', ['lat' => -11.9333, 'lon' => -76.7, 'tipo' => 'hospital'])) ?>">Chosica · hospital</a>
                    <a class="wq-chip hover:border-brand-gold/50" href="<?= e(url('planificacion', ['lat' => -13.5319, 'lon' => -71.9675, 'tipo' => 'colegio'])) ?>">Cusco · colegio</a>
                </div>
            </div>
        </form>
    </section>

    <!-- Resultado -->
    <section class="space-y-5">
        <?php if ($evaluacion): ?>
            <?php $cl = $evaluacion['clase']; ?>
            <div class="wq-panel-dark p-6 relative overflow-hidden">
                <div class="absolute -right-16 -top-16 w-56 h-56 rounded-full blur-3xl opacity-25"
                     style="background: <?= e($cl['color']) ?>"></div>

                <div class="relative">
                    <div class="wq-eyebrow mb-2">Veredicto</div>

                    <div class="flex flex-wrap items-center gap-4">
                        <div class="wq-num text-[3.4rem] leading-none" style="color: <?= e($cl['color']) ?>">
                            <?= (int)round($evaluacion['ise']) ?>
                        </div>
                        <div>
                            <div class="text-[1.35rem] font-light" style="color: <?= e($cl['color']) ?>">
                                <?= e($evaluacion['veredicto']) ?>
                            </div>
                            <div class="text-[0.72rem] text-slate-400 mt-1">
                                Clase <?= e($cl['id']) ?> · <?= e($cl['nombre']) ?>
                                · ISE base <?= $evaluacion['ise_base'] ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="wq-bar h-2">
                            <div class="wq-bar__fill" style="width: <?= max(2, (float)$evaluacion['ise']) ?>%; background: <?= e($cl['color']) ?>"></div>
                        </div>
                        <div class="flex justify-between text-[0.56rem] uppercase tracking-[0.12em] text-slate-600 mt-1.5">
                            <span>No apto</span><span>Restringido</span><span>Apto c/ condiciones</span><span>Apto</span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-[0.7rem]">
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Distrito evaluado</div>
                            <div class="text-white"><?= e($evaluacion['territorio']['distrito']) ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Equipamiento</div>
                            <div class="text-white"><?= e($evaluacion['tipo_etiqueta']) ?></div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Coordenadas</div>
                            <div class="text-white font-mono text-[0.68rem]">
                                <?= number_format((float)$evaluacion['lat'], 4, '.', '') ?>,
                                <?= number_format((float)$evaluacion['lon'], 4, '.', '') ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500 mb-0.5">Distancia al centro</div>
                            <div class="text-white"><?= $evaluacion['distancia_km'] !== null ? $evaluacion['distancia_km'] . ' km' : '—' ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Razones -->
            <div class="wq-card">
                <div class="wq-card__head">
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Razones del veredicto</h2>
                    <span class="wq-chip"><?= count($evaluacion['razones']) ?> factores</span>
                </div>
                <div class="wq-card__body space-y-3">
                    <?php foreach ($evaluacion['razones'] as $razon): ?>
                        <div class="flex gap-3">
                            <span class="mt-1 wq-dot <?= $razon['severidad'] === 'crítica' ? 'wq-dot--rojo' : 'wq-dot--naranja' ?>"></span>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[0.8rem] text-white"><?= e($razon['factor']) ?></span>
                                    <span class="wq-badge <?= $razon['severidad'] === 'crítica' ? 'wq-badge--rojo' : 'wq-badge--naranja' ?>">
                                        <?= e($razon['severidad']) ?>
                                    </span>
                                    <span class="text-[0.6rem] text-slate-600">peso <?= number_format($razon['peso'], 2, '.', '') ?></span>
                                </div>
                                <p class="text-[0.74rem] text-slate-400 mt-1 leading-relaxed"><?= e($razon['detalle']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($evaluacion['razones'])): ?>
                        <p class="text-[0.78rem] text-slate-400">
                            No se identificaron factores de amenaza relevantes para este tipo de equipamiento.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recomendaciones -->
            <div class="wq-card">
                <div class="wq-card__head">
                    <h2 class="text-[0.95rem] font-normal tracking-wide">Qué hacer</h2>
                    <i class="fa-solid fa-helmet-safety text-brand-gold/60"></i>
                </div>
                <div class="wq-card__body">
                    <ul class="space-y-2">
                        <?php foreach ($evaluacion['recomendaciones'] as $rec): ?>
                            <li class="flex gap-2.5 text-[0.78rem] text-slate-300">
                                <i class="fa-solid fa-arrow-right text-brand-gold/60 text-[0.6rem] mt-1.5"></i>
                                <span><?= e($rec) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($evaluacion['recomendaciones'])): ?>
                            <li class="text-[0.78rem] text-slate-400">
                                Mantener los estudios de suelos y la normativa urbana vigente.
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Escenario futuro -->
            <?php if ($futuro): ?>
                <div class="wq-panel-dark p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <div class="wq-eyebrow">Proyección climática</div>
                            <div class="text-[0.8rem] text-slate-300 mt-0.5">
                                Con +<?= (int)round(($futuro['futuro']['factor_lluvia'] - 1) * 100) ?> % de lluvia
                            </div>
                        </div>
                        <i class="fa-solid fa-arrow-trend-up text-brand-gold/60"></i>
                    </div>

                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">Hoy</div>
                            <div class="wq-num text-2xl" style="color: <?= e($evaluacion['clase']['color']) ?>">
                                <?= (int)round($evaluacion['ise']) ?>
                            </div>
                            <div class="text-[0.58rem]" style="color: <?= e($evaluacion['clase']['color']) ?>">
                                clase <?= e($evaluacion['clase']['id']) ?>
                            </div>
                        </div>
                        <div class="flex-1 h-px bg-gradient-to-r from-brand-gold/50 to-brand-gold/10 relative">
                            <i class="fa-solid fa-chevron-right absolute right-0 -top-1.5 text-brand-gold/60 text-[0.6rem]"></i>
                        </div>
                        <div class="text-center">
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">Proyectado</div>
                            <div class="wq-num text-2xl" style="color: <?= e($futuro['futuro']['clase']['color']) ?>">
                                <?= (int)round($futuro['futuro']['ise']) ?>
                            </div>
                            <div class="text-[0.58rem]" style="color: <?= e($futuro['futuro']['clase']['color']) ?>">
                                clase <?= e($futuro['futuro']['clase']['id']) ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="wq-delta <?= $futuro['delta'] < 0 ? 'wq-delta--up' : 'wq-delta--flat' ?> text-lg">
                                <?= $futuro['delta'] > 0 ? '+' : '' ?><?= $futuro['delta'] ?>
                            </div>
                            <div class="text-[0.56rem] uppercase tracking-[0.14em] text-slate-500">delta ISE</div>
                        </div>
                    </div>

                    <p class="text-[0.68rem] text-slate-500 mt-3 leading-relaxed">
                        El ISE proyectado es una aproximación de sensibilidad: estima cuánto se degradaría
                        la aptitud del sitio bajo un escenario de lluvia más intensa. No es un pronóstico climático.
                    </p>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="wq-panel-dark p-8 flex flex-col items-center justify-center text-center min-h-[420px]">
                <div class="w-14 h-14 rounded-full border border-brand-gold/30 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-location-dot text-brand-gold/70 text-lg"></i>
                </div>
                <h3 class="wq-title text-xl mb-2">Seleccione un punto</h3>
                <p class="text-[0.78rem] text-slate-400 max-w-sm leading-relaxed">
                    Haga clic en el mapa o ingrese coordenadas. WILLAQ identificará el distrito
                    y devolverá un veredicto con las razones ordenadas por peso.
                </p>
                <div class="mt-5 grid grid-cols-2 gap-2 text-[0.62rem] text-slate-500">
                    <span class="wq-chip justify-center">A · Apto</span>
                    <span class="wq-chip justify-center">B · Con condiciones</span>
                    <span class="wq-chip justify-center">C · Restringido</span>
                    <span class="wq-chip justify-center">D · No apto</span>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Ranking -->
<section class="wq-card">
    <div class="wq-card__head">
        <div>
            <h2 class="text-[0.95rem] font-normal tracking-wide">Ranking de aptitud territorial</h2>
            <p class="text-[0.68rem] text-slate-500 mt-0.5">
                De más seguro a más restringido · <?= count($ranking) ?> distritos
            </p>
        </div>
        <span class="wq-chip">ISE base, sin ajuste por equipamiento</span>
    </div>

    <div class="overflow-x-auto wq-scroll">
        <table class="wq-table">
            <thead>
                <tr>
                    <th class="!pl-5">#</th>
                    <th>Distrito</th>
                    <th>Región</th>
                    <th class="w-[110px]">ISE</th>
                    <th>Clase</th>
                    <th>Susceptibilidad dominante</th>
                    <th class="!pr-5 text-right">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ranking as $i => $fila): ?>
                    <?php
                    $t = $fila['territorio'];
                    $susc = [
                        'Inundación' => (float)$t['susc_inundacion'],
                        'Huaico' => (float)$t['susc_huaico'],
                        'Sequía' => (float)$t['susc_sequia'],
                    ];
                    arsort($susc);
                    $dominante = array_key_first($susc);
                    ?>
                    <tr>
                        <td class="!pl-5 text-slate-500 wq-num"><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= e(url('territorio', ['id' => $t['id']])) ?>"
                               class="hover:text-brand-gold transition-colors"><?= e($t['distrito']) ?></a>
                        </td>
                        <td class="text-[0.74rem] text-slate-400"><?= e($t['region']) ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <div class="wq-bar flex-1">
                                    <div class="wq-bar__fill" style="width: <?= max(2, (float)$fila['ise']) ?>%; background: <?= e($fila['clase']['color']) ?>"></div>
                                </div>
                                <span class="wq-num text-[0.78rem] w-8 text-right" style="color: <?= e($fila['clase']['color']) ?>">
                                    <?= (int)round($fila['ise']) ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="wq-badge" style="color: <?= e($fila['clase']['color']) ?>; border-color: <?= e($fila['clase']['color']) ?>55; background: <?= e($fila['clase']['color']) ?>18">
                                <?= e($fila['clase']['id']) ?> · <?= e($fila['clase']['nombre']) ?>
                            </span>
                        </td>
                        <td class="text-[0.72rem] text-slate-400"><?= e($dominante) ?></td>
                        <td class="!pr-5 text-right">
                            <a href="<?= e(url('planificacion', ['lat' => $t['lat'], 'lon' => $t['lon'], 'tipo' => 'colegio'])) ?>"
                               class="text-[0.66rem] uppercase tracking-[0.12em] text-brand-gold hover:text-brand-goldlight">
                                Evaluar
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    if (typeof maplibregl === 'undefined') return;

    const mapa = new maplibregl.Map({
        container: 'wq-map-plan',
        style: { version: 8, sources: {}, layers: [{ id: 'fondo', type: 'background', paint: { 'background-color': '#060d18' } }] },
        center: [-76.0, -9.8],
        zoom: 4.4
    });

    mapa.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

    mapa.on('load', () => {
        // Mapa base: Esri World Dark Gray.
        //
        // Se usaba `basemaps.cartocdn.com/dark_all`, pero Carto empezó a exigir
        // API key y sus teselas pasaron a ser una marca de agua "API KEY
        // REQUIRED" sobre fondo liso: el mapa se veía vacío. Esri World Dark
        // Gray no pide clave, es de tema oscuro y encaja con la paleta.
        // Nota: Esri usa el orden {z}/{y}/{x} (fila antes que columna).
        mapa.addSource('esri', {
            type: 'raster',
            tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'],
            tileSize: 256,
            maxzoom: 16,
            attribution: 'Tiles © Esri — Esri, DeLorme, NAVTEQ'
        });
        mapa.addLayer({ id: 'esri', type: 'raster', source: 'esri', paint: { 'raster-opacity': 0.85 } });

        mapa.addSource('distritos', { type: 'geojson', data: <?= json_encode($geojson, JSON_UNESCAPED_UNICODE) ?> });

        mapa.addLayer({
            id: 'distritos',
            type: 'circle',
            source: 'distritos',
            paint: {
                'circle-radius': 6,
                'circle-color': ['get', 'color'],
                'circle-stroke-width': 1,
                'circle-stroke-color': 'rgba(255,255,255,.55)',
                'circle-opacity': 0.85
            }
        });

        // Punto evaluado
        <?php if ($lat !== null && $lon !== null): ?>
        mapa.addSource('sitio', {
            type: 'geojson',
            data: {
                type: 'Feature',
                geometry: { type: 'Point', coordinates: [<?= (float)$lon ?>, <?= (float)$lat ?>] },
                properties: {}
            }
        });
        mapa.addLayer({
            id: 'sitio-halo',
            type: 'circle',
            source: 'sitio',
            paint: { 'circle-radius': 26, 'circle-color': '#C5A880', 'circle-opacity': 0.18, 'circle-blur': 1 }
        });
        mapa.addLayer({
            id: 'sitio',
            type: 'circle',
            source: 'sitio',
            paint: {
                'circle-radius': 7,
                'circle-color': '#C5A880',
                'circle-stroke-width': 2,
                'circle-stroke-color': '#0A1628'
            }
        });
        mapa.flyTo({ center: [<?= (float)$lon ?>, <?= (float)$lat ?>], zoom: 8.2 });
        <?php endif; ?>

        // Clic para elegir un punto
        mapa.on('click', (ev) => {
            const lat = ev.lngLat.lat.toFixed(4);
            const lon = ev.lngLat.lng.toFixed(4);
            document.getElementById('pf-lat').value = lat;
            document.getElementById('pf-lon').value = lon;

            if (mapa.getLayer('sitio')) {
                mapa.getSource('sitio').setData({
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [ev.lngLat.lng, ev.lngLat.lat] },
                    properties: {}
                });
            }
            new maplibregl.Popup({ closeButton: false, offset: 12 })
                .setLngLat(ev.lngLat)
                .setHTML('<div class="wq-pop" style="min-width:auto"><div class="wq-pop__title">Sitio seleccionado</div>' +
                         '<div class="wq-pop__meta">' + lat + ', ' + lon + ' · pulse Evaluar</div></div>')
                .addTo(mapa);
        });
    });

    // ---------------------------------------------------------------
    // Desplegables departamento → distrito.
    // Al elegir un distrito, sus coordenadas mueven el mapa y se envía.
    // ---------------------------------------------------------------
    const selDep = document.getElementById('pf-dep');
    const selDist = document.getElementById('pf-dist');
    const form = document.getElementById('wq-form-sitio');
    let regiones = {};
    try {
        // Los territorios viven en un <script type="application/json"> aparte,
        // así las comillas y acentos no tienen que escapar dentro del marcado.
        const nodo = document.getElementById('wq-regiones');
        regiones = nodo ? JSON.parse(nodo.textContent) : {};
    } catch (e) {
        console.error('[WILLAQ] no se pudo leer el listado de territorios', e);
        regiones = {};
    }

    if (selDep && selDist) {
        selDep.addEventListener('change', () => {
            const lista = regiones[selDep.value] || [];
            selDist.innerHTML = '';

            const vacio = document.createElement('option');
            vacio.value = '';
            vacio.textContent = lista.length ? '— Elige distrito —' : '— Elige departamento —';
            selDist.appendChild(vacio);

            lista.forEach((d) => {
                const o = document.createElement('option');
                o.value = d.id;
                o.textContent = d.distrito;
                selDist.appendChild(o);
            });

            // Un solo distrito en el departamento: se selecciona solo.
            if (lista.length === 1) {
                selDist.value = lista[0].id;
            }
        });

        selDist.addEventListener('change', () => {
            if (!selDist.value) return;

            const lista = regiones[selDep.value] || [];
            const d = lista.find((x) => x.id === selDist.value);
            const fLat = document.getElementById('pf-lat');
            const fLon = document.getElementById('pf-lon');

            if (d) {
                if (fLat) fLat.value = Number(d.lat).toFixed(4);
                if (fLon) fLon.value = Number(d.lon).toFixed(4);
                // Mueve el mapa de inmediato, sin esperar la recarga.
                mapa.flyTo({ center: [d.lon, d.lat], zoom: 9.4 });
            }

            form.submit();
        });
    }
})();
</script>

