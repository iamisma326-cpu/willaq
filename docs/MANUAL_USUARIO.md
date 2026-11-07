# Manual de usuario — WILLAQ

> Guía práctica de la plataforma, organizada por rol.
> Tres roles: **ciudadano**, **autoridad**, **administrador**.

---

## 1. Antes de empezar

### Qué es WILLAQ

WILLAQ es una plataforma de **inteligencia territorial y alerta temprana** del
Fenómeno El Niño. Convierte pronóstico, caudal de río y datos de exposición
territorial en una decisión concreta: qué distrito vigilar, dónde no construir
y qué pasaría si llueve el doble.

> **WILLAQ emite recomendaciones de apoyo a la decisión.**
> **No emite alertas oficiales.** La alerta oficial corresponde a SENAMHI,
> INDECI y ENFEN. WILLAQ no las sustituye ni las anticipa.

### Los tres pilares

| Pilar | Pregunta que responde | Dónde está |
|---|---|---|
| **VIGILAR** | ¿Qué va a pasar en 24/48/72 h y a quién le afecta? | Sala de situación, Mapa, Fichas |
| **PLANIFICAR** | ¿Dónde **no** se debe construir un colegio u hospital? | Planificación |
| **SIMULAR** | ¿Qué pasaría si llueve el doble, si hay que evacuar o reubicar? | Simulador |

### Requisitos

* Navegador moderno (Chrome, Firefox, Edge).
* **Internet es necesario solo la primera vez.** A partir de ahí, la caché
  funciona; y con el modo feria activated, la aplicación funciona **sin
  internet**.

---

## 2. Acceso

### Entrar

1. Abrir la dirección del sistema (por ejemplo `http://localhost:8000`).
2. Pulsar **Acceso** en la esquina superior derecha (o ir directo a
   `?action=login`).
3. Escribir el correo y la contraseña.

### Cuentas de demostración

| Correo | Rol | Puede |
|---|---|---|
| `admin@willaq.pe` | Administrador | Todo + panel, caché, snapshots, base de datos |
| `defensa@willaq.pe` | Autoridad | Emitir recomendaciones y registrar acuses |
| `ciudadano@willaq.pe` | Ciudadano | Consultar mapa, fichas, alertas y simulador |

Contraseña para las tres: `willaq2026`

### Salir

Botón **Cerrar sesión** en la cabecera, a la derecha.

---

## 3. Ciudadano — guía de consulta

No hace falta iniciar sesión para consultar. Estas cinco pantallas cubren el
uso diario.

### 3.1 Sala de situación (`?action=home`)

La pantalla de entrada. Responde de un vistazo: **¿cómo está el país hoy?**

* **Nivel nacional**: el peor nivel entre los 29 distritos, con su etiqueta
  operativa ("Riesgo alto en al menos un distrito").
* **Cuatro indicadores**: número de distritos por nivel (bajo, medio, alto,
  muy alto), número con alerta activa, y el total de distritos vigilados.
* **Tabla de distritos críticos**: los 10 de mayor riesgo, ordenados por índice, con
  la amenaza dominante y su valor.
* **Vigilancia de hoy** (columna lateral):
  * *Distritos con lluvia material* — los que superan su umbral material de
    lluvia (no solo su percentil: una llovizna que supera el p95 en Lima no
    cuenta como evento).
  * *Mayor intensidad de lluvia* — valor y distrito.
  * *Fuente de pronóstico* y *última actualización*.

> Si aparece la franja **"Se muestran snapshots precalculados"**, el sistema
> está en modo feria: los datos son del último cálculo y no se actualizan en
> vivo. Es una señal de transparencia, no un error.

### 3.2 Mapa (`?action=mapa`)

Mapa nacional con los 29 distritos coloreados por nivel de riesgo.

* **Colores**: verde (bajo), amarillo (medio), naranja (alto), rojo (muy
  alto).
