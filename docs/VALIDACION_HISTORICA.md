# Validación histórica de WILLAQ

> ¿El motor de riesgo habría detectado los episodios de El Niño que de verdad
> ocurrieron en el Perú?
>
> **Script:** `scripts/validar_historico.php` · **Salida:** `data/validacion_historica.json`
> **Serie:** ERA5 (Open-Meteo Archive), 2015–2025 · **Generado:** 5 de noviembre de 2026

---

## 1. Por qué este documento existe

Un índice de riesgo que nunca se pone a prueba es una fórmula bonita. Este
documento somete el motor a los tres episodios de El Niño costero registrados
en la última década y responde con números, no con adjetivos.

Lo que **no** se busca: "acertar el número". El piloto no es un modelo
operativo y no pretende serlo. Lo que se busca es demostrar dos cosas:

1. **Sensibilidad:** si en la realidad un evento produjo emergencia, el motor
   tiene que producir un nivel alto o muy alto.
2. **Honestidad:** cuando el motor falla, hay que decir por qué falló y
   distinguir si la culpa es del **modelo** o de la **fuente de datos**.

El segundo punto es el que distingue un ejercicio académico serio de uno
decorativo.

---

## 2. Método

No se reimplementa nada: **se corre el motor de riesgo sobre el pasado**.

1. Se descarga la serie ERA5 de los últimos 11 años para los 29 distritos.
2. Dentro de la ventana de cada evento se evalúan **tres escalas de
   acumulación**, tomando el máximo de cada una:

   | Escala | Qué captura | Umbral de materialidad |
   |---|---|---|
   | Pico diario | Aguacero convectivo intenso | 3 mm → 15 mm |
   | Acumulado 3 días | Lluvia sostenida (equivale a la ventana 72 h del motor) | 7 mm → 35 mm |
   | Acumulado 30 días | Persistencia del episodio | 60 mm → 200 mm |

   La ventana de N días debe caber **entera** dentro del evento: una ventana
   de 30 días que empieza el 20 de febrero y termina el 22 de marzo incluye
   marzo, que no pertenece al episodio.
3. Cada escala se normaliza contra la distribución del **mismo periodo del
   calendario** (±15 días) de los 11 años, con la misma función de producción
   `riesgo_indice_lluvia()`:

   ```
   H = riesgo_norm(valor, p50, p95, p99) × rampa_materialidad(valor, min, signo)
   R = 100 · H · (0.40 + 0.35·E + 0.25·V)
   ```

4. Se conserva la escala de mayor índice y se compara contra la
   **referencia documentada**: los distritos con emergencia registrada en
   cada episodio.

> La función `riesgo_indice_lluvia()` vive en `models/Riesgo.php` y la usan
> tanto el motor en producción como esta validación. Si la fórmula cambia,
> cambia en los dos lados a la vez. No hay dos verdades.

---

## 3. Cómo se diagnostica una no detección

Cuando un distrito con emergencia documentada **no** es detectado, la causa
puede ser muy distinta, y el script la clasifica con reglas fijadas por
adelantado:

| Diagnóstico | Regla | Significado |
|---|---|---|
| `evento_ausente_en_datos` | Ninguna escala supera su p95 local | El reanálisis **no registra** el episodio. Ningún modelo que use esta fuente puede detectarlo. |
| `evento_debil_en_datos` | Alguna escala supera el p95 en **menos del 50 %** | La anomalía está dentro de la variabilidad interanual ordinaria: el reanálisis **suaviza** el episodio. |
| `modelo_no_detecta` | Alguna escala supera el p95 en **más del 50 %**, pero el índice no llegó al umbral | Esto sí es una **falla del modelo** y hay que corregirla. |

El umbral de 50 % separa "el dato dice que fue un año más lluvioso" de "el
dato dice que fue un episodio extraordinario".

---

## 4. Resultados

### 4.1 El Niño costero 2017 (enero–marzo 2017)

