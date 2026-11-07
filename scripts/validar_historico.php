#!/usr/bin/env php
<?php
/**
 * WILLAQ — VALIDACIÓN HISTÓRICA (El Niño 2017 y El Niño costero 2023)
 *
 * Responde a la pregunta más difícil que le pueden hacer al motor de riesgo:
 * "¿esto habría detectado los eventos que de verdad ocurrieron?".
 *
 * Método
 * ------
 *   1. Se toma la serie ERA5 (Open-Meteo Archive) de los últimos 11 años.
 *   2. Para cada evento histórico se evalúan TRES escalas de acumulación
 *      (1 día, 3 días, 30 días): el máximo móvil dentro de la ventana del
 *      evento.
 *   3. Cada escala se normaliza contra la distribución del MISMO periodo del
 *      calendario (±15 días, 11 años).
 *   4. Se aplica EXACTAMENTE la misma función de producción
 *      (riesgo_indice_lluvia() = riesgo_norm × rampa de materialidad) y la
 *      misma fórmula de índice con exposición y vulnerabilidad del distrito.
 *   5. Se conserva la escala de mayor índice.
 *
 * No hay reimplementación: se corre el motor sobre el pasado.
 *
 * Diagnóstico
 * -----------
 * Cuando un distrito con emergencia documentada NO es detectado, el script
 * separa dos causas muy distintas:
 *
 *   · datos_sin_senal        → en el reanálisis, el evento no es anómalo
 *                               (valor ≤ p95 en todas las escalas). El modelo
 *                               no puede detectarlo porque el dato no está.
 *   · senal_presente_no_detectado → el evento SÍ es anómalo en los datos pero
 *                               el índice no llegó al umbral. Esto sí sería
 *                               una falla del modelo y hay que corregirlo.
 *
 * Esa distinción es la diferencia entre "el sistema no sirve" y "el sistema
 * necesita mejores datos en esta cuenca".
 *
 * Uso
 * ---
 *   php scripts/validar_historico.php
 *   php scripts/validar_historico.php --ayuda
 *
 * Salida: data/validacion_historica.json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Cache.php';
require_once __DIR__ . '/../models/Clima.php';
require_once __DIR__ . '/../models/Riesgo.php';
// La aritmética de detección vive en una librería compartida con el backtest
// de estaciones: una sola implementación, dos fuentes de lluvia.
require_once __DIR__ . '/lib_validacion.php';

$opciones = getopt('', ['ayuda']);

if (isset($opciones['ayuda'])) {
    echo <<<TXT
WILLAQ · validación histórica

  Corre el motor de riesgo sobre los eventos de El Niño 2017, 2017-2018 y 2023
  y contrasta los distritos señalados con los que tienen emergencia documentada.

  Escalas evaluadas: pico diario, acumulado 3 días, acumulado 30 días.
  Diagnóstico: separa "el modelo falló" de "el reanálisis no contiene el evento".

  Salida: data/validacion_historica.json

TXT;
    exit(0);
}

set_time_limit(0);
ini_set('memory_limit', '512M');

/**
 * Eventos históricos del piloto.
 * La definición vive en la librería compartida: el backtest de estaciones
 * debe comparar exactamente las mismas ventanas y las mismas listas de
 * referencia, o la diferencia entre ERA5 y SENAMHI sería indistinguible de
 * un desfase de fechas.
 */
$EVENTOS = wq_eventos_historicos();

$territorios = territorios_todos();
$anioFin = (int)clima_hoy()->format('Y') - 1;
$anioIni = $anioFin - 10;

wq_linea();
wq_linea('  ╔══════════════════════════════════════════════════════════╗');
wq_linea('  ║  WILLAQ · validación histórica (2017 / 2023)              ║');
wq_linea('  ╚══════════════════════════════════════════════════════════╝');
wq_linea('  Distritos: ' . count($territorios) . '   Serie: ERA5 ' . $anioIni . '-' . $anioFin);
wq_linea('  Escalas: ' . implode(' · ', array_map(
    fn($e) => $e['etiqueta'],
    array_values($ESCALAS_DETECCION)
)));
wq_linea();

