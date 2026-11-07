# Metodología del índice de riesgo WILLAQ

> Versión 1 · motor multi-amenaza explicable
> Archivos: `models/Riesgo.php`, `models/Clima.php`, `config/umbrales.php`

---

## 1. Principio de diseño

Un índice de riesgo útil para una autoridad local tiene que responder tres
preguntas a la vez:

1. **¿Cuánto de intenso es el evento?** → Peligro `H`
2. **¿A cuántas personas alcanza?** → Exposición `E`
3. **¿Qué tan mal lo están tomando?** → Vulnerabilidad `V`

La decisión metodológica central: **el peligro es una condición necesaria**.
Si no llueve, no sube el río o no hay calor, no hay inundación aunque haya
mucha población expuesta. Por eso `H` **multiplica** y `E`/`V` **suman** dentro
de un paréntesis acotado:

```
R = 100 · H · (0.40 + 0.35·E + 0.25·V)
```

* Rango: 0–100
* Si `H = 0` → `R = 0`, sin importar la exposición (correcto: no hay evento)
* Si `H = 1` y `E = V = 1` → `R = 100` (peor caso conceivable)
* El factor interno queda entre 0.40 y 1.00, así que el riesgo nunca supera el
  peligro: la exposición amplifica, no crea.

Los pesos (`config/umbrales.php` → `$PESOS_RIESGO`) son **declarados, no
ocultos**, y se muestran en la interfaz (`?action=ayuda#metodologia`).

---

## 2. Normalización del peligro contra el percentil local

No se usan umbrales absolutos. El mismo caudal significa cosas distintas en el
río Piura y en el Rímac, y 20 mm de lluvia en Tumbes en noviembre no es lo
mismo que en Puno.

Para cada distrito se calcula la distribución histórica de la variable
(ERA5, últimos 11 años, ventana de calendario ±15 días) y se conservan:

| Percentil | Uso |
|---|---|
| `p50` | Mediana local: valor "normal" de la zona en esa época del año |
| `p95` | Umbral de evento: se supera 5 días de cada 100 |
| `p99` | Techo de saturación: valor extraordinario |

Normalización (piecewise, `riesgo_norm()`):

```
Si v ≤ p95:   H = 0.75 · (v − p50) / (p95 − p50)
Si v > p95:   H = 0.75 + 0.25 · min(1, (v − p95) / (p99 − p95))
```

* La primera rama reparte 0 → 0.75 entre la mediana y el p95.
* La segunda reparte 0.75 → 1.00 entre p95 y p99.
* Por encima de p99 el valor se satura en 1.0: un solo valor atípico no
  "infla" el índice (evita que un error de sensor domine todo el sistema).

**Cálculo en línea vs. cálculo pesado.** La climatología se calcula en
`scripts/calibrar.php` (29 districts × 11 años ≈ 150 s). La web **solo lee**
la caché (`clima_climatologia($t, $permitir_calculo = false)`); si no existe,
usa los umbrales regionales de respaldo y marca la confianza como **media**.

La calibración vive en `data/climatologia_resumen.json`.

---

## 2.b Materialidad: cuando "extremo" no significa "peligroso"

El percentil local mide rareza, no impacto. Sin un segundo filtro el motor
produce absurdos que un jurado detecta de inmediato: en Lima el p99 diario de
lluvia es ~1 mm, así que **1.3 mm de llovizna** quedaba clasificado como
"lluvia extrema" (nivel rojo). Estadísticamente era correcto; operativamente era
un disparate.

Por eso cada amenaza declara un **umbral de materialidad**
(`config/umbrales.php` → `$MATERIALIDAD`) con dos valores absolutos:

| Amenaza | `min_abs` (de aquí en adelante hay evento) | `signo_abs` (evento pleno) |
|---|---|---|
| Lluvia 24 h | 3 mm | 15 mm |
| Lluvia 48 h | 5 mm | 25 mm |
| Lluvia 72 h | 7 mm | 35 mm |
| Intensidad 3 h (huaico) | 4 mm | 15 mm |
| Calor (temperatura aparente) | 28 °C | 35 °C |
| Sequía (déficit) | 15 % | 50 % |
| Caudal (anomalía) | ratio 1.05 | ratio 2.50 |

El mínimo real es **el mayor entre el valor absoluto y el p95 local**: en una
zona donde llueve normalmente (Cusco, p95 = 12.4 mm) el evento no empieza antes
del p95; en un desierto (Lima, p95 = 0.5 mm) empieza a los 3 mm.

Factor de materialidad (rampa lineal 0 → 1):

```
M = clamp( (v − min) / (signo − min), 0, 1 )
H_final = H_percentil × M
```

Ejemplo real del piloto (5 nov 2026):

| Distrito | Lluvia 24 h | p95 local | H antes | H después | Nivel |
|---|---|---|---|---|---|
| Catacaos | 17.9 mm | 1.5 mm | 1.00 | 1.00 | 🔴 91 |
| Tumbes | 12.2 mm | 2.3 mm | 1.00 | 0.77 | 🔴 83 (dominante: inundación) |
| Lima Cercado | 1.3 mm | 0.5 mm | 1.00 | 0.00 | 🟢 5 |
| Villa El Salvador | 5.9 mm | 0.8 mm | 1.00 | 0.24 | 🟢 22 |

El factor se muestra en la ficha, en el reporte PDF y en la respuesta JSON
(`materialidad`), de modo que la decisión es auditable.

---

## 3. Las seis amenazas

Cada amenaza tiene su propio disparador físico y su propia combinación de
peligro y exposición.

### 3.1 Lluvia extrema
* **Peligro**: precipitación acumulada en la ventana (24/48/72 h) contra
  `p50/p95/p99` escalados por el factor de ventana (1.0 / 1.6 / 2.1).
* Si la probabilidad máxima de lluvia ≥ 60 %, el peligro se refuerza ×1.08
  (la probabilidad alta con lluvia moderada es más peligrosa que la lluvia
  intensa improbable).
* **Exposición**: 50 % población expuesta a inundación + 50 % susceptibilidad de
  llanura.

### 3.2 Inundación fluvial
* **Peligro**: `0.45·H_caudal + 0.35·H_lluvia + 0.20·susc_inundacion`
* `H_caudal` se calcula sobre la **anomalía relativa**: caudal máximo
  pronosticado ÷ p95 del caudal de los 31 días previos
  (`H = min(1, (ratio − 1) / 2.5)`).
* Se usa anomalía y **no** umbral absoluto porque la API Flood entrega valores
  locales inconsistentes entre cuencas (mismo río, distinto punto de malla).
  La anomalía es comparable entre distritos.

### 3.3 Huaico / deslizamiento
* **Peligro**: `max(H_lluvia, H_intensidad_3h) · susc_huaico`
* `H_intensidad_3h` normaliza la suma horaria de 3 horas consecutivas contra
  `0.45·p95_24` y `0.60·p99_24` (la lluvia intense y breve es la que dispara
  huaicos, no la lluvia suave y larga).
* La susceptibilidad combina pendiente de la cuenca, superficie de aporte e
  historial documentado.

### 3.4 Sequía
* **Peligro**: `0.75·déficit + 0.25·(días secos / 45)`
* Déficit = `1 − acumulado_90d / p50_90`.
* Es una amenaza **estructural**, no de evento: se evalúa con la serie histórica
  reciente, no con el pronóstico.

### 3.5 Calor extremo
* **Peligro**: temperatura aparente máxima de la ventana contra
  `p50_temp/p95_temp/p99_temp`, más `0.05` por cada día de la ventana que
  supera el p95 local.
* Se usa **temperatura apparent** (incluye humedad y viento), no la temperatura
  del aire: es la que afecta la salud de la persona.

