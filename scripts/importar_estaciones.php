#!/usr/bin/env php
<?php
/**
 * IMPORTADOR DE SERIES DE ESTACIONES — WILLAQ (fase 1)
 * ============================================================================
 *
 * Qué hace
 *   Lee los CSV crudos que deja Garúa en data/estaciones/_garua/csv/ (o un CSV
 *   canónico), los normaliza a un registro diario en hora de America/Lima,
 *   les aplica control de calidad y escribe:
 *
 *     data/estaciones/{id}.json          serie diaria + QA de esa estación
 *     data/estaciones/importacion.json   reporte QA de toda la corrida
 *
 *   Con --aplicar actualiza además, en config/estaciones.json, los campos
 *   derivados de la serie (serie_cargada, fechas, días, % faltantes). No toca
 *   ninguna otra decisión del registro.
 *
 * Qué NO hace (y es importante)
 *   · NO cambia el motor de riesgo. La fase 2 sigue bloqueada: el flag
 *     `motor_usa_estaciones` queda en false y los números de la demo no se
 *     mueven.
 *   · NO inventa datos. `S/D` es ausencia de dato, no cero. `T` es traza
 *     (precipitación menor al umbral medible): se conserva como marca, no se
 *     convierte a 0 ni a un valor inventado.
 *   · NO corrige el dato en silencio: cada descarte, duplicado, centinela o
 *     valor fuera de rango queda contado y listado en el reporte QA.
 *   · NO rellena huecos por interpolación. Un día sin dato queda sin dato.
 *
 * FORMATOS DE ENTRADA
 *   1. CSV crudo de Garúa (separador ';'), con una de estas cuatro cabeceras:
 *        · Meteorológica convencional  
 *          Año;Mes;Día;Temp. Máx;Temp. Mín;Humedad;Precipitación
 *        · Meteorológica automática  
 *          Año;Mes;Día;Hora;Temperatura;Precipitación;Humedad;Dir. Viento;Vel. Viento
 *        · Hidrológica convencional   
 *          Año;Mes;Día;Nivel del río 06;Nivel del río 10;... (+18)
 *        · Hidrológica automática     
 *          Año;Mes;Día;Hora;Nivel del río;Precipitación (mm/hora)
 *      La cabecera se reconoce por nombre de columna, no por posición.
 *
 *   2. CSV canónico (separador ',') con cabecera:
 *        estacion,fecha,precip_mm,temp_c,fuente
 *      `estacion` puede ser el código SENAMHI (105063) o el id del registro.
 *
 * USO
 *   php scripts/importar_estaciones.php --garua
 *   php scripts/importar_estaciones.php --garua --aplicar
 *   php scripts/importar_estaciones.php --estacion=105063 --aplicar
 *   php scripts/importar_estaciones.php serie.csv --estacion=105063 --aplicar
 *   php scripts/importar_estaciones.php --ayuda
 *
 *   Sin --aplicar es SIEMPRE dry-run: calcula y muestra el QA, pero no escribe
 *   series ni registro.
 *
 * Salidas (códigos)
 *   0  todo importado sin rechazos
 *   1  hubo filas rechazadas o avisos que revisar
 *   64 uso incorrecto · 66 archivo ausente · 65 formato no reconocido
 *   70 registro ilegible · 73 no se pudo escribir
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

$RAIZ = dirname(__DIR__);

// ============================================================================
// OPCIONES
// ============================================================================

$opciones = [
    'garua'    => false,
    'estaciones' => [],   // códigos pedidos explícitamente
    'archivos' => [],
    'aplicar'  => false,
    'dir_csv'  => $RAIZ . '/data/estaciones/_garua/csv',
    'salida'   => $RAIZ . '/data/estaciones',
    'registro' => $RAIZ . '/config/estaciones.json',
    'reporte'  => $RAIZ . '/data/estaciones/importacion.json',
    'respaldo' => $RAIZ . '/data/backups',
];

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if ($arg === '--garua') {
        $opciones['garua'] = true;
    } elseif ($arg === '--aplicar') {
        $opciones['aplicar'] = true;
    } elseif ($arg === '--dry-run') {
        $opciones['aplicar'] = false;
    } elseif (preg_match('/^--estacion=(.+)$/', $arg, $m)) {
        $opciones['estaciones'][] = trim($m[1]);
    } elseif (strpos($arg, '--dir-csv=') === 0) {
        $opciones['dir_csv'] = substr($arg, 10);
    } elseif (strpos($arg, '--salida=') === 0) {
        $opciones['salida'] = substr($arg, 9);
    } elseif (strpos($arg, '--registro=') === 0) {
        $opciones['registro'] = substr($arg, 11);
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

foreach ($opciones['archivos'] as $archivo) {
    if (!is_file($archivo)) {
        fwrite(STDERR, "No se encuentra el archivo: {$archivo}\n");
        exit(66);
    }
}

// Sin entrada explícita se asume --garua: importar lo que ya está descargado.
if (!$opciones['garua'] && !$opciones['estaciones'] && !$opciones['archivos']) {
    $opciones['garua'] = true;
}

// ============================================================================
// REGISTRO DE ESTACIONES
// ============================================================================

function est_leer_json($ruta) {
    if (!is_file($ruta)) {
        return null;
    }
    $crudo = @file_get_contents($ruta);
    if ($crudo === false) {
        return null;
    }
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : null;
}

$registro = est_leer_json($opciones['registro']);
if ($registro === null || empty($registro['estaciones'])) {
    fwrite(STDERR, "ERROR: config/estaciones.json ilegible o vacío.\n");
    exit(70);
}

$estaciones = [];
foreach ($registro['estaciones'] as $e) {
    if (!is_array($e) || empty($e['id']) || empty($e['cod'])) {
        continue;
    }
    $estaciones[$e['id']] = $e;
}

/**
 * Busca una estación del registro por código, id o nombre normalizado.
 */
function est_resolver_estacion($valor, $estaciones) {
    $valor = (string)$valor;
    foreach ($estaciones as $e) {
        if ((string)$e['cod'] === $valor || (string)$e['id'] === $valor) {
            return $e;
        }
    }
    $n = est_norm($valor);
    foreach ($estaciones as $e) {
        if (est_norm($e['nombre']) === $n) {
            return $e;
        }
    }
    return null;
}

// ============================================================================
// SELECCIÓN DE ARCHIVOS A IMPORTAR
// ============================================================================

$trabajos = [];   // [estacion, [rutas csv]]

