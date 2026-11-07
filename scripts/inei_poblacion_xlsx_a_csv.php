<?php
/**
 * INEI · POBLACIÓN PROYECTADA POR DISTRITO → CSV CANÓNICO DE EXPOSICIÓN
 * ===============================================================
 *
 * QUÉ HACE
 *   Lee el XLSX oficial del INEI ("Perú: Población Total Proyectada al 30 de
 *   junio de cada año, según Departamento, Provincia y Distrito") y extrae las
 *   filas de los distritos del catálogo WILLAQ para producir el CSV que
 *   `scripts/importar_exposicion.php` entiende.
 *
 *   No inventa nada: el VALOR sale del archivo oficial; el ubigeo se cruza
 *   contra `config/territorios.json`; y cada fila queda con la fuente, el
 *   año y el sha256 del archivo de origen en la nota.
 *
 * POR QUÉ UN SCRIPT PROPIO (y no un "convertidor genérico")
 *   · El XLSX del INEI tiene una hoja con un título, un encabezado de dos
 *     filas (años) y luego una tabla jerárquica: departamento (2 dígitos),
 *     provincia (4) y distrito (6). Convertirlo "a pelo" produce filas que
 *     el importador no puede resolver.
 *   · Aquí se resuelve la jerarquía y se empareja cada ubigeo del catálogo
 *     con su fila oficial. Si un distrito no aparece o su nombre no es
 *     compatible, el script lo **reporta y lo omite**: nunca inventa ni
 *   reasigna.
 *   · PHP puro, sin Composer: el runtime y la ingesta comparten el mismo
 *     lenguaje y no se agrega ninguna dependencia.
 *
 * USO
 *   php scripts/inei_poblacion_xlsx_a_csv.php \
 *        --xlsx=datos/oficial/inei_poblacion_distrital_2018_2026.xlsx \
 *        --anio=2026 \
 *        --salida=datos/oficial/inei_poblacion.csv
 *
 * SALIDAS
 *   0 = los 29 distritos del catálogo se resolvieron
 *   1 = faltó algún distrito o hubo nombres incompatibles
 *   64 = uso incorrecto
 */

$RAIZ = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Opciones
// ---------------------------------------------------------------------------
$op = [
    'xlsx'    => '',
    'salida'  => $RAIZ . '/datos/oficial/inei_poblacion.csv',
    'anio'    => '',
    'catalogo' => $RAIZ . '/config/territorios.json',
    'indicador' => 'poblacion',
    'fuente'  => 'INEI · Población total proyectada al 30/06 (Boletín 2018-2026)',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-zA-Z_]+)(?:=(.*))?$/', $arg, $m)) {
        fwrite(STDERR, "ERROR: opción no reconocida «{$arg}\"\n");
        exit(64);
    }
    $clave = $m[1];
    if (!array_key_exists($clave, $op)) {
        fwrite(STDERR, "ERROR: opción desconocida «--{$clave}\"\n");
        exit(64);
    }
    $op[$clave] = $m[2] ?? '';
}

if ($op['xlsx'] === '' || !is_file($op['xlsx'])) {
    fwrite(STDERR, "ERROR: falta --xlsx=RUTA (o el archivo no existe)\n");
    exit(64);
}
if ($op['anio'] === '' || !preg_match('/^\d{4}$/', $op['anio'])) {
    fwrite(STDERR, "ERROR: falta --anio=AAAA\n");
    exit(64);
}

// ---------------------------------------------------------------------------
// Catálogo: los distritos que nos interesan
// ---------------------------------------------------------------------------
$cat = json_decode(@file_get_contents($op['catalogo']), true);
if (!is_array($cat) || empty($cat['territorios'])) {
    fwrite(STDERR, "ERROR: no se pudo leer el catálogo {$op['catalogo']}\n");
    exit(64);
}

$objetivo = [];
foreach ($cat['territorios'] as $t) {
    $ub = str_pad((string)($t['ubigeo'] ?? ''), 6, '0', STR_PAD_LEFT);
    $objetivo[$ub] = [
        'id'       => $t['id'] ?? '',
        'distrito' => (string)($t['distrito'] ?? ''),
        'provincia' => (string)($t['provincia'] ?? ''),
        'region'   => (string)($t['region'] ?? ''),
    ];
}

// ---------------------------------------------------------------------------
// Lectura del XLSX (ZipArchive + SimpleXML, sin dependencias)
// ---------------------------------------------------------------------------
/**
 * Devuelve las celdas de una hoja XLSX como arrays indexados desde 0,
 * respetando la posición declarada por cada celda (columnas vacías se
 * respetan porque el XLSX guarda la referencia en el atributo "r").
 */