* **Clic en un distrito** → ficha con su detalle.
* **Capas opcionales** (botón derecho):
  * *Lluvia IMERG* (NASA GIBS) — precipitación satelital del día anterior.
  * *Imagen satelital* (GIBS).
  * *Mapa base* — se atenúa para no competir con los datos.
* **Navegación**: rueda para acercar, arrastre para desplazar, botón de
  capas para alternar.

> Sin internet, el mapa sigue funcionando: muestra los marcadores sobre el
> fondo oscuro del propio estilo. Las capas remotas simplemente no cargan.

### 3.3 Ficha de distrito (`?action=territorio&id=<id>`)

El detalle completo de un distrito. Secciones:

1. **Cabecera**: nombre, región, índice, nivel y amenaza dominante.
2. **Las seis amenazas**: lluvia extrema, inundación fluvial, huaico, sequía,
   calor extremo y riesgo agropecuario, cada una con su peligro, exposición,
   vulnerabilidad e índice.
3. **Trazabilidad**: para cada amenaza, una tabla con la variable, su valor, el
   **umbral con el que se comparó** y la **fuente**. Nada es una caja negra.
4. **Regla de materialidad**: el factor aplicado y por qué. Si la lluvia no
   supera el mínimo absoluto, el factor es 0 y el riesgo es 0 aunque el valor
   sea raro para la zona.
5. **Aptitud territorial (ISE)**: el Índice de Seguridad de Emplazamiento y la
   clase (A–D) con su recomendación.
6. **Datos de exposición**: población expuesta por amenaza, con la **fuente**
   (en el piloto, estimación de referencia).
7. **Reporte imprimible**: botón para generar el informe PDF de la ficha.

### 3.4 Alertas (`?action=alertas`)

Lista de **recomendaciones de apoyo a la decisión** emitidas.

* Cada una indica: código (`WQ-AAAAMMDD-XXXX`), distrito, amenaza, nivel,
  fecha y estado (`emitida` / `acusada`).
* Al abrir una, se ve el **protocolo sugerido** para esa amenaza y ese nivel,
  con acciones concretas (limpieza de drenajes, verificar puentes, suspender
  actividades, preparar albergues…).
* Si eres usuario con rol de autoridad o administrador, puedes **acusar
  recibo**: queda registrado quién y cuándo.

> El botón de acuse es lo que convierte una recomendación en un circuito de
> decisión real: la autoridad confirma que la recibió y que la está
> gestionando.

### 3.5 Planificación (`?action=planificacion`)

Responde: **¿dónde no se debe construir?**

1. Escribir **latitud** y **longitud** del sitio, o seleccionarlo en el mapa.
2. Elegir el **tipo de equipamiento**: colegio, hospital, albergue, vivienda
   u obra pública.
3. Pulsar **Evaluar sitio**.

El resultado es un veredicto con:

* **Clase de aptitud** (A–D) y su color.
* **Razones del veredicto**, una por factor (inundación, cota, exposición,
  distancia a cauce, zonificación vigente).
* **Qué hacer**: la recomendación concreta (reubicar, condicionar, estudiar,
  o no construir).
* **Ranking de aptitud territorial**: los distritos del piloto ordenados de
  más a menos aptos, para comparar.

> La clase A significa "el lugar más seguro dentro de lo evaluable". **No
> significa "construir aquí"**: cualquier obra requiere estudio de suelos y
> de riesgo que WILLAQ no sustituye.

---

## 4. Autoridad — guía de decisión

Todo lo del ciudadano, más la capacidad de emitir recomendaciones.

### 4.1 Emitir una recomendación

Desde la **ficha de un distrito** o desde el panel de alertas:

1. Pulsar **Emitir recomendación**.
2. Revisar el nivel propuesto (viene del índice) y el mensaje.
3. Añadir el mensaje: **qué pasó, qué se recomienda y quién debe actuar**.
4. Confirmar.

El sistema genera un código único, registra emisor y fecha, y adjunta el
protocolo de acciones correspondiente al nivel y la amenaza.

### 4.2 Acusar recibo

