# API pública — WILLAQ

> Cuatro endpoints JSON, sin autenticación, con CORS abierto.
> Diseñados para integrarse en un panel externo, un chatbot o un reporte
> automático.

> **Aviso:** WILLAQ expone **recomendaciones de apoyo a la decisión**. Ningún
> endpoint emite ni sustituye una alerta oficial (SENAMHI, INDECI, ENFEN).

---

## 1. Convenciones

| Aspecto | Comportamiento |
|---|---|
| Base | `http://<host>/?action=<endpoint>` |
| Formato | JSON (`application/json; charset=utf-8`) |
| Autenticación | No requerida (consulta pública) |
| CORS | `Access-Control-Allow-Origin: *` |
| Caché | `no-store` (el dato cambia con la meteorología) |
| Errores | `{"error": "..."}` con código HTTP 400, 404 o 500 |

```bash
# Todos los ejemplos de esta documentación son ejecutables tal cual
BASE="http://localhost:8000"
curl "$BASE/?action=api_territorios"
```

---

## 2. `api_territorios` — catálogo territorial

```bash
curl "$BASE/?action=api_territorios"
```

Devuelve el catálogo completo de distritos piloto.

```json
{
  "meta": {
    "descripcion": "Catálogo piloto WILLAQ. Población e infraestructura son ESTIMACIONES de referencia para la demo; reemplazables con el importador CSV (INEI/MINEDU/MINSA) sin tocar código.",
    "version": "1.0",
    "actualizado": "2026-11-05",
    "fuente_exposicion": "estimacion_ratio_poblacional",
    "nota": "susc_* = susceptibilidad estática 0-1 calibrada con inventario histórico 2000-2025. No es un modelo hidrológico."
  },
  "total": 29,
  "territorios": [
    {
      "id": "piu-catacaos",
      "pais": "Perú",
      "region": "Piura",
      "provincia": "Piura",
      "distrito": "Catacaos",
      "lat": -5.2667,
      "lon": -80.6833,
      "altitud": 22,
      "zona": "costa_norte",
      "poblacion": 70000,
      "viviendas": 18000,
      "escuelas": 120,
      "centros_salud": 8,
      "km_vias": 260,
      "hectareas_cultivo": 6500,
      "vulnerabilidad": 0.8,
      "susc_inundacion": 0.95,
      "susc_huaico": 0.15,
      "susc_sequia": 0.5,
      "pendiente": 2,
      "cuenca_km2": 7400,
      "umbral_caudal": 800,
      "cultivo": "arroz",
      "albergues_capacidad": 3500,
      "poblacion_expuesta_inundacion": 66500,
      "poblacion_expuesta_huaico": 10500,
      "poblacion_expuesta_sequia": 28000,
      "poblacion_expuesta_calor": 42000,
      "poblacion_expuesta_agro": 54000,
      "region_slug": "piura",
      "nombre_completo": "Catacaos, Piura (Piura)",
      "fuente_exposicion": "estimacion_ratio_poblacional"
    }
  ]
}
```

**Nota de honestidad:** sin overlay, `fuente_exposicion` es
`estimacion_ratio_poblacional` y los campos `poblacion`, `viviendas`,
`escuelas` y `centros_salud` son **estimaciones de referencia**, no cifras
oficiales. Si se cargó el overlay oficial, `meta.exposicion_oficial` resume la
procedencia y cada territorio trae su bloque `exposicion`:

```json
"exposicion": {
  "estado": "mixta",
  "campos": {
    "poblacion": {
      "valor": 70000,
      "oficial": true,
      "fuente": "INEI Censo 2017",
      "anio": 2017,
      "archivo": "inei_poblacion.csv",
      "sha256": "…",
      "importado_en": "2026-11-06T…"
    },
    "escuelas": {
      "valor": 120,
      "oficial": false,
      "fuente": "",
      "anio": null,
      "nota": "estimación de referencia del catálogo piloto"
    }
  },
  "expuestos": {
    "inundacion": {
      "valor": 66500,
      "estimado": true,
      "metodo": "población total × susceptibilidad a inundación (0.95)"
    }
  }
}
```