function xlsx_filas($rutaXlsx, &$error = null) {
    $filas = [];

    if (!class_exists('ZipArchive')) {
        $error = 'la extensión zip de PHP no está disponible';
        return $filas;
    }
    $zip = new ZipArchive();
    if ($zip->open($rutaXlsx) !== true) {
        $error = 'no se pudo abrir el XLSX';
        return $filas;
    }

    // --- textos compartidos ---
    $compartidas = [];
    if ($zip->locateName('xl/sharedStrings.xml') !== false) {
        $ss = @simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $txt .= (string)$r->t;
                    }
                }
                $compartidas[] = $txt;
            }
        }
    }

    // --- hojas ---
    $wb = @simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    if ($wb === false) {
        $error = 'el XLSX no tiene workbook.xml legible';
        $zip->close();
        return $filas;
    }
    $rel = @simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    if ($rel !== false) {
        foreach ($rel->Relationship as $r) {
            $relMap[(string)$r['Id']] = (string)$r['Target'];
        }
    }

    $rutaHoja = null;
    foreach ($wb->sheets->sheet as $s) {
        $rid = (string)$s->attributes('r', true)['id'];
        $target = $relMap[$rid] ?? '';
        if ($target === '') {
            continue;
        }
        $rutaHoja = 'xl/' . ltrim(str_replace('xl/', '', $target), '/');
        break; // la primera hoja es la tabla en este archivo
    }
    if ($rutaHoja === null || $zip->locateName($rutaHoja) === false) {
        $error = 'no se encontró la hoja de datos';
        $zip->close();
        return $filas;
    }

    $sheet = @simplexml_load_string($zip->getFromName($rutaHoja));
    if ($sheet === false) {
        $error = 'la hoja no se pudo leer como XML';
        $zip->close();
        return $filas;
    }

    foreach ($sheet->sheetData->row as $row) {
        $celdas = [];
        foreach ($row->c as $c) {
            $idx = xlsx_indice_celda((string)$c['r']);
            $tipo = (string)$c['t'];
            if ($tipo === 's') {
                $valor = $compartidas[(int)$c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $valor = (string)($c->is->t ?? '');
            } else {
                $valor = (string)($c->v ?? '');
            }
            if ($idx === null) {
                $celdas[] = $valor;
            } else {
                $celdas[$idx] = $valor;
            }
        }
        ksort($celdas);
        $filas[] = array_values($celdas);
    }

    $zip->close();
    return $filas;
}

/** "AB12" → índice de columna (base 26, A=0). */
function xlsx_indice_celda($ref) {
    if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) {
        return null;
    }
    $letras = $m[1];
    $n = 0;
    for ($i = 0; $i < strlen($letras); $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64);
    }
    return $n - 1;
}

/** Normaliza un nombre para comparar: sin tildes, sin signos, minúsculas. */
function xlsx_norm($texto) {
    $texto = mb_strtolower(trim((string)$texto), 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
        'Ñ' => 'n',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $texto);
}

$error = null;
$filas = xlsx_filas($op['xlsx'], $error);
if ($error !== null) {
    fwrite(STDERR, "ERROR: no se pudo leer el XLSX: {$error}\n");
    exit(64);
}

// ---------------------------------------------------------------------------
// Encabezado: menemukan la columna del año pedido
// ---------------------------------------------------------------------------
$anio = (int)$op['anio'];
$colAnio = null;
$aniosDisponibles = [];

foreach ($filas as $i => $f) {
    foreach ($f as $j => $v) {
        $v = trim((string)$v);
        if (preg_match('/^(19|20)\d{2}$/', $v)) {
            $aniosDisponibles[$v] = true;
            if ((int)$v === $anio && $colAnio === null) {
                $colAnio = $j;
            }
        }
    }
    if ($colAnio !== null && count($aniosDisponibles) >= 2) {
        break; // el encabezado del INEI tiene dos filas: la de años está completa
    }
}

if ($colAnio === null) {
    fwrite(STDERR, "ERROR: el XLSX no tiene la columna del año {$anio}. Años hallados: "
        . implode(', ', array_keys($aniosDisponibles)) . "\n");
    exit(64);
}

// ---------------------------------------------------------------------------
// Recorrido: se busca la fila de cada distrito del catálogo
// ---------------------------------------------------------------------------
$sha = hash_file('sha256', $op['xlsx']);
$archivoOrigen = basename($op['xlsx']);
$encontrados = [];
$faltantes = [];
$incompatibles = [];

