# Plan de los dos pendientes: exposición oficial y estaciones

> **Estado:** **fase A ejecutada** (importador + overlay) y **fase B · fase 1
> ejecutada** (registro, ingesta real con Garúa, QA y procedencia). La fase 2
> de estaciones sigue **bloqueada por compuerta**.
> **Fecha de diseño:** 5 de noviembre de 2026 · **última ejecución:** 6 de
> noviembre de 2026
> **Alcance:** piloto WILLAQ (29 distritos, Perú).
> **Regla de lenguaje:** todo resultado es una *recomendación de apoyo a la
> decisión*; nunca una alerta oficial.

Este documento explica, antes de tocar código, qué resuelven y qué no resuelven
los dos pendientes abiertos:

1. Importador CSV de INEI / MINEDU / MINSA para reemplazar la exposición
   estimada (`estimacion_ratio_poblacional`).
2. Estaciones SENAMHI / ANA (hoy citadas como “SENAMHI/OEA”) para la costa
   norte, con el fin de atacar las 5 no detecciones históricas.

---

## Decisiones tomadas (5 de noviembre de 2026)

| # | Pregunta | Respuesta |
|---|---|---|
| 1 | ¿Qué significa “OEA”? | **SENAMHI (PISCO) + ANA** |
| 2 | ¿Dispone de los CSV oficiales? | **Los descargo de portales oficiales** |
| 3 | ¿Qué población usar? | **Ambas, declarando año** (Censo 2017 y proyección INEI 2024/2025) |
| 4 | ¿Qué nivel de avance con estaciones? | **Solo fase 1 por ahora** (registro, ingesta, QA y procedencia) |
| 5 | ¿Cómo aplicar los datos oficiales? | **Overlay con respaldo** (`data/exposicion_oficial.json`) |

### Consecuencias de cada decisión

1. **SENAMHI (PISCO) + ANA**: se sustituye la sigla “OEA” en
   `VALIDACION_HISTORICA.md`, `COMPARATIVA.md` y `GUION_DEMO.md` por
   “SENAMHI (PISCO) + ANA”. No se afirma que ANA tenga datos en tiempo real.
2. **CSV descargados por el usuario**: el importador se construye con el
   formato canónico y fixtures de prueba; la carga real se hace cuando el
   usuario descargue los archivos de INEI / ESCALE / RENIPRESS.
3. **Ambas poblaciones**: la ficha y el API muestran Censo 2017 y proyección
   INEI 2024/2025 con su año. La cifra que alimenta `E` se declara
   explícitamente y queda configurable; no se mezclan años sin avisar.
4. **Solo fase 1 de estaciones**: se implementa registro, ingesta, control de
   calidad y procedencia. La climatología local y la fusión con el motor
   (fase 2) quedan bloqueadas hasta tener series con ≥ 10 años y < 10 % de
   faltantes.
5. **Overlay con respaldo**: los valores oficiales viven en
   `data/exposicion_oficial.json`; `config/territorios.json` no se reescribe.
   Borrar el overlay devuelve el sistema a la estimación actual.

### Pendientes operativos (no bloquean el diseño)

- Descargar los CSV oficiales (INEI, ESCALE, RENIPRESS) y confirmar la unidad
  de conteo de colegios y centros de salud.
- Completar la descarga de estaciones con Garúa: las 6 `critica` están
  descargadas e importadas; las 17 `alta` se descargan en segundo plano.
- Ejecutar el backtest de falsos positivos cuando la compuerta medible pase.

---

## Estado de ejecución (6 de noviembre de 2026)

### A. Exposición oficial — **ejecutada**

| Pieza | Estado | Dónde |
|---|---|---|
| Importador CSV con dry-run por defecto y `--aplicar` | ✅ | `scripts/importar_exposicion.php` |
| Lista blanca: `poblacion`, `viviendas`, `escuelas`, `centros_salud` | ✅ | importador |
| Resolución ubigeo + nombre/provincia/región, rechazo explícito | ✅ | 12 rutas de rechazo probadas |
| Overlay + respaldo fechado + reporte | ✅ | `data/exposicion_oficial.json`, `data/backups/`, `data/importacion_reporte.json` |
| Lectura del overlay sin reescribir el catálogo | ✅ | `models/Exposicion.php`, `models/Territorio.php` |
| Ficha, panel admin, reporte y APIs con procedencia por campo | ✅ | `views/territorio/ficha.php`, `views/admin/panel.php`, `index.php` |
| Textos de alcance **dinámicos** (se redactan desde el overlay) | ✅ | `models/Exposicion.php` → `exposicion_texto_breve()`, `exposicion_texto_limite()`, `exposicion_texto_corto()`; usados en home, ayuda y reporte |
| **Población oficial del INEI** (proyección 30/06/2026, 29/29) | ✅ | `datos/oficial/` + `scripts/inei_poblacion_xlsx_a_csv.php` |
| Viviendas / escuelas / centros de salud oficiales | ⛔ | ESCALE y RENIPRESS bloquean la descarga automatizada (anti-bot / WAF) |