| Métrica | Valor |
|---|---|
| Distritos señalados en alto/muy alto | **20 de 29** |
| Acierto sobre la referencia documentada | **6 de 6 (100 %)** |
| Episodios ausentes del reanálisis | 0 |
| Episodios débiles en el reanálisis | 0 |
| **Fallas del modelo** | **0** |

Los seis distritos con emergencia documentada, con su posición en el ranking
de 29:

| Pos. | Distrito | Escala | Valor | p95 local | Ratio | Índice |
|---:|---|---|---:|---:|---:|---:|
| 2 | Sullana | Pico diario | 105.3 mm | 22.42 mm | 4.70× | **91.4 · Muy alto** |
| 3 | Catacaos | Pico diario | 138.1 mm | 19.20 mm | 7.19× | **90.9 · Muy alto** |
| 4 | Tumbes | Pico diario | 67.1 mm | 21.30 mm | 3.15× | **90.2 · Muy alto** |
| 5 | Piura | Pico diario | 130.6 mm | 17.50 mm | 7.46× | **88.9 · Muy alto** |
| 7 | Tambogrande | Pico diario | 130.2 mm | 42.43 mm | 3.07× | **87.6 · Muy alto** |
| 12 | Chulucanas | Pico diario | 119.8 mm | 20.30 mm | 5.90× | **81.2 · Muy alto** |

Cinco de los seis quedan en los **primeros 7 lugares** de 29. Es el resultado
principal: el motor no solo detectó el episodio, lo sitúa en el lugar correcto
del ranking, que es lo que permite priorizar la respuesta.

### 4.2 El Niño costero 2017–2018 (diciembre 2017 – febrero 2018)

| Métrica | Valor |
|---|---|
| Distritos señalados en alto/muy alto | 2 de 29 |
| Acierto sobre la referencia documentada | 1 de 5 (20 %) |
| Acierto sobre los distritos con señal clara | **1 de 1 (100 %)** |
| Episodios ausentes del reanálisis | **3** |
| Episodios débiles en el reanálisis | 1 |
| **Fallas del modelo** | **0** |

| Distrito | Diagnóstico | Ratio máx. | Total del episodio | Índice |
|---|---|---:|---:|---:|
| Tumbes | **Detectado** (acumulado 3 días, 57.2 mm vs p95 33.3 mm) | 2.02× | — | **80.1 · Muy alto** |
| Piura | `evento_debil_en_datos` | 1.42× | 40.8 mm | 8.4 · Bajo |
| Catacaos | `evento_ausente_en_datos` | 0.77× | 23.7 mm | 0 · Bajo |
| Tambogrande | `evento_ausente_en_datos` | 0.49× | 144.1 mm | 0 · Bajo |
| Sullana | `evento_ausente_en_datos` | 0.72× | 59.1 mm | 0 · Bajo |

**Este es el resultado que más conviene mirar de cerca**, porque es donde un
sistema poco honesto escondería la cabeza.

Comprobación independiente: los acumulados de campaña DIC–FEB en ERA5 para
Piura son 92 (2015), **289 (2016)**, 41 (2017), 164 (2018), 34, 49, 50, 72,
146, 104, 11 mm. La campaña DIC-2016–FEB-2017 — que termina con la crecida
del río Piura de marzo 2017 — es **el máximo de toda la serie**, y sí se
detecta. La campaña DIC-2017–FEB-2018, en cambio, es **más seca que la
mediana** en ERA5, en todos los distritos de referencia salvo Tumbes.

Es decir: **ERA5 a 25 km no registra el episodio de finales de 2017–principios
de 2018 en la costa norte**. No es que el motor fallara: es que la señal no
está en el dato de entrada. La única excepción, Tumbes, sí aparece — con un
pico de 3 días — y el motor la detecta en el **primer lugar del ranking**.

Consecuencia operativa, y es una conclusión accionable: **para estas cuencas
el uso de ERA5 como única fuente es insuficiente**. Un sistema operativo
necesitaría las estaciones de SENAMHI (PISCO) y de la ANA. La arquitectura de
caché de WILLAQ
ya está preparada para admitir fuentes alternativas (ver `docs/ARQUITECTURA.md`,
sección 5).

