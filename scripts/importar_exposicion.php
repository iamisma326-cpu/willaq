#!/usr/bin/env php
<?php
/**
 * IMPORTADOR DE EXPOSICIÓN OFICIAL — WILLAQ
 * ============================================================================
 *
 * Qué hace
 *   Lee uno o varios CSV de INEI / MINEDU / MINSA y produce un OVERLAY con
 *   los valores oficiales de población, viviendas, escuelas y centros de
 *   salud, indexado por ubigeo.
 *
 * Qué NO hace (y es importante)
 *   · No convierte la exposición en oficial. Solo cambia el TOTAL.
 *     La fracción expuesta sigue siendo `total × susceptibilidad`.
 *   · No toca `vulnerabilidad`, `susc_*`, `hectareas_cultivo`,
 *     `albergues_capacidad` ni `km_vias`: hay una lista blanca.
 *   · No reescribe `config/territorios.json`. El overlay es aparte, y
 *     borrarlo devuelve el sistema a la estimación actual.
 *   · No asigna nada en silencio: una fila ambigua o sin match se RECHAZA y
 *     se lista, para que la persona la revise.
 *
 * Formato canónico de entrada
 *   Cabecera obligatoria:  indicador, valor
 *   Cabecera recomendada:  ubigeo, anio, fuente
 *   Cabecera opcional:     distrito, provincia, region, nota
 *
 *   ubigeo,indicador,valor,anio,fuente,nota
 *   150101,poblacion,9824,2017,INEI Censo 2017,
 *   150101,viviendas,2650,2017,INEI Censo 2017,
 *   150101,escuelas,42,2023,MINEDU ESCALE,locales escolares
 *   150101,centros_salud,7,2024,MINSA RENIPRESS,IPRESS categoria I
 *
 *   `ubigeo`, `anio` y `fuente` son obligatorios en la práctica: sin ellos no
 *   se sabe de dónde sale el número ni de cuándo es, y eso no es publicable.
 *   Si el CSV trae `distrito`/`provincia`/`region`, se usan como segunda
 *   validación contra el catálogo (un ubigeo con nombre que no corresponde es
 *   la forma más fácil de asignar la población de un distrito a otro).
 *
 * Uso
 *   php scripts/importar_exposicion.php ARCHIVO.csv [más archivos...]
 *   php scripts/importar_exposicion.php ARCHIVO.csv --aplicar
 *   php scripts/importar_exposicion.php ARCHIVO.csv --dry-run
 *   php scripts/importar_exposicion.php ARCHIVO.csv --aplicar --salida=ruta.json
 *   php scripts/importar_exposicion.php --ayuda
 *
 *   Sin --aplicar es SIEMPRE dry-run: muestra la tabla de cambios y no
 *   escribe nada. Ese es el comportamiento por defecto a propósito.
 *
 * Salidas
 *   data/exposicion_oficial.json    overlay (solo con --aplicar)
 *   data/backups/exposicion_*.json  respaldo del overlay anterior (con --aplicar)
 *   data/importacion_reporte.json   reporte de la última ejecución
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

require_once __DIR__ . '/../models/Territorio.php';
require_once __DIR__ . '/../models/Exposicion.php';

$RAIZ = dirname(__DIR__);

// ============================================================================
// OPCIONES
// ============================================================================

$opciones = [
    'archivos' => [],
    'aplicar'  => false,
    'salida'   => $RAIZ . '/data/exposicion_oficial.json',
    'reporte'  => $RAIZ . '/data/importacion_reporte.json',
    'respaldo' => $RAIZ . '/data/backups',
];

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if ($arg === '--aplicar') {
        $opciones['aplicar'] = true;
    } elseif ($arg === '--dry-run') {
        $opciones['aplicar'] = false;
    } elseif (strpos($arg, '--salida=') === 0) {
        $opciones['salida'] = substr($arg, 9);
    } elseif (strpos($arg, '--reporte=') === 0) {
        $opciones['reporte'] = substr($arg, 10);
    } elseif (strpos($arg, '--respaldo=') === 0) {
        $opciones['respaldo'] = substr($arg, 11);
    } elseif ($arg === '--ayuda' || $arg === '-h' || $arg === '--help') {
        print_ayuda();
        exit(0);
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "Opción desconocida: {$arg}\n");
        print_ayuda();
        exit(64);
    } else {
        $opciones['archivos'][] = $arg;
    }
}

if (!$opciones['archivos']) {
    fwrite(STDERR, "Falta el archivo CSV de entrada.\n\n");
    print_ayuda();
    exit(64);
}

foreach ($opciones['archivos'] as $archivo) {
    if (!is_file($archivo)) {
        fwrite(STDERR, "No se encuentra el archivo: {$archivo}\n");
        exit(66);
    }
}

// ============================================================================
// CATÁLOGO DE REFERENCIA (para resolver ubigeo ↔ distrito)
// ============================================================================

$catálogo = json_decode(file_get_contents($RAIZ . '/config/territorios.json'), true);
if (!is_array($catálogo) || empty($catálogo['territorios'])) {
    fwrite(STDERR, "ERROR: config/territorios.json ilegible o vacío.\n");
    exit(70);
}

$porUbigeo = [];
$porNombre = [];
$porDistrito = [];

foreach ($catálogo['territorios'] as $t) {
    $u = (string)($t['ubigeo'] ?? '');
    if ($u !== '') {
        $porUbigeo[$u] = $t;
    }
    $clave = imp_norm($t['distrito'] . '|' . $t['provincia']);
    $porNombre[$clave][] = $t;
    $porDistrito[imp_norm($t['distrito'])][] = $t;
}

// ============================================================================
// LECTURA Y VALIDACIÓN
// ============================================================================

$permitidos = exposicion_campos_permitidos();

// Overlay que ya existe en la ruta de destino: es la base sobre la que se
// acumulan los cambios. Se lee de la RUTA QUE SE VA A ESCRIBIR (puede estar
// cambiada con --salida), no de la ruta por defecto.
$overlayPrevio = imp_leer_overlay($opciones['salida']);

$cambios    = [];   // cambios propuestos: distrito / campo / antes / después
$rechazos  = [];   // filas rechazadas, con motivo
$vistos     = [];   // clave ubigeo|indicador|anio → línea (para duplicados)
$archivos   = [];   // metadatos de cada archivo leído
$porTerritorio = []; // ubigeo => ['distrito'=>, 'provincia'=>, 'campos'=>[]]

$lineaNum = 0;

foreach ($opciones['archivos'] as $rutaCsv) {
    $sha = hash_file('sha256', $rutaCsv);
    $nombre = basename($rutaCsv);

    $h = fopen($rutaCsv, 'r');
    if ($h === false) {
        fwrite(STDERR, "ERROR: no se pudo abrir {$rutaCsv}\n");
        exit(74);
    }

    // Cabecera
    $cabecera = fgetcsv($h, 0, ',', '"', '');
    if ($cabecera === false || $cabecera === null) {
        fwrite(STDERR, "ERROR: {$nombre} está vacío.\n");
        fclose($h);
        exit(66);
    }
    // Quitar BOM UTF-8 del primer campo
    $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cabecera[0]);
    $cabecera = array_map(function ($c) {
        return mb_strtolower(trim((string)$c), 'UTF-8');
    }, $cabecera);
    $idx = array_flip($cabecera);

    $faltan = [];
    foreach (['indicador', 'valor'] as $req) {
        if (!isset($idx[$req])) {
            $faltan[] = $req;
        }
    }
    if ($faltan) {
        fwrite(STDERR, "ERROR: {$nombre} — faltan columnas obligatorias: " . implode(', ', $faltan) . "\n");
        fwrite(STDERR, "       Cabecera encontrada: " . implode(', ', $cabecera) . "\n");
        fclose($h);
        exit(65);
    }

    $tiene = function ($col) use ($idx) {
        return isset($idx[$col]);
    };

    $filasArchivo = 0;
    $aceptadasArchivo = 0;

    while (($fila = fgetcsv($h, 0, ',', '"', '')) !== false) {
        $lineaNum++;
        $filasArchivo++;

        // Línea vacía: se ignora sin contar como error.
        if (count($fila) === 1 && trim((string)$fila[0]) === '') {
            $lineaNum--;
            $filasArchivo--;
            continue;
        }

        $get = function ($col) use ($fila, $idx) {
            if (!isset($idx[$col]) || !isset($fila[$idx[$col]])) {
                return '';
            }
            return trim((string)$fila[$idx[$col]]);
        };

        $ubigeo    = $get('ubigeo');
        $indicador = mb_strtolower($get('indicador'));
        $valorTxt  = $get('valor');
        $anioTxt   = $get('anio');
        $fuente    = $get('fuente');
        $nota      = $get('nota');
        $distritoCsv  = $get('distrito');
        $provinciaCsv = $get('provincia');
        $regionCsv    = $get('region');

        $motivo = null;
        $territorio = null;
        $detalle = [];

        // --- 1. Indicador en la lista blanca -------------------------------
        if ($indicador === '') {
            $motivo = 'indicador vacío';
        } elseif (!in_array($indicador, $permitidos, true)) {
            $motivo = "indicador «{$indicador}» fuera de la lista blanca ("
                . implode(', ', $permitidos) . ')';
        }

        // --- 2. Valor numérico entero --------------------------------------
        $valor = null;
        if ($motivo === null) {
            $valorCrudo = str_replace([' ', "\u{00A0}"], '', $valorTxt);
            if ($valorCrudo === '') {
                $motivo = 'valor vacío';
            } elseif (!preg_match('/^-?[0-9]+$/', $valorCrudo)) {
                $motivo = "valor «{$valorTxt}» no es un número entero";
            } else {
                $valor = (int)$valorCrudo;
                if ($valor < 0) {
                    $motivo = "valor negativo ({$valor})";
                }
            }
        }

        // --- 3. Año ---------------------------------------------------------
        $anio = null;
        if ($motivo === null) {
            if ($anioTxt === '') {
                $motivo = 'falta el año (sin año el dato no es publicable)';
            } elseif (!preg_match('/^[0-9]{4}$/', $anioTxt)) {
                $motivo = "año «{$anioTxt}» no tiene 4 dígitos";
            } else {
                $anio = (int)$anioTxt;
                if ($anio < 1900 || $anio > (int)date('Y') + 1) {
                    $motivo = "año {$anio} fuera de rango";
                }
            }
        }

        // --- 4. Fuente ------------------------------------------------------
        if ($motivo === null && $fuente === '') {
            $motivo = 'falta la fuente (sin fuente el dato no es trazable)';
        }

        // --- 5. Rango del valor --------------------------------------------
        if ($motivo === null) {
            [$min, $max] = exposicion_campo_rango($indicador);
            if ($valor < $min || $valor > $max) {
                $motivo = "valor {$valor} fuera del rango admitido para «{$indicador}» ({$min}–{$max})";
            }
        }

        // --- 6. Resolución del territorio ------------------------------------
        if ($motivo === null) {
            $resolucion = imp_resolver(
                $ubigeo,
                $distritoCsv,
                $provinciaCsv,
                $regionCsv,
                $porUbigeo,
                $porNombre,
                $porDistrito
            );

            if ($resolucion['error']) {
                $motivo = $resolucion['error'];
                if (!empty($resolucion['candidatos'])) {
                    $detalle['candidatos'] = $resolucion['candidatos'];
                }
            } else {
                $territorio = $resolucion['territorio'];
            }
        }

        // --- 7. Duplicados ---------------------------------------------------
        if ($motivo === null) {
            $clave = $territorio['ubigeo'] . '|' . $indicador . '|' . $anio;
            if (isset($vistos[$clave])) {
                $motivo = "duplicado: la línea {$vistos[$clave]} ya trae «{$indicador}» de {$anio} para {$territorio['ubigeo']}";
            } else {
                $vistos[$clave] = $lineaNum;
            }
        }

        // --- Resultado -------------------------------------------------------
        if ($motivo !== null) {
            $rechazos[] = [
                'archivo'    => $nombre,
                'linea'      => $lineaNum,
                'motivo'     => $motivo,
                'ubigeo'     => $ubigeo,
                'indicador'  => $indicador,
                'valor'      => $valorTxt,
                'anio'       => $anioTxt,
                'fuente'     => $fuente,
                'contenido'  => trim(implode(',', array_map(function ($c) {
                    return (string)$c;
                }, $fila))),
            ] + $detalle;
            continue;
        }

        $aceptadasArchivo++;

        // Valor anterior: el del catálogo (estimación) o el del overlay actual.
        $anterior = (int)($territorio[$indicador] ?? 0);
        $overlayPrevioCampos = $overlayPrevio['territorios'][$territorio['ubigeo']]['campos'] ?? [];
        $veniaOficial = isset($overlayPrevioCampos[$indicador]);

        if ($veniaOficial) {
            $anterior = (int)$overlayPrevioCampos[$indicador]['valor'];
        }

        $cambios[] = [
            'archivo'    => $nombre,
            'linea'      => $lineaNum,
            'territorio_id' => $territorio['id'],
            'ubigeo'     => $territorio['ubigeo'],
            'distrito'   => $territorio['distrito'],
            'provincia'  => $territorio['provincia'],
            'region'     => $territorio['region'],
            'campo'      => $indicador,
            'antes'      => $anterior,
            'despues'    => $valor,
            'fuente'     => $fuente,
            'anio'       => $anio,
            'nota'       => $nota,
            'sha256'     => $sha,
            'reemplaza_oficial' => $veniaOficial,
        ];

        if (!isset($porTerritorio[$territorio['ubigeo']])) {
            $porTerritorio[$territorio['ubigeo']] = [
                'distrito'  => $territorio['distrito'],
                'provincia' => $territorio['provincia'],
                'campos'    => [],
            ];
        }
        $porTerritorio[$territorio['ubigeo']]['campos'][$indicador] = [
            'valor'        => $valor,
            'fuente'       => $fuente,
            'anio'         => $anio,
            'archivo'      => $nombre,
            'sha256'       => $sha,
            'importado_en' => date('c'),
            'nota'         => $nota,
        ];
    }

    fclose($h);

    $archivos[] = [
        'archivo'   => $nombre,
        // Ruta relativa a la raíz del proyecto cuando se puede: un reporte
        // versionado no debería filtrar la ruta local de quien lo generó.
        'ruta'      => imp_ruta_relativa(realpath($rutaCsv) ?: $rutaCsv),
        'sha256'    => $sha,
        'filas'     => $filasArchivo,
        'aceptadas' => $aceptadasArchivo,
        'rechazadas' => $filasArchivo - $aceptadasArchivo,
    ];
}

// ============================================================================
// RESUMEN EN PANTALLA
// ============================================================================

$totalAceptadas = count($cambios);
$totalRechazos  = count($rechazos);
$territoriosAfectados = count($porTerritorio);
$camposPorTerritorio = array_map('count', array_column($porTerritorio, 'campos'));

echo "\n";
echo "  ╔══════════════════════════════════════════════════════════════════╗\n";
echo "  ║  WILLAQ · importador de exposición oficial                        ║\n";
echo "  ╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "  Archivos leídos:\n";
foreach ($archivos as $a) {
    printf("    · %-34s %5d filas · %5d aceptadas · %5d rechazadas\n",
        $a['archivo'], $a['filas'], $a['aceptadas'], $a['rechazadas']);
    printf("      sha256 %s\n", $a['sha256']);
}

echo "\n  ── Cambios propuestos ────────────────────────────────────────────\n";

if (!$totalAceptadas) {
    echo "    (ninguno)\n";
} else {
    printf("    %-22s %-14s %12s %12s  %-20s %s\n",
        'DISTRITO', 'CAMPO', 'ANTES', 'DESPUÉS', 'FUENTE', 'AÑO');
    echo "    " . str_repeat('─', 104) . "\n";
    foreach ($cambios as $c) {
        printf("    %-22s %-14s %12s %12s  %-20s %s%s\n",
            mb_strimwidth($c['distrito'], 0, 22, '…', 'UTF-8'),
            $c['campo'],
            number_format($c['antes'], 0, ',', ' '),
            number_format($c['despues'], 0, ',', ' '),
            mb_strimwidth($c['fuente'], 0, 20, '…', 'UTF-8'),
            $c['anio'],
            $c['reemplaza_oficial'] ? '  (reemplaza oficial)' : '');
    }
}

echo "\n  ── Filas rechazadas ──────────────────────────────────────────────\n";

if (!$totalRechazos) {
    echo "    (ninguna)\n";
} else {
    foreach ($rechazos as $r) {
        printf("    %s:%d  %s\n", $r['archivo'], $r['linea'], $r['motivo']);
        printf("      contenido: %s\n", mb_strimwidth($r['contenido'], 0, 90, '…', 'UTF-8'));
        if (!empty($r['candidatos'])) {
            printf("      candidatos: %s\n", implode(' | ', $r['candidatos']));
        }
    }
}

echo "\n  ── Resumen ───────────────────────────────────────────────────────\n";
printf("    Filas aceptadas      : %d\n", $totalAceptadas);
printf("    Filas rechazadas     : %d\n", $totalRechazos);
printf("    Territorios afectados: %d de %d del catálogo\n",
    $territoriosAfectados, count($catálogo['territorios']));

// Distritos del catálogo sin ningún dato oficial: conviene nombrarlos.
$sinDatos = [];
foreach ($catálogo['territorios'] as $t) {
    if (empty($t['ubigeo']) || !isset($porTerritorio[$t['ubigeo']])) {
        $sinDatos[] = $t['distrito'];
    }
}
if ($sinDatos) {
    printf("    Sin datos en este lote: %s\n", implode(', ', $sinDatos));
}

// ============================================================================
// APLICACIÓN
// ============================================================================

$overlayFinal = imp_construir_overlay($overlayPrevio['territorios'], $porTerritorio);

/**
 * Construye el contenido final del overlay.
 */
