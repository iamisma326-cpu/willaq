# Guion de demostración — WILLAQ

> **Duración:** 8 minutos (10 con preguntas).
> **Pantalla:** una sola, con el navegador en maximized.
> **Datos del guion:** snapshot del 5 de noviembre de 2026, 23:57, ventana 24 h
> (10 muy alto · 5 alto · 1 medio · 13 bajo).
> **Modo:** `WILLAQ_MODO_OFFLINE=1` — sin depender del wifi de la feria.

---

## 0. Antes de que empiece (5 minutos antes)

```bash
cd willaq
php scripts/actualizar.php --ventana=all   # snapshots 24/48/72 h frescos
php scripts/semear_alertas.php --limpiar    # recomendaciones coherentes
WILLAQ_MODO_OFFLINE=1 php -S localhost:8000
```

Comprobar antes de abrir al jurado:

- [ ] `http://localhost:8000` carga en menos de 2 s
- [ ] El mapa muestra los 29 distritos coloreados
- [ ] La franja naranja de modo offline es visible (es una **ventaja**: el
      jurado ve que el sistema declara su estado)
- [ ] Las alertas listadas coinciden con el snapshot
- [ ] El navegador está en modo incógnito o con la sesión cerrada

---

## 1. Apertura — el problema (0:00 – 1:00)

> **Qué decir:**
>
> "El Perú tiene uno de los sistemas de alerta temprana de El Niño más
> reputados del mundo. SENAMHI, INDECI y ENFEN tienen datos excelentes y avisos
> de precisión. El problema no es medir. El problema es **decidir**: con 29
> distritos y seis amenazas simultáneas, ¿qué se atiende primero, dónde no se
> construye, y qué pasa si el escenario se duplica?
>
> WILLAQ es un sistema de **apoyo a la decisión territorial**. No emite
> alertas oficiales — eso es de SENAMHI, INDECI y ENFEN. Emite recomendaciones
> de apoyo a la decisión, con protocolo, responsable y acuse de recibo."

**No mostrar todavía la pantalla.** Presentar el problema en limpio.

---

## 2. La sala de situación — el ahora (1:00 – 2:30)

Ir a `?action=home`.

> **Qué señalar:**
>
> - **Nivel nacional**: 15 de 29 distritos en nivel alto o muy alto.
> - **Los cuatro contadores**: 10 muy alto, 5 alto, 1 medio, 13 bajo.
> - **Exposición total de los distritos en riesgo**: **1 249 500 personas**,
>   **2 107 colegios** y **136 centros de salud**. Este es el número que
>   traduce el pronóstico en carga humana.
> - **Tabla de distritos críticos**: Veintiséis de Octubre 92, Sullana 91,
>   Catacaos 91, Piura 89, Tambogrande 88.

**Truco de presentación:** señalar que la columna de lluvia de Lima no
aparece en rojo. Volveremos a esto en el minuto 5; es el argumento técnico
más fuerte del sistema.

---

## 3. El mapa — la geografía del riesgo (2:30 – 3:30)

Ir a `?action=mapa`.

> **Qué decir:**
>
> "Este mapa no usa Google Maps. Usa **MapLibre** con un estilo propio, con
> todo el código local: funciona sin internet. Las capas de OpenStreetMap y
> las del satélite NASA son opcionales y se atenúan para que no compitan con
> el dato."

- Clic en **Catacaos** → ficha.
- En el mapa, activar la **capa de lluvia IMERG** de NASA si hay red.

> **Remate:** "El mapa no solo muestra dónde llueve. Muestra dónde el llueve
> le da a la gente."

---

## 4. La ficha — por qué este número (3:30 – 5:00)

En la ficha de Catacaos, recorrer las secciones de arriba abajo.

### 4.1 El índice y su fórmula