### 4.3 El Niño costero 2023 (diciembre 2022 – marzo 2023)

| Métrica | Valor |
|---|---|
| Distritos señalados en alto/muy alto | 15 de 29 |
| Acierto sobre la referencia documentada | **6 de 7 (86 %)** |
| Acierto sobre los distritos con señal clara | **6 de 6 (100 %)** |
| Episodios ausentes del reanálisis | 0 |
| Episodios débiles en el reanálisis | 1 |
| **Fallas del modelo** | **0** |

| Pos. | Distrito | Escala | Valor | p95 local | Ratio | Índice |
|---:|---|---|---:|---:|---:|---:|
| 2 | Catacaos | Pico diario | 37.2 mm | 20.50 mm | 1.81× | **90.9 · Muy alto** |
| 3 | Piura | Pico diario | 37.3 mm | 19.70 mm | 1.89× | **88.9 · Muy alto** |
| 11 | Paita | Pico diario | 20.7 mm | 10.20 mm | 2.03× | **71.5 · Alto** |
| 12 | Sullana | Pico diario | 35.0 mm | 22.75 mm | 1.54× | **70.8 · Alto** |
| 14 | Tumbes | Pico diario | 48.1 mm | 32.89 mm | 1.46× | **66.1 · Alto** |
| 15 | Aguas Verdes | Pico diario | 48.0 mm | 33.80 mm | 1.42× | **52.5 · Alto** |
| 20 | Zarumilla | `evento_debil_en_datos` | 44.7 mm | 35.58 mm | 1.26× | 30.3 · Medio |

Zarumilla es el caso interesante: su p95 local de 35.58 mm/día es
extraordinariamente alto, porque su ventana de calendario incluye días muy
húmedos de otros años de la serie. Un valor de 44.7 mm es un 26 % por encima
de ese p95: **estadísticamente notable, materialmente no extremo**. El motor
dice "Medio" y eso es defendible. Documentarlo es preferible a maquillar el
resultado.

### 4.4 Resumen consolidado

| Episodio | Señalados | Recall global | Recall con señal clara | Ausente | Débil | **Falla del modelo** |
|---|---:|---:|---:|---:|---:|---:|
| 2017 | 20/29 | 6/6 (100 %) | 6/6 (100 %) | 0 | 0 | **0** |
| 2017–18 | 2/29 | 1/5 (20 %) | 1/1 (100 %) | 3 | 1 | **0** |
| 2023 | 15/29 | 6/7 (86 %) | 6/6 (100 %) | 0 | 1 | **0** |
| **Total** | — | **13/18 (72 %)** | **13/13 (100 %)** | 3 | 2 | **0** |

La línea que importa es la última: **sobre los distritos donde la fuente de
datos sí registra el episodio, el motor acierta 13 de 13**. Las cinco
no detecciones son, en todos los casos, ausencia o debilidad de la señal en
ERA5.

---

## 5. Sobre la sobre-señalización

El motor señaló más distritos de los que tienen emergencia documentada: 14
adicionales en 2017 y 9 en 2023. Esto **no es un error**, y conviene
explicarlo:

* **7 de los 14** señalados en 2017 (Veintiséis de Octubre, Castilla,
  Corrales, Zarumilla, Paita, La Cruz, Aguas Verdes) pertenecen al mismo
  sistema fluvial costero norte que los distritos de referencia. Comparten
  cuenca, allí las lluvias y los desbordes fueron **sistemáticos**, no
  localised a una cabecera. Señalarlos no es sobre-señalización: es
  reconocer que el riesgo afecta a todo el sistema, no solo a los seis
  distritos donde se levantó el acta.
* El resto (Lurigancho, Chaclacayo, Santa Eulalia, San Mateo, Villa El
  Salvador, Puno) tiene una dinámica distinta. Su índice lo empuja la
  **exposición y la vulnerabilidad**, no la lluvia: en Lima y la sierra el
  detector de lluvia se activa por umbrales bajos y el peso recae en la
  concentración poblacional y la fragilidad de infraestructura. Por eso la
  recomendación que el sistema emite para ellos es **seguimiento**, no
  evacuación.

