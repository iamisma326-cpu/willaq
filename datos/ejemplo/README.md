# Datos de ejemplo — fixtures, NO datos oficiales

Todo lo que hay en esta carpeta es **dato sintético de prueba**, generado para
ejercitar el importador. No proviene de INEI, MINEDU ni MINSA, y no debe
presentarse como oficial en la feria ni usarse para calcular riesgo.

Cada fila lo declara explícitamente en la columna `fuente`:

```text
FIXTURE-PRUEBA (no es dato oficial de INEI)
```

| Archivo | Qué prueba |
|---|---|
| `inei_poblacion.csv` | Fila canónica de población (ubigeo + nombre/provincia/región) |
| `inei_viviendas.csv` | Mismo formato para viviendas |
| `minedu_escale.csv` | Colegios (ESCALE/MINEDU) |
| `minsa_renipress.csv` | Centros de salud (RENIPRESS/MINSA) |
| `casos_rechazo.csv` | Filas **deliberadamente inválidas** para ver los rechazos |

## Cómo usarlos

```bash
# 1. Vista previa: NO escribe nada
php scripts/importar_exposicion.php datos/ejemplo/inei_poblacion.csv

# 2. Aplicar (escribe overlay + respaldo fechado + reporte)
php scripts/importar_exposicion.php datos/ejemplo/inei_poblacion.csv --aplicar

# 3. Volver al estado sin overlay (el catálogo vuelve a la estimación)
rm data/exposicion_oficial.json
```

`casos_rechazo.csv` existe para que el rechazo sea visible: ubigeo
inexistente, nombre que no corresponde al ubigeo, provincia equivocada,
región equivocada, indicador fuera de la lista blanca, valor no numérico y
año imposible. El importador los rechaza **uno por uno y los lista**; nunca
asigna en silencio.

## Con los CSV de verdad

El formato esperado es el mismo, pero con los archivos oficiales descargados
del portal correspondiente y **sin** la marca `FIXTURE-PRUEBA`. La verificación
de procedencia no es un adorno: el reporte de importación registra el
`sha256` de cada archivo aplicado, y la ficha del distrito muestra de dónde
salió cada campo.