> "El índice es **una sola fórmula**, declarada y auditable:
>
> ```
> R = 100 · H · (0.40 + 0.35·E + 0.25·V)
> ```
>
> **H** es el peligro: la intensidad del evento comparada contra el
> **percentil histórico local** de ese distrito. **E** es la exposición:
> cuánta gente y equipamientos alcanza. **V** es la vulnerabilidad:
> infraestructura, pobreza, accesibilidad.
>
> Fíjense en la estructura: el peligro **multiplica**. Si no hay evento, no
> hay riesgo, por mucha población que haya expuesta. La exposición amplifica,
> no crea."

### 4.2 La normalización local

Señalar la tabla de umbrales de la ficha.

> "No usamos un umbral nacional. El mismo caudal significa cosas distintas
> en el Piura y en el Rímac. Cada distrito tiene su propia distribución
> histórica de 11 años, calculada con ERA5. Por eso Tumbes y Puno pueden
> tener números de lluvia parecido y niveles completamente distintos."

### 4.3 La regla de materialidad — el momento técnico

> "Aquí está lo que nos costó más trabajo, y es lo que hace que el sistema
> sea creíble.
>
> El percentil mide **rareza**, no **impacto**. En Lima, el percentil 99 de
> lluvia diaria es de un milímetro. Si normalizamos solo contra el percentil,
> **1.3 mm de llovizna sale como ‘lluvia extrema’ en rojo**. Estadísticamente
> es correcto; operativamente es un disparate.
>
> Por eso cada amenaza declara un **umbral de materialidad**: hay un mínimo
> absoluto a partir del cual el evento existe, y un umbral a partir del cual
> es plenamente material. El peligro final es el percentil **por** la
> materialidad.
>
> El factor aparece en pantalla, junto al valor y al umbral. Si alguien no
> está de acuerdo con el número, puede discutir el umbral: no hay caja
> negra."

### 4.4 La trazabilidad

Señalar la tabla de detalle: variable, valor, umbral, fuente.

> "Cada número muestra de dónde salió. Esto no es una bola de cristal: es un
> cálculo que se puede auditar línea por línea."

---

## 5. Planificación — el pilar que evita el daño futuro (5:00 – 6:00)

Ir a `?action=planificacion`.

1. Escribir las coordenadas de **Catacaos** (lat −5.2667, lon −80.6833).
2. Tipo: **colegio**.
3. Evaluar.

> **Qué decir:**
>
> "Este es el pilar que más valor tiene a largo plazo. La pregunta no es
> ‘¿qué va a pasar mañana?’, sino ‘¿**dónde no se debe construir**?’.
>
> Un colegio en una zona inundable no se salva con una alerta: se salva con
> no construirlo allí. El sistema calcula un **Índice de Seguridad de
> Emplazamiento** y lo devuelve como una clase, con razones y con lo que
> hay que hacer.
>
> Y ojo con lo que dice al final: **no es un estudio de suelos**. Para una
> licencia de obra sigue haciendo falta un professionals. WILLAQ acota la
> decisión, no la reemplaza."

---

## 6. Simulador — las tres preguntas (6:00 – 7:00)

Ir a `?action=simulador`.

### Modo Clima

Subir el multiplicador de lluvia de ×1.0 a ×2.0 y observar.

> "Si llueve el doble, ¿qué pasa? El motor **recalcula las seis amenazas**,
> no solo la lluvia: el caudal sube, el huaico sube, la inundación sube, y el
> riesgo ¿sube?"

### Modo Evacuación

Cambiar a evacuación, distrito Catacaos.

> "Aquí la pregunta ya no es meteorológica: es de **logística**. ¿Alcanza el
> tiempo? ¿Alcanzan los albergues? El sistema cruza población expuesta,
> capacidad de salida por vía principal y albergues declarados, y dice si
> hay déficit de capacidad."

### Modo Reubicación

> "Y si hay que reubicar: propone alternativas ordenadas por aptitud, con la
> razón de cada una."

---

## 7. Alertas — el circuito de decisión (7:00 – 7:30)

Ir a `?action=alertas`.