function imp_construir_overlay($previo, $nuevos) {
    $resultado = [];
    $permitidos = exposicion_campos_permitidos();

    // Territorios que ya estaban en el overlay y no aparecen en este lote:
    // se conservan (importar un archivo nuevo no borra lo anterior).
    foreach ($previo as $ubigeo => $bloque) {
        $resultado[$ubigeo] = [
            'distrito'  => $bloque['distrito'],
            'provincia' => $bloque['provincia'],
        ];
        foreach ($bloque['campos'] as $campo => $entrada) {
            $resultado[$ubigeo][$campo] = $entrada;
        }
    }

    // Territorios de este lote.
    foreach ($nuevos as $ubigeo => $bloque) {
        if (!isset($resultado[$ubigeo])) {
            $resultado[$ubigeo] = [
                'distrito'  => $bloque['distrito'],
                'provincia' => $bloque['provincia'],
            ];
        }
        foreach ($bloque['campos'] as $campo => $entrada) {
            $resultado[$ubigeo][$campo] = $entrada;
        }
    }

    return $resultado;
}

$overlayFinal = imp_construir_overlay($overlayPrevio['territorios'], $porTerritorio);

$contenido = [
    '_meta' => [
        'descripcion'    => 'Overlay de exposición oficial de WILLAQ. Generado por scripts/importar_exposicion.php. '
            . 'Borrar este archivo devuelve el sistema a las estimaciones de config/territorios.json.',
        'version'        => (int)($overlayPrevio['_meta']['version'] ?? 0) + 1,
        'generado_en'    => date('c'),
        'generado_por'   => 'scripts/importar_exposicion.php',
        'modo'           => $opciones['aplicar'] ? 'aplicar' : 'dry-run',
        'archivos'       => $archivos,
        'campos_permitidos' => $permitidos,
        'total_territorios' => count($overlayFinal),
        'total_campos'      => array_sum(array_map(
            fn($b) => count(array_intersect_key($b, array_flip(exposicion_campos_permitidos()))),
            $overlayFinal
        )),
        'advertencia'    => 'Los totales son oficiales; la fracción expuesta sigue siendo una estimación '
            . 'por ratio de susceptibilidad. No confundir ambos números.',
    ],
];