`estado` es `estimada`, `mixta` u `oficial` según cuántos campos vienen del
overlay. Aunque el total sea oficial, **la fracción expuesta siempre es
estimada** (`total × susceptibilidad`), y `expuestos.*.estimado` lo declara.

---

## 3. `api_riesgo` — riesgo de uno o de todos los distritos

```bash
# Nacional (los 29 distritos, ordenados por riesgo descendente)
curl "$BASE/?action=api_riesgo"

# Un distrito
curl "$BASE/?action=api_riesgo&id=piu-catacaos"

# Otra ventana de análisis
curl "$BASE/?action=api_riesgo&id=piu-catacaos&ventana=72"
```

| Parámetro | Obligatorio | Por defecto | Valores |
|---|---|---|---|
| `id` | no | — | id de territorio (`piu-catacaos`, `tum-tumbes`, …) |
| `ventana` | no | `24` | `24`, `48`, `72` |

> **Nacional vs. individual: no devuelven lo mismo, y es intencional.**
>
> * **Sin `id`** devuelve los **29 distritos desde el snapshot** (el archivo
>   precalculado de la ventana pedida). Es **reproducible**: devuelve lo mismo
>   siempre que no se regenere el snapshot.
> * **Con `id`** **recalcula ese distrito en el momento**, con los datos
>   vivos. La ventana de 24 h avanza con el reloj, así que el valor **se mueve
>   durante el día**: el mismo Catacaos que a las 23:57 marcaba 90.9 con
>   17.9 mm acumulados marcaba 37.6 con 7.6 mm una hora y media después,
>   porque la ventana ya había desplazado el intervalo y parte de la lluvia
>   había salido de él.
>
> Para una demostración o una captura reproducible, usa la forma **nacional**
> (o el snapshot). Para consultar el estado actual de un distrito, usa la
> forma **con `id`**.

### Respuesta nacional

```json
{
  "ventana": 24,
  "generado_en": 1793923036,
  "exposicion": {
    "estado_global": {
      "total_territorios": 29,
      "con_datos_oficiales": 0,
      "por_campo": { "poblacion": 0, "viviendas": 0, "escuelas": 0, "centros_salud": 0 },
      "fuentes": [],
      "version": 0
    }
  },
  "riesgos": [ { "...": "un objeto de riesgo por distrito, idéntico al de la versión individual" } ]
}
```

### Respuesta individual

> Los valores de este ejemplo corresponden al **snapshot del 5 de noviembre de
> 2026, 23:57** (`data/snapshots/riesgo_nacional_24h.json`), para que sean
> verificables contra un archivo concreto del repositorio.