Abrir la recomendación y pulsar **Acusar recibo**. El estado pasa de
`emitida` a `acusada` y queda registrado el nombre y la hora. Esta trazabilidad
es lo que permite después reconstruir qué sabía cada autoridad y cuándo.

### 4.3 Generar el reporte (`?action=reporte&id=<id>`)

Informe imprimible de un distrito, en HTML listo para convertir a PDF. Contiene:
datos del distrito, las amenazas con su detalle de umbrales, la regla de
materialidad aplicada, la aptitud territorial, las recomendaciones y la nota
legal. Use **Imprimir → Guardar como PDF**.

---

## 5. Administrador — guía de operación

Panel en `?action=admin` (solo administrators).

| Sección | Qué hace |
|---|---|
| **Snapshot de riesgo nacional** | Estado, fecha de generación, distribución por nivel. Botón para recalcular. |
| **Caché multinivel** | Cuántos ítems hay, cuántos vigentes, cuántos expirados, tamaño en disco. Botón para limpiar. |
| **Catálogo de territorios** | Los 29 distritos con su región, población expuesta, vulnerabilidad y susceptibilidad. |
| **Entorno de ejecución** | Versión de PHP, modo offline, rutas, permisos de escritura. |
| **Roles y permisos** | Qué puede hacer cada rol. |
| **API pública** | Los cuatro endpoints con ejemplos copiables. |
| **Base de datos** | Estado de la conexión PDO (o su ausencia) y el esquema disponible. |

### Acciones de mantenimiento

```bash
# Recalcular el snapshot de riesgo (desde el panel o por CLI)
php scripts/actualizar.php
php scripts/actualizar.php --solo=snapshot   # sin tocar la red

# Calentar la caché
php scripts/actualizar.php --limite=5         # prueba rápida

# Vaciar la caché (desde el panel de administración)
# → botón "Limpiar caché"; admite ?patron=forecast: para borrar solo un tipo
```

### Antes de una demostración

```bash
php scripts/actualizar.php --ventana=all     # snapshots 24/48/72 h
php scripts/semear_alertas.php --limpiar      # recomendaciones coherentes con el snapshot
```

Y presentar con:

```bash
WILLAQ_MODO_OFFLINE=1 php -S localhost:8000
```

Comprobar que el modo offline está activo:

```bash
curl -s "http://localhost:8000/?action=home" | grep -o "Modo offline\|Datos en línea"
```

Debe salir **Modo offline**. Si sale *Datos en línea*, la variable de entorno
no se está leyendo: en la configuración habitual de PHP (`variables_order=GPCS`)
`$_ENV` llega vacío, y por eso WILLAQ lee el entorno también con `getenv()`.

---

## 6. Simulador — guía de escenarios

`?action=simulador`. Tres modos, según lo que se quiera responder.

### Modo Clima — "¿qué pasaría si llueve más?"

* **Distrito** a analizar.
* **Multiplicador de lluvia** (×1.0 a ×3.0).
* **Incremento de temperatura** (0 a +4 °C).
* **Ventana de análisis**: 24 h, 48 h o 72 h.

Devuelve el índice recalculado bajo el escenario, las seis amenazas
reevaluadas, y la comparación contra la situación actual: cuánto sube el
riesgo y en qué amenaza.

### Modo Evacuación — "¿alcanza el tiempo y la capacidad?"

Para un distrito con nivel alto o muy alto:

* Población expuesta estimada.
* **Capacidad de salida**: 1 200 personas por hora por vía principal
  (supuesto documentado y editable).
* **Albergues declarados** y su capacidad.
* Tiempo estimado de evacuación, horas de cobertura y **déficit de
  capacidad** cuando los albergues no alcanzan.

### Modo Reubicación — "¿dónde muevo el equipamiento?"

* Tipo de equipamiento a reubicar (colegio, hospital, albergue…).
* Punto de origen.
* Multiplicador de lluvia.

Devuelve las **alternativas de reubicación** ordenadas por aptitud, con la
razón de cada una y la distancia aproximada.

---

## 7. API pública

