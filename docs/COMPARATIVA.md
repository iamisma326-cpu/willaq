# Comparativa — WILLAQ frente a las herramientas existentes

> **Propósito:** responder con precisión a la pregunta que un jurado hace
> siempre — *"¿por qué no usan SENAMHI, ENFEN o Google Flood Hub?"*.
>
> **Fecha de consulta de todas las fuentes externas:** 5 de noviembre de 2026.
> Cada cifra de este documento está verificada contra la fuente oficial el día
> de esa fecha; el enlace está al lado. Las fuentes cambian: para una
> presentación posterior a esa fecha, volver a verificarlas.
>
> **Postura:** este documento **no** presenta a WILLAQ como mejor que las
> herramientas oficiales. El estado oficial de El Niño Costero a la fecha de
> corte es **«Alerta de El Niño Costero»** (Comunicado Oficial ENFEN N.° 19-2026,
> 30 de octubre de 2026), y ninguna herramienta de IA sustituye a SENAMHI.
> Lo que WILLAQ hace es **ocupar un hueco que ninguna de las cuatro cubre**:
> convertir un pronóstico y una capa de exposición en una **decisión
> territorial** (dónde no construir, qué escenario ensayar, a quién priorizar)
> con trazabilidad de cada número.

---

## 1. Las cuatro referencias