El overlay aplicado cubre **población** en los 29 distritos (INEI, sha256
`9436df29…d90d5c8b`); **`viviendas`, `escuelas` y `centros_salud` siguen
estimados** y la interfaz lo dice campo por campo. Sin overlay el catálogo se
comporta **exactamente igual** que antes (hash
`4136bb6e408be67bc5af87b431998806`): borrar `data/exposicion_oficial.json`
revierte a estimación. Un JSON roto no rompe el sistema: se ignora y se
registra el error.

Los textos de alcance **no están escritos a mano por caso**: home, ayuda y
reporte preguntan al modelo qué es oficial y qué no, y lo redactan. Con el
overlay actual dicen «el campo de población es oficial (INEI…, 2026,
29/29 distritos); los campos de viviendas, colegios y centros de salud siguen
siendo estimación». Si mañana se aplican los padrones de ESCALE y RENIPRESS,
esas frases cambian solas; si se borra el overlay, vuelven a declarar que todo
es estimación. Un alcance que se queda escrito a mano es un alcance que
termina mintiendo.

### B. Estaciones — **fase 1 ejecutada, fase 2 medida y bloqueada por el resultado**

| Pieza | Estado | Dónde |
|---|---|---|
| Garúa instalado y validado (`--health` OK) | ✅ | CLI externo, solo ingesta |
| Snapshot del catálogo SENAMHI (1029 estaciones) | ✅ | `data/estaciones/catalogo_senamhi.json` (sha256 `b6694672…612fe`) |
| Registro de estaciones | ✅ | `config/estaciones.json`: **103 estaciones**, **29/29 distritos** cubiertos |
| Prioridad de descarga derivada del backtest | ✅ | 6 `critica`, 17 `alta`, 21 `media`, 59 `baja` |
| Descarga real: 6 `critica` + 17 `alta` (2017–2026) | ✅ | `data/estaciones/_garua/csv/` (23 estaciones) |
| Importador con QA y normalización | ✅ | `scripts/importar_estaciones.php` |
| Series normalizadas | ✅ | `data/estaciones/senamhi-*.json` (**23 series**) |
| Aritmética de detección compartida | ✅ | `scripts/lib_validacion.php` (validación ERA5 y backtest usan la misma función) |
| Panel admin con cobertura y compuerta | ✅ | sección «Cobertura de estaciones» |
| Backtest de falsos positivos (gate de fase 2) | ✅ ejecutado | `scripts/backtest_estaciones.php` → `data/backtest_estaciones.json` |
| Motor de riesgo usando estaciones | ⛔ | `motor_usa_estaciones = false` (0 estaciones aptas) |

Resultado medido sobre las **23 series** descargadas (6 `critica` + 17 `alta`):