foreach ($overlayFinal as $ubigeo => $bloque) {
    $contenido[$ubigeo] = $bloque;
}

$reporte = [
    'generado_en' => date('c'),
    'modo'        => $opciones['aplicar'] ? 'aplicar' : 'dry-run',
    'archivos'    => $archivos,
    'resumen'     => [
        'filas_leidas'          => array_sum(array_column($archivos, 'filas')),
        'filas_aceptadas'       => $totalAceptadas,
        'filas_rechazadas'      => $totalRechazos,
        'territorios_afectados' => $territoriosAfectados,
        'campos_por_territorio' => $camposPorTerritorio,
    ],
    'cambios'  => $cambios,
    'rechazos' => $rechazos,
    'overlay'  => [
        'ruta'              => $opciones['salida'],
        'total_territorios' => count($overlayFinal),
        'total_campos'      => $contenido['_meta']['total_campos'],
    ],
];

if (!$opciones['aplicar']) {
    // El reporte de un dry-run sí se escribe: sirve de bitácora de la revisión.
    imp_escribir_json($opciones['reporte'], $reporte);

    echo "\n  Modo: DRY-RUN (no se escribió el overlay).\n";
    echo "  Revisa la tabla de arriba. Si todo está correcto, repite con --aplicar.\n";
    echo "  Reporte de esta revisión: " . $opciones['reporte'] . "\n\n";
    exit($totalRechazos > 0 ? 1 : 0);
}