| # | Sistema | Qué es | Autoridad | URL |
|---|---|---|---|---|
| 1 | **SENAMHI** | Servicio Nacional de Meteorología e Hidrología: pronóstico, **avisos** preventivos y umbrales de inundación por estación | Organismo público adscrito al **Ministerio del Ambiente** | [senamhi.gob.pe](https://www.senamhi.gob.pe/) |
| 2 | **ENFEN** | Comisión Multisectorial que **caracteriza** El Niño/La Niña y pronostica su evolución | Creada por **D.S. N.° 007-2017-PRODUCE**; IGP (preside), IMARPE, INDECI, CENEPRED y ministerios | [enfen.imarpe.gob.pe](https://enfen.imarpe.gob.pe/) |
| 3 | **Google Flood Hub** | Pronóstico global de crecidas fluviales (7 días) y aniegos urbanos (24 h) con modelos de IA | Empresa privada (Google Research) | [sites.research.google/floodforecasting](https://sites.research.google/floodforecasting/) |
| 4 | **INDECI** | Instituto Nacional de Defensa Civil: visor geográfico, rutas de evacuación, zonas seguras, reportes de emergencia | Organismo público ejecutor adscrito al **Ministerio de Defensa**; Sistema Nacional de Gestión del Riesgo de Desastres | [geo.indeci.gob.pe/visorindeci](https://geo.indeci.gob.pe/visorindeci/) |

---

## 2. La posición oficial del Estado, y por qué importa

El Comunicado Oficial ENFEN N.° 19-2026 (30 octubre 2026) no solo mantiene la
alerta: **señala explícitamente el hueco que WILLAQ ocupa**.

> «Se exhorta a los tomadores de decisiones considerar los estudios de
> escenarios de riesgo actualizados, tomando como base los avisos
> meteorológicos y pronósticos estacionales para la reducción de riesgo de
> desastres y la preparación ante peligros inminentes.»
>
> «Se pone a disposición de los tres niveles de gobierno el uso del **Visor
> INDECI** como herramienta de soporte técnico para la gestión de
> emergencias.»
>
> — [Comunicado Oficial ENFEN N.° 19-2026](https://enfen.imarpe.gob.pe/comunicados/), 30 de octubre de 2026

Esto define el terreno con precisión:

1. El Estado emite el **aviso** (SENAMHI) y characterize el **fenómeno**
   (ENFEN).
2. El Estado **remite a las autoridades** a las herramientas de **soporte
   técnico** (Visor INDECI) y a los **estudios de escenarios de riesgo**.
3. WILLAQ es un estudio de escenarios de riesgo **a escala de distrito, con
   exposición territorial y trazabilidad completa**, que se puede instalar sin
   depender de un servidor del Estado.

Es decir: WILLAQ no compite con la capa oficial; **implementa el paso que el
propio comunicado oficial recomienda**, con un costo y una complejidad de
despliegue que permiten que un gobierno local lo use.

Además, la elección geográfica del piloto no es arbitraria: el mismo
comunicado anticipa **caudales superiores a lo normal en los ríos Tumbes,
Chira, Piura, La Leche y Jequetepeque** — las cinco cuencas del piloto
— para noviembre 2026–enero 2027.

---

## 3. Comparación por dimensión

| Dimensión | SENAMHI | ENFEN | Google Flood Hub | INDECI | **WILLAQ** |
|---|---|---|---|---|---|
| **Qué pronostica** | Lluvia, temperatura, caudal, con avisos por región | Estado oceánico y estacional de ENSO | Crecidas fluviales y aniegos urbanos | No pronostica: registra y referencia | Índice de riesgo 0–100 por distrito, 6 amenazas, 3 ventanas |
| **Amenazas cubiertas** | Lluvia, caudal, temperatura, sequedad | ENSO (agregado) | Inundación **fluvial y urbana** | Eventos, rutas, zonas seguras | **Lluvia, inundación, huaico, sequía, calor, agropecuario** |
| **Antelación** | Avisos 24 h; SAT 24–96 h | Estacional / trimestral | 7 días (fluvial) · 24 h (urbano) | En vivo | 24 / 48 / 72 h |
| **Resolución** | Estación y radar (cuencas priorizadas) | Regional (Niño 1+2, 3.4) | 5 km fluvial · **20 × 20 km** urbano | Nacional, cartografía oficial | **Distrito** (29 en el piloto) |
| **¿Usa exposición?** | No (solo peligro meteorológico) | No | Modelo de inundación (dónde se inunda, no quién) | Sí (inventario oficial) | **Sí: población, viviendas, colegios, salud, cultivo, susceptibilidad** |
| **¿Sirve para decidir dónde construir?** | No | No | No | Parcialmente (zonas seguras, rutas) | **Sí: ISE en 4 clases, veredicto A–D con razones** |
| **¿Simula escenarios?** | No | No | No | No | **Sí: clima ×2, evacuación, reubicación** |
| **Explicabilidad del número** | Umbrales por estación | Índices oceanic (ICEN, RONI) | Modelo ML (opaco) | Registros | **Variable, umbral, fuente y peso por cada cifra** |
| **Verificación publicada** | Validación operativa | Boletines e informes técnicos | *Nature* (2024) y dataset GRRR | Informes de emergencia | **Backtest 2017/2017-18/2023 + sensibilidad de 40 escenarios** |
| **API abierta** | Datos abiertos por descarga | Reportes PDF | API para expertos (acceso restringido) | Portal SINPAD | **4 endpoints JSON, CORS abierto, sin clave** |
| **Funciona sin internet** | No | No | No | No | **Sí (modo feria con snapshot por ventana)** |
| **Costo de licencia** | Gratuito | Gratuito | Gratuito | Gratuito | **Cero (PHP nativo, sin Composer)** |
| **Despliegue** | Servidor del Estado | Servidor del Estado | Nube de Google | Servidor del Estado | **Hosting compartido o `php -S`, en minutos** |
| **Mando** | Oficial | Oficial | Proveedor privado | Oficial | **No oficial — recomendación de apoyo a la decisión** |

---

## 4. Dónde WILLAQ gana, con honestidad

### 4.1 Amenazas que casi nadie cubre en el mismo lugar

Flood Hub cubre crecidas y aniegos urbanos. SENAMHI cubre meteorología e
hidrología. **Ninguno de los dos integra en un solo índice la sequía, el calor
extremo y el riesgo agropecuario**, que en un evento El Niño son tan
pertinentes como la inundación —y son las amenazas que dañan la producción
alimentaria de la costa norte.

WILLAQ evalúa **seis amenazas** con la misma fórmula y deja ver cuál domina en
cada distrito (`principal`), con el valor, el umbral y la fuente de cada una.

### 4.2 El pilar que nadie más tiene: **dónde no construir**

Este es el diferencial real. Las tres herramientas oficiales y la de Google
responden *"¿qué va a pasar y dónde?"*. WILLAQ responde además *"**¿puedo
construir aquí?**", y lo hace con:

- un **Índice de Seguridad de Emplazamiento (ISE)** en 4 clases (A apto,
  B apto con condiciones, C restringido, D no apto);
- un evaluador de sitio por **latitud/longitud y tipo de obra** (colegio,
  hospital, vivienda, planta de agua, albergue, carretera);
- **razones enumeradas con su peso** para cada veredicto, no un número opaco;
- **susceptibilidad estática** por amenaza, que es lo que permite decir
  "aquí no" incluso cuando el pronóstico del día es benigno.

El propio Google lo dice en su nota sobre Flood Hub: *"More work is needed to
increase our coverage of riverine floods to yet more locations globally and to
extend our AI-based flood forecasting to other types of flood-related
events"* (Google Research, 11 nov 2024). Y en la versión 2026 ya se
añadió la predicción de aniegos urbanos — pero **sigue siendo pronóstico de inundación**, no
criterio de emplazamiento.

### 4.3 Verificación reproducible, publicada y auditable

WILLAQ no publica un número: publica **cómo se obtuvo**, con datos que el
jurado puede volver a correr:

| Evidencia | Resultado |
|---|---|
| Backtest contra El Niño 2017, 2017–18 y 2023 (motor de producción, no una copia) | **13/13** con señal clara · **0 fallas del modelo** |
| Diagnóstico de cada no detección | `evento_ausente_en_datos` / `evento_debil_en_datos` / `modelo_no_detecta` |
| Sensibilidad de los pesos (40 escenarios) | Spearman mín. **0.9286** · top-10 idéntico en **39/40** |
| Equivalencia del cálculo rápido con el motor real | Diferencia máxima **0** |
| Datos de cada corrida | `data/validacion_historica.json`, `data/sensibilidad.json` |

La lección methodological es la importante: **3 de las 5 no detecciones no son
fallos del modelo, son fallos de la fuente** (ERA5 a 25 km no registra los
extremos orográficos de la costa norte). Un sistema que dice *"aquí no puedo
afirmar nada porque mi fuente no lo registra"* es más útil para un comité de
emergencia que uno que emite un número con apariencia de certeza.

### 4.4 Despliegue y costo de entrada

| | WILLAQ | SENAMHI / ENFEN / INDECI | Flood Hub |
|---|---|---|---|
| Dónde corre | Servidor propio o hosting compartido | Infraestructura del Estado | Nube de Google |
| Instalación | `git clone` + `php -S` | No aplica (son servicios) | Registro en la plataforma |
| Claves de API | 0 | — | 0 para uso web; API de expertos con lista de espera |
| Dependencias | PHP 8.1+, sin Composer | — | — |
| Funciona sin red | **Sí**, con snapshots por ventana | No | No |

Para una municipalidad o una dirección regional de gestión del riesgo, esto
significa que **WILLAQ se puede instalar en una máquina de la propia entidad**
y seguir funcionando cuando se cae el internet — que es exactamente el
escenario de una emergencia.

### 4.5 API abierta y encadenable

Flood Hub ofrece API, pero **con lista de espera para uso experto**. WILLAQ
expone 4 endpoints JSON con CORS abierto y sin autenticación: un chatbot, un
panel municipal o un reporte automático puede consumirlo el mismo día que se
instala. La referencia completa está en [`API.md`](API.md).

---

## 5. Dónde WILLAQ es claramente peor

Un documento comparativo que solo listes ventajas no sirve. Estas son las
debilidades reales, declaradas:

### 5.1 La resolución de los datos es inferior

| Sistema | Resolución real |
|---|---|
| SENAMHI | Radar cada 10 min, estaciones automáticas horarias, PISCO grillado a 10 km |
| WILLAQ (piloto) | ERA5 a **25 km**, GloFAS a 5 km |

En la costa norte, donde la orografía genera chubascos muy localizados, **25 km
es demasiado grueso**. Esto no es una opinión: es el resultado medido del
backtest (3 no detecciones por fuente inadecuada). Por eso el siguiente paso
del proyecto está escrito y es concreto: **incorporar estaciones de SENAMHI
(PISCO) y de la ANA**.

**Estado real (6 de noviembre de 2026):** la fase 1 de estaciones está
construida. Hay un registro reproducible de **103 estaciones** del catálogo
SENAMHI que cubre **29/29 distritos**, descargado con Garúa, y **6 series
críticas** (2017–2026) ya normalizadas con QA. Sin embargo, **el motor sigue
usando solo ERA5**: 4 de 6 series cumplen los criterios medibles, pero la
fase 2 está bloqueada hasta medir falsos positivos en el backtest. Decir que
WILLAQ «ya usa estaciones» sería falso; decir que «ya puede descargarlas y
auditarlas» es exacto.

### 5.2 La exposición es una estimación

Población, viviendas, colegios y centros de salud del piloto son
`estimacion_ratio_poblacional`, marcados como tales en cada pantalla y en cada
respuesta de API. Un distrito con más población real de la estimada tendrá un
índice subestimado. El importador CSV (INEI / MINEDU / MINSA) **ya está
construido** (`scripts/importar_exposicion.php`), con dry-run por defecto,
overlay con respaldo y procedencia por campo; lo que falta es **cargar los CSV
oficiales**. Mientras no se carguen, cada campo sigue siendo una estimación, y
así se muestra.

Además, incluso con el total oficial, la **fracción expuesta** sigue siendo
`total × susceptibilidad`: saber que hay 42 colegios no dice cuántos están en
zona inundable. Esa separación se mantiene visible en la ficha, el panel y la
API.

### 5.3 No hay etiqueta oficial, así que no hay precisión

No se puede afirmar precisión, tasa de falsas alarmas ni ROC-AUC: en el piloto
no existe un padrón oficial de «qué distritos declararon emergencia». Se
reporta **recall sobre una referencia documentada** y se declara el
diagnóstico de cada no detección. Es menos vistoso que una matriz de confusión
y es lo defendible.

### 5.4 Cobertura y autoridad

- **29 distritos**, no el país. La escalabilidad está diseñada (el catálogo
  tiene el mismo formato por país) pero **no está ejecutada**.
- **No es una alerta oficial.** El texto "recomendación de apoyo a la decisión,
  nunca alerta oficial" viaja en cada alerta, cada reporte, cada mapa, la API
  y la ayuda. No es una fórmula legal: es una decisión de diseño que evita que
  un distrito use un número de WILLAQ como si fuera un aviso de SENAMHI.
- **No tiene usuario institutional.** Cada acceso es anónimo y la escritura
  (acuse de recibo) exige rol interno. Es lo adecuado para un piloto, no para
  un sistema en producción.

### 5.5 Frente a Flood Hub en model's forecast

Para **pronóstico de crecidas**, Flood Hub es técnicamente superior y lo
admite: su modelo hidrológico supera a GloFAS (publicado en *Nature*, 2024) y
da 7 días de anticipación verificada, más 24 h de aniegos urbanos a 20 km.
WILLAQ **consume GloFAS**, no lo supera, y su anticipación es de 72 h. No hay
argumento honesto en contra de Flood Hub en ese terreno, y no lo hay en este
documento.

---

## 6. La frase que resume la posición

> **SENAMHI avisa, ENFEN caracteriza, INDECI registra y Google predice crecidas
> en todo el mundo. WILLAQ se sienta en la mesa donde el Estado pide que
> alguien se siente: la de convertir el aviso y el pronóstico en una decisión
> territorial — a quién priorizar, dónde no construir, qué escenario ensayar —
> y deja por escrito cómo obtuvo cada número.**

Y, si el jurado insiste:

> «No vine a reemplazar a SENAMHI. Vine a hacer la parte que su propio
> comunicado del 30 de octubre pide: **estudios de escenarios de riesgo
> actualizados** para los tomadores de decisiones, a escala de distrito, con
> la exposición territorial que falta en un pronóstico y con una capa de
> planificación —dónde no construir— que no existe en ninguna de las
> herramientas que vimos. Y lo hice con 29 distritos, cero licencias y sin
> internet: se instala en un hosting compartido y funciona el mismo día.»

---

## 7. Fuentes consultadas

Todas consultadas el **5 de noviembre de 2026**.

### Oficiales del Perú

| Fuente | URL | Qué se usó |
|---|---|---|
| ENFEN — Comunicados (N.° 19-2026, 30 oct 2026) | [enfen.imarpe.gob.pe/comunicados](https://enfen.imarpe.gob.pe/comunicados/) | Estado «Alerta de El Niño Costero»; pronóstico de lluvias y caudales; exhortación a los tomadores de decisiones; remisión al Visor INDECI |
| ENFEN — Informe Técnico Año 12 N.° 19 (al 28 oct 2026) | [enfen.imarpe.gob.pe](https://enfen.imarpe.gob.pe/) |Cadencia de publicación (comunicados c/ 2 semanas; informes técnicos semanales) |
| SENAMHI — Avisos meteorológicos | [senamhi.gob.pe/?p=aviso-meteorologico](https://www.senamhi.gob.pe/?p=aviso-meteorologico) | Naturaleza del aviso: «pronósticos de carácter preventivo ante eventos severos, indicando las áreas que podrían verse afectadas y el nivel de peligrosidad» |
| SENAMHI — Aviso 24 h | [senamhi.gob.pe/?p=aviso-24H](https://www.senamhi.gob.pe/?p=aviso-24H) | Antelación y estructura de los avisos |
| SENAMHI — Nota Técnica del SAT (cuencas priorizadas) | [PDF 01402SENA-13](https://www.senamhi.gob.pe/load/file/01402SENA-13.pdf) | Cuencas: Tumbes, Piura, Lambayeque, La Libertad, Ancash, Lima, Ica; percentiles 90/95/99; radar 10 min, pluviógrafos horarios; avisos 24–96 h |
| SENAMHI — Umbrales de inundación, cuencas Chira y Piura | [PDF 01403SENA-38](https://www.senamhi.gob.pe/load/file/01403SENA-38.pdf) | Ejemplo de umbral rojo: 1800 m³/s en el río Piura, Puente Sánchez Cerro |
| SENAMHI — SILVIA (movimientos en masa por lluvia) | [PDF 01401SENA-86](https://www.senamhi.gob.pe/load/file/01401SENA-86.pdf) | Índice de Lluvia Antecedente (ILA) y mapas de susceptibilidad; PISCO operativo 10 km |
| SENAMHI — Observatorio Nacional | [observatorio.senamhi.gob.pe](https://observatorio.senamhi.gob.pe/) | Existencia de un observatorio nacional con red en tiempo real |
| INDECI — Visor INDECI | [geo.indeci.gob.pe/visorindeci](https://geo.indeci.gob.pe/visorindeci/) | Rutas de evacuación y zonas seguras, últimos eventos, almacenes nacionales |
| INDECI — Portal SINPAD | [sinpad1.indeci.gob.pe/PortalSINPAD](https://sinpad1.indeci.gob.pe/PortalSINPAD/) | Visor geográfico nacional con herramientas de apoyo |
| INDECI — institutional | [gob.pe/indeci](https://www.gob.pe/indeci) | Rol: organismo público ejecutor adscrito al Ministerio de Defensa |

### Google

| Fuente | URL | Qué se usó |
|---|---|---|
| Google Research — Flood Forecasting | [sites.research.google/floodforecasting](https://sites.research.google/floodforecasting/) | Antelación 7 días (fluvial) y 24 h (urbano); >150 países y 2 000 millones de personas; API de expertos; modelo abierto; dataset GRRR; resolución urbana 20 × 20 km; metodología Groundsource (2.6 M de reportes) |
| Google Research — Modelo de pronóstico de crecidas | [blog, 11 nov 2024](https://research.google/blog/a-flood-forecasting-ai-model-trained-and-evaluated-globally/) | Cobertura ampliada, «virtual gauges» sin verificar, lista de espera para API; límites de alcance |
| Google Research — *Nature* (2024) | [research.google/blog/using-ai-to-expand-global-access-to-reliable-flood-forecasts](https://research.google/blog/using-ai-to-expand-global-access-to-reliable-flood-forecasts/) | Superioridad del modelo sobre GloFAS; alcance declarado (solo fluvial en esa versión) |
| Nota de prensa (Agencia Andina / Infobae Perú, 20 ago 2026) | [infobae.com](https://www.infobae.com/peru/2026/08/20/lluvias-intensas-en-peru-google-lanza-herramienta-de-ia-que-predice-inundaciones-urbanas-con-24-horas-de-anticipacion/) | Lanzamiento de la predicción de aniegos urbanos en Perú (Lima/Callao); Flood Hub en más de 100 países; advertencia de que no sustituye los sistemas oficiales |

### De WILLAQ

| Evidencia | Archivo |
|---|---|
| Fórmula, pesos, materialidad | [`METODOLOGIA.md`](METODOLOGIA.md) |
| Backtest y sensibilidad | [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md) |
| Arquitectura y decisiones de diseño | [`ARQUITECTURA.md`](ARQUITECTURA.md) |
| API | [`API.md`](API.md) |
| Comportamiento verificable | `data/validacion_historica.json`, `data/sensibilidad.json` |

---

## 8. Nota sobre la vigencia

> Este documento compara **sistemas que cambian**. Flood Hub amplió cobertura
> en noviembre de 2024 y en agosto de 2026; SENAMHI opera el SAT en cuencas
> priorizadas y las extiende cada temporada; ENFEN publica un comunicado cada
> dos semanas.
>
> Antes de usar esta tabla en una presentación posterior al 5 de noviembre de
> 2026, **verifica las cifras de la sección 7**. La estructura de la
> comparación (qué responde cada sistema) es estable; los números concretos no
> lo son.
>
> El único dato de WILLAQ que no cambia es el peor de todos: **no es una alerta
> oficial**, y por eso no debe presentarse nunca como una.

---

*Documento preparado para la Semana Técnica del IESTP "Argentina", DSI.
Las cifras de WILLAQ son reproducibles con los scripts del repositorio; las
cifras externas, con los enlaces de la sección 7.*

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