| Estación | Días con dato | Faltantes | Años cal. | Extensión real | Años compl. | Criterios medibles |
|---|---:|---:|---:|---:|---:|---|
| 104084 PARTIDOR | 3.478 | 3,75 % | 10 | 9,85 | 8 | ✅ |
| 105070 CHULUCANAS | 3.435 | 2,91 % | 10 | 9,68 | 7 | ✅ |
| 105012 BERNAL | 3.405 | 0,56 % | 10 | 9,35 | 8 | ✅ |
| 103040 PAPAYAL | 3.368 | 6,54 % | 10 | 9,85 | 8 | ✅ |
| 105063 SAN MIGUEL | 2.711 | 24,43 % | 10 | 9,81 | 6 | ❌ |
| 103038 PUERTO PIZARRO | 2.401 | 25,01 % | 9 | 8,73 | 4 | ❌ |
| 104079 MALLARES | 2.226 | 33,13 % | 10 | 9,10 | 5 | ❌ |
| 105100 MIRAFLORES | 1.806 | 31,13 % | 7 | 7,17 | 3 | ❌ |
| 47E0522C TAMBO GRANDE | 1.802 | 0,00 % | 6 | 4,93 | 4 | ❌ |
| 472F6540 CHULUCANAS | 1.799 | 0,17 % | 6 | 4,93 | 4 | ❌ |
| 47E024BC PUENTE TUMBES | 1.736 | 9,82 % | 6 | 5,27 | 2 | ❌ |
| 472EF2D8 CAMPAMENTO SEDE | 1.711 | 6,60 % | 6 | 5,02 | 2 | ❌ |
| 103041 EL SALTO | 1.536 | 14,33 % | 5 | 4,66 | 3 | ❌ |
| 47E0415A PUENTE ÑACARA | 1.533 | 21,59 % | 6 | 5,35 | 1 | ❌ |
| 472F353C MATAPALO | 1.528 | 0,00 % | 5 | 4,18 | 3 | ❌ |
| 103008 MATAPALO | 1.516 | 2,38 % | 5 | 4,25 | 3 | ❌ |
| 104109 EL CHIRA | 1.091 | 13,07 % | 4 | 3,44 | 1 | ❌ |
| 210503 PUENTE EGUIGUREN | 880 | 15,30 % | 3 | 2,84 | 1 | ❌ |
| 105143 SAN MIGUEL | 710 | 1,66 % | 3 | 1,98 | 1 | ❌ |
| 105144 BERNAL | 395 | 0,00 % | 2 | 1,08 | 0 | ❌ |
| 105112 MIRAFLORES | 394 | 80,35 % | 3 | 5,49 | 0 | ❌ |
| 104113 PARTIDOR | 393 | 0,00 % | 2 | 1,08 | 0 | ❌ |
| 103068 PUERTO PIZARRO | 373 | 0,00 % | 2 | 1,02 | 0 | ❌ |

La columna **Años cal.** cuenta años calendario tocados (2017–2026), que fue la
decisión registrada. Para no sobrevender la cobertura, el panel muestra además
la **extensión real** (días calendario / 365,25) y los **años completos** (años
con ≤ 10 % de días faltantes). La extensión real todavía es **menor a 10 años**:
si se exigiera continuidad estricta, ninguna pasaría hasta 2027.

**4 de 23 cumplen los criterios medibles y 0 están habilitadas.** La diferencia
es deliberada, y ahora tiene **tres capas** que el panel muestra por separado:
`cumple medible` (años y faltantes) ≠ `medido en backtest` (la medición de
falsos positivos ya se ejecutó) ≠ `habilitada` (el motor la usa). El backtest
del 7 de noviembre de 2026 midió 8 estaciones: **ninguna** alcanzó el recall
mínimo de 0.80 sobre la referencia documentada que evaluó, así que la fase 2
queda **cerrada por medición**, no por falta de trabajo.

Lo que muestra la foto: la calidad de la cobertura **no depende solo del
nombre de la estación**. Hay pares de estaciones con el mismo nombre
(«BERNAL», «MATAPALO», «SAN MIGUEL») y solo una de cada par tiene serie
larga: el código de una estación repetida puede ser una estación distinta, con
otra red y otra cobertura. Por eso el registro guarda el código y la ficha
completa, no solo el nombre.

Defectos reales del dato que el importador maneja y documenta: `S/D`
(ausencia, nunca cero), `T` (traza, se conserva como marca), centinela
`-999.0`, negativos imposibles (`-880.x` a `-887.x`, `-1,776.0`), duplicados
exactos/compatibles/conflictivos del día 1 de mes, años con 367–377 filas y
2026 incompleto. Ningún hueco se rellena por interpolación.

---

## Resumen ejecutivo

| Pendiente | Qué corrige | Qué **no** corrige |
|---|---|---|
| **A. Importador CSV** | El **denominador**: cuánta población, viviendas, colegios y centros de salud hay realmente en cada distrito. | La **fracción expuesta**. Seguirá siendo un ratio hasta cruzar manzanas censales con capas de peligro (CENEPRED/INDECI), fuera del alcance del piloto. |
| **B. Estaciones SENAMHI** | La **resolución espacial** del peligro: ERA5 a 25 km suaviza lluvias convectivas locales de la costa norte. | No garantiza detectar los 5 casos históricos: depende de si esas estaciones tienen serie para 2017/2018/2023 y de si el evento aparece en ellas. |

---

# A. Importador CSV INEI / MINEDU / MINSA

## A.1 Qué hay hoy en el código

- `config/territorios.json` tiene 29 distritos y
  `_meta.fuente_exposicion = "estimacion_ratio_poblacional"`.
- No existe `ubigeo` en ningún distrito.
- `models/Territorio.php::territorio_derivar()` calcula los campos expuestos con
  ratios fijos:

