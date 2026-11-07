<?php
/**
 * REPORTE TÉCNICO IMPRIMIBLE — WILLAQ
 *
 * Se sirve directamente (no usa el layout) para que el navegador lo imprima
 * o lo guarde como PDF sin elementos de navegación. Todos los estilos van
 * embebidos: el archivo se puede imprimir sin conexión.
 */
$n = $r['nivel']['id'];
$principal = $r['amenazas'][$r['principal']] ?? reset($r['amenazas']);
$acciones = alerta_protocolo($r['principal'], $n);
$etiquetasNivel = ['verde' => 'BAJO', 'amarillo' => 'MEDIO', 'naranja' => 'ALTO', 'rojo' => 'MUY ALTO'];
$colores = ['verde' => '#15803d', 'amarillo' => '#a16207', 'naranja' => '#c2410c', 'rojo' => '#b91c1c'];
$color = $colores[$n] ?? '#334155';
$fechaInforme = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de riesgo · <?= e($t['distrito']) ?> · WILLAQ</title>
    <style>
        @font-face {
            font-family: 'Montserrat';
            src: url('assets/fonts/montserrat-var.woff2') format('woff2-variations');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
        @page { size: A4; margin: 14mm 13mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
            color: #1e293b;
            background: #eef1f5;
            font-size: 10pt;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .hoja {
            width: 210mm;
            min-height: 297mm;
            margin: 10mm auto;
            background: #fff;
            padding: 14mm 13mm;
            box-shadow: 0 8px 30px rgba(15,23,42,.14);
        }
        @media print {
            body { background: #fff; }
            .hoja { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .no-print { display: none !important; }
        }
        .cabecera {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 12mm; padding-bottom: 5mm; border-bottom: 2px solid #0A1628;
        }
        .marca { display: flex; align-items: center; gap: 3mm; }
        .marca img { width: 13mm; height: 13mm; border-radius: 3mm; }
        .marca-nombre { font-size: 15pt; letter-spacing: .3em; font-weight: 300; color: #0A1628; }
        .marca-lema { font-size: 6.5pt; letter-spacing: .2em; text-transform: uppercase; color: #8a7448; margin-top: 1mm; }
        .meta { text-align: right; font-size: 7.5pt; color: #64748b; line-height: 1.6; }
        .meta strong { color: #0A1628; font-weight: 600; }

        h1 { font-size: 20pt; font-weight: 300; letter-spacing: -.02em; margin: 7mm 0 1mm; color: #0A1628; }
        .subtitulo { font-size: 8.5pt; color: #64748b; margin: 0 0 6mm; }
        .subtitulo span { margin-right: 4mm; }

        .bloque { margin-bottom: 6mm; }
        .bloque-titulo {
            font-size: 7pt; letter-spacing: .18em; text-transform: uppercase;
            color: #8a7448; font-weight: 600; margin: 0 0 2.5mm;
            border-bottom: 1px solid #e2e8f0; padding-bottom: 1.2mm;
        }

        .indice {
            display: flex; align-items: stretch; gap: 5mm;
            border: 1px solid #dbe2ea; border-radius: 3mm; overflow: hidden;
            background: #f8fafc;
        }
        .indice-valor {
            padding: 5mm 7mm; text-align: center; background: <?= $color ?>; color: #fff;
            display: flex; flex-direction: column; justify-content: center; min-width: 38mm;
        }
        .indice-num { font-size: 34pt; font-weight: 200; line-height: .9; letter-spacing: -.04em; }
        .indice-de { font-size: 7pt; letter-spacing: .16em; opacity: .8; margin-top: 1.5mm; }
        .indice-detalle { padding: 4mm 5mm; flex: 1; }
        .indice-nivel { font-size: 11pt; font-weight: 600; color: <?= $color ?>; margin-bottom: 1mm; }
        .indice-texto { font-size: 8.5pt; color: #475569; margin: 0; }
        .componentes { display: flex; gap: 6mm; margin-top: 3mm; }
        .componente { font-size: 8pt; color: #64748b; }
        .componente strong { display: block; font-size: 11pt; color: #0A1628; font-weight: 500; }

        table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        th {
            text-align: left; font-size: 6.5pt; letter-spacing: .1em; text-transform: uppercase;
            color: #64748b; font-weight: 600; padding: 2mm 2.5mm; background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
        }
        td { padding: 2.2mm 2.5mm; border-bottom: 1px solid #e8edf2; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .nivel-tag {
            display: inline-block; font-size: 6.5pt; letter-spacing: .08em; text-transform: uppercase;
            padding: .8mm 2mm; border-radius: 10mm; color: #fff; font-weight: 600; white-space: nowrap;
        }

        .dos-columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; }
        .dato { display: flex; justify-content: space-between; gap: 4mm; font-size: 8.5pt; padding: 1.4mm 0; border-bottom: 1px dotted #dbe2ea; }
        .dato:last-child { border-bottom: none; }
        .dato span:first-child { color: #64748b; }
        .dato span:last-child { color: #0A1628; font-weight: 500; text-align: right; }

        ol.acciones { margin: 0; padding-left: 5mm; }
        ol.acciones li { font-size: 8.5pt; color: #334155; margin-bottom: 1.6mm; padding-left: 1mm; }

        .fuentes { font-size: 7.5pt; color: #475569; }
        .fuentes div { display: flex; justify-content: space-between; padding: 1mm 0; border-bottom: 1px dotted #e2e8f0; }
        .fuentes div:last-child { border-bottom: none; }
        .fuentes span:first-child { color: #64748b; }

        .aviso {
            border-left: 3px solid #8a7448; padding: 2.5mm 4mm; background: #fbf8f2;
            font-size: 7.5pt; color: #57534e; line-height: 1.55; margin-top: 5mm;
        }
        .aviso strong { color: #0A1628; }

        .pie {
            margin-top: 7mm; padding-top: 3mm; border-top: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; gap: 5mm;
            font-size: 6.5pt; color: #94a3b8;
        }

        .botones { max-width: 210mm; margin: 0 auto 6mm; display: flex; gap: 3mm; justify-content: flex-end; }
        .boton {
            display: inline-block; padding: 2.5mm 5mm; border-radius: 2mm; font-size: 8pt;
            text-decoration: none; border: 1px solid #0A1628; color: #0A1628; background: #fff;
            font-family: inherit; cursor: pointer; font-weight: 500;
        }
        .boton--primario { background: #0A1628; color: #fff; }
        .tabla-amenazas td:first-child { font-weight: 500; color: #0A1628; }
    </style>
</head>
<body>

<div class="botones no-print">
    <a class="boton" href="<?= e(url('territorio', ['id' => $t['id'], 'ventana' => $ventana])) ?>">← Volver a la ficha</a>
    <button class="boton boton--primario" type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>

<div class="hoja">

    <!-- Cabecera -->
    <header class="cabecera">
        <div class="marca">
            <img src="assets/img/logo-willaq.svg" alt="WILLAQ">
            <div>
                <div class="marca-nombre">WILLAQ</div>
                <div class="marca-lema">Vigilar · Planificar · Simular</div>
            </div>
        </div>
        <div class="meta">
            <div><strong>Reporte técnico de riesgo</strong></div>
            <div>Emitido: <?= e($fechaInforme) ?></div>
            <div>Ventana: <?= (int)$ventana ?> horas</div>
            <div>Código: WQ-RPT-<?= e(strtoupper(substr(sha1($t['id'] . $r['actualizado']), 0, 8))) ?></div>
        </div>
    </header>

    <h1><?= e($t['distrito']) ?></h1>
    <p class="subtitulo">
        <span><?= e($t['region']) ?> · <?= e($t['provincia']) ?></span>
        <span><?= number_format($t['lat'], 4, '.', '') ?>, <?= number_format($t['lon'], 4, '.', '') ?></span>
        <span><?= (int)$t['altitud'] ?> m s. n. m.</span>
        <span>zona <?= e(str_replace('_', ' ', $t['zona'])) ?></span>
    </p>

    <!-- Índice -->
    <section class="bloque">
        <h2 class="bloque-titulo">1. Índice de riesgo</h2>
        <div class="indice">
            <div class="indice-valor">
                <div class="indice-num"><?= (int)round($r['riesgo']) ?></div>
                <div class="indice-de">DE 100</div>
            </div>
            <div class="indice-detalle">
                <div class="indice-nivel">NIVEL <?= e($etiquetasNivel[$n] ?? strtoupper($n)) ?></div>
                <p class="indice-texto"><?= e($r['resumen']) ?></p>
                <div class="componentes">
                    <div class="componente">Peligro<strong><?= number_format($principal['peligro'], 3, '.', '') ?></strong></div>
                    <div class="componente">Exposición<strong><?= number_format($principal['exposicion'], 3, '.', '') ?></strong></div>
                    <div class="componente">Vulnerabilidad<strong><?= number_format($principal['vulnerabilidad'], 3, '.', '') ?></strong></div>
                    <div class="componente">Confianza<strong><?= e($r['confianza']) ?></strong></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Amenazas -->
    <section class="bloque">
        <h2 class="bloque-titulo">2. Las seis amenazas evaluadas</h2>
        <table class="tabla-amenazas">
            <thead>
                <tr>
                    <th>Amenaza</th>
                    <th class="num">Índice</th>
                    <th class="num">Peligro</th>
                    <th class="num">Exposición</th>
                    <th>Nivel</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($r['amenazas'] as $a): ?>
                    <?php $na = $a['nivel']['id']; ?>
                    <tr>
                        <td><?= e($a['nombre']) ?></td>
                        <td class="num"><?= (int)round($a['riesgo']) ?></td>
                        <td class="num"><?= number_format($a['peligro'], 2, '.', '') ?></td>
                        <td class="num"><?= number_format($a['exposicion'], 2, '.', '') ?></td>
                        <td>
                            <span class="nivel-tag" style="background: <?= e($colores[$na] ?? '#334155') ?>">
                                <?= e($etiquetasNivel[$na] ?? $na) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="dos-columnas">
        <!-- Exposición -->
        <section class="bloque">
            <h2 class="bloque-titulo">3. Exposición del territorio</h2>
            <?php
            $proc = $t['exposicion']['campos'] ?? [];
            $etiquetaEstado = [
                'oficial'  => 'totales oficiales',
                'mixta'    => 'datos mixtos',
                'estimada' => 'estimación del catálogo piloto',
            ][$t['exposicion']['estado'] ?? 'estimada'] ?? 'estimación';
            $filasRep = [
                ['poblacion',     'Población total',  numero_legible($t['poblacion']) . ' hab.'],
                ['viviendas',     'Viviendas',        numero_legible($t['viviendas'])],
                ['escuelas',      'Colegios',         (int)$t['escuelas']],
                ['centros_salud', 'Centros de salud', (int)$t['centros_salud']],
            ];
            foreach ($filasRep as [$campo, $lbl, $val]):
                $p = $proc[$campo] ?? null;
                ?>
                <div class="dato">
                    <span><?= e($lbl) ?></span>
                    <span>
                        <?= e((string)$val) ?>
                        <br><small style="color:#94a3b8;font-weight:400">
                            <?php if (!empty($p['oficial'])): ?>
                                oficial · <?= e($p['fuente']) ?><?= !empty($p['anio']) ? ' · ' . e((string)$p['anio']) : '' ?>
                            <?php else: ?>
                                estimación de referencia
                            <?php endif; ?>
                        </small>
                    </span>
                </div>
            <?php endforeach; ?>
            <div class="dato"><span>Red vial</span><span><?= numero_legible($t['km_vias']) ?> km</span></div>
            <div class="dato"><span>Cultivos</span><span><?= numero_legible($t['hectareas_cultivo']) ?> ha</span></div>
            <div class="dato"><span>Población en zona inundable <small style="color:#94a3b8;font-weight:400">(estimada)</small></span><span><?= numero_legible($t['poblacion_expuesta_inundacion']) ?></span></div>
            <div class="dato"><span>Población en zona de huaico <small style="color:#94a3b8;font-weight:400">(estimada)</small></span><span><?= numero_legible($t['poblacion_expuesta_huaico']) ?></span></div>
            <div class="dato"><span>Capacidad de albergues</span><span><?= numero_legible($t['albergues_capacidad']) ?></span></div>
            <div class="dato"><span>Estado de la exposición</span><span><?= e($etiquetaEstado) ?></span></div>
            <p style="font-size:8.5pt;color:#94a3b8;line-height:1.45;margin-top:6px">
                El total puede provenir de una fuente oficial; la fracción expuesta es siempre una
                estimación (total × susceptibilidad). Son dos cifras distintas.
            </p>
        </section>

        <!-- Variables -->
        <section class="bloque">
            <h2 class="bloque-titulo">4. Variables del peligro dominante</h2>
            <?php foreach ($principal['detalle'] as $d): ?>
                <div class="dato">
                    <span><?= e($d['variable']) ?></span>
                    <span>
                        <?= $d['valor'] === null ? 'sin dato' : number_format((float)$d['valor'], is_float($d['valor']) && $d['valor'] < 10 ? 2 : 1, '.', '') ?>
                        <?= e($d['unidad']) ?>
                        <?php if ($d['umbral'] !== null): ?>
                            <br><small style="color:#94a3b8;font-weight:400">umbral <?= number_format((float)$d['umbral'], 1, '.', '') ?> <?= e($d['unidad']) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <!-- Zonificación -->
    <section class="bloque">
        <h2 class="bloque-titulo">5. Aptitud del territorio para equipamientos críticos (ISE)</h2>
        <div class="dato">
            <span>Índice de Seguridad de Emplazamiento</span>
            <span style="color: <?= e($clase['color']) ?>; font-weight: 600">
                <?= (int)round($ise) ?> / 100 · Clase <?= e($clase['id']) ?> — <?= e($clase['nombre']) ?>
            </span>
        </div>
        <p style="font-size:8.5pt;color:#475569;margin:2.5mm 0 0">
            <?php if ($clase['id'] === 'D'): ?>
                No se recomienda emplazar colegios, hospitales ni albergues en este distrito sin obras mayores de mitigación.
                La evaluación de un punto específico requiere estudio de suelos y de riesgo.
            <?php elseif ($clase['id'] === 'C'): ?>
                Emplazamiento restringido: exige estudio de riesgo específico y obras de mitigación antes de la licencia.
            <?php elseif ($clase['id'] === 'B'): ?>
                Apto con condiciones: documentar medidas de mitigación en el expediente.
            <?php else: ?>
                Sin restricciones relevantes para equipamientos, sujeto a estudios específicos de suelos.
            <?php endif; ?>
        </p>
    </section>

    <!-- Acciones -->
    <section class="bloque">
        <h2 class="bloque-titulo">6. Recomendaciones de apoyo a la decisión</h2>
        <ol class="acciones">
            <?php foreach ($acciones as $accion): ?>
                <li><?= e($accion) ?></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- Fuentes -->
    <section class="bloque">
        <h2 class="bloque-titulo">7. Trazabilidad de las fuentes</h2>
        <div class="fuentes">
            <?php foreach ($r['fuentes'] as $clave => $fuente): ?>
                <div><span><?= e(ucfirst($clave)) ?></span><span><?= e($fuente) ?></span></div>
            <?php endforeach; ?>
            <div><span>Climatología</span><span><?= e($r['fuente_climatologia']) ?></span></div>
            <div><span>Actualizado</span><span><?= e(fecha_legible($r['actualizado'])) ?></span></div>
        </div>
    </section>

    <div class="aviso">
        <strong>Aviso importante.</strong>
        Este documento es una <strong>recomendación de apoyo a la decisión</strong> generada por WILLAQ.
        No constituye una alerta oficial ni sustituye a SENAMHI, INDECI, ENFEN ni a las autoridades
        competentes. Ante una emergencia, siga los canales oficiales.
        Las cifras de exposición de este informe se declaran campo por campo.
        <?= e(exposicion_texto_corto()) ?>
    </div>

    <footer class="pie">
        <span>WILLAQ · Inteligencia territorial y alerta temprana del Fenómeno El Niño</span>
        <span>Proyecto académico · IESTP "Argentina" — DSI</span>
        <span>Página 1 de 1</span>
    </footer>
</div>

</body>
</html>