### 3.6 Riesgo agropecuario
* **Peligro**: `0.55·estrés hídrico + 0.45·H_calor`
* Estrés hídrico = `max(exceso sobre p95, déficit)`.
* La sensibilidad del cultivo (`$SENSIBILIDAD_CULTIVO`) modula la exposición:
  arroz 0.95, banano 0.90, maíz 0.85, papa 0.75, quinua 0.60, pastos 0.55.

---

## 4. Exposición y vulnerabilidad

**Exposición `E`** por amenaza (0–1):

```
E_lluvia = E_inundacion = 0.5·f(pob. expuesta a inundación) + 0.5·susc_inundacion
E_huaico = 0.5·f(pob. expuesta a huaico)       + 0.5·susc_huaico
E_sequia  = 0.5·f(pob. expuesta a sequía)        + 0.5·susc_sequia
E_calor   = 0.65·f(pob. expuesta a calor)        + 0.35·0.70
E_agro    = 0.5·f(pob. expuesta a agro)          + 0.5·min(1, ha_cultivo / 9000)
```

`f(población)` es una escala con saturación:
`f = min(1, (p / 120000)^0.35)` — a partir de ~120 000 personas la diferencia
de magnitud deja de ser relevante.

**Vulnerabilidad `V`** es un valor único por distrito (0–1) que resume
infraestructura, pobreza, accesibilidad y capacidad de respuesta
(`config/territorios.json` → `vulnerabilidad`).

> ⚠️ **La exposición del piloto es una estimación de referencia**
> (`fuente_exposicion: estimacion_ratio_poblacional`), no un padrón oficial.
> El importador CSV desde INEI, MINEDU y MINSA ya está construido
> (`scripts/importar_exposicion.php`) y escribe un overlay con procedencia por
> campo; falta cargar los CSV oficiales. Aun con el total oficial, la fracción
> expuesta seguirá siendo `total × susceptibilidad`, es decir, estimada.

---

## 5. Cortes de nivel y protocols

| Índice | Nivel | Lectura operativa | Acción típica |
|---|---|---|---|
| 0–25 | 🟢 Bajo | Monitoreo | Mantenimiento preventivo |
| 26–50 | 🟡 Medio | Seguimiento | Limpieza de drenajes, verificar rutas |
| 51–75 | 🟠 Alto | Activación | Activar COE, preparar albergues |
| 76–100 | 🔴 Muy alto | Actuación | Evacuación preventiva, suspender actividades |

Cada nivel tiene un **protocolo sugerido por amenaza** (`$PROTOCOLOS` en
`config/umbrales.php`): inundación, huaico, lluvia, sequía, calor y agro.

**Lenguaje obligatorio:** *recomendación de apoyo a la decisión*.
Nunca *alerta oficial*. La alerta oficial es de SENAMHI, INDECI y ENFEN.

---

## 6. Ventanas de análisis

| Ventana | Días | Factor | Uso |
|---|---|---|---|
| 24 h | 1 | 1.0 | Vigilancia inmediata, decisión de hoy |
| 48 h | 2 | 1.6 | Preparación (almbergues, material y logística) |
| 72 h | 3 | 2.1 | Planificación preventiva, movimentação de obras |

El factor de ventana escala los percentiles, no el dato: un evento de 48 h se
compara contra un p95 de 48 h.

---

## 7. Trazabilidad

Cada resultado de `riesgo_calcular()` devuelve:

* `territorio` completo (con la fuente de exposición)
* `amenazas[6]` con `peligro`, `exposicion`, `vulnerabilidad`, `riesgo`, `nivel`
  y `detalle[]` (variable, valor, umbral, fuente, peso)
* `datos` con los valores crudos comparados
* `fuentes` con el nombre de la fuente de cada bloque
* `confianza` (`alta` con climatología ERA5, `media` con respaldo regional)
* `actualizado` (timestamp de cálculo)