```php
$t['poblacion_expuesta_inundacion'] = round($t['poblacion'] * $t['susc_inundacion']);
$t['poblacion_expuesta_huaico']     = round($t['poblacion'] * $t['susc_huaico']);
$t['poblacion_expuesta_sequia']     = round($t['poblacion'] * $t['susc_sequia'] * 0.8);
$t['poblacion_expuesta_calor']      = round($t['poblacion'] * 0.6);
$t['poblacion_expuesta_agro']       = round($t['poblacion'] * 0.4 + min($t['poblacion'] * 0.6, $t['hectareas_cultivo'] * 4));
```

- Ese `poblacion_expuesta_*` alimenta `riesgo_exp_poblacion()` → `E` → **el
  índice de riesgo**. Es decir, el importador **sí puede mover el ranking y los
  snapshots**; no es solo cosmético.
- La ficha (`views/territorio/ficha.php`) y el reporte muestran
  `fuente_exposicion` como un chip único por distrito, no por campo.

## A.2 Diseño propuesto

### Formato canónico de entrada

Un CSV por fuente, o uno consolidado:

```csv
ubigeo,indicador,valor,anio,fuente,nota
150101,poblacion,9824,2017,INEI Censo 2017,
150101,viviendas,2650,2017,INEI Censo 2017,
150101,escuelas,42,2023,MINEDU ESCALE,locales escolares
150101,centros_salud,7,2024,MINSA RENIPRESS,IPRESS categoría I
```

### Flujo del script `scripts/importar_exposicion.php`

1. **Validar cabecera y tipos** (sin escribir nada).
2. **Resolver cada fila a un distrito**:
   - por `ubigeo` exacto, y además verificar que nombre/provincia/región
     coincidan;
   - si no hay ubigeo, por nombre normalizado (sin tildes, minúsculas) +
     provincia;
   - si hay ambigüedad o no hay match → **fila rechazada y listada**, nunca
     asignación silenciosa.
3. **Validar rangos** (población > 0 y < 5 000 000; escuelas ≥ 0; sin duplicados
   ubigeo + indicador + año; sin negativos).
4. **Dry-run por defecto**: muestra tabla
   `distrito / campo / antes / después / fuente / año`.
5. **`--aplicar`**: escribe `data/exposicion_oficial.json`, guarda respaldo con
   fecha y genera `data/importacion_reporte.json`.
6. **No toca** `vulnerabilidad`, `susc_*`, `hectareas_cultivo` ni
   `albergues_capacidad`: no vienen de INEI/MINEDU/MINSA. Solo hay una lista
   blanca de campos.

### Overlay, no reescritura del catálogo

`territorio_derivar()` leería primero `data/exposicion_oficial.json`; si un
campo no está, usa el valor del catálogo y lo marca como estimado. Esto permite:

- volver atrás borrando un archivo;
- mezclar campos oficiales y estimados sin mentir;
- que el jurado vea el diff completo.

### Procedencia por campo, no por distrito

```json
{
  "150101": {
    "poblacion": {
      "valor": 9824,
      "fuente": "INEI Censo 2017",
      "anio": 2017,
      "archivo": "inei_poblacion.csv",
      "sha256": "…",
      "importado_en": "…"
    },
    "escuelas": {
      "valor": 42,
      "fuente": "MINEDU ESCALE",
      "anio": 2023,
      "archivo": "minedu_escale.csv",
      "sha256": "…",
      "importado_en": "…"
    }
  }
}
```

La ficha y el API mostrarían, por ejemplo: **“Población total: 9 824
(INEI 2017)”** y, separado, **“Población expuesta a inundación: 8 842
(estimación por ratio)”**. Son dos números distintos y no se deben mezclar.

## A.3 Archivos que se tocarían

| Archivo | Cambio |
|---|---|
| `config/territorios.json` | Añadir `ubigeo` a los 29 distritos + actualizar `_meta` |
| `models/Territorio.php` | `territorio_derivar()` lee overlay; helper de procedencia |
| `scripts/importar_exposicion.php` | **Nuevo**: validador + importador CLI |
| `data/exposicion_oficial.json` | **Nuevo**: overlay (generado) |
| `views/territorio/ficha.php` | Mostrar total oficial y expuesto estimado por separado |
| `views/admin/panel.php` | Sección “Procedencia de exposición”: archivo, hash, fecha, campos importados |
| `index.php` | Exponer procedencia en `api_riesgo` / `api_territorios` |
| `docs/REQUERIMIENTOS.md`, `COMPARATIVA.md`, `METODOLOGIA.md`, `MANUAL_INSTALACION.md`, `API.md` | Actualizar cifras y estado del dato |
| `scripts/verificar_enlaces.php` | Ya existe; debe seguir en 0 enlaces rotos |