if ($opciones['garua'] || $opciones['estaciones']) {
    if (!is_dir($opciones['dir_csv'])) {
        fwrite(STDERR, "ERROR: no existe el directorio de CSV: {$opciones['dir_csv']}\n");
        fwrite(STDERR, "       Descarga primero con: bash scripts/descargar_estaciones_garua.sh\n");
        exit(66);
    }

    $dirs = glob(rtrim($opciones['dir_csv'], '/') . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        $archivos = glob($dir . '/*.csv') ?: [];
        if (!$archivos) {
            continue;
        }
        $est = null;
        foreach ($estaciones as $e) {
            // El directorio de Garúa es {Nombre}_{cod}_{tipo}_{categoria}.
            if (strpos(basename($dir), '_' . $e['cod'] . '_') !== false) {
                $est = $e;
                break;
            }
        }
        if ($est === null) {
            fwrite(STDERR, "AVISO: no reconozco la estación del directorio " . basename($dir) . "; se omite.\n");
            continue;
        }
        if ($opciones['estaciones'] && !in_array((string)$est['cod'], array_map('strval', $opciones['estaciones']), true)) {
            continue;
        }
        sort($archivos);
        $trabajos[] = [$est, $archivos];
    }

    // Códigos pedidos que no aparecieron en disco: avisar, no inventar.
    if ($opciones['estaciones']) {
        foreach ($opciones['estaciones'] as $cod) {
            $encontrado = false;
            foreach ($trabajos as $t) {
                if ((string)$t[0]['cod'] === (string)$cod) {
                    $encontrado = true;
                    break;
                }
            }
            if (!$encontrado) {
                fwrite(STDERR, "AVISO: no hay CSV descargado para la estación {$cod}.\n");
            }
        }
    }
}

if ($opciones['archivos']) {
    foreach ($opciones['archivos'] as $ruta) {
        $trabajos[] = [null, [$ruta]];   // la estación se resuelve desde el CSV
    }
}

if (!$trabajos) {
    fwrite(STDERR, "No hay series para importar.\n");
    $pend = glob(rtrim($opciones['dir_csv'], '/') . '/*', GLOB_ONLYDIR) ?: [];
    if (!$pend) {
        fwrite(STDERR, "El directorio {$opciones['dir_csv']} está vacío.\n");
    }
    exit(66);
}

// ============================================================================
// IMPORTACIÓN
// ============================================================================

$resultados = [];
$rechazosGlobales = 0;
$avisosGlobales = 0;

foreach ($trabajos as $trabajo) {
    [$estacion, $archivos] = $trabajo;

    // --- Resolver estación para CSV canónicos -----------------------------
    if ($estacion === null) {
        $primera = $archivos[0];
        $cab = est_leer_cabecera($primera);
        if ($cab === null) {
            fwrite(STDERR, "ERROR: no se pudo leer la cabecera de {$primera}\n");
            exit(66);
        }
        $mapa = est_mapear_cabecera($cab);
        if (!isset($mapa['estacion'])) {
            if (count($opciones['estaciones']) === 1) {
                $estacion = est_resolver_estacion($opciones['estaciones'][0], $estaciones);
            }
            if ($estacion === null) {
                fwrite(STDERR, "ERROR: {$primera} no trae columna `estacion` y no hay --estacion=COD.\n");
                exit(65);
            }
        } else {
            $codigos = est_codigos_en_csv($primera, $mapa);
            if (count($codigos) !== 1) {
                fwrite(STDERR, "ERROR: {$primera} contiene " . count($codigos)
                    . " estaciones distintas; separa una estación por archivo.\n");
                exit(65);
            }
            $estacion = est_resolver_estacion($codigos[0], $estaciones);
            if ($estacion === null) {
                fwrite(STDERR, "ERROR: la estación `{$codigos[0]}` de {$primera} no está en config/estaciones.json.\n");
                exit(66);
            }
        }
    }

    echo "\n>>> {$estacion['cod']} · {$estacion['nombre']} ({$estacion['id']})\n";

    $res = est_importar_archivos($estacion, $archivos, $opciones);
    if ($res === null) {
        $avisosGlobales++;
        continue;
    }

    $rechazosGlobales += $res['serie']['qa']['filas_rechazadas'];
    if (!empty($res['serie']['qa']['valores_sospechosos'])) {
        $avisosGlobales++;
    }

    est_imprimir_resumen($res);
    $resultados[] = $res;

    if ($opciones['aplicar']) {
        $rutaSerie = rtrim($opciones['salida'], '/') . '/' . $estacion['id'] . '.json';
        if (!is_dir(dirname($rutaSerie))) {
            @mkdir(dirname($rutaSerie), 0775, true);
        }
        if (!est_escribir_json($rutaSerie, $res['serie'])) {
            fwrite(STDERR, "ERROR: no se pudo escribir {$rutaSerie}\n");
            exit(73);
        }
        echo "    Escrito: {$rutaSerie}\n";
    }
}

// ============================================================================
// REPORTE Y ACTUALIZACIÓN DEL REGISTRO
// ============================================================================

$reporte = [
    '_meta' => [
        'descripcion' => 'Reporte QA de la importación de series hidrometeorológicas de WILLAQ (fase 1).',
        'version'     => '1.0',
        'generado_en' => gmdate('c'),
        'generado_por'=> 'scripts/importar_estaciones.php',
        'modo'        => $opciones['aplicar'] ? 'aplicado' : 'dry-run',
        'zona_horaria'=> 'America/Lima',
        'fuente'      => 'SENAMHI — series diarias descargadas con Garúa (https://github.com/danyneyra/garua)',
        'motor_usa_estaciones' => false,
        'fase'        => 1,
        'honestidad'  => 'Un día sin dato queda sin dato. `S/D` no es cero; `T` es traza y no se convierte en un número. Ningún hueco se rellena por interpolación.',
    ],
    'estaciones' => [],
];

foreach ($resultados as $res) {
    $reporte['estaciones'][$res['estacion']['id']] = [
        'cod'             => $res['estacion']['cod'],
        'nombre'          => $res['estacion']['nombre'],
        'formato'         => $res['serie']['_meta']['formato'],
        'rango'           => $res['serie']['rango'],
        'qa'              => $res['serie']['qa'],
    ];
}

$reporte['resumen'] = [
    'estaciones_importadas' => count($resultados),
    'filas_rechazadas'      => $rechazosGlobales,
    'estaciones_con_avisos' => $avisosGlobales,
];

if ($opciones['aplicar']) {
    if (!is_dir(dirname($opciones['reporte']))) {
        @mkdir(dirname($opciones['reporte']), 0775, true);
    }
    if (!est_escribir_json($opciones['reporte'], $reporte)) {
        fwrite(STDERR, "ERROR: no se pudo escribir el reporte {$opciones['reporte']}\n");
        exit(73);
    }
    $actualizadas = est_actualizar_registro($opciones['registro'], $resultados, $opciones['respaldo']);
    echo "\n  Reporte QA       : {$opciones['reporte']}\n";
    echo "  Registro        : {$actualizadas} estación(es) marcadas con serie cargada\n";
} else {
    echo "\n  Modo: DRY-RUN (no se escribió ninguna serie ni el registro).\n";
    echo "  Revisa el QA de arriba. Si todo está correcto, repite con --aplicar.\n";
}