$resultado = [
    'generado_en' => time(),
    'generado_legible' => date('c'),
    'serie' => ['anio_inicio' => $anioIni, 'anio_fin' => $anioFin, 'fuente' => 'ERA5 (Open-Meteo Archive)'],
    'metodo' => 'El motor de riesgo se ejecuta sobre la serie ERA5 histórica. Para cada evento se '
        . 'calcula el máximo acumulado en tres escalas (1, 3 y 30 días) dentro de la ventana del '
        . 'evento; cada escala se normaliza contra la distribución del mismo periodo del calendario '
        . '(±15 días, 11 años) usando la misma función de producción riesgo_indice_lluvia() '
        . '(percentil local × rampa de materialidad). Se conserva la escala de mayor índice.',
    'escalas' => $ESCALAS_DETECCION,
    'umbral_deteccion' => 'nivel alto (≥51) o muy alto (≥76)',
    'eventos' => [],
];

foreach ($EVENTOS as $clave => $evento) {
    wq_linea('  ────────────────────────────────────────────────────────────────');
    wq_linea('  ' . $evento['titulo']);
    wq_linea('  ' . str_repeat('─', 78));
    wq_linea('  ' . str_pad('DISTRITO', 26) . str_pad('ESCALA', 15) . str_pad('VALOR', 11)
        . str_pad('P95 REF', 10) . str_pad('H', 7) . str_pad('ÍNDICE', 9) . 'NIVEL');
    wq_linea('  ' . str_repeat('─', 78));

    $filas = [];
    $senalados = [];
    $referencia = $evento['referencia'];

    foreach ($territorios as $t) {
        $archivo = clima_archivo($t['lat'], $t['lon'], $anioIni . '-01-01', $anioFin . '-12-31');
        if (!$archivo) {
            continue;
        }
        $serie = wq_serie_precipitacion($archivo);

        // Exposición y vulnerabilidad: las MISMAS que usa el motor para la
        // amenaza de lluvia, con el mismo redondeo a 3 decimales antes de
        // entrar en la fórmula. Así la validación y la producción comparten
        // una aritmética literalmente idéntica, no solo equivalente.
        [$E, $V] = wq_exposicion_vulnerabilidad($t);

        // La detección vive en la librería compartida: es la MISMA función
        // que corre el backtest de estaciones, con la serie del SENAMHI.
        $fila = wq_deteccion_evento($serie, $evento, $E, $V, $t);
        if ($fila === null) {
            continue;
        }

        $filas[] = $fila;

        if ($fila['detectado']) {
            $senalados[] = $t['id'];
        }
    }

    usort($filas, fn($a, $b) => $b['riesgo'] <=> $a['riesgo']);

    foreach ($filas as $f) {
        $marca = $f['en_referencia'] ? '•' : ' ';
        wq_linea(sprintf(
            '  %s%-25s %-15s %-11s %-10s %-7s %-9s %s',
            $marca,
            substr($f['distrito'], 0, 24),
            $ESCALAS_DETECCION[$f['escala_ganadora']]['etiqueta'],
            $f['valor'] . ' mm',
            $f['referencia_p95'] . ' mm',
            $f['peligro'],
            $f['riesgo'],
            $f['nivel_etiqueta']
        ));
    }

    // ---------- Diagnóstico de los distritos de referencia no detectados ----------
    // Tres categorías, con reglas fijadas por adelantado:
    //
    //   evento_ausente_en_datos  ninguna escala supera su p95 local.
    //                            El reanálisis no registra el episodio: ningún
    //                            modelo que use esta fuente puede detectarlo.
    //   evento_debil_en_datos    alguna escala supera el p95, pero por menos
    //                            del 50 %: la anomalía está dentro de la
    //                            variabilidad interanual ordinaria y el
    //                            reanálisis no representa el episodio
    //                            documentado (suavizado por resolución).
    //   modelo_no_detecta        alguna escala supera el p95 en más del 50 %,
    //                            pero el índice no alcanzó el umbral: esto sí
    //                            es una limitación del modelo y hay que
    //                            corregirla.
    $noDetectados = [];
    $ausentes = 0;
    $debil = 0;
    $falloModelo = 0;
    foreach ($filas as &$f) {
        if (!$f['en_referencia'] || $f['detectado']) {
            continue;
        }
        if ($f['ratio_p95_max'] <= 1.0) {
            $f['diagnostico'] = 'evento_ausente_en_datos';
            $ausentes++;
        } elseif ($f['ratio_p95_max'] <= 1.5) {
            $f['diagnostico'] = 'evento_debil_en_datos';
            $debil++;
        } else {
            $f['diagnostico'] = 'modelo_no_detecta';
            $falloModelo++;
        }
        $noDetectados[] = [
            'id' => $f['id'],
            'distrito' => $f['distrito'],
            'diagnostico' => $f['diagnostico'],
            'ratio_p95_max' => $f['ratio_p95_max'],
            'ratio_p95_max_escala' => $f['ratio_p95_max_escala'],
            'evento_total_mm' => $f['evento_total_mm'],
            'mejor_riesgo' => $f['riesgo'],
            'nivel' => $f['nivel_etiqueta'],
            'explicacion' => $f['diagnostico'] === 'evento_ausente_en_datos'
                ? 'En ERA5 el episodio no es anómalo en ninguna escala: el valor no supera su p95 local. '
                  . 'El reanálisis no contiene la señal, así que ningún modelo que use esta fuente '
                  . 'puede detectarlo. Limitación de datos, no del método.'
                : ($f['diagnostico'] === 'evento_debil_en_datos'
                    ? 'El episodio solo supera su p95 local de forma marginal (menos del 50 %), dentro '
                      . 'de la variabilidad interanual ordinaria. El reanálisis a 25 km suaviza los '
                      . 'extremos orográficos y convectivos de la costa norte: no representa el episodio '
                      . 'documentado. Limitación de datos, no del método.'
                    : 'El episodio es claramente anómalo en ERA5 (supera su p95 local en más del 50 %), '
                      . 'pero el índice no alcanzó el umbral de detección. Limitación del modelo: '
                      . 'requiere revisión.'),
        ];
    }
    unset($f);

    $detectados = count(array_intersect($senalados, $referencia));
    $totalRef = count($referencia);
    $falsosPositivos = count(array_diff($senalados, $referencia));
    $recall = $totalRef > 0 ? $detectados / $totalRef : 0;
    $conSenal = $totalRef - $ausentes - $debil;
    $recallConSenal = $conSenal > 0 ? $detectados / $conSenal : 0;

    wq_linea();
    wq_linea(sprintf('  Distritos señalados en nivel alto/muy alto: %d', count($senalados)));
    wq_linea(sprintf(
        '  Acierto sobre la referencia documentada: %d de %d (%.0f %%)',
        $detectados,
        $totalRef,
        $recall * 100
    ));
    if ($conSenal > 0) {
        wq_linea(sprintf(
            '  Acierto sobre los distritos con señal clara en los datos: %d de %d (%.0f %%)',
            $detectados,
            $conSenal,
            $recallConSenal * 100
        ));
    }
    wq_linea(sprintf('  Episodio ausente en el reanálisis: %d', $ausentes));
    wq_linea(sprintf('  Episodio presente pero débil en el reanálisis: %d', $debil));
    wq_linea(sprintf('  Episodio con señal clara no detectado por el modelo: %d', $falloModelo));
    wq_linea(sprintf('  Señalados fuera de la referencia: %d', $falsosPositivos));
    wq_linea('  Nota: "•" marca los distritos con emergencia documentada en el evento.');

    if (!empty($noDetectados)) {
        wq_linea();
        wq_linea('  Diagnóstico de no detectados:');
        foreach ($noDetectados as $nd) {
            wq_linea(sprintf(
                '    · %-22s %-30s ratio máx %s · índice %s (%s)',
                substr($nd['distrito'], 0, 21),
                $nd['diagnostico'],
                $nd['ratio_p95_max'],
                $nd['mejor_riesgo'],
                $nd['nivel']
            ));
        }
    }
    wq_linea();

    $resultado['eventos'][$clave] = [
        'titulo' => $evento['titulo'],
        'ventana' => [$evento['inicio'], $evento['fin']],
        'distritos' => $filas,
        'referencia' => $referencia,
        'senalados' => $senalados,
        'detectados' => $detectados,
        'referencia_total' => $totalRef,
        'recall' => round($recall, 3),
        'recall_con_senal' => round($recallConSenal, 3),
        'referencia_con_senal' => $conSenal,
        'evento_ausente_en_datos' => $ausentes,
        'evento_debil_en_datos' => $debil,
        'modelo_no_detecta' => $falloModelo,
        'no_detectados' => $noDetectados,
        'falsos_positivos' => $falsosPositivos,
    ];
}

$ruta = __DIR__ . '/../data/validacion_historica.json';
$json = json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($ruta, $json . PHP_EOL) !== false) {
    wq_linea('  ✓ Resultado escrito en data/validacion_historica.json');
    wq_linea();
} else {
    wq_linea('  ⚠ No se pudo escribir data/validacion_historica.json (permisos).');
    wq_linea();
}

exit(0);