// --- Respaldo del overlay anterior -----------------------------------------
$respaldoHecho = '';
if (is_file($opciones['salida'])) {
    $dirRespaldo = $opciones['respaldo'];
    if (!is_dir($dirRespaldo)) {
        @mkdir($dirRespaldo, 0775, true);
    }
    $nombreRespaldo = 'exposicion_' . date('Ymd_His') . '.json';
    $rutaRespaldo = $dirRespaldo . '/' . $nombreRespaldo;
    if (@copy($opciones['salida'], $rutaRespaldo)) {
        $respaldoHecho = $rutaRespaldo;
    } else {
        fwrite(STDERR, "AVISO: no se pudo crear el respaldo en {$rutaRespaldo}.\n");
    }
}

if (!imp_escribir_json($opciones['salida'], $contenido)) {
    fwrite(STDERR, "ERROR: no se pudo escribir el overlay en {$opciones['salida']}.\n");
    exit(73);
}

$reporte['overlay']['respaldo'] = $respaldoHecho;
$reporte['overlay']['escrito'] = $opciones['salida'];
imp_escribir_json($opciones['reporte'], $reporte);

echo "\n  Modo: APLICADO\n";
echo "  Overlay escrito    : " . $opciones['salida'] . "\n";
if ($respaldoHecho) {
    echo "  Respaldo anterior  : " . $respaldoHecho . "\n";
}
echo "  Reporte            : " . $opciones['reporte'] . "\n";
printf("  Territorios con dato oficial: %d · campos: %d\n",
    count($overlayFinal), $contenido['_meta']['total_campos']);