foreach ($filas as $f) {
    $ub = trim((string)($f[0] ?? ''));
    if ($ub === '' || strlen($ub) !== 6) {
        continue;
    }
    $ub = str_pad($ub, 6, '0', STR_PAD_LEFT);
    if (!isset($objetivo[$ub])) {
        continue;
    }
    if (isset($encontrados[$ub])) {
        continue; // el INEI no repite ubigeos, pero por si acaso
    }

    $nombreOficial = trim((string)($f[1] ?? ''));
    $valorBruto = trim((string)($f[$colAnio] ?? ''));
    $meta = $objetivo[$ub];

    $nOf = xlsx_norm($nombreOficial);
    $nCat = xlsx_norm($meta['distrito']);
    $compatible = ($nOf !== '' && $nCat !== '' && (
        $nOf === $nCat ||
        strpos($nCat, $nOf) === 0 ||   // "LIMA" vs "Lima Cercado"
        strpos($nOf, $nCat) === 0
    ));

    if (!$compatible) {
        $incompatibles[] = "{$ub} · catálogo «{$meta['distrito']}» vs INEI «{$nombreOficial}»";
        continue;
    }

    if ($valorBruto === '' || !is_numeric($valorBruto)) {
        $faltantes[] = "{$ub} · {$meta['distrito']}: valor no numérico para {$anio} («{$valorBruto}»)";
        continue;
    }

    $nota = "INEI XLSX «{$archivoOrigen}» sha256:{$sha}";
    if ($nOf !== $nCat) {
        $nota .= "; nombre INEI «{$nombreOficial}» vs catálogo «{$meta['distrito']}»";
    }

    $encontrados[$ub] = [
        'ubigeo'    => $ub,
        'distrito'  => $meta['distrito'],
        'provincia' => $meta['provincia'],
        'region'    => $meta['region'],
        'indicador' => $op['indicador'],
        'valor'     => (int)round((float)$valorBruto),
        'anio'      => $anio,
        'fuente'    => $op['fuente'],
        'nota'      => $nota,
    ];
}

foreach ($objetivo as $ub => $meta) {
    if (!isset($encontrados[$ub])) {
        $faltantes[] = "{$ub} · {$meta['distrito']}: no aparece en el archivo oficial";
    }
}

// ---------------------------------------------------------------------------
// Salida
// ---------------------------------------------------------------------------
if (!empty($encontrados)) {
    $dir = dirname($op['salida']);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "ERROR: no se pudo crear el directorio {$dir}\n");
        exit(64);
    }

    $campos = ['ubigeo', 'distrito', 'provincia', 'region', 'indicador', 'valor', 'anio', 'fuente', 'nota'];
    $man = fopen($op['salida'], 'w');
    if ($man === false) {
        fwrite(STDERR, "ERROR: no se pudo escribir {$op['salida']}\n");
        exit(64);
    }
    fputcsv($man, $campos, ',', '"', '\\');

    ksort($encontrados);
    foreach ($encontrados as $fila) {
        fputcsv($man, array_map(function ($c) use ($campos, $fila) {
            return $fila[$c];
        }, $campos), ',', '"', '\\');
    }
    fclose($man);
}

echo "══════════════════════════════════════════════════════════════\n";
echo " INEI · población proyectada por distrito → CSV canónico\n";
echo "══════════════════════════════════════════════════════════════\n";
echo " Origen        : {$op['xlsx']}\n";
echo " sha256        : {$sha}\n";
echo " Año           : {$anio}\n";
echo " Distritos     : " . count($encontrados) . ' de ' . count($objetivo) . " del catálogo\n";
echo " CSV           : {$op['salida']}\n";

if ($faltantes) {
    echo "\n Faltantes o sin valor:\n";
    foreach ($faltantes as $f) {
        echo "   · {$f}\n";
    }
}
if ($incompatibles) {
    echo "\n Nombres incompatibles (no se importaron):\n";
    foreach ($incompatibles as $f) {
        echo "   · {$f}\n";
    }
}

echo "\n";
if (empty($faltantes) && empty($incompatibles)) {
    echo " OK: los " . count($objetivo) . " distritos se resolvieron contra el archivo oficial.\n";
    echo " Siguiente paso: php scripts/importar_exposicion.php {$op['salida']} --aplicar\n";
    exit(0);
}

echo " INCOMPLETO: revisa las filas listadas antes de aplicar el overlay.\n";
exit(1);