**Límite explícito de la precisión:** este documento no calcula precisión
contra una etiqueta oficial, porque en el piloto no existe un padrón oficial
de «qué ocurrió una emergencia en cada distrito». Sin esa etiqueta, la
precisión no es una métrica defendible y no se afirma.

---

## 6. Análisis de sensibilidad

> **Script:** `scripts/sensibilidad.php` · **Salida:** `data/sensibilidad.json`

### 6.1 La pregunta

El ranking de riesgo depende de tres pesos elegidos por el equipo:

```
R = 100 · H · (base + wE·E + wV·V)
     base = 0.40   wE = 0.35   wV = 0.25
```

Si el ranking se mueve cuando los pesos se mueven, entonces el ranking es
una decisión de los parámetros, no del territorio. Esa es la objeción legítima
que un jurado tiene derecho a hacer, así que se responde con números.

### 6.2 Método

Los pesos solo intervienen en la expresión final: el peligro `H` y la
exposición `E` no dependen de ellos. Por eso el script:

1. calcula **una vez** el riesgo de los 29 distritos con el motor real y
   extrae `H`, `E` y `V` de las seis amenazas;
2. recalcula el índice para cada escenario con aritmética pura.

Es idéntico a reejecutar el motor con otros pesos, pero tarda segundos en
lugar de minutos. La equivalencia no se supone: la opción `--verificar`
reejecuta el motor real con un escenario completo y compara. El resultado es
**diferencia máxima 0** en los 29 distritos.

> Por qué es exactamente 0: el motor redondea `H`, `E` y `V` a 3 decimales
> **antes** de la fórmula, y expone esos mismos valores. Lo que se muestra en
> pantalla es literalmente lo que entra en el cálculo, así que un auditor
> puede rehacer la cuenta a mano y obtener el mismo índice.

### 6.3 Escenarios evaluados (40)

| Familia | Escenarios | Qué prueba |
|---|---:|---|
| Cada peso por separado | 12 (±10 %, ±20 %) | Sensibilidad individual |
| Combinaciones simultáneas de ±20 % | 26 | Sensibilidad conjunta |
| Límites | 2 | `base = 0` (solo E y V) y `base = 1` (todo peligro) |

### 6.4 Resultados

| Métrica | Mínimo | Medio | Máximo |
|---|---:|---:|---:|
| Spearman con el ranking base | **0.9286** | **0.9968** | 1.0000 |
| Solapamiento del top-10 (Jaccard) | **0.667** | **0.992** | 1.000 |
| Distritos que cambian de nivel | 0 | — | 10 |

Distribución de los 40 escenarios según cuántos distritos cambian de nivel:

| Cambios de nivel | Escenarios |
|---:|---:|
| 0 | **15** |
| 1 | 13 |
| 2 | 1 |
| 3 | 6 |
| 5 | 1 |
| 7 | 2 |
| 8 | 1 (límite `base = 1.00`) |
| 10 | 1 (`combo -20 %/-20 %/-20 %`) |

### 6.5 Lectura

**El orden de prioridad es estable.** En 39 de los 40 escenarios el top-10
coincide por completo (Jaccard = 1.000) y la correlación de rangos baja de
1.000 a 0.9286 como máximo. Los distritos que se mueven son, casi siempre,
los que están en la frontera entre dos niveles (25 / 50 / 75), donde un cambio
de 0.1 puntos ya cambia la etiqueta. Eso no es inestabilidad del modelo: es
que la etiqueta es discreta y el índice es continuo.

**El único escenario que degrada el top-10** es `base = 1.00` (todo el peso al
peligro, sin amplificación por exposición ni vulnerabilidad): baja a Jaccard
0.667 y 8 distritos cambian de nivel. No es una configuración defendible —un
índice que ignora a quién le afecta no puede priorizar una respuesta— y está
incluido precisamente para mostrar hasta dónde aguanta el modelo.