echo "\n  Siguiente paso (opcional, para que los snapshots reflejen los nuevos\n";
echo "  totales):\n";
echo "    php scripts/actualizar.php --ventana=all\n";
echo "    php scripts/semear_alertas.php --limpiar\n";
echo "\n  Recuerda: el total puede ser oficial; la fracción expuesta sigue\n";
echo "  siendo una estimación por ratio. Son dos números distintos.\n\n";

exit($totalRechazos > 0 ? 1 : 0);

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

/**
 * Lee un overlay desde una ruta concreta y lo devuelve normalizado.
 *
 * El importador NO usa exposicion_cargar() del modelo, porque aquel lee
 * siempre la ruta por defecto y aquí la ruta puede estar cambiada con
 * --salida. Además, este lector no cachea: cada ejecución lee del disco.
 *
 * @return array ['_meta' => [...], 'territorios' => [ubigeo => ['campos'=>...]]]
 */
function imp_leer_overlay($ruta) {
    $vacio = ['_meta' => [], 'territorios' => []];

    if (!$ruta || !is_file($ruta)) {
        return $vacio;
    }
    $crudo = @file_get_contents($ruta);
    $datos = json_decode((string)$crudo, true);
    if (!is_array($datos)) {
        return $vacio;
    }

    $meta = is_array($datos['_meta'] ?? null) ? $datos['_meta'] : [];
    $permitidos = exposicion_campos_permitidos();
    $territorios = [];

    foreach ($datos as $clave => $bloque) {
        if ($clave === '_meta' || !is_array($bloque)) {
            continue;
        }
        if (!preg_match('/^[0-9]{6}$/', (string)$clave)) {
            continue;
        }
        $campos = [];
        foreach ($permitidos as $campo) {
            if (!isset($bloque[$campo]) || !is_array($bloque[$campo])) {
                continue;
            }
            $e = $bloque[$campo];
            if (!isset($e['valor']) || !is_numeric($e['valor'])) {
                continue;
            }
            $campos[$campo] = [
                'valor'        => (int)$e['valor'],
                'fuente'       => (string)($e['fuente'] ?? ''),
                'anio'         => isset($e['anio']) ? (int)$e['anio'] : null,
                'archivo'      => (string)($e['archivo'] ?? ''),
                'sha256'       => (string)($e['sha256'] ?? ''),
                'importado_en' => (string)($e['importado_en'] ?? ''),
                'nota'         => (string)($e['nota'] ?? ''),
            ];
        }
        if ($campos) {
            $territorios[(string)$clave] = [
                'distrito'  => (string)($bloque['distrito'] ?? ''),
                'provincia' => (string)($bloque['provincia'] ?? ''),
                'campos'    => $campos,
            ];
        }
    }

    return ['_meta' => $meta, 'territorios' => $territorios];
}

