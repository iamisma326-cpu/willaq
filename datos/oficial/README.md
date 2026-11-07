# Datos oficiales de exposición

Archivos reales descargados de portales oficiales y sus derivados. Nada de
esta carpeta es dato sintético.

| Archivo | Qué es | Origen | sha256 |
|---|---|---|---|
| `inei_poblacion_distrital_2018_2026.xlsx` | XLSX original del INEI | [INEI · Perú: Población Total Proyectada al 30 de junio de cada año, según Departamento, Provincia y Distrito, 2018-2026](https://www.gob.pe/institucion/inei/informes-publicaciones/6894980-peru-poblacion-total-proyectada-al-30-de-junio-de-cada-ano-segun-departamento-provincia-y-distrito-2018-2026) (publicado el 31-12-2025) | `9436df29b883fd4a9db3705040a6668ff4efe7047c2643249b6b6bedd90d5c8b` |
| `inei_poblacion.csv` | CSV canónico para el importador (29 distritos, año 2026) | Derivado del XLSX con `scripts/inei_poblacion_xlsx_a_csv.php` | se recalcula en cada corrida |

## Cómo se reprodujo

```bash
# 1. Descargar el XLSX oficial (URL estable de gob.pe)
curl -L -o datos/oficial/inei_poblacion_distrital_2018_2026.xlsx \
  "https://cdn.www.gob.pe/uploads/document/file/8261096/6894980-peru-poblacion-total-proyectada-al-30-de-junio-de-cada-ano-segun-departamento-provincia-y-distrito-2018-2026.xlsx"

# 2. Verificar el sha256 (debe coincidir con el de la tabla de arriba)
sha256sum datos/oficial/inei_poblacion_distrital_2018_2026.xlsx

# 3. Convertir a CSV canónico (29/29 distritos, 0 rechazos)
php scripts/inei_poblacion_xlsx_a_csv.php \
  --xlsx=datos/oficial/inei_poblacion_distrital_2018_2026.xlsx \
  --anio=2026

# 4. Aplicar el overlay (escribe data/exposicion_oficial.json + respaldo)
php scripts/importar_exposicion.php datos/oficial/inei_poblacion.csv --aplicar

# 5. Refrescar los snapshots para que reflejen los totales oficiales
php scripts/actualizar.php --ventana=all
php scripts/semear_alertas.php --limpiar
```

## Qué queda estimado y por qué

| Campo | Estado | Motivo |
|---|---|---|
| `poblacion` | **Oficial** (INEI, proyección al 30/06/2026) | Archivo oficial descargado y verificado por sha256. |
| `viviendas` | Estimación del catálogo | No se encontró un archivo oficial **distrital y descargable** con viviendas. Las opciones del INEI (REDATAM, Atlas en PDF, productos censales) son interactivas o no traen XLSX por distrito. |
| `escuelas` | Estimación del catálogo | ESCALE exige verificación anti-bot (Check Point) y la plataforma nacional de datos abiertos responde 418 (CloudWAF). |
| `centros_salud` | Estimación del catálogo | Ídem: el portal de datos abiertos del Estado bloquea la descarga automatizada. |

Esto es exactamente para lo que el overlay está diseñado: **procedencia por
campo**. Un distrito puede tener población oficial y viviendas estimadas, y la
interfaz lo dice campo por campo. La fracción expuesta sigue siendo
`total × susceptibilidad`, es decir, **siempre estimada**.

Cuando se consigan los CSV de ESCALE y RENIPRESS, el flujo es el mismo:
descargar → convertir al formato canónico → `importar_exposicion.php
--aplicar`. El overlay **acumula** (no pisa) campos de otras fuentes.
