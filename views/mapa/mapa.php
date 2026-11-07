<?php
/**
 * VISTA — MAPA DE RIESGO
 *
 * Estrategia "sin conexión":
 *   - El estilo base es un fondo oscuro propio + capas GeoJSON generadas desde
 *     el catálogo. El mapa funciona aunque no haya internet ni teselas.
 *   - El mapa base (CARTO) y las capas satelitales (NASA GIBS) son mejoras
 *     opcionales que se activan por encima y fallan en silencio.
 */
$puntos = [];
foreach ($riesgos as $r) {
    $t = $r['territorio'];
    $puntos[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$t['lon'], (float)$t['lat']]],
        'properties' => [
            'id' => $t['id'],
            'distrito' => $t['distrito'],
            'region' => $t['region'],
            'poblacion' => (int)$t['poblacion'],
            'riesgo' => (float)$r['riesgo'],
            'nivel' => $r['nivel']['id'],
            'amenaza' => $r['amenazas'][$r['principal']]['nombre'] ?? '',
            'ise' => zonificacion_ise_territorio($t),
            'url' => url('territorio', ['id' => $t['id'], 'ventana' => $ventana]),
        ],
    ];
}
$geojson = ['type' => 'FeatureCollection', 'features' => $puntos];

$conteo = ['verde' => 0, 'amarillo' => 0, 'naranja' => 0, 'rojo' => 0];
foreach ($riesgos as $r) {
    $conteo[$r['nivel']['id']] = ($conteo[$r['nivel']['id']] ?? 0) + 1;
}
$ayer = (new DateTime('yesterday', new DateTimeZone('America/Lima')))->format('Y-m-d');
?>