echo "\n  Recuerda: esto es fase 1 (ingesta y QA). El motor de riesgo NO usa\n";
echo "  estas series todavía. La fase 2 exige ≥10 años, <10 % de días\n";
echo "  faltantes y medir falsos positivos en el backtest.\n\n";

exit(($rechazosGlobales > 0 || $avisosGlobales > 0) ? 1 : 0);

// ============================================================================
// FUNCIONES
// ============================================================================

/**
 * Normaliza texto para comparar: minúsculas, sin tildes, sin espacios dobles.
 */
function est_norm($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
    return preg_replace('/\s+/', ' ', $s);
}

/**
 * Lee solo la cabecera de un CSV y la devuelve como array de strings.
 */
function est_leer_cabecera($ruta) {
    $h = @fopen($ruta, 'r');
    if ($h === false) {
        return null;
    }
    $sep = est_detectar_separador($ruta);
    $cab = fgetcsv($h, 0, $sep, '"', '');
    fclose($h);
    if ($cab === false || $cab === null) {
        return null;
    }
    if (isset($cab[0])) {
        $cab[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cab[0]);
    }
    return array_map(function ($c) { return trim((string)$c); }, $cab);
}

/**
 * Detecta el separador mirando la primera línea.
 */
function est_detectar_separador($ruta) {
    $h = @fopen($ruta, 'r');
    if ($h === false) {
        return ';';
    }
    $linea = (string)fgets($h);
    fclose($h);
    if ($linea === '') {
        return ';';
    }
    $puntoYComa = substr_count($linea, ';');
    $coma = substr_count($linea, ',');
    return $puntoYComa >= $coma ? ';' : ',';
}

/**
 * Mapea nombres de columna a claves internas.
 * Devuelve ['anio'=>i, 'mes'=>i, 'dia'=>i, 'precip'=>i, ...].
 */
function est_mapear_cabecera($cabecera) {
    $mapa = [];
    foreach ($cabecera as $i => $col) {
        $n = est_norm($col);
        if ($n === '') {
            continue;
        }
        if ($n === 'ano' || $n === 'anio' || $n === 'year') {
            $mapa['anio'] = $i;
        } elseif ($n === 'mes' || $n === 'month') {
            $mapa['mes'] = $i;
        } elseif ($n === 'dia' || $n === 'day') {
            $mapa['dia'] = $i;
        } elseif ($n === 'hora' || $n === 'hour') {
            $mapa['hora'] = $i;
        } elseif ($n === 'fecha' || $n === 'date') {
            $mapa['fecha'] = $i;
        } elseif ($n === 'estacion' || $n === 'station' || $n === 'codigo' || $n === 'cod') {
            $mapa['estacion'] = $i;
        } elseif ($n === 'fuente' || $n === 'source') {
            $mapa['fuente'] = $i;
        } elseif ($n === 'precip_mm' || strpos($n, 'precipitacion') !== false) {
            $mapa['precip'] = $i;
        } elseif (strpos($n, 'temp') !== false || strpos($n, 'temperatura') !== false) {
            if (strpos($n, 'max') !== false) {
                $mapa['temp_max'] = $i;
            } elseif (strpos($n, 'min') !== false) {
                $mapa['temp_min'] = $i;
            } elseif (isset($mapa['temp'])) {
                $mapa['temp_alt'] = $i;
            } else {
                $mapa['temp'] = $i;
            }
        } elseif (strpos($n, 'humedad') !== false) {
            $mapa['humedad'] = $i;
        } elseif (strpos($n, 'nivel') !== false) {
            $clave = 'nivel';
            if (preg_match('/(\d{2})$/', $n, $m)) {
                $clave = 'nivel_' . $m[1];
            }
            $mapa[$clave] = $i;
        } elseif ($n === 'temp_c') {
            $mapa['temp'] = $i;
        }
    }
    return $mapa;
}

/**
 * Devuelve los códigos de estación presentes en un CSV canónico.
 */
function est_codigos_en_csv($ruta, $mapa) {
    $h = @fopen($ruta, 'r');
    if ($h === false) {
        return [];
    }
    $sep = est_detectar_separador($ruta);
    fgetcsv($h, 0, $sep, '"', '');
    $vistos = [];
    while (($fila = fgetcsv($h, 0, $sep, '"', '')) !== false) {
        if ($fila === [null] || $fila === []) {
            continue;
        }
        $v = trim((string)($fila[$mapa['estacion']] ?? ''));
        if ($v !== '') {
            $vistos[$v] = true;
        }
    }
    fclose($h);
    return array_keys($vistos);
}

/**
 * Convierte un valor numérico del SENAMHI a float, o null con motivo.
 *
 * Reglas (basadas en el dato real encontrado):
 *   · '' / 'S/D' / 'NA'  → null, motivo 'sin_dato'
 *   · 'T'                → null, motivo 'traza' (se marca aparte; NO es cero)
 *   · '-99' / '-999'     → null, motivo 'centinela'
 *   · '1,5'              → 1.5 (coma decimal)
 *   · '1,234.5'          → 1234.5 (coma de miles)
 *   · '1.234,5'          → 1234.5 (coma decimal europea)
 *
 * @param string $valor
 * @param string $motivo (por referencia)
 * @return float|null
 */
function est_numero($valor, &$motivo) {
    $motivo = '';
    $v = trim((string)$valor);
    if ($v === '') {
        $motivo = 'sin_dato';
        return null;
    }
    $vn = est_norm($v);
    if (in_array($vn, ['s/d', 'sd', 's/dato', 'sin dato', 'na', 'n/a', '--', '-', 'nan'], true)) {
        $motivo = 'sin_dato';
        return null;
    }
    if ($vn === 't' || $vn === 'traza') {
        $motivo = 'traza';
        return null;
    }

    // Normalizar separadores decimales.
    $n = str_replace(' ', '', $v);
    if (strpos($n, ',') !== false && strpos($n, '.') !== false) {
        // El último separador es el decimal.
        if (strrpos($n, ',') > strrpos($n, '.')) {
            $n = str_replace('.', '', $n);
            $n = str_replace(',', '.', $n);
        } else {
            $n = str_replace(',', '', $n);
        }
    } elseif (strpos($n, ',') !== false) {
        $n = str_replace(',', '.', $n);
    }

    if (!is_numeric($n)) {
        $motivo = 'no_numerico';
        return null;
    }
    $f = (float)$n;

    if (abs($f + 99) < 0.0001 || abs($f + 999) < 0.0001 || abs($f + 9999) < 0.0001) {
        $motivo = 'centinela';
        return null;
    }
    if ($f < 0) {
        $motivo = 'negativo';
        return null;
    }
    return $f;
}

/**
 * Construye una fecha YYYY-MM-DD y la valida.
 */