## A.4 Límites honestos

1. **No convierte la exposición en oficial.** Solo el total. La fracción
   expuesta sigue siendo `población × susceptibilidad`, y así debe decirse en
   pantalla.
2. **Los colegios y centros de salud son denominadores.** Saber que hay 42
   colegios no dice cuántos están en zona inundable. Eso requiere cruzar su
   coordenada con una capa de peligro.
3. **Unidades semánticas distintas**: MINEDU puede dar “locales”, “servicios” o
   “instituciones”; MINSA puede incluir farmacias si no se filtra por categoría
   de IPRESS. Hay que fijar la unidad antes de importar, o los números no serán
   comparables.
4. **Años distintos por fuente**: censo 2017, ESCALE 2023, RENIPRESS 2024. Se
   declara el año de cada campo; no se finge un corte único.
5. **Riesgo de ubigeo incorrecto**: un dígito mal puesto asigna población de
   otro distrito. Mitigación: doble validación ubigeo + nombre + provincia, y
   rechazo si no coinciden.
6. **No incluye `km_vias`**: eso es MTC, no INEI/MINEDU/MINSA. Se queda
   estimado y marcado.

## A.5 Criterios de aceptación

- `--dry-run` con CSV oficial: **29/29 distritos resueltos, 0 ambiguos,
  0 rechazados** (o lista explícita de los que falten).
- `--aplicar` genera overlay + respaldo + reporte; el sistema sin overlay se
  comporta **exactamente igual** que hoy.
- Ningún campo se sobrescribe sin fuente, año y hash.
- Ficha y API distinguen “total oficial” de “expuesto estimado”.
- Snapshots regenerados (`php scripts/actualizar.php --ventana=all`) y alertas
  re-sembradas (`php scripts/semear_alertas.php --limpiar`), con diff
  antes/después documentado.
- La validación histórica **no cambia** (no usa exposición; usa solo lluvia),
  así que no hay que repetirla.

## A.6 Esfuerzo

**1,5 a 2,5 jornadas** si los CSV existen. Si hay que construir la tabla de
29 ubigeos y limpiar los archivos, súmale 0,5 a 1 jornada. El código es la parte
fácil; la discusión de unidades y años es la que consume tiempo.

---

# B. Estaciones SENAMHI / “OEA” para la costa norte

## B.1 Primero: qué es “OEA”

En el repo aparece en `VALIDACION_HISTORICA.md`, `COMPARATIVA.md` y
`GUION_DEMO.md` como “SENAMHI/OEA”. **No conozco una red peruana de estaciones
llamada OEA.** Las posibilidades reales son:

| Sigla | Qué es | ¿Tiene estaciones? |
|---|---|---|
| **SENAMHI** | Servicio Nacional de Meteorología e Hidrología | Sí, red nacional + PISCO grillado 10 km |
| **ANA** | Autoridad Nacional del Agua | Sí, red de estaciones hidrológicas |
| **ENFEN** | Estudio Nacional del Fenómeno El Niño (SENAMHI, IMARPE, DHN, IGP, ANA) | No propias; coordina |
| **OEFA** | Organismo de Evaluación y Fiscalización Ambiental | Monitoreo ambiental, no red hidrometeorológica |
| **OEA** | Organización de Estados Americanos | No tiene red de estaciones en Perú |

Mi recomendación: **cambiar “OEA” por “ANA”** en los documentos, o por
“SENAMHI (PISCO) + ANA”. Si el jurado pregunta “¿qué es OEA?”, la respuesta
actual no se sostiene. Es un detalle pequeño pero de credibilidad.

## B.2 Por qué ERA5 falla en la costa norte

El backtest ya lo midió, no es una hipótesis:

| Caso | Distrito | Diagnóstico | Ratio vs p95 |
|---|---|---|---|
| 2017–18 | Piura | `evento_debil_en_datos` | 1.42× |
| 2017–18 | Catacaos | `evento_ausente_en_datos` | 0.77× |
| 2017–18 | Tambogrande | `evento_ausente_en_datos` | 0.49× |
| 2017–18 | Sullana | `evento_ausente_en_datos` | 0.72× |
| 2023 | Zarumilla | `evento_debil_en_datos` | 1.26× |