> "Una pantalla sin acuse de recibo es un cartel. Esto es un circuito: la
> autoridad emite la recomendación con su protocolo de acciones, y el
> acuse queda registrado con nombre y hora."

Si hay tiempo, iniciar sesión con `defensa@willaq.pe` / `willaq2026` y emitir
una recomendación en vivo, o mostrar una ya emitida con su acuse.

**Recordatorio ético importante (decirlo en voz alta):**
> "El lenguaje es deliberado. En ningún lugar dice ‘alerta oficial’. Cada
> recomendación lleva su nota legal. Si dijéramos ‘alerta’, estaríamos
> usurpando una función del Estado."

---

## 8. La validación — la honestidad como argumento (7:30 – 8:00)

Este es el momento que más diferencia a WILLAQ de un proyecto de maqueta.

Ir a `?action=ayuda#validacion` o mostrar `docs/VALIDACION_HISTORICA.md`.

> **Qué decir:**
>
> "Un sistema de riesgo que nunca se pone a prueba es una fórmula bonita.
> Corrimos el motor sobre los episodios de El Niño costero de 2017, 2017–2018
> y 2023.
>
> En **2017**: 6 de 6 distritos con emergencia documentada, y cinco de los
> seis en los **primeros siete lugares** de 29. No solo los detectamos: los
> ordenamos bien.
>
> En **2023**: 6 de 7.
>
> En **2017–2018**: solo 1 de 5. Y aquí viene lo importante: **no escondemos
> ese resultado**. Investigamos por qué, y la respuesta no fue ‘el modelo
> falla’. Fue: **ERA5, a 25 km de resolución, no registra ese episodio en la
> costa norte**. Los acumulados de campaña de esos meses están por debajo de
> la mediana de la serie. El dato no está. Ningún modelo que use esa fuente
> podría detectarlo.
>
> Así que separamos las dos cosas: **0 fallas de modelo** en 18 casos
> evaluados, y 5 casos en que el problema eran los datos. Eso no es un
> resultado perfecto: es un resultado **diagnosticado**, y el diagnóstico
> dice exactamente qué hay que hacer después: incorporar estaciones de
> SENAMHI (PISCO) y de la ANA para la costa norte."
>
> **Y si preguntan "¿ya lo hicieron?"**:
>
> "La **fase 1** está hecha: descargamos con Garúa el catálogo del SENAMHI y
> armamos un registro de 103 estaciones que cubre los 29 distritos, con 6
> series críticas de 2017–2026 ya normalizadas y auditadas. Lo que **no**
> hemos hecho es enchufarlas al motor: la fase 2 exige medir falsos positivos
> en el backtest y hoy hay **4 series que cumplen los criterios medibles y 0
> habilitadas**. Preferimos decirlo así antes que fingir que ya está."
>
> **Y si preguntan "¿y los pesos no son un invento?"** (preguntan):
>
> "También lo pusimos a prueba. Variamos los tres pesos del índice en 40
> escenarios —cada uno en ±10 y ±20 %, las combinaciones simultáneas, y dos
> casos límite— y medimos cuánto se mueve el ranking.
>
> El resultado: **la correlación de rangos nunca baja de 0.9286** y el top-10
> de distritos prioritarios es **idéntico en 39 de los 40 escenarios**. En 15
> de ellos no cambia ni un nivel de riesgo.
>
> Y hay una comprobación extra: como los pesos solo aparecen en la fórmula
> final, el script puede recalcular el índice sin repetir las 29 evaluaciones.
> Pero no lo dejamos en teoría — hay una bandera `--verificar` que corre el
> motor real con otros pesos y compara. **La diferencia máxima es 0.**
>
> O sea: el ranking no es el resultado de unos números que elegimos. Es el
> resultado de los datos, y es estable."

---

## 9. Cierre (8:00 – 8:15)