function est_fecha($anio, $mes, $dia) {
    $a = (int)$anio;
    $m = (int)$mes;
    $d = (int)$dia;
    if ($a < 1900 || $a > 2100 || $m < 1 || $m > 12 || $d < 1 || $d > 31) {
        return null;
    }
    if (!checkdate($m, $d, $a)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $a, $m, $d);
}

/**
 * Parsea una fecha en formatos YYYY-MM-DD, DD/MM/YYYY o YYYY/MM/DD.
 */
function est_fecha_texto($texto) {
    $t = trim((string)$texto);
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $t, $m)) {
        return est_fecha($m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $t, $m)) {
        return est_fecha($m[3], $m[2], $m[1]);
    }
    return null;
}

/**
 * Importa los CSV de una estación. Devuelve el paquete serie+estacion o null.
 */
function est_importar_archivos($estacion, $archivos, $opciones) {
    $acumulado = [];       // fecha => acumulador
    $qa = est_qa_vacia();
    $qa['archivos'] = [];
    $formato = null;

    foreach ($archivos as $ruta) {
        $sep = est_detectar_separador($ruta);
        $h = @fopen($ruta, 'r');
        if ($h === false) {
            fwrite(STDERR, "ERROR: no se pudo abrir {$ruta}\n");
            return null;
        }

        $cabecera = fgetcsv($h, 0, $sep, '"', '');
        if ($cabecera === false || $cabecera === null) {
            fclose($h);
            fwrite(STDERR, "ERROR: {$ruta} está vacío.\n");
            return null;
        }
        $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cabecera[0]);
        $cabecera = array_map(function ($c) { return trim((string)$c); }, $cabecera);
        $mapa = est_mapear_cabecera($cabecera);

        $esGarua = isset($mapa['anio'], $mapa['mes'], $mapa['dia']);
        $esCanonico = isset($mapa['fecha']) && (isset($mapa['precip']) || isset($mapa['temp']) || isset($mapa['temp_max']));

        if (!$esGarua && !$esCanonico) {
            fclose($h);
            fwrite(STDERR, "ERROR: {$ruta} — cabecera no reconocida.\n");
            fwrite(STDERR, "       Encontrada: " . implode($sep, $cabecera) . "\n");
            return null;
        }

        $tipoFormato = est_clasificar_formato($mapa, $esGarua);
        if ($formato === null) {
            $formato = $tipoFormato;
        } elseif ($formato !== $tipoFormato) {
            fwrite(STDERR, "AVISO: {$ruta} mezcla formatos ({$formato} y {$tipoFormato}); se importa igual.\n");
        }

        $linea = 1;
        $qa['archivos'][] = [
            'ruta'   => $ruta,
            'sha256' => hash_file('sha256', $ruta),
            'formato'=> $tipoFormato,
            'bytes'  => filesize($ruta),
        ];

        while (($fila = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            $linea++;
            if ($fila === [null] || $fila === []) {
                continue;
            }
            // Fila con menos columnas que la cabecera: se completa con vacíos.
            $fila = array_pad($fila, count($cabecera), '');
            $qa['filas_leidas']++;

            if ($esGarua) {
                $fecha = est_fecha($fila[$mapa['anio']], $fila[$mapa['mes']], $fila[$mapa['dia']]);
                if ($fecha === null) {
                    $qa['filas_rechazadas']++;
                    est_anotar_rechazo($qa, $linea, $ruta, 'fecha_invalida', implode(';', array_slice($fila, 0, 3)));
                    continue;
                }
                $hora = isset($mapa['hora']) ? trim((string)$fila[$mapa['hora']]) : null;
            } else {
                $fecha = est_fecha_texto($fila[$mapa['fecha']] ?? '');
                if ($fecha === null) {
                    $qa['filas_rechazadas']++;
                    est_anotar_rechazo($qa, $linea, $ruta, 'fecha_invalida', (string)($fila[$mapa['fecha']] ?? ''));
                    continue;
                }
                $hora = null;
            }

            // Leer valores con su motivo.
            $valores = [];
            $motivos = [];
            foreach (['precip', 'temp', 'temp_max', 'temp_min', 'humedad'] as $campo) {
                if (!isset($mapa[$campo])) {
                    continue;
                }
                $valores[$campo] = est_numero($fila[$mapa[$campo]], $motivos[$campo]);
            }
            if (isset($mapa['temp_alt']) && !isset($mapa['temp'])) {
                $valores['temp'] = est_numero($fila[$mapa['temp_alt']], $motivos['temp']);
            }
            $niveles = [];
            foreach ($mapa as $clave => $idx) {
                if (strpos($clave, 'nivel') === 0) {
                    $niveles[$clave] = est_numero($fila[$idx], $motivos[$clave]);
                }
            }
            if ($niveles) {
                $valores['nivel'] = $niveles;
            }

            // Contabilidad de motivos.
            foreach ($motivos as $campo => $motivo) {
                if ($motivo === 'sin_dato') {
                    $qa['valores_sd']++;
                } elseif ($motivo === 'traza') {
                    if ($campo === 'precip') {
                        $qa['valores_traza']++;
                    }
                } elseif ($motivo === 'centinela') {
                    $qa['valores_centinela']++;
                } elseif ($motivo === 'negativo') {
                    $qa['valores_negativos']++;
                    est_anotar_sospechoso($qa, $fecha, $campo, (string)$fila[$mapa[$campo] ?? 0], 'negativo');
                } elseif ($motivo === 'no_numerico') {
                    $qa['valores_no_numericos']++;
                    est_anotar_sospechoso($qa, $fecha, $campo, (string)$fila[$mapa[$campo] ?? 0], 'no_numerico');
                }
            }

            // Traza: se guarda como marca, no como número.
            $traza = ($motivos['precip'] ?? '') === 'traza';

            // Plausibilidad: se conserva el valor pero se marca para revisión.
            if (isset($valores['precip']) && $valores['precip'] !== null) {
                if ($valores['precip'] > 500.0) {
                    est_anotar_sospechoso($qa, $fecha, 'precip', (string)$valores['precip'], 'precipitacion_extrema');
                }
            }
            if (isset($valores['humedad']) && $valores['humedad'] !== null
                && ($valores['humedad'] < 0 || $valores['humedad'] > 100)) {
                est_anotar_sospechoso($qa, $fecha, 'humedad', (string)$valores['humedad'], 'humedad_fuera_de_rango');
            }

            est_acumular($acumulado, $fecha, $valores, $traza, $hora, $tipoFormato, $qa);
        }
        fclose($h);
    }

    if (!$acumulado) {
        fwrite(STDERR, "AVISO: {$estacion['cod']} no produjo ningún día utilizable.\n");
        return null;
    }

    // --- Consolidar días --------------------------------------------------
    $dias = [];
    foreach ($acumulado as $fecha => $acc) {
        $dias[] = est_consolidar_dia($fecha, $acc, $formato, $qa);
    }
    usort($dias, function ($a, $b) { return strcmp($a['fecha'], $b['fecha']); });

    // --- QA de cobertura --------------------------------------------------
    $qa = est_completar_qa($qa, $dias, $formato);

    $serie = [
        '_meta' => [
            'descripcion'  => 'Serie diaria normalizada de una estación hidrometeorológica del SENAMHI, con su reporte QA.',
            'version'      => '1.0',
            'generado_en'  => gmdate('c'),
            'generado_por' => 'scripts/importar_estaciones.php',
            'estacion_id'  => $estacion['id'],
            'estacion_cod' => $estacion['cod'],
            'estacion_nombre' => $estacion['nombre'],
            'tipo'         => $estacion['tipo'] ?? '',
            'categoria'    => $estacion['categoria'] ?? '',
            'lat'          => $estacion['lat'] ?? null,
            'lon'          => $estacion['lon'] ?? null,
            'altitud_msnm' => $estacion['altitud_msnm'] ?? null,
            'departamento' => $estacion['departamento'] ?? '',
            'provincia'    => $estacion['provincia'] ?? '',
            'formato'      => $formato,
            'zona_horaria' => 'America/Lima',
            'fuente'       => 'SENAMHI — serie diaria descargada con Garúa (https://github.com/danyneyra/garua)',
            'licencia'     => 'El SENAMHI no publica una licencia de reutilización conocida; se cita la fuente y no se redistribuye como dominio público.',
            'archivos_origen' => $qa['archivos'],
            'variables'    => est_variables($dias),
            'advertencia'  => 'Fase 1: serie normalizada y auditada. El motor de riesgo NO la usa todavía (motor_usa_estaciones=false).',
            'motor_usa_estaciones' => false,
            'fase'         => 1,
        ],
        'rango' => [
            'primera' => $dias[0]['fecha'],
            'ultima'  => $dias[count($dias) - 1]['fecha'],
        ],
        'qa'    => $qa,
        'datos' => $dias,
    ];

    return ['estacion' => $estacion, 'serie' => $serie];
}