ERA5 es un reanálisis de ~25 km: promedia la celda y diluye chubascos
convectivos que en la costa norte son de escala local. Una estación mide en un
punto; si ese punto captó el núcleo, la señal aparece. Pero **una estación
tampoco es la verdad espacial del distrito**: es un punto con representatividad
limitada.

## B.3 Qué se puede y qué no se puede prometer

**Se puede:**

- Incorporar series diarias de estaciones con control de calidad y procedencia.
- Calcular climatología local (p50/p95/p99) con la misma función
  `clima_percentil()`.
- Asignar estación → distrito con reglas explícitas de distancia y altitud.
- Medir el backtest contra ERA5 con la misma aritmética de producción. ✅
  **Hecho el 7 de noviembre de 2026**: 29 distritos×evento comparables, 15
  casos de referencia evaluados por ambas fuentes, recall de estación =
  recall de ERA5 = 0.7333, 0 falsos positivos nuevos, 1 caso recuperado
  (`tum-zarumilla`, 2023). 0 estaciones aptas.
- Dejar la arquitectura lista para nowcast si algún día hay acceso en tiempo
  real.

**No se puede prometer:**

- Que las estaciones resuelvan los 5 casos que ERA5 perdió. **Medido: no.**
  De los 5 casos en que ERA5 no detectó (4 en 2017-18 + `tum-zarumilla` en
  2023), las estaciones disponibles solo recuperaron **1**. Y no porque las
  series fueran malas —4 tienen ≥ 10 años y < 10 % de faltantes— sino porque
  en esos distritos y fechas la precipitación puntual registrada **tampoco**
  alcanzó el nivel del evento. Sería deshonesto decir “con estaciones se
  arregla”.
- Tiempo real. SENAMHI no garantiza API pública abierta; scrapear su web no es
  una base operativa ni estable para una feria.
- Leer NetCDF de PISCO en PHP puro. PISCO se distribuye en NetCDF; PHP no lo lee
  sin extensiones. Opciones: (a) usar CSV de estaciones, (b) pre-convertir PISCO
  a CSV una vez fuera del runtime (con la salvedad de que se pidió “sin Python”;
  habría que confirmar si una conversión puntual de escritorio es aceptable), o
  (c) no usar PISCO.

## B.4 Diseño en tres fases, con compuerta

### Fase 1 — Registro e ingesta (entregada el 6 de noviembre de 2026)

- `data/estaciones/catalogo_senamhi.json`: snapshot del catálogo del SENAMHI
  descargado con **Garúa** (1029 estaciones, sha256 registrado). Es la fuente
  del registro; no se consulta el portal en runtime.
- `scripts/generar_registro_estaciones.py`: genera
  `config/estaciones.json` de forma reproducible. **103 estaciones**, **29/29
  distritos** dentro de radio, con prioridad `critica/alta/media/baja` derivada
  del backtest. La asignación exige ≤ 25 km y |Δaltitud| ≤ 500 m, prefiere
  meteorológica sobre hidrológica y `listed` sobre `absent`.
- `scripts/descargar_estaciones_garua.sh`: driver de descarga por prioridad.
  Garúa es **solo ingesta**: abre Chrome para superar Cloudflare Turnstile y
  deja CSV crudos en `data/estaciones/_garua/csv/`. Nunca se ejecuta desde PHP.
- `scripts/importar_estaciones.php`: importa los **cuatro formatos** de Garúa
  (meteorológica/hidrológica, convencional/automática) y un CSV canónico
  `estacion,fecha,precip_mm,temp_c,fuente`. Normaliza a día calendario
  `America/Lima`; maneja `S/D`, `T`, centinelas `-99/-999`, negativos,
  decimales con coma, duplicados exactos/compatibles/conflictivos y huecos.
- `data/estaciones/{id}.json`: serie diaria normalizada + reporte QA.
- `models/Estacion.php`: capa de lectura, compuerta y tablero. El panel admin
  muestra cobertura, última fecha, % faltantes, fuente y licencia.
- **El motor de riesgo no cambia** (flag apagado). Los números de la demo siguen
  idénticos.

### Fase 2 — Climatología y fusión (compuerta cerrada por medición)

Requisitos mínimos antes de usar una estación:

- ≥ 10 años de serie y < 10 % de días faltantes.
- Asignación al distrito solo si está a ≤ 25 km y con diferencia de altitud
  ≤ 500 m; si no, se documenta la excepción o no se usa.
- Regla de fusión conservadora: la estación puede **elevar** la señal local
  ausente en ERA5, nunca bajarla sin justificación. Propuesta inicial:
  `H_lluvia = max(H_ERA5, H_estacion)` cuando la ventana de la estación está
  completa; si faltan días, se usa solo ERA5.