/**
 * Devuelve la ruta relativa a la raíz del proyecto si el archivo está dentro
 * de ella; si está fuera, devuelve la ruta absoluta. Sirve para que el
 * reporte de importación no arrastre la ruta local de quien lo generó.
 */
function imp_ruta_relativa($ruta) {
    $ruta = (string)$ruta;
    $raiz = dirname(__DIR__);
    $real = realpath($ruta);
    if ($real && strpos($real, $raiz . DIRECTORY_SEPARATOR) === 0) {
        return ltrim(substr($real, strlen($raiz)), DIRECTORY_SEPARATOR);
    }
    return $ruta;
}

/**
 * Normaliza un texto para comparar nombres: sin tildes, minúsculas, solo
 * letras y números.
 */
function imp_norm($texto) {
    $texto = mb_strtolower(trim((string)$texto), 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
        'ò' => 'o', 'ù' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i',
        'ö' => 'o', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        'Ü' => 'u', 'Ñ' => 'n',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $texto);
}

/**
 * Resuelve una fila a un territorio del catálogo.
 *
 * @return array ['territorio'=>array|null, 'error'=>string|null, 'candidatos'=>array]
 */
function imp_resolver(
    $ubigeo,
    $distritoCsv,
    $provinciaCsv,
    $regionCsv,
    $porUbigeo,
    $porNombre,
    $porDistrito
) {
    $candidatos = [];

    // --- Camino 1: ubigeo exacto -----------------------------------------
    if ($ubigeo !== '') {
        if (!preg_match('/^[0-9]{6}$/', $ubigeo)) {
            return [
                'territorio' => null,
                'error'      => "ubigeo «{$ubigeo}» no tiene 6 dígitos",
                'candidatos' => [],
            ];
        }
        if (!isset($porUbigeo[$ubigeo])) {
            return [
                'territorio' => null,
                'error'      => "ubigeo {$ubigeo} no está en el catálogo de 29 distritos",
                'candidatos' => [],
            ];
        }
        $t = $porUbigeo[$ubigeo];

        // Segunda validación: si el CSV trae nombres, tienen que concordar.
        $discordancia = [];
        if ($distritoCsv !== '' && imp_norm($distritoCsv) !== imp_norm($t['distrito'])) {
            $discordancia[] = "distrito «{$distritoCsv}» vs «{$t['distrito']}»";
        }
        if ($provinciaCsv !== '' && imp_norm($provinciaCsv) !== imp_norm($t['provincia'])) {
            $discordancia[] = "provincia «{$provinciaCsv}» vs «{$t['provincia']}»";
        }
        if ($regionCsv !== '' && imp_norm($regionCsv) !== imp_norm($t['region'])) {
            $discordancia[] = "región «{$regionCsv}» vs «{$t['region']}»";
        }
        if ($discordancia) {
            return [
                'territorio' => null,
                'error'      => 'ubigeo y nombre no coinciden: ' . implode('; ', $discordancia)
                    . ' — el ubigeo puede tener un dígito mal puesto',
                'candidatos' => [$t['id'] . ' · ' . $t['distrito'] . ' (' . $t['provincia'] . ')'],
            ];
        }

        return ['territorio' => $t, 'error' => null, 'candidatos' => []];
    }

    // --- Camino 2: sin ubigeo → por nombre + provincia --------------------
    if ($distritoCsv === '') {
        return [
            'territorio' => null,
            'error'      => 'la fila no trae ubigeo ni distrito: no hay forma de asignarla',
            'candidatos' => [],
        ];
    }

    if ($provinciaCsv !== '') {
        $clave = imp_norm($distritoCsv . '|' . $provinciaCsv);
        if (isset($porNombre[$clave])) {
            $candidatos = $porNombre[$clave];
        }
    }

    if (!$candidatos) {
        // Solo distrito: puede ser ambiguo, y esa ambigüedad se reporta.
        $claveD = imp_norm($distritoCsv);
        $candidatos = $porDistrito[$claveD] ?? [];
    }

    if (!$candidatos) {
        return [
            'territorio' => null,
            'error'      => "no se encontró ningún distrito del catálogo que sea «{$distritoCsv}»"
                . ($provinciaCsv !== '' ? " en la provincia «{$provinciaCsv}»" : ''),
            'candidatos' => [],
        ];
    }

    if (count($candidatos) > 1) {
        $lista = [];
        foreach ($candidatos as $c) {
            $lista[] = $c['id'] . ' · ' . $c['distrito'] . ' (' . $c['provincia'] . ')';
        }
        return [
            'territorio' => null,
            'error'      => 'nombre ambiguo: ' . count($candidatos)
                . ' distritos del catálogo coinciden con «' . $distritoCsv . '»',
            'candidatos' => $lista,
        ];
    }

    $t = $candidatos[0];

    // Si el CSV trae provincia o región, se comprueban contra el catálogo.
    $discordancia = [];
    if ($provinciaCsv !== '' && imp_norm($provinciaCsv) !== imp_norm($t['provincia'])) {
        $discordancia[] = "provincia «{$provinciaCsv}» vs «{$t['provincia']}»";
    }
    if ($regionCsv !== '' && imp_norm($regionCsv) !== imp_norm($t['region'])) {
        $discordancia[] = "región «{$regionCsv}» vs «{$t['region']}»";
    }
    if ($discordancia) {
        return [
            'territorio' => null,
            'error'      => 'nombre y ubicación no coinciden: ' . implode('; ', $discordancia),
            'candidatos' => [$t['id'] . ' · ' . $t['distrito'] . ' (' . $t['provincia'] . ')'],
        ];
    }

    return ['territorio' => $t, 'error' => null, 'candidatos' => []];
}