<div class="flex h-[calc(100vh-64px)] relative">

    <!-- ============ PANEL LATERAL ============ -->
    <aside id="wq-sidebar"
           class="hidden lg:flex flex-col w-[360px] shrink-0 border-r border-brand-gold/15 bg-[#081120]/95 backdrop-blur-md
                  absolute lg:static inset-y-0 left-0 z-40 max-w-[88%] lg:max-w-none">

        <div class="p-5 border-b border-white/[0.06]">
            <div class="wq-eyebrow mb-1.5">Mapa de riesgo</div>
            <h1 class="wq-title text-xl mb-1">Territorio vigilado</h1>
            <p class="text-[0.7rem] text-slate-500">
                <?= count($riesgos) ?> distritos · ventana de <?= (int)$ventana ?> h
            </p>

            <!-- Selector de ventana -->
            <div class="mt-4 flex gap-1.5">
                <?php foreach ([24, 48, 72] as $v): ?>
                    <a href="<?= e(url('mapa', ['ventana' => $v])) ?>"
                       class="wq-tab flex-1 text-center <?= $ventana === $v ? 'is-active' : '' ?>">
                        <?= $v ?> h
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Capas -->
        <div class="p-5 border-b border-white/[0.06]">
            <div class="wq-eyebrow mb-3">Capas</div>
            <div class="space-y-2.5 text-[0.76rem]">
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-slate-300 group-hover:text-white">Riesgo por distrito</span>
                    <input type="checkbox" id="capa-puntos" checked
                           class="accent-[#C5A880] w-4 h-4 cursor-pointer">
                </label>
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-slate-300 group-hover:text-white">Mapa de calor</span>
                    <input type="checkbox" id="capa-calor" checked
                           class="accent-[#C5A880] w-4 h-4 cursor-pointer">
                </label>
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-slate-300 group-hover:text-white">Mapa base (CARTO)</span>
                    <input type="checkbox" id="capa-base" checked
                           class="accent-[#C5A880] w-4 h-4 cursor-pointer">
                </label>
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-slate-300 group-hover:text-white">Lluvia satelital (NASA)</span>
                    <input type="checkbox" id="capa-lluvia"
                           class="accent-[#C5A880] w-4 h-4 cursor-pointer">
                </label>
                <label class="flex items-center justify-between cursor-pointer group">
                    <span class="text-slate-300 group-hover:text-white">Imagen satelital (NASA)</span>
                    <input type="checkbox" id="capa-satelite"
                           class="accent-[#C5A880] w-4 h-4 cursor-pointer">
                </label>
            </div>
            <p class="mt-3 text-[0.62rem] text-slate-600 leading-relaxed">
                Las capas NASA y el mapa base requieren internet. Si fallan, el mapa
                de riesgo sigue funcionando.
            </p>
        </div>

        <!-- Leyenda -->
        <div class="p-5 border-b border-white/[0.06]">
            <div class="wq-eyebrow mb-3">Nivel de riesgo</div>
            <div class="grid grid-cols-2 gap-2">
                <?php foreach (['verde', 'amarillo', 'naranja', 'rojo'] as $n): ?>
                    <div class="flex items-center gap-2">
                        <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                        <span class="text-[0.7rem] text-slate-300">
                            <?= e(['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'][$n]) ?>
                        </span>
                        <span class="ml-auto wq-num text-[0.7rem] text-slate-500"><?= (int)$conteo[$n] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 text-[0.62rem] text-slate-600">
                El tamaño del punto representa la población del distrito.
            </p>
        </div>

        <!-- Lista -->
        <div class="flex-1 overflow-y-auto wq-scroll">
            <div class="px-5 pt-4 pb-2 sticky top-0 bg-[#081120]/95 backdrop-blur z-10">
                <div class="wq-eyebrow">Distritos</div>
            </div>
            <div class="divide-y divide-white/[0.04]">
                <?php foreach ($riesgos as $r): ?>
                    <?php $t = $r['territorio']; $n = $r['nivel']['id']; ?>
                    <button type="button"
                            class="wq-distrito w-full text-left px-5 py-2.5 hover:bg-white/[0.04] transition-colors flex items-center gap-3"
                            data-id="<?= e($t['id']) ?>"
                            data-lat="<?= (float)$t['lat'] ?>" data-lon="<?= (float)$t['lon'] ?>">
                        <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.78rem] text-white/90 truncate"><?= e($t['distrito']) ?></span>
                            <span class="block text-[0.58rem] uppercase tracking-[0.12em] text-slate-500">
                                <?= e($t['region']) ?>
                            </span>
                        </span>
                        <span class="wq-num text-[0.8rem] wq-level-text--<?= e($n) ?>"><?= (int)round($r['riesgo']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-4 border-t border-white/[0.06] text-[0.6rem] text-slate-600 leading-relaxed">
            Fuente: Open-Meteo · GloFAS · ERA5 · NASA GIBS.
            Recomendación de apoyo a la decisión, no alerta oficial.
        </div>
    </aside>

    <!-- ============ MAPA ============ -->
    <div class="relative flex-1 min-w-0">
        <div id="wq-map" class="absolute inset-0"></div>

        <!-- Botón panel móvil -->
        <button type="button"
                onclick="document.getElementById('wq-sidebar').classList.toggle('hidden')"
                class="lg:hidden absolute top-3 left-3 z-30 wq-chip !px-3 !py-2 bg-[#081120]/90">
            <i class="fa-solid fa-sliders"></i> Panel
        </button>

        <!-- Leyenda flotante -->
        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 z-20 wq-panel-dark px-4 py-2.5 flex items-center gap-4 text-[0.62rem]">
            <?php foreach (['verde' => 'Bajo', 'amarillo' => 'Medio', 'naranja' => 'Alto', 'rojo' => 'Muy alto'] as $n => $txt): ?>
                <span class="flex items-center gap-1.5">
                    <span class="wq-dot wq-dot--<?= e($n) ?>"></span>
                    <span class="text-slate-300"><?= e($txt) ?></span>
                </span>
            <?php endforeach; ?>
            <span class="hidden sm:inline text-slate-600 pl-3 border-l border-white/10">
                clic en un distrito para ver la ficha
            </span>
        </div>

        <!-- Aviso de capa caída -->
        <div id="wq-map-warn"
             class="hidden absolute top-3 right-3 z-20 max-w-xs wq-panel-dark px-3.5 py-2.5 text-[0.68rem] text-risk-yellow border-risk-yellow/30">
        </div>
    </div>
</div>

<script>
(function () {
    const DISTRITOS = <?= json_encode($geojson, JSON_UNESCAPED_UNICODE) ?>;
    const AYER = <?= json_encode($ayer) ?>;

    if (typeof maplibregl === 'undefined') {
        document.getElementById('wq-map').innerHTML =
            '<div class="h-full flex items-center justify-center text-slate-500 text-sm">MapLibre no disponible.</div>';
        return;
    }

    const estilo = {
        version: 8,
        sources: {},
        layers: [{
            id: 'fondo',
            type: 'background',
            paint: { 'background-color': '#060d18' }
        }]
    };

    const mapa = new maplibregl.Map({
        container: 'wq-map',
        style: estilo,
        center: [-76.5, -9.5],
        zoom: 4.6,
        minZoom: 3.5,
        maxZoom: 13,
        attributionControl: false
    });

    mapa.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    mapa.addControl(new maplibregl.ScaleControl({ maxWidth: 110, unit: 'metric' }), 'bottom-right');
    mapa.addControl(new maplibregl.AttributionControl({
        compact: true,
        customAttribution: 'WILLAQ · Open-Meteo · GloFAS · NASA GIBS'
    }), 'bottom-right');

    mapa.on('load', () => {
        // 1. Mapa base (opcional, requiere red).
        //    Se usan teselas estándar de OpenStreetMap, que no piden API key, y
        //    se atenúan con filtros de color para que no rompan la tema oscura.
        //    Si no hay internet, el mapa sigue funcionando con su estilo propio.
        mapa.addSource('osm', {
            type: 'raster',
            tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
            tileSize: 256,
            maxzoom: 19,
            attribution: '© OpenStreetMap contributors'
        });
        mapa.addLayer({
            id: 'osm',
            type: 'raster',
            source: 'osm',
            paint: {
                'raster-opacity': 0.45,
                'raster-brightness-max': 0.62,
                'raster-brightness-min': 0.02,
                'raster-saturation': -0.55,
                'raster-contrast': 0.12
            }
        });

        // 2. Lluvia IMERG (NASA GIBS)
        mapa.addSource('lluvia', {
            type: 'raster',
            tiles: ['https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/IMERG_Precipitation_Rate/default/' + AYER + '/GoogleMapsCompatible_Level6/{z}/{y}/{x}.png'],
            tileSize: 256
        });
        mapa.addLayer({
            id: 'lluvia',
            type: 'raster',
            source: 'lluvia',
            layout: { visibility: 'none' },
            paint: { 'raster-opacity': 0.7 }
        });

        // 3. Imagen satelital verdadero color
        mapa.addSource('satelite', {
            type: 'raster',
            tiles: ['https://gibs.earthdata.nasa.gov/wmts/epsg3857/best/VIIRS_SNPP_CorrectedReflectance_TrueColor/default/' + AYER + '/GoogleMapsCompatible_Level9/{z}/{y}/{x}.jpg'],
            tileSize: 256
        });
        mapa.addLayer({
            id: 'satelite',
            type: 'raster',
            source: 'satelite',
            layout: { visibility: 'none' },
            paint: { 'raster-opacity': 0.85 }
        });

        // 4. Distritos
        mapa.addSource('distritos', { type: 'geojson', data: DISTRITOS });

        mapa.addLayer({
            id: 'riesgo-calor',
            type: 'heatmap',
            source: 'distritos',
            paint: {
                'heatmap-weight': ['interpolate', ['linear'], ['get', 'riesgo'], 0, 0, 100, 1],
                'heatmap-intensity': 0.85,
                'heatmap-radius': 48,
                'heatmap-opacity': 0.55,
                'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'],
                    0, 'rgba(10,22,40,0)',
                    0.18, 'rgba(34,197,94,0.25)',
                    0.42, 'rgba(234,179,8,0.40)',
                    0.62, 'rgba(249,115,22,0.52)',
                    0.82, 'rgba(239,68,68,0.62)',
                    1, 'rgba(239,68,68,0.78)'
                ]
            }
        });

        mapa.addLayer({
            id: 'distritos-glow',
            type: 'circle',
            source: 'distritos',
            filter: ['in', ['get', 'nivel'], ['literal', ['naranja', 'rojo']]],
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['get', 'poblacion'], 0, 12, 200000, 20, 1100000, 34],
                'circle-color': ['match', ['get', 'nivel'], 'rojo', '#ef4444', 'naranja', '#f97316', '#f97316'],
                'circle-opacity': 0.10,
                'circle-blur': 1
            }
        });

        mapa.addLayer({
            id: 'distritos',
            type: 'circle',
            source: 'distritos',
            paint: {
                'circle-radius': ['interpolate', ['linear'], ['get', 'poblacion'], 0, 4.5, 150000, 7.5, 1100000, 15],
                'circle-color': ['match', ['get', 'nivel'],
                    'verde', '#22c55e',
                    'amarillo', '#eab308',
                    'naranja', '#f97316',
                    'rojo', '#ef4444',
                    '#94a3b8'
                ],
                'circle-stroke-width': 1.2,
                'circle-stroke-color': 'rgba(255,255,255,0.55)',
                'circle-opacity': 0.92
            }
        });

        // Popup
        const popup = new maplibregl.Popup({ closeButton: true, maxWidth: '260px' });

        mapa.on('mouseenter', 'distritos', () => { mapa.getCanvas().style.cursor = 'pointer'; });
        mapa.on('mouseleave', 'distritos', () => { mapa.getCanvas().style.cursor = ''; });

        mapa.on('click', 'distritos', (ev) => {
            mostrarPopup(ev.features[0]);
        });

        // Enlaces desde el panel
        document.querySelectorAll('.wq-distrito').forEach(btn => {
            btn.addEventListener('click', () => {
                const lon = parseFloat(btn.dataset.lon);
                const lat = parseFloat(btn.dataset.lat);
                mapa.flyTo({ center: [lon, lat], zoom: 8.4, speed: 1.1 });
                const f = DISTRITOS.features.find(x => x.properties.id === btn.dataset.id);
                if (f) {
                    setTimeout(() => mostrarPopup(f), 650);
                }
            });
        });

        function mostrarPopup(feature) {
            const p = feature.properties;
            const color = WQ.color(p.nivel);
            popup.setLngLat(feature.geometry.coordinates).setHTML(
                '<div class="wq-pop">' +
                '<div class="wq-pop__title">' + p.distrito + '</div>' +
                '<div class="wq-pop__meta">' + p.region + ' · ' + WQ.fmt(p.poblacion) + ' hab.</div>' +
                '<div class="wq-pop__row" style="margin-top:.55rem"><span style="color:#93a1b8">Riesgo</span>' +
                '<strong style="color:' + color + '">' + Math.round(p.riesgo) + ' · ' + WQ.nivel(p.nivel) + '</strong></div>' +
                '<div class="wq-pop__row"><span style="color:#93a1b8">Amenaza</span><span>' + p.amenaza + '</span></div>' +
                '<div class="wq-pop__row"><span style="color:#93a1b8">Aptitud del sitio (ISE)</span><span>' + p.ise + '</span></div>' +
                '<a class="wq-pop__link" href="' + p.url + '">Ver ficha completa →</a>' +
                '</div>'
            ).addTo(mapa);
        }

        conectarCapas();
    });

    function capaVisible(id, visible) {
        if (mapa.getLayer(id)) {
            mapa.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
        }
    }

    function conectarCapas() {
        document.getElementById('capa-puntos').addEventListener('change', e => {
            capaVisible('distritos', e.target.checked);
            capaVisible('distritos-glow', e.target.checked);
        });
        document.getElementById('capa-calor').addEventListener('change', e => {
            capaVisible('riesgo-calor', e.target.checked);
        });
        document.getElementById('capa-base').addEventListener('change', e => {
            capaVisible('carto', e.target.checked);
        });
        document.getElementById('capa-lluvia').addEventListener('change', e => {
            capaVisible('lluvia', e.target.checked);
        });
        document.getElementById('capa-satelite').addEventListener('change', e => {
            capaVisible('satelite', e.target.checked);
        });
    }

    // Advertir si el mapa base no carga (feria sin internet)
    let teselasOk = 0;
    mapa.on('data', (e) => {
        if (e.sourceId === 'carto' && e.isSourceLoaded) teselasOk++;
    });
    setTimeout(() => {
        if (teselasOk === 0) {
            const warn = document.getElementById('wq-map-warn');
            warn.textContent = 'Sin conexión al mapa base. El riesgo por distrito sigue operativo.';
            warn.classList.remove('hidden');
            setTimeout(() => warn.classList.add('hidden'), 9000);
        }
    }, 6000);
})();
</script>