```json
{
  "territorio": { "id": "piu-catacaos", "distrito": "Catacaos", "...": "..." },
  "ventana": 24,
  "actualizado": 1793923036,
  "confianza": "alta",
  "fuente_climatologia": "ERA5 · 2015-2025 · ventana ±15 días",

  "materialidad": {
    "lluvia":        { "min_mm": 3,  "signo_mm": 15, "factor": 1 },
    "intensidad_3h": { "min_mm": 4,  "signo_mm": 15, "factor": 0.28 },
    "calor":         { "min_c": 28,  "signo_c": 35,   "factor": 0.99 },
    "sequia":        { "min_deficit": 0.15, "signo_deficit": 0.5, "factor": 0 }
  },

  "escenario": { "factor_lluvia": 1, "delta_temp": 0, "factor_caudal": 1 },

  "amenazas": {
    "lluvia": {
      "id": "lluvia",
      "nombre": "Lluvia extrema",
      "icono": "lluvia",
      "peligro": 1,
      "exposicion": 0.882,
      "vulnerabilidad": 0.8,
      "riesgo": 90.9,
      "nivel": { "min": 76, "max": 100, "id": "rojo", "etiqueta": "Muy alto", "color": "#ef4444" },
      "detalle": [
        {
          "variable": "Precipitación acumulada 24 h",
          "valor": 17.9,
          "unidad": "mm",
          "umbral": 1.5,
          "fuente": "Open-Meteo",
          "peso": 1
        },
        {
          "variable": "Probabilidad máxima de lluvia",
          "valor": 100,
          "unidad": "%",
          "umbral": 60,
          "fuente": "Open-Meteo",
          "peso": 0.3
        },
        {
          "variable": "Factor de materialidad",
          "valor": 1,
          "unidad": "×",
          "umbral": 3,
          "fuente": "Regla de materialidad",
          "peso": 1
        }
      ]
    },
    "inundacion": { "...": "misma estructura" },
    "huaico":      { "...": "misma estructura" },
    "sequia":      { "...": "misma estructura" },
    "calor":       { "...": "misma estructura" },
    "agro":        { "...": "misma estructura" }
  },

  "principal": "lluvia",
  "riesgo": 90.9,
  "nivel": { "min": 76, "max": 100, "id": "rojo", "etiqueta": "Muy alto", "color": "#ef4444" },

  "resumen": "Lluvia de 17.9 mm en la ventana analizada frente a un umbral de referencia de 1.5 mm (p95 histórico). Población expuesta estimada: 70 mil habitantes.",

  "datos": {
    "lluvia_ventana": 17.9,
    "lluvia_threshold_p95": 1.5,
    "prob_lluvia": 100,
    "caudal_pico": 0,
    "caudal_p95_pasado": 0,
    "caudal_ratio": 0.02,
    "intensidad_3h": 7.1,
    "stmax": 34.9,
    "acumulado_90d": 36.4,
    "dias_sin_lluvia": 79
  },

  "fuentes": {
    "pronostico": "Open-Meteo Forecast (ERA5/ICON)",
    "caudal": "Open-Meteo Flood (GloFAS 5 km)",
    "historico": "Open-Meteo Archive (ERA5)",
    "climatologia": "ERA5 · 2015-2025 · ventana ±15 días"
  },

  "exposicion": {
    "estado": "estimada",
    "campos": { "...": "procedencia por campo: valor, oficial, fuente, año, archivo, sha256" },
    "expuestos": { "...": "población expuesta por amenaza, siempre estimada" },
    "overlay": { "...": "resumen global del overlay (igual que en api_territorios)" }
  }
}
```

### Cómo interpretarlo

1. **`riesgo`** es el índice 0–100 del distrito; **`nivel.id`** es la banda
   (`verde`, `amarillo`, `naranja`, `rojo`).
2. **`materialidad`** explica el filtro: si `lluvia.factor` es `0`, la lluvia
   no es un evento para ese distrito por muy alto que sea su percentil.
3. **`amenazas[].detalle[]`** es la trazabilidad: cada variable con su valor,
   el umbral con el que se comparó y su fuente. Con esos datos se puede
   **rehacer el cálculo a mano**.
4. **`datos`** contiene las magnitudes crudas (mm, m³/s, °C) para quien quiera
   construir su propia visualización.
5. **`fuentes`** declara de dónde sale cada cosa. No hay números sin origen.
6. **`exposicion`** separa el **total** (puede ser oficial si hay overlay) de
   la **fracción expuesta** (siempre estimada). `estado` es `estimada`, `mixta`
   u `oficial` según cuántos campos vengan del overlay.
6. **`principal`** es el **id** (string) de la amenaza dominante, no un objeto:
   para llegar a sus datos, `amenazas[principal]`.

### Reproducir el índice con una calculadora

El motor redondea `peligro`, `exposicion` y `vulnerabilidad` a 3 decimales
**antes** de la fórmula, y expone esos mismos valores. Así que el índice es
reproducible **a mano**, sin el sistema:

```
R = 100 · peligro · (0.40 + 0.35 · exposicion + 0.25 · vulnerabilidad)
```

Para el ejemplo de arriba (`lluvia` en Catacaos):

```
R = 100 · 1 · (0.40 + 0.35 · 0.882 + 0.25 · 0.8)
  = 100 · 1 · (0.40 + 0.3087 + 0.2)
  = 90.87  →  90.9   ✓ el mismo valor que devuelve la API
```

El índice **no** se calcula con más precisión que la que se muestra: lo que se
ve en pantalla es literalmente lo que entra en la cuenta. Si el número no
cuadra, es un error de implementación, no un efecto del redondeo.

### Errores

```json
{ "error": "Territorio no encontrado" }   // HTTP 404
```

---

## 4. `api_simular` — escenarios

```bash
# Modo clima: multiplicador de lluvia y delta de temperatura
curl "$BASE/?action=api_simular&id=piu-catacaos&lluvia=2&temp=1&modo=clima"

# Modo evacuación
curl "$BASE/?action=api_simular&id=piu-catacaos&modo=evacuacion"
```