/**
 * Acumula un registro en el día correspondiente.
 *
 * Los formatos automáticos traen varias horas por día: se acumulan como
 * observaciones horarias. Los convencionales traen una fila por día: si
 * aparecen varias, son duplicados y se resuelven por campo.
 */
function est_acumular(&$acumulado, $fecha, $valores, $traza, $hora, $formato, &$qa) {
    if (!isset($acumulado[$fecha])) {
        $acumulado[$fecha] = [
            'filas'  => 0,
            'horaria'=> false,
            'campos' => [],
            'horas'  => [],
            'firmas' => [],
            'traza'  => false,
        ];
    }
    $acc = &$acumulado[$fecha];
    $acc['filas']++;
    // Firma de la fila cruda, para distinguir duplicado exacto de duplicado
    // que solo difiere en un dato faltante.
    $acc['firmas'][md5(json_encode($valores) . '|' . ($traza ? 'T' : 'N'))] = true;

    $esHoraria = in_array($formato, ['meteorologica_automatica', 'hidrologica_automatica'], true);
    if ($esHoraria) {
        $acc['horaria'] = true;
        $h = est_hora_entera($hora);
        if ($h !== null) {
            if (isset($acc['horas'][$h])) {
                // Dos registros para la misma hora: se conserva el primero y se avisa.
                $qa['duplicados_hora']++;
            } else {
                $acc['horas'][$h] = true;
                foreach ($valores as $campo => $v) {
                    if (is_array($v)) {
                        continue;
                    }
                    if ($v !== null) {
                        $acc['campos'][$campo][] = $v;
                    }
                }
                if (isset($valores['nivel']) && is_array($valores['nivel'])) {
                    foreach ($valores['nivel'] as $k => $v) {
                        if ($v !== null) {
                            $acc['campos'][$k][] = $v;
                        }
                    }
                }
            }
        } else {
            // Sin hora válida no se puede agregar con rigor: se descarta la fila.
            $qa['filas_rechazadas']++;
            est_anotar_rechazo($qa, 0, '', 'hora_invalida', (string)$hora);
        }
    } else {
        foreach ($valores as $campo => $v) {
            if (is_array($v)) {
                foreach ($v as $k => $vv) {
                    if ($vv !== null) {
                        $acc['campos'][$k][] = $vv;
                    }
                }
            } elseif ($v !== null) {
                $acc['campos'][$campo][] = $v;
            }
        }
    }

    if ($traza && !$esHoraria) {
        $acc['traza'] = true;
    }
}

/**
 * Convierte '07:00', '7', '07' a la hora entera.
 */
function est_hora_entera($hora) {
    $h = trim((string)$hora);
    if ($h === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})(?::\d{2}(?::\d{2})?)?$/', $h, $m)) {
        $v = (int)$m[1];
        return ($v >= 0 && $v <= 23) ? $v : null;
    }
    return null;
}

/**
 * Consolida un día acumulado en una fila de salida.
 *
 * Regla de duplicados: por campo, si todos los valores no nulos coinciden se
 * usa ese valor; si hay valores distintos, el campo queda en null y se marca
 * el conflicto. En el dato real, los conflictos son siempre S/D contra 0.0,
 * y esta regla se queda con el 0.0 medido sin inventar nada.
 */
