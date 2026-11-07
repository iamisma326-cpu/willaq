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

Sin overlay el catálogo se comporta **exactamente igual** que antes (hash
`4136bb6e408be67bc5af87b431998806`). Un JSON roto no rompe el sistema: se
ignora y se registra el error. El repositorio se entrega **sin** overlay
aplicado, para no presentar datos sintéticos como reales.

### B. Estaciones — **fase 1 ejecutada, fase 2 bloqueada**

| Pieza | Estado | Dónde |
|---|---|---|
| Garúa instalado y validado (`--health` OK) | ✅ | CLI externo, solo ingesta |
| Snapshot del catálogo SENAMHI (1029 estaciones) | ✅ | `data/estaciones/catalogo_senamhi.json` (sha256 `b6694672…612fe`) |
| Registro de estaciones | ✅ | `config/estaciones.json`: **103 estaciones**, **29/29 distritos** cubiertos |
| Prioridad de descarga derivada del backtest | ✅ | 6 `critica`, 17 `alta`, 21 `media`, 59 `baja` |
| Descarga real de las 6 críticas (2017–2026) | ✅ | `data/estaciones/_garua/csv/` |
| Importador con QA y normalización | ✅ | `scripts/importar_estaciones.php` |
| Series normalizadas | ✅ | `data/estaciones/senamhi-*.json` |
| Panel admin con cobertura y compuerta | ✅ | sección «Cobertura de estaciones» |
| Motor de riesgo usando estaciones | ⛔ | `motor_usa_estaciones = false` |

Resultado medido de las 6 estaciones críticas:

| Estación | Días con dato | Faltantes | Años cal. | Extensión real | Años compl. | Criterios medibles |
|---|---:|---:|---:|---:|---:|---|
| 105012 BERNAL | 3405 | 0,56 % | 10 | 9,35 | 8 | ✅ |
| 105070 CHULUCANAS | 3435 | 2,91 % | 10 | 9,68 | 7 | ✅ |
| 104084 PARTIDOR | 3478 | 3,75 % | 10 | 9,85 | 8 | ✅ |
| 103040 PAPAYAL | 3368 | 6,54 % | 10 | 9,85 | 8 | ✅ |
| 105063 SAN MIGUEL | 2711 | 24,43 % | 10 | 9,81 | 6 | ❌ |
| 104079 MALLARES | 2226 | 33,13 % | 10 | 9,10 | 5 | ❌ |

La columna **Años cal.** cuenta años calendario tocados (2017–2026), que fue la
decisión registrada. Para no sobrevender la cobertura, el panel muestra además
la **extensión real** (días calendario / 365,25) y los **años completos** (años
con ≤ 10 % de días faltantes). La extensión real todavía es **menor a 10 años**:
si se exigiera continuidad estricta, ninguna pasaría hasta 2027.

**4 de 6 cumplen los criterios medibles y 0 están habilitadas.** La diferencia
es deliberada: la fase 2 exige además medir falsos positivos en el backtest
sobre los 13 casos ya detectados, y esa medición todavía no se ejecutó. El
panel muestra ambos estados por separado (`cumple` ≠ `habilitada`).

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
- Re-ejecutar el backtest y **medir** si los 5 casos mejoran.
- Dejar la arquitectura lista para nowcast si algún día hay acceso en tiempo
  real.

**No se puede prometer:**

- Que los 5 casos se corrijan. Si la estación no tiene datos de 2017/2018/2023,
  o el evento también fue débil en esa estación, el diagnóstico se mantiene.
  Sería deshonesto decir “con estaciones se arregla”.
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

### Fase 2 — Climatología y fusión (solo si la fase 1 pasa)

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

## B.7 Criterios de aceptación (fase 2, solo si hay datos)

- Al menos **3 de los 5** casos pasan de `ausente/débil` a detectado, **y** no se
  añade más de 1 falso positivo entre los 13 ya detectados.
- Si la serie no cubre el año del evento, el resultado se reporta como
  **“no evaluable”**, no como éxito.
- Toda mejora se mide con el mismo `riesgo_indice_lluvia()` y el mismo protocolo
  multiescala; no se cambia la fórmula para forzar detecciones.

## B.8 Esfuerzo y bloqueo real

- Fase 1: **ejecutada** (registro reproducible, 6 series críticas importadas,
  panel con cobertura y compuerta).
- Fase 2: **2 a 4 jornadas**, más el tiempo de completar las series y ejecutar
  el backtest de falsos positivos.
- El bloqueo ya no es de acceso —Garúa resolvió la descarga— sino de **calidad
  del dato y medición**: 4 de 6 estaciones críticas cumplen años y faltantes,
  pero ninguna está habilitada hasta medir falsos positivos.

---

# Orden recomendado (ejecutado)

1. **Importador CSV.** ✅ Implementado con overlay, respaldo y reporte.
2. **Estaciones, fase 1.** ✅ Registro de 103 estaciones, ingesta real con
   Garúa, QA y cobertura en el panel.
3. **Fase 2 solo con series en mano.** ⛔ Bloqueada: falta el backtest de
   falsos positivos. La compuerta se definió antes de mirar resultados.

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