/**
 * Escribe JSON de forma atómica (temporal + rename): si la web está leyendo
 * el archivo en ese instante, nunca ve un JSON a medio escribir.
 */
function imp_escribir_json($ruta, $datos) {
    $dir = dirname($ruta);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $payload = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) {
        return false;
    }
    $tmp = $ruta . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $payload) === false) {
        return false;
    }
    if (!@rename($tmp, $ruta)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Ayuda del script.
 */
function print_ayuda() {
    echo <<<TXT

  WILLAQ · importador de exposición oficial (INEI / MINEDU / MINSA)

  USO
    php scripts/importar_exposicion.php ARCHIVO.csv [ARCHIVO2.csv ...] [opciones]

  OPCIONES
    --aplicar          Escribe el overlay. Sin esta opción es dry-run.
    --dry-run          No escribe nada (comportamiento por defecto).
    --salida=RUTA      Ruta del overlay. Por defecto data/exposicion_oficial.json
    --reporte=RUTA     Ruta del reporte. Por defecto data/importacion_reporte.json
    --respaldo=DIR     Carpeta de respaldos. Por defecto data/backups
    --ayuda            Esta ayuda.

  FORMATO DE ENTRADA (CSV con cabecera)
    Obligatorias : indicador, valor
    Recomendadas : ubigeo, anio, fuente
    Opcionales   : distrito, provincia, region, nota

    ubigeo,indicador,valor,anio,fuente,nota
    150101,poblacion,9824,2017,INEI Censo 2017,
    150101,escuelas,42,2023,MINEDU ESCALE,locales escolares

  CAMPOS IMPORTABLES (lista blanca)
    poblacion, viviendas, escuelas, centros_salud

    No son importables vulnerabilidad, susc_*, hectareas_cultivo,
    albergues_capacidad ni km_vias: no provienen de INEI/MINEDU/MINSA.

  EJEMPLOS
    # Revisar sin escribir nada
    php scripts/importar_exposicion.php datos/ejemplo/inei_poblacion.csv

    # Aplicar de verdad (guarda respaldo del overlay anterior)
    php scripts/importar_exposicion.php datos/ejemplo/*.csv --aplicar

  NOTAS
    · El total puede quedar oficial; la fracción expuesta sigue siendo una
      estimación por ratio de susceptibilidad. Son dos números distintos.
    · Borrar data/exposicion_oficial.json devuelve el sistema a la estimación.
    · Salida 0 = sin rechazos. Salida 1 = hubo filas rechazadas para revisar.

TXT;
}