Cuatro endpoints JSON, sin autenticación, con CORS abierto. Ideales para
integrar en un panel externo, un chatbot o un reporte automático.

| Endpoint | Devuelve |
|---|---|
| `?action=api_territorios` | Catálogo completo y metadatos |
| `?action=api_riesgo` | Riesgo nacional, o de un distrito con `&id=` |
| `?action=api_simular&id=&lluvia=&temp=&modo=` | Simulación de escenario |
| `?action=api_evaluar&lat=&lon=&tipo=` | Evaluación de un sitio |

```bash
curl "http://localhost:8000/?action=api_riesgo&id=piu-catacaos"
```

La respuesta de riesgo incluye el desglose por amenaza, el detalle de umbres
y fuentes, el factor de materialidad aplicado y el nivel.

---

## 8. Preguntas frecuentes

**¿Por qué no sale "alerta oficial" en ninguna parte?**
Porque WILLAQ no es un servicio oficial. Emitir "alerta oficial" sería
incorrecto. La plataforma emite *recomendaciones de apoyo a la decisión*, con
protocolo y acuse de recibo.

**Los valores no coinciden con los del CENAMI.**
El motor normaliza contra el percentil histórico **local** de cada distrito, no
contra un umbral nacional único. Es intencional: 40 mm/día en Tumbes no es lo
mismo que en Puno. En la ficha de cada distrito se muestra el percentil usado.

**Sale "nivel rojo" con poca lluvia.**
Revise el factor de materialidad en la ficha. Si está en 0, el riesgo no
depende de la lluvia. Y si la lluvia es alta pero en un distrito con alta
exposición y alta vulnerabilidad, el índice sube por esa razón, no por la
lluvia: eso es exactamente lo que el índice debe hacer.

**¿Por qué la web tarda tanto la primera vez?**
Falta el calentamiento de caché. Ejecute `php scripts/actualizar.php` antes de
la demostración.

**¿Puedo usarlo sin internet?**
Sí, con `WILLAQ_MODO_OFFLINE=1`. La plataforma lee el snapshot precalculado de
la ventana pedida (`data/snapshots/riesgo_nacional_24h.json`, `_48h.json`,
`_72h.json`) y lo declara con una franja visible.

**¿Cómo cambio el número de distritos?**
`config/territorios.json`. El formato es el mismo para todo el país: es la
hoja de ruta del alcance internacional.

**¿Puedo cambiar los pesos del índice?**
Sí, en `config/umbrales.php` (`$PESOS_RIESGO`, `$MATERIALIDAD`, `$NIVELES`).
Todo está declarado en un solo lugar y se muestra en la interfaz.

Antes de cambiarlo, mida cuánto afecta:

```bash
php scripts/sensibilidad.php --verificar
```

Compara 40 escenarios de pesos contra el ranking actual y reporta la
correlación de rangos (Spearman), el solapamiento del top-10 y cuántos
distritos cambian de nivel. Con ±20 % el top-10 de distritos prioritarios no
cambia.

---

## 9. Glosario

| Término | Significado en WILLAQ |
|---|---|
| **Peligro (H)** | Intensidad del evento comparada contra su percentil histórico local, con la regla de materialidad aplicada |
| **Exposición (E)** | Población, equipamientos y susceptibilidad dentro de la zona afectable |
| **Vulnerabilidad (V)** | Infraestructura, pobreza, accesibilidad y capacidad de respuesta del distrito |
| **Índice (R)** | `R = 100 · H · (0.40 + 0.35·E + 0.25·V)` |
| **Materialidad** | Filtro que distingue "raro para la zona" de "realmente importa" |
| **p50 / p95 / p99** | Mediana, percentil 95 y percentil 99 de la distribución local del evento |
| **ISE** | Índice de Seguridad de Emplazamiento: aptitud del territorio para equipamiento crítico |
| **Snapshot** | Cálculo completo de riesgo guardado, para responder sin recalcular y para operar sin internet |
| **Aviso / Acuse** | Registro de que una autoridad recibió y está gestionando una recomendación |

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