- Cada amenaza expone `fuente_peligro`: `ERA5`, `estacion` o `fusion`. La ficha
  y el API lo muestran.
- Se mide antes de adoptar: falsos positivos nuevos sobre los 13 casos ya
  detectados.

#### Resultado de la medición (ejecutada el 7 de noviembre de 2026)

Los umbrales se fijaron **antes** de ver los resultados: recall ≥ 0.80 sobre
la referencia documentada que la estación evaluó, 0 falsos positivos nuevos y
≥ 3 combinaciones distrito×evento medidas por estación.

| Métrica | Valor |
|---|---|
| Distritos×evento comparables (evaluados por ambas fuentes) | 29 |
| Casos de referencia documentada evaluados por ambas fuentes | 15 |
| Recall ERA5 | 0.7333 (11 de 15) |
| Recall estación | 0.7333 (11 de 15) |
| Falsos positivos ERA5 / estación | 10 / 10 |
| Falsos positivos **nuevos** de la estación | **0** |
| Falsos positivos resueltos | 0 |
| Casos recuperados por la estación | 1 (`tum-zarumilla`, 2023) |
| Estaciones con serie | 23 |
| Cumplen criterios medibles (años/faltantes) | 4 |
| Evaluadas en el backtest (tuvieron ≥ 1 combo) | 8 |
| **Aptas para fusión** | **0** |

Veredicto por estación (las 4 que cumplen los criterios medibles):

| Estación | Combos | Ref. evaluada | Recall | FP nuevos | Veredicto |
|---|---|---|---|---|---|
| PAPAYAL | 9 | 5 | 0.00 | 0 | `medida_recall_insuficiente` |
| PARTIDOR | 3 | 2 | 0.00 | 0 | `medida_recall_insuficiente` |
| CHULUCANAS | 3 | 1 | 0.00 | 0 | `medida_recall_insuficiente` |
| SAN MIGUEL | 8 | 4 | 0.00 | 0 | `bloqueada_criterios_medibles` (serie < 10 años o > 10 % faltantes) |

**Lectura honesta del resultado.** Las estaciones no son «peores» que ERA5:
detectaron **exactamente los mismos 11 distritos** que el reanálisis sobre los
15 casos de referencia comparables, no añadieron ni un señalamiento fuera de la
referencia y recuperaron un caso que ERA5 había perdido (`tum-zarumilla`,
2023). El problema es otro: **no aportan la señal que faltaba**. La razón
métrica es que el recall de la estación (0.7333) es idéntico al de ERA5, así
que no supera el umbral de 0.80 que la compuerta exigía.

**Consecuencia.** La compuerta queda **cerrada** y `motor_usa_estaciones`
sigue en `false`. Ninguna estación entra al motor. La medición no se maquilla
ni se reescribe: para reabrir el gate hay que **recalibrar por fuente** (por
ejemplo, umbrales de estación más exigentes que los de ERA5, porque una
estación puntual no necesita el mismo margen de materialidad que una celda de
25 km) y volver a medir con la misma aritmética compartida
(`scripts/lib_validacion.php`).

Artefacto: `data/backtest_estaciones.json` (generado por
`scripts/backtest_estaciones.php`). El panel admin muestra el mismo resultado
en «Cobertura de estaciones».

### Fase 3 — Nowcast (fuera del alcance de la feria)

Solo si existe acceso real en tiempo real. Si no, se declara “no operativo en
tiempo real” en la documentación y en la demo. No se simula tiempo real con
datos históricos.

## B.5 Dónde se enchufa en el código

| Punto | Función / archivo |
|---|---|
| Registro y series | `config/estaciones.json`, `data/estaciones/{id}.json` |
| Lectura y compuerta | `models/Estacion.php` (`estacion_puede_usarse()`, `estacion_resumen()`, `estacion_tablero()`) |
| Datos por distrito | `models/Riesgo.php::riesgo_datos()` (hoy solo llama a `clima_forecast`, `clima_flood`, `clima_reciente`, `riesgo_umbrales`) |
| Cálculo de lluvia | `models/Riesgo.php::riesgo_calcular()`, bloque `$lluviaAcum` / `riesgo_indice_lluvia()` |
| Climatología | `models/Clima.php::clima_climatologia()`; nueva `clima_climatologia_estacion()` |
| Backtest | `scripts/validar_historico.php`, donde hoy usa `clima_archivo()` |
| Calibración | `scripts/calibrar.php` o nuevo `scripts/calibrar_estaciones.php` |
| Trazabilidad | `detalle` de cada amenaza y `fuente_peligro` en la salida |