function est_consolidar_dia($fecha, $acc, $formato, &$qa) {
    $fila = ['fecha' => $fecha];
    $conflictos = [];

    $esAutomatica = $acc['horaria'];

    // Precipitación: diaria directa o suma de horas.
    if ($esAutomatica) {
        if (!empty($acc['campos']['precip'])) {
            $fila['precip_mm'] = round(array_sum($acc['campos']['precip']), 2);
            $horas = count($acc['campos']['precip']);
            if ($horas > 0) {
                $fila['horas_con_dato'] = $horas;
            }
            if ($horas < 24) {
                $fila['precip_completa'] = false;
            }
        }
    } else {
        [$v, $huboConflicto] = est_resolver_campo($acc['campos']['precip'] ?? []);
        if ($v !== null) {
            $fila['precip_mm'] = round($v, 2);
        } elseif ($huboConflicto) {
            $conflictos[] = 'precip';
        }
        if ($acc['traza'] && $v === null && !$huboConflicto) {
            $fila['precip_traza'] = true;
        }
    }

    // Temperatura: media de las disponibles; máxima y mínima si existen.
    if ($esAutomatica) {
        if (!empty($acc['campos']['temp'])) {
            $fila['temp_c'] = round(array_sum($acc['campos']['temp']) / count($acc['campos']['temp']), 2);
            $fila['temp_max_c'] = round(max($acc['campos']['temp']), 2);
            $fila['temp_min_c'] = round(min($acc['campos']['temp']), 2);
        }
    } else {
        [$tmax, $c1] = est_resolver_campo($acc['campos']['temp_max'] ?? []);
        [$tmin, $c2] = est_resolver_campo($acc['campos']['temp_min'] ?? []);
        [$tmed, $c3] = est_resolver_campo($acc['campos']['temp'] ?? []);

        if ($tmax !== null) {
            $fila['temp_max_c'] = round($tmax, 2);
        }
        if ($tmin !== null) {
            $fila['temp_min_c'] = round($tmin, 2);
        }
        if ($tmax !== null && $tmin !== null) {
            $fila['temp_c'] = round(($tmax + $tmin) / 2, 2);
        } elseif ($tmed !== null) {
            $fila['temp_c'] = round($tmed, 2);
        } elseif ($tmax !== null || $tmin !== null) {
            $fila['temp_c'] = round($tmax ?? $tmin, 2);
            $fila['temp_parcial'] = true;
        }
        if ($c1 || $c2 || $c3) {
            $conflictos[] = 'temp';
        }
    }

    // Humedad: media de las disponibles.
    if (!empty($acc['campos']['humedad'])) {
        $fila['humedad_pct'] = round(array_sum($acc['campos']['humedad']) / count($acc['campos']['humedad']), 1);
    }

    // Nivel de río: media y máximo de las lecturas del día.
    $niveles = [];
    foreach ($acc['campos'] as $campo => $vals) {
        if (strpos($campo, 'nivel') === 0 && $vals) {
            foreach ($vals as $v) {
                $niveles[] = $v;
            }
        }
    }
    if ($niveles) {
        $fila['nivel_rio_m'] = round(array_sum($niveles) / count($niveles), 3);
        $fila['nivel_rio_max_m'] = round(max($niveles), 3);
        $fila['nivel_rio_lecturas'] = count($niveles);
    }

    if ($conflictos) {
        $fila['conflicto_duplicado'] = $conflictos;
    }

    // En los formatos diarios, más de una fila por fecha es un duplicado.
    // En los horarios, varias filas por fecha son legítimas.
    if (!$esAutomatica && $acc['filas'] > 1) {
        if ($conflictos) {
            $qa['duplicados_conflictivos']++;
        } elseif (count($acc['firmas']) > 1) {
            $qa['duplicados_compatibles']++;
        } else {
            $qa['duplicados_exactos'] += $acc['filas'] - 1;
        }
    }

    return $fila;
}

/**
 * Resuelve un campo con posibles valores duplicados.
 * @return array [valor|null, huboConflicto]
 */
function est_resolver_campo($valores) {
    $valores = array_values(array_filter($valores, function ($v) { return $v !== null; }));
    if (!$valores) {
        return [null, false];
    }
    $unico = $valores[0];
    foreach ($valores as $v) {
        if (abs($v - $unico) > 0.0001) {
            return [null, true];
        }
    }
    return [$unico, false];
}

/**
 * Estructura QA vacía.
 */
function est_qa_vacia() {
    return [
        'filas_leidas'        => 0,
        'filas_rechazadas'    => 0,
        'duplicados_exactos'  => 0,
        'duplicados_compatibles' => 0,
        'duplicados_conflictivos' => 0,
        'duplicados_hora'     => 0,
        'valores_sd'          => 0,
        'valores_traza'       => 0,
        'valores_centinela'   => 0,
        'valores_negativos'   => 0,
        'valores_no_numericos'=> 0,
        'valores_sospechosos' => [],
        'rechazos'            => [],
        'archivos'            => [],
    ];
}

/**
 * Añade un rechazo al reporte QA (máximo 100 para no inflar el JSON).
 */
function est_anotar_rechazo(&$qa, $linea, $ruta, $motivo, $muestra) {
    if (count($qa['rechazos']) >= 100) {
        return;
    }
    $qa['rechazos'][] = [
        'archivo' => $ruta,
        'linea'   => $linea,
        'motivo'  => $motivo,
        'muestra' => mb_substr((string)$muestra, 0, 120, 'UTF-8'),
    ];
}

/**
 * Añade un valor sospechoso al reporte QA (máximo 100).
 */
function est_anotar_sospechoso(&$qa, $fecha, $campo, $valor, $motivo) {
    if (count($qa['valores_sospechosos']) >= 100) {
        return;
    }
    $qa['valores_sospechosos'][] = [
        'fecha'  => $fecha,
        'campo'  => $campo,
        'valor'  => $valor,
        'motivo' => $motivo,
    ];
}

/**
 * Calcula cobertura, huecos, duplicados y métricas de la compuerta.
 */