> "WILLAQ hace tres cosas:
>
> **Vigilar** — qué va a pasar y a quién le afecta.
> **Planificar** — dónde no se debe construir.
> **Simular** — qué pasa si el escenario empeora.
>
> Con un motor de riesgo explicable, auditable y validado contra la
> historia; que funciona sin base de datos, sin Composer y sin internet; y
> que está pensado para pasar de 29 distritos a un país, porque el catálogo
> territorial es el único lugar que hay que tocar para escalarlo.
>
> Gracias."

---

## 10. Preguntas que seguro llegan

| Pregunta | Respuesta corta |
|---|---|
| **¿Esto reemplaza a SENAMHI?** | No. Es un sistema de apoyo a la decisión. La alerta oficial es de SENAMHI, INDECI y ENFEN; WILLAQ entrega recomendaciones con protocolo y acuse. |
| **¿De dónde salen los datos?** | Open-Meteo: pronóstico (ICON), histórico (ERA5) y caudal (GloFAS). Todo abierto, gratis, sin claves de API. |
| **¿La exposición es real?** | En el piloto es una **estimación de referencia**, y la interfaz lo dice. El importador CSV de INEI, MINEDU y MINSA ya está construido; falta cargar los CSV oficiales. |
| **¿Por qué no necesita base de datos?** | Porque funciona con archivos JSON. Aun así, el proyecto incluye el esquema PostgreSQL completo: es lo que se usaría con varios usuarios o en la nube. |
| **¿Qué base de datos usa?** | PostgreSQL 13+, con los scripts `.sql` incluidos. En local se administra con DBeaver Community; en la nube, AWS RDS o Google Cloud SQL. `config/database.php` activa la conexión solo si hay credenciales. |
| **¿Es un modelo hidrológico?** | No. El caudal viene de GloFAS a 5 km, no de un modelo de cuenca calibrado. Está declarado como límite. |
| **¿Cómo se escala al país?** | `config/territorios.json` es el catálogo. La fórmula, los umbrales y el motor son los mismos para todos los distritos. |
| **¿Puedo cambiar los pesos?** | Sí, en `config/umbrales.php`. Todo está declarado en un solo lugar y se muestra en la interfaz. Y antes de cambiarlos, `php scripts/sensibilidad.php` mide cuánto mueve el ranking: con ±20 % el top-10 no cambia. |
| **¿Y si el wifi de la feria falla?** | Está previsto: `WILLAQ_MODO_OFFLINE=1` y el sistema declara en pantalla que muestra snapshots precalculados (uno por ventana: 24, 48 y 72 h). |
| **¿Cuánto costó?** | Cero en licencias: PHP, Open-Meteo, MapLibre, Chart.js y Tailwind son libres. El coste es tiempo de desarrollo. |

---

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

## 11. Plan B

| Si falla… | Haz esto |
|---|---|
| El servidor no arranca | `php -S localhost:8000` desde `willaq/`. Revisa que el puerto 8000 esté libre. |
| El mapa sale vacío | Es el modo offline: funciona con el fondo propio. No depende de teselas externas. Si necesitas las teselas, quita `WILLAQ_MODO_OFFLINE=1` y comprueba la red. |
| Una pantalla tarda | Clic en "Recalcular snapshot" (admin) o `php scripts/actualizar.php --solo=snapshot`. |
| La demo es en un portátil sin internet | Todo lo anterior: el snapshot está en `data/snapshots/`. |
| Un jurado pide ver la validación en vivo | `php scripts/validar_historico.php` (2–5 min con caché). Ten el JSON abierto por si acaso. |
| Un jurado pide ver la sensibilidad | `php scripts/sensibilidad.php --verificar` (menos de 1 min). Salida: `data/sensibilidad.json`. |
| Te preguntan por la base de datos | `database/schema_postgresql.sql` está incluido (6 tablas, 2 vistas, semilla); `config/database.php` activa la conexión solo si hay credenciales. |
| Te preguntan por la nube | La guía `database/README_POSTGRESQL.md` tiene los pasos para AWS RDS y Google Cloud SQL, incluido el `DB_SSLMODE=require`. |
