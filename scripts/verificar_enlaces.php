#!/usr/bin/env php
<?php
/**
 * Verifica que los enlaces markdown internos del README y de docs/ apunten a
 * archivos existentes y a anclas reales.
 *
 * Uso:
 *   php scripts/verificar_enlaces.php
 *
 * Código de salida 0 si no hay enlaces rotos, 1 si hay alguno.
 */

$raiz = dirname(__DIR__) . '/';
$archivos = array_merge([$raiz . 'README.md'], glob($raiz . 'docs/*.md'));

/**
 * Convierte un título markdown en el ancla que genera GitHub.
 * Solo se cubre lo que usan estos documentos: acentos, signos y numeración.
 */
function wq_ancla(string $titulo): string
{
    $t = trim($titulo);
    $t = preg_replace('/`([^`]*)`/u', '$1', $t);
    $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $t);
    $t = mb_strtolower($t, 'UTF-8');
    $t = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $t);
    $t = preg_replace('/\s+/u', '-', $t);

    return trim($t, '-');
}

/** Anclas presentes en un archivo (títulos + anclas <a name="...">). */
function wq_anclas(string $ruta): array
{
    $texto = file_get_contents($ruta);
    $anclas = [];

    if (preg_match_all('/^#{1,6}\s+(.+)$/mu', $texto, $m)) {
        foreach ($m[1] as $titulo) {
            $anclas[wq_ancla($titulo)] = true;
        }
    }
    if (preg_match_all('/<a\s+(?:name|id)="([^"]+)"/u', $texto, $m)) {
        foreach ($m[1] as $ancla) {
            $anclas[$ancla] = true;
        }
    }

    return $anclas;
}

$total = 0;
$rotos = [];
$cacheAnclas = [];

foreach ($archivos as $archivo) {
    if (!is_file($archivo)) {
        continue;
    }

    $texto = file_get_contents($archivo);
    if (!preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/u', $texto, $m)) {
        continue;
    }

    foreach ($m[1] as $destino) {
        if (preg_match('#^(https?:|mailto:)#i', $destino)) {
            continue;
        }

        $total++;
        [$ruta, $ancla] = array_pad(explode('#', $destino, 2), 2, null);

        if ($ruta === '') {
            $objetivo = $archivo;
        } else {
            $objetivo = realpath(dirname($archivo) . '/' . rawurldecode($ruta));
            if ($objetivo === false || !is_file($objetivo)) {
                $rotos[] = [$archivo, $destino, 'archivo inexistente'];
                continue;
            }
        }

        if ($ancla === null || $ancla === '') {
            continue;
        }

        if (!isset($cacheAnclas[$objetivo])) {
            $cacheAnclas[$objetivo] = wq_anclas($objetivo);
        }
        if (!isset($cacheAnclas[$objetivo][rawurldecode($ancla)])) {
            $rotos[] = [$archivo, $destino, 'ancla inexistente'];
        }
    }
}

$relativo = fn(string $ruta): string => ltrim(str_replace($raiz, '', $ruta), '/');

echo "Enlaces internos revisados: {$total}\n";

if ($rotos === []) {
    echo "OK: 0 enlaces rotos.\n";
    exit(0);
}

echo "ROTOS: " . count($rotos) . "\n";
foreach ($rotos as [$archivo, $destino, $motivo]) {
    echo "· {$motivo}: {$destino}  (en " . $relativo($archivo) . ")\n";
}
exit(1);