function est_completar_qa($qa, $dias, $formato) {
    $primera = $dias[0]['fecha'];
    $ultima = $dias[count($dias) - 1]['fecha'];

    $inicio = new DateTimeImmutable($primera);
    $fin = new DateTimeImmutable($ultima);
    $diasCalendario = (int)$inicio->diff($fin)->days + 1;

    $conPrecip = 0;
    $conTraza = 0;
    $conTemp = 0;
    $conNivel = 0;
    $conAlgo = 0;
    $fechas = [];
    $diasPrecip = [];
    $diasNivel = [];
    $diasTemp = [];
    $porAnio = [];

    foreach ($dias as $d) {
        $f = $d['fecha'];
        $fechas[$f] = true;

        $tienePrecip = array_key_exists('precip_mm', $d);
        $tieneTraza = !empty($d['precip_traza']);
        if ($tienePrecip) {
            $conPrecip++;
        }
        if ($tieneTraza) {
            $conTraza++;
        }
        if ($tienePrecip || $tieneTraza) {
            $diasPrecip[$f] = true;
        }
        if (array_key_exists('temp_c', $d)) {
            $conTemp++;
            $diasTemp[$f] = true;
        }
        if (array_key_exists('nivel_rio_m', $d)) {
            $conNivel++;
            $diasNivel[$f] = true;
        }
        if ($tienePrecip || $tieneTraza || array_key_exists('temp_c', $d) || array_key_exists('nivel_rio_m', $d)) {
            $conAlgo++;
        }

        $anio = substr($f, 0, 4);
        if (!isset($porAnio[$anio])) {
            $porAnio[$anio] = [
                'esperados' => 0, 'con_precip' => 0, 'con_traza' => 0,
                'con_nivel' => 0, 'con_temp' => 0,
            ];
        }
        $porAnio[$anio]['con_precip'] += $tienePrecip ? 1 : 0;
        $porAnio[$anio]['con_traza'] += $tieneTraza ? 1 : 0;
        $porAnio[$anio]['con_nivel'] += array_key_exists('nivel_rio_m', $d) ? 1 : 0;
        $porAnio[$anio]['con_temp'] += array_key_exists('temp_c', $d) ? 1 : 0;
    }

    // Días esperados por año (bisiesto incluido).
    foreach ($porAnio as $anio => &$info) {
        $info['esperados'] = (int)(new DateTimeImmutable($anio . '-12-31'))->format('z') + 1;
    }
    unset($info);

    // Variable de cobertura: la fase 2 mide precipitación. Si la estación no
    // mide precipitación (por ejemplo, una hidrológica), se reporta la
    // variable que sí mide, sin fingir que mide lluvia.
    if ($conPrecip + $conTraza > 0) {
        $varPrincipal = 'precip_mm';
        $diasPrincipal = $diasPrecip;
    } elseif ($conNivel > 0) {
        $varPrincipal = 'nivel_rio_m';
        $diasPrincipal = $diasNivel;
    } else {
        $varPrincipal = 'temp_c';
        $diasPrincipal = $diasTemp;
    }

    // Cobertura por año. El año en curso se mide contra los días transcurridos
    // hasta la última fecha, y NO cuenta como año completo.
    $anioActual = (int)date('Y');
    $anosCompletos = 0;
    foreach ($porAnio as $anio => &$info) {
        if ($varPrincipal === 'precip_mm') {
            $conDato = $info['con_precip'] + $info['con_traza'];
        } elseif ($varPrincipal === 'nivel_rio_m') {
            $conDato = $info['con_nivel'];
        } else {
            $conDato = $info['con_temp'];
        }
        $esperados = $info['esperados'];
        $esActual = ((int)$anio === $anioActual);
        $efectivos = $esperados;
        if ($esActual) {
            $ultimo = new DateTimeImmutable(min($ultima, date('Y-m-d')));
            $efectivos = (int)(new DateTimeImmutable($anio . '-01-01'))->diff($ultimo)->days + 1;
        }
        $pct = $efectivos > 0 ? (($efectivos - $conDato) / $efectivos) * 100 : 100.0;
        $info['dias_con_dato'] = $conDato;
        $info['dias_esperados'] = $esperados;
        $info['pct_faltantes'] = round(max(0.0, $pct), 2);
        $info['completo'] = (!$esActual && $pct <= 10.0);
        if ($info['completo']) {
            $anosCompletos++;
        }
    }
    unset($info);

    // Huecos: rachas de días sin dato de la variable principal del rango.
    $huecos = [];
    $rachaInicio = null;
    $rachaLargo = 0;
    $cursor = $inicio;
    while ($cursor <= $fin) {
        $f = $cursor->format('Y-m-d');
        $tiene = isset($diasPrincipal[$f]);
        if (!$tiene) {
            if ($rachaInicio === null) {
                $rachaInicio = $f;
                $rachaLargo = 1;
            } else {
                $rachaLargo++;
            }
        } else {
            if ($rachaInicio !== null) {
                $huecos[] = ['desde' => $rachaInicio, 'dias' => $rachaLargo];
                $rachaInicio = null;
                $rachaLargo = 0;
            }
        }
        $cursor = $cursor->modify('+1 day');
    }
    if ($rachaInicio !== null) {
        $huecos[] = ['desde' => $rachaInicio, 'dias' => $rachaLargo];
    }

    // Duplicados: se derivan de las marcas de consolidación.
    $dupsExactos = $qa['duplicados_exactos'];
    $dupsConflictivos = 0;
    $fechasConflicto = [];
    foreach ($dias as $d) {
        if (!empty($d['conflicto_duplicado'])) {
            $dupsConflictivos++;
            if (count($fechasConflicto) < 50) {
                $fechasConflicto[] = $d['fecha'];
            }
        }
    }

    $diasConPrincipal = count($diasPrincipal);
    $faltantes = max(0, $diasCalendario - $diasConPrincipal);

    $qa['formato'] = $formato;
    $qa['variable_cobertura'] = $varPrincipal;
    $qa['primera_fecha'] = $primera;
    $qa['ultima_fecha'] = $ultima;
    $qa['dias_calendario'] = $diasCalendario;
    $qa['dias_con_dato'] = $conAlgo;
    $qa['dias_con_precip'] = $conPrecip;
    $qa['dias_con_traza'] = $conTraza;
    $qa['dias_con_temp'] = $conTemp;
    $qa['dias_con_nivel_rio'] = $conNivel;
    $qa['dias_faltantes'] = $faltantes;
    $qa['pct_dias_faltantes'] = $diasCalendario > 0
        ? round(($faltantes / $diasCalendario) * 100, 2)
        : 100.0;
    $qa['anos_calendario'] = count($porAnio);
    $qa['anos_completos'] = $anosCompletos;
    // Extensión real de la serie en años (días calendario / 365,25). Es el
    // dato estricto; `anos_calendario` cuenta años tocados, aunque el primero
    // y el último estén incompletos.
    $qa['anos_span'] = round($diasCalendario / 365.25, 2);
    // La compuerta cuenta años CALENDARIO con dato (2017–2026 = 10), no años
    // cerrados al 31/12: la serie puede terminar hoy y seguir siendo una
    // serie de 10 años. `anos_completos` y `anos_span` quedan como
    // transparencia para no sobrevender la cobertura.
    $qa['anos_cobertura'] = $qa['anos_calendario'];
    $qa['cobertura_por_anio'] = $porAnio;
    $qa['huecos'] = $huecos;
    $qa['hueco_max_dias'] = $huecos ? max(array_column($huecos, 'dias')) : 0;
    $qa['duplicados_exactos'] = $dupsExactos;
    $qa['duplicados_conflictivos'] = $dupsConflictivos;
    $qa['fechas_conflicto'] = $fechasConflicto;

    // Compuerta (informativa: el motor no la usa todavía).
    $cumpleMedible = ($varPrincipal === 'precip_mm'
        && $qa['anos_calendario'] >= 10
        && $qa['pct_dias_faltantes'] <= 10.0);
    $qa['compuerta'] = [
        'anios_minimos'       => 10,
        'faltantes_max_pct'   => 10.0,
        'requiere_backtest'   => true,
        'cumple_medible'      => $cumpleMedible,
        'nota'                => $varPrincipal !== 'precip_mm'
            ? 'La estación no mide precipitación; la compuerta de la fase 2 no aplica a esta serie.'
            : 'Cumplir estos dos criterios NO habilita la fase 2: falta medir falsos positivos en el backtest.',
    ];

    return $qa;
}

/**
 * Variables presentes en la serie.
 */
function est_variables($dias) {
    $vars = [];
    foreach ($dias as $d) {
        if (array_key_exists('precip_mm', $d)) {
            $vars['precip_mm'] = true;
        }
        if (array_key_exists('temp_c', $d)) {
            $vars['temp_c'] = true;
        }
        if (array_key_exists('nivel_rio_m', $d)) {
            $vars['nivel_rio_m'] = true;
        }
    }
    return array_keys($vars);
}

/**
 * Clasifica el formato a partir del mapa de columnas.
 */
function est_clasificar_formato($mapa, $esGarua) {
    if (!$esGarua) {
        return 'canonico';
    }
    $hidrologica = isset($mapa['nivel']) || isset($mapa['nivel_06'])
        || isset($mapa['nivel_10']) || isset($mapa['nivel_14']) || isset($mapa['nivel_18']);
    if ($hidrologica) {
        return isset($mapa['hora']) ? 'hidrologica_automatica' : 'hidrologica_convencional';
    }
    return isset($mapa['hora']) ? 'meteorologica_automatica' : 'meteorologica_convencional';
}