**La banda de posición** de los diez primeros en todo el abanico va de 1 a
15 posiciones (amplitud máxima 7). Es decir: la prioridad puede moverse
varios puestos, pero **los mismos distritos siguen siendo los prioritarios**.

### 6.6 Limitación declarada

Se variaron los **pesos del índice**, que es lo que el criterio 6 pide. No se
variaron:

* los percentiles de referencia (dependen de la serie ERA5 observada);
* los umbrales absolutos de materialidad (son decisiones de política de
  riesgo, no parámetros estadísticos);
* la fórmula de normalización por tramos.

Una sensibilidad completa sobre estos tres ejes exigiría recalibrar la
climatología y repetir la validación histórica completa: es trabajo de la
siguiente fase, y está declarado como tal en lugar de presented como hecho.

---

## 7. Limitaciones que este ejercicio deja al descubierto

1. **ERA5 a 25 km suaviza los extremos orográficos y convectivos.** Es la
   limitación dominante: 3 de 4 no detecciones del episodio 2017–18 se
   explican por esto. Para uso operativo en la costa norte hacen falta
   estaciones.
2. **La exposición del piloto es una estimación** (`estimacion_ratio_poblacional`),
   no un padrón INEI/MINEDU/MINSA. Un distrito con mayor población real que la
   estimada tendrá un índice subestimado.
3. **Un umbral de percentil local es menos sensible en las zonas de alta
   variabilidad** (caso Zarumilla en 2023). Es el precio de no usar un
   umbral nacional único.
4. **Solo se evaluó la amenaza de lluvia.** Inundación fluvial, huaico,
   sequía, calor y riesgo agropecuario no se validan contra eventos
   históricos independientes: no hay, para el piloto, una serie de referencia
   con la misma calidad de etiquetado.
5. **El piloto cubre 29 distritos.** Un sistema nacional con la misma
   arquitectura (misma fórmula, mismos umbrales) es una extrapolación, no una
   verificación.

---

## 8. Cómo reproducirlo

```bash
php scripts/validar_historico.php
php scripts/sensibilidad.php --verificar
```

El primero descarga la serie ERA5 (con caché), corre el motor sobre los tres
episodios y regenera `data/validacion_historica.json`. Tarda entre 2 y 5
minutos según la caché disponible.

El segundo corre los 40 escenarios de pesos, verifica la equivalencia con el
motor real y regenera `data/sensibilidad.json`. Tarda menos de un minuto.

Para auditar una decisión concreta, el JSON de validación incluye por distrito
y por escala: el valor observado, sus percentiles de referencia, el factor de
materialidad, el peligro, la exposición, la vulnerabilidad, el índice, el nivel
y el diagnóstico. El JSON de sensibilidad incluye, por escenario, el
Spearman, el solapamiento del top-10, los cambios de nivel y el top-10
resultante. No hay ninguna cifra de este informe que no sea reproducible desde
esos dos archivos.

---

## 9. Conclusión

El motor de WILLAQ, evaluado sobre los episodios de El Niño costero de 2017,
2017–2018 y 2023:

* **detecta los dos episodios grandes** (2017 y 2023) en los distritos donde
  la emergencia fue documentada, con **100 %** de acierto sobre los distritos
  con señal clara en los datos;
* **coloca los distritos de referencia en los primeros lugares del ranking**,
  que es la propiedad que realmente sirve para priorizar recursos;
* **no registra ninguna falla de modelo** en los 18 casos evaluados;
* **mantiene el orden de prioridad** ante ±20 % en los pesos del índice
  (Spearman mínimo 0.9286, top-10 idéntico en 39 de 40 escenarios);
* y **declara con precisión dónde el problema son los datos**, que es lo que
  permite diseñar la siguiente fase: incorporar estaciones de SENAMHI (PISCO)
  y de la ANA, y expandirse a las cuencas del Pilcomayo y del Apurímac.

Un sistema que dice "aquí no puedo afirmar nada porque mi fuente no lo
registra" es más útil para un comité de emergencia que uno que emite un
número con apariencia de certeza.

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