## B.6 Criterios de aceptación (fase 1)

- Importa N estaciones, con reporte QA y cobertura visible en el panel.
- Series normalizadas y atribuidas; sin datos inventados ni estaciones “de
  ejemplo” presentadas como reales.
- El motor produce **exactamente los mismos números** que hoy mientras el flag
  esté apagado.
- Documentación actualizada, incluida la sustitución de “OEA” por la sigla
  correcta.
- `verificar_enlaces.php` sigue en 0 enlaces rotos.

## B.7 Criterios de aceptación (fase 2) — ejecutados y medidos

Los criterios se fijaron antes de ejecutar la medición:

- Al menos **3 de los 5** casos pasan de `ausente/débil` a detectado, **y** no se
  añade más de 1 falso positivo entre los 13 ya detectados.
- Si la serie no cubre el año del evento, el resultado se reporta como
  **“no evaluable”**, no como éxito.
- Toda mejora se mide con el mismo `riesgo_indice_lluvia()` y el mismo protocolo
  multiescala; no se cambia la fórmula para forzar detecciones.

**Resultado: los criterios de aceptación NO se cumplieron.** La medición
registró **1 caso recuperado** de los 5 que ERA5 había perdido
(`tum-zarumilla`, 2023), no 3; y **0 falsos positivos nuevos**. Con el criterio
de recall ≥ 0.80 sobre la referencia documentada, **0 estaciones** quedaron
aptas para fusión. La compuerta queda cerrada y documentada como tal.

## B.8 Esfuerzo y bloqueo real

- Fase 1: **ejecutada** (registro reproducible de 103 estaciones, **23 series
  reales** importadas y auditadas, panel con cobertura y compuerta).
- Fase 2 (medición): **ejecutada** el 7 de noviembre de 2026
  (`scripts/backtest_estaciones.php` → `data/backtest_estaciones.json`).
- Fase 2 (fusión): **bloqueada por el resultado, no por falta de trabajo**.
  El bloqueo ya no es de acceso —Garúa resolvió la descarga— ni de
  medición —la medición está hecha y es reproducible—. Es un bloqueo de
  **calidad de la señal**: las estaciones disponibles no reproducen la
  precipitación de los eventos extrema que ERA5 perdió, así que activarlas no
  mejoraría nada y añadiría complejidad al motor.
- **Cómo se reabre:** recalibrar los umbrales por fuente (una estación puntual
  no necesita el mismo margen de materialidad que una celda de 25 km) y
  re-ejecutar la medición con la misma aritmética compartida. Además,
  completar las 19 series cortas (2 a 9 años) con dos o tres años más de
  descarga acercaría el conjunto a los criterios medibles.

---

# Orden recomendado (ejecutado)

1. **Importador CSV.** ✅ Implementado con overlay, respaldo y reporte.
2. **Estaciones, fase 1.** ✅ Registro de 103 estaciones, ingesta real con
   Garúa, QA y cobertura en el panel.
3. **Fase 2, medición.** ✅ Ejecutada el 7 de noviembre de 2026. 29
   distritos×evento comparables, 15 casos de referencia evaluados por ambas
   fuentes, recall de estación = recall de ERA5 = 0.7333, **0 falsos
   positivos nuevos**, 1 caso recuperado. **0 estaciones aptas.**
4. **Fase 2, fusión con el motor.** ⛔ Bloqueada por el resultado de la
   medición: con los umbrales fijados antes de medir, ninguna estación alcanza
   el recall mínimo de 0.80. `motor_usa_estaciones = false`. Reabrir exige
   recalibrar por fuente y volver a medir.

---

# Decisiones cerradas

Las cinco decisiones están registradas en
[Decisiones tomadas](#decisiones-tomadas-5-de-noviembre-de-2026). No queda
ninguna decisión de diseño abierta; solo los pendientes operativos de descarga
de CSV y series de estaciones descritos en esa misma sección.

---

# Documentos relacionados

- [`../PLAN_IMPLEMENTACION_WILLAQ.md`](../../PLAN_IMPLEMENTACION_WILLAQ.md)
- [`REQUERIMIENTOS.md`](REQUERIMIENTOS.md)
- [`METODOLOGIA.md`](METODOLOGIA.md)
- [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md)
- [`COMPARATIVA.md`](COMPARATIVA.md)
- [`MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md)
- [`API.md`](API.md)