/**
 * Imprime el resumen de una estación.
 */
function est_imprimir_resumen($res) {
    $s = $res['serie'];
    $qa = $s['qa'];
    printf("    Formato        : %s\n", $qa['formato']);
    printf("    Rango          : %s → %s (%d días calendario)\n",
        $qa['primera_fecha'], $qa['ultima_fecha'], $qa['dias_calendario']);
    printf("    Días con precip: %d · traza: %d · temp: %d\n",
        $qa['dias_con_precip'], $qa['dias_con_traza'], $qa['dias_con_temp']);
    printf("    Faltantes      : %d días (%.2f %%)\n",
        $qa['dias_faltantes'], $qa['pct_dias_faltantes']);
    printf("    Años completos : %d de %d calendario\n",
        $qa['anos_completos'], $qa['anos_calendario']);
    printf("    Hueco máximo   : %d días\n", $qa['hueco_max_dias']);
    printf("    Duplicados     : %d exactos · %d compatibles · %d conflictivos\n",
        $qa['duplicados_exactos'], $qa['duplicados_compatibles'], $qa['duplicados_conflictivos']);
    printf("    Valores        : S/D=%d · traza=%d · centinela=%d · negativos=%d · no numéricos=%d\n",
        $qa['valores_sd'], $qa['valores_traza'], $qa['valores_centinela'],
        $qa['valores_negativos'], $qa['valores_no_numericos']);

    if ($qa['valores_sospechosos']) {
        echo "    Avisos:\n";
        foreach (array_slice($qa['valores_sospechosos'], 0, 5) as $v) {
            printf("      · %s %s = %s (%s)\n", $v['fecha'], $v['campo'], $v['valor'], $v['motivo']);
        }
        if (count($qa['valores_sospechosos']) > 5) {
            printf("      … y %d más en el reporte.\n", count($qa['valores_sospechosos']) - 5);
        }
    }
}

/**
 * Escribe JSON de forma atómica (temporal + rename).
 */
function est_escribir_json($ruta, $datos) {
    $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        fwrite(STDERR, "ERROR: no se pudo serializar JSON: " . json_last_error_msg() . "\n");
        return false;
    }
    $tmp = $ruta . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json . "\n") === false) {
        return false;
    }
    if (!@rename($tmp, $ruta)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Actualiza en config/estaciones.json los campos derivados de la serie.
 * Hace un respaldo fechado antes de la primera escritura. No cambia
 * `verificado` (que se refiere al catálogo, no a la serie).
 */
function est_actualizar_registro($ruta, $resultados, $dirRespaldo) {
    $registro = est_leer_json($ruta);
    if ($registro === null) {
        fwrite(STDERR, "AVISO: no se pudo releer el registro para actualizarlo.\n");
        return 0;
    }

    if (!is_dir($dirRespaldo)) {
        @mkdir($dirRespaldo, 0775, true);
    }
    $respaldo = $dirRespaldo . '/estaciones_' . date('Ymd_His') . '.json';
    @copy($ruta, $respaldo);

    $porId = [];
    foreach ($resultados as $res) {
        $porId[$res['estacion']['id']] = $res['serie'];
    }

    $actualizadas = 0;
    foreach ($registro['estaciones'] as &$e) {
        if (!isset($e['id']) || !isset($porId[$e['id']])) {
            continue;
        }
        $s = $porId[$e['id']];
        $e['serie_cargada'] = true;
        $e['serie_archivo'] = 'data/estaciones/' . $e['id'] . '.json';
        $e['serie_actualizada_en'] = $s['_meta']['generado_en'];
        $e['serie_primera_fecha'] = $s['rango']['primera'];
        $e['serie_ultima_fecha'] = $s['rango']['ultima'];
        $e['serie_dias_con_dato'] = $s['qa']['dias_con_dato'];
        $e['serie_pct_faltantes'] = $s['qa']['pct_dias_faltantes'];
        $e['serie_anos_completos'] = $s['qa']['anos_completos'];
        $e['serie_sha256_origen'] = $s['_meta']['archivos_origen'][0]['sha256'] ?? '';
        $actualizadas++;
    }
    unset($e);

    if ($actualizadas > 0 && !est_escribir_json($ruta, $registro)) {
        fwrite(STDERR, "AVISO: no se pudo escribir el registro actualizado.\n");
        return 0;
    }
    return $actualizadas;
}

/**
 * Ayuda del script.
 */
function print_ayuda() {
    echo <<<TXT

  WILLAQ · importador de series de estaciones (fase 1)

  USO
    php scripts/importar_estaciones.php --garua [--aplicar]
    php scripts/importar_estaciones.php --garua --estacion=105063 [--aplicar]
    php scripts/importar_estaciones.php ARCHIVO.csv [--estacion=COD] [--aplicar]

  OPCIONES
    --garua            Importa todo CSV descargado en data/estaciones/_garua/csv/
                       (comportamiento por defecto si no se indica entrada).
    --estacion=COD     Limita la importación a una estación (código SENAMHI).
    --aplicar          Escribe las series y actualiza el registro.
                       Sin esta opción es dry-run: solo calcula y muestra el QA.
    --dir-csv=RUTA     Directorio de CSV crudos de Garúa.
    --salida=DIR       Directorio de series normalizadas (data/estaciones).
    --registro=RUTA    Registro de estaciones (config/estaciones.json).
    --reporte=RUTA     Reporte QA de la corrida (data/estaciones/importacion.json).
    --respaldo=DIR     Carpeta de respaldos (data/backups).
    --ayuda            Esta ayuda.

  QUÉ HACE CON EL DATO
    · S/D  → sin dato. NO se convierte en cero.
    · T    → traza. Se marca como `precip_traza`; NO se convierte en 0 ni en
             un número inventado.
    · -99 / -999 → centinela. Se descarta y se cuenta.
    · Negativos → imposibles para precipitación; se descartan y se listan.
    · Duplicados exactos → se colapsan.
    · Duplicados con valores distintos → por campo se conserva el valor no
      nulo si es único; si hay dos valores distintos, el campo queda en null
      y el día se marca como conflicto.
    · Huecos → no se rellenan. Se miden y se listan.

  COMPUERTA (solo informativa en fase 1)
    Una estación pasa la compuerta medible si tiene ≥10 años completos y
    <10 % de días faltantes. Aun así, la fase 2 sigue bloqueada hasta medir
    falsos positivos en el backtest.

  SALIDAS
    0 = sin rechazos · 1 = hubo rechazos o avisos · 64 = uso incorrecto
    65 = formato no reconocido · 66 = archivo ausente · 70 = registro ilegible
    73 = no se pudo escribir

TXT;
}
