#!/usr/bin/env php
<?php
/**
 * Inserta un bloque de navegación «Documentos relacionados» al final de cada
 * documento de willaq/docs/, salvo en los que ya lo tienen.
 *
 * Motivo: hasta el 5 de noviembre de 2026 la documentación tenía 3 enlaces
 * markdown en total — los documentos casi no se referenciaban entre sí, y
 * eso es una carencia real para el criterio «Documentación completa».
 */

$base = dirname(__DIR__) . '/docs/';

$nav = <<<'MD'

---

## Documentos relacionados

| Documento | Para qué |
|---|---|
| [`README.md`](../README.md) | Vista general, scripts y estructura del proyecto |
| [`METODOLOGIA.md`](METODOLOGIA.md) | La fórmula del índice, los umbrales y la regla de materialidad |
| [`ARQUITECTURA.md`](ARQUITECTURA.md) | Cómo está construido el sistema y por qué se decidió así |
| [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md) | El backtest contra El Niño 2017/2017-18/2023 y el análisis de sensibilidad |
| [`API.md`](API.md) | Los 4 endpoints JSON con ejemplos ejecutables |
| [`COMPARATIVA.md`](COMPARATIVA.md) | WILLAQ frente a SENAMHI, ENFEN, Google Flood Hub e INDECI |
| [`MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md) | Despliegue paso a paso en hosting compartido |
| [`REQUERIMIENTOS.md`](REQUERIMIENTOS.md) | Requisitos con criterio de aceptación y su prueba |
| [`MANUAL_DISENO.md`](MANUAL_DISENO.md) | Sistema visual: paleta, tipografía, componentes y accesibilidad |
| [`MANUAL_USUARIO.md`](MANUAL_USUARIO.md) | Cómo se usa la aplicación, por rol |
| [`GUION_DEMO.md`](GUION_DEMO.md) | Guion de sustentación de 8 minutos |

MD;

$archivos = glob($base . '*.md');
$insertados = 0;

foreach ($archivos as $ruta) {
    $contenido = file_get_contents($ruta);

    if (strpos($contenido, '## Documentos relacionados') !== false) {
        echo "· ya tiene navegación: " . basename($ruta) . "\n";
        continue;
    }

    // Se inserta antes de una sección final que ya exista, o al final del archivo.
    $marca = "\n## 12. ";
    $pos = strrpos($contenido, $marca);
    if ($pos === false) {
        $marca = "\n## 11. ";
        $pos = strrpos($contenido, $marca);
    }
    if ($pos !== false) {
        $contenido = substr($contenido, 0, $pos) . $nav . substr($contenido, $pos);
    } else {
        $contenido = rtrim($contenido) . "\n" . $nav;
    }

    file_put_contents($ruta, $contenido);
    echo "✓ navegación añadida: " . basename($ruta) . "\n";
    $insertados++;
}

echo "\nDocumentos actualizados: $insertados\n";