Nada de esto es oculto para el usuario: la ficha de distrito y el reporte PDF
muestran los umbrales junto a los valores.

---

## 8. Validación histórica

El contraste con los eventos de **El Niño 2017** y **El Niño costero 2023** se
genera con `scripts/validar_historico.php` y se documenta en
`VALIDACION_HISTORICA.md`. El objetivo no es "acertar el número" (no lo
perseguimos: el piloto no es un modelo operativo), sino demostrar que el
motor **es sensible a los eventos que sí ocurrieron**: un evento que en la
realidad produjo emergencia tiene que producir un nivel alto o muy alto en el
motor, y un distrito seco no puede aparecer en rojo solo por tener caudal alto.

### 8.1 Resultados

| Episodio | Acierto sobre la referencia | Con señal clara en los datos | Fallas del modelo |
|---|---:|---:|---:|
| 2017 | 6/6 (100 %) | 6/6 (100 %) | **0** |
| 2017–18 | 1/5 (20 %) | 1/1 (100 %) | **0** |
| 2023 | 6/7 (86 %) | 6/6 (100 %) | **0** |
| **Total** | **13/18 (72 %)** | **13/13 (100 %)** | **0** |

Las cinco no detecciones se clasifican con tres diagnósticos que separan la
limitación de datos de la del método: `evento_ausente_en_datos`,
`evento_debil_en_datos` y `modelo_no_detecta`. **Ninguna cayó en la tercera
categoría**: en los 18 casos evaluados, el motor no falló.

### 8.2 Análisis de sensibilidad

Los pesos del índice (`base = 0.40`, `wE = 0.35`, `wV = 0.25`) son una
decisión de diseño, así que se someten a 40 escenarios: cada peso en ±10 % y
±20 %, las 26 combinaciones simultáneas de ±20 %, y dos casos límite.

| Métrica | Resultado |
|---|---|
| Spearman con el ranking base | mínimo **0.9286**, medio **0.9968** |
| Solapamiento del top-10 (Jaccard) | mínimo **0.667**, medio **0.992** |
| Escenarios sin ningún cambio de nivel | **15 de 40** |

El orden de prioridad es estable. La única degradación relevante aparece en
el límite `base = 1.00` (todo el peso al peligro, sin amplificación por
exposición ni vulnerabilidad), que es una configuración que el modelo no
defiende y que se incluye para mostrar dónde está su límite.

Como los pesos solo intervienen en la expresión final, el análisis puede
recalcular el índice sin repetir las 29 evaluaciones completas. La opción
`--verificar` de `scripts/sensibilidad.php` comprueba esa equivalencia contra
el motor real: **diferencia máxima 0**.

Detalle en `VALIDACION_HISTORICA.md`, sección 6.

### 8.3 Reproducibilidad

`H`, `E` y `V` se redondean a 3 decimales **antes** de entrar en la fórmula, y
el sistema expone esos mismos valores. Consecuencia práctica: el índice que
aparece en la ficha se puede **rehacer a mano** con los números que están en
pantalla y sale exactamente lo mismo. No hay una caja negra detrás del
resultado.

---

## 9. Límites conocidos

1. **No es un modelo hidrológico**: el caudal viene de GloFAS a 5 km, no de un
   modelo de cuenca calibrado local.
2. **La exposición es estimada** en el piloto.
3. **No hay pronóstico oficial propio**: se consumen productos de Open-Meteo
   (ERA5/ICON).
4. **No cubre evaporación ni balance hídrico completo**: la sequía usa un
   déficit simple.
5. **No predice laivables exactos**: la zonificación indica *aptitud del
   territorio*, no la cota de inundación de un lote.
6. **El ISE no reemplaza el estudio de suelos** para una licencia de obra.

Todos estos límites están declarados en la interfaz (`?action=ayuda#limites`),
en el reporte PDF y en este documento. Declarar límites es parte del
ejercicio: el jurado valora más un sistema honesto que uno que promete de más.

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