| Parámetro | Obligatorio | Por defecto | Valores |
|---|---|---|---|
| `id` | **sí** | — | id de territorio |
| `modo` | no | `clima` | `clima`, `evacuacion` |
| `lluvia` | no | `1.5` | multiplicador (1.0 = sin cambio) |
| `temp` | no | `0` | incremento en °C |
| `ventana` | no | `24` | `24`, `48`, `72` |

**Modo clima** devuelve el índice recalculado bajo el escenario, las seis
amenazas reevaluadas y la comparación contra la situación actual (cuánto sube
el riesgo y en qué amenaza).

**Modo evacuación** cruza población expuesta, capacidad de salida por vía
principal (1 200 personas/h, supuesto documentado) y albergues declarados, y
señala el déficit de capacidad.

> El modo `reubicacion` está disponible en la interfaz
> (`?action=simulador&modo=reubicacion`) pero **no** está expuesto por la API:
> requiere el punto de origen, no solo el distrito.

---

## 5. `api_evaluar` — evaluación de un sitio

```bash
curl "$BASE/?action=api_evaluar&lat=-5.2667&lon=-80.6833&tipo=colegio"
```

| Parámetro | Obligatorio | Por defecto | Valores |
|---|---|---|---|
| `lat` | **sí** | — | latitud decimal |
| `lon` | **sí** | — | longitud decimal |
| `tipo` | no | `colegio` | `colegio`, `hospital`, `vivienda`, `planta_agua`, `albergue`, `carretera` |

```json
{
  "lat": -5.2667,
  "lon": -80.6833,
  "tipo": "colegio",
  "tipo_etiqueta": "Colegio / institución educativa",
  "territorio": { "id": "piu-catacaos", "distancia_km": 0, "...": "..." },
  "ise": 36.1,
  "ise_base": 39.4,
  "clase": { "id": "C", "nombre": "Restringido", "color": "#f97316" },
  "veredicto": "RESTRINGIDO — requiere estudio específico",
  "recomendaciones": [
    "Elevar la cota de cimentación por encima de la línea de inundación histórica.",
    "Diseñar drenaje pluvial de capacidad superior al evento de 100 años.",
    "Requiere estudio de riesgo específico y obras de mitigación antes de la licencia."
  ],
  "razones": [
    {
      "factor": "Inundación",
      "severidad": "crítica",
      "peso": 0.35,
      "detalle": "Llanura de inundación recurrente; eventos documentados en 2017 y 2023."
    },
    {
      "factor": "Vulnerabilidad social",
      "severidad": "alta",
      "peso": 0.2,
      "detalle": "Alta vulnerabilidad estructural: capacidad de respuesta local limitada."
    }
  ],
  "distancia_km": 0,
  "generado_en": 1793924414
}
```

**Clases de aptitud (ISE):**

| Clase | Significado | Color |
|---|---|---|
| **A** | Apto | verde |
| **B** | Apto con condiciones | amarillo |
| **C** | Restringido | naranja |
| **D** | No apto | rojo |

> La clase A significa "el lugar más seguro dentro de lo evaluable", **no**
> "construir aquí". Una obra exige estudio de suelos y de riesgo que WILLAQ no
> sustituye.

---

## 6. Uso responsable

* Los datos de exposición del piloto son **estimaciones**. Si se usa la API
  para decidir, hay que sustituir la fuente (`fuente_exposicion`).
* El caudal es de GloFAS a 5 km, **no** de un modelo de cuenca calibrado.
* Los percentiles son **locales**: comparar el índice de un distrito con el de
  otro no significa comparar su meteorología.
* El sistema no emite alertas oficiales. Si se cita su salida, citarlo como
  **recomendación de apoyo a la decisión**.

---

## 7. Verificación rápida

```bash
for ep in "api_territorios" "api_riesgo" "api_riesgo&id=piu-catacaos" \
          "api_simular&id=piu-catacaos" "api_evaluar&lat=-5.2667&lon=-80.6833&tipo=colegio"; do
  printf "%-56s %s\n" "$ep" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?action=$ep")"
done
```

Los cinco deben responder `200`.

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
