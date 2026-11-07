# WILLAQ

[![Licencia: CC BY-NC-SA 4.0](https://img.shields.io/badge/Licencia-CC%20BY--NC--SA%204.0-8a6d3a?style=for-the-badge)](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.es)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-%777bb4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Sin dependencias](https://img.shields.io/badge/Composer-no%20necesario-4a4a4a?style=for-the-badge)](https://getcomposer.org/)
[![Compuerta fase 2](https://img.shields.io/badge/estaciones-fase%202%3A%20compuerta%20cerrada-c0392b?style=for-the-badge)](#7d-backtest-de-estaciones-compuerta-de-la-fase-2)

> **WILLAQ** (*quechua: el que avisa*) — Inteligencia territorial y alerta temprana
> del Fenómeno El Niño.
> **Vigilar · Planificar · Simular**

Plataforma web en PHP puro que convierte pronóstico, caudal de río y datos de
exposición territorial en una **decisión concreta**: qué distrito vigilar, dónde
no construir y qué pasaría si llueve el doble.

---

## 1. Qué resuelve

| Pilar | Pregunta | Módulo |
|---|---|---|
| **Vigilar** | ¿Qué va a pasar en 24/48/72 h y a quién le afecta? | Sala de situación, mapa, fichas, alertas con protocolo |
| **Planificar** | ¿Dónde **no** se debe construir un colegio u hospital? | Zonificación territorial (ISE) y evaluación de sitios |
| **Simular** | ¿Qué pasaría si llueve el doble, si hay que evacuar o si se reubica? | Simulador de escenarios (clima / evacuación / reubicación) |

Los tres pilares comparten **un solo motor de riesgo explicable**:

```
R = 100 · H · (0.40 + 0.35·E + 0.25·V)
```

* `H` = peligro (intensidad del evento contra su percentil histórico local)
* `E` = exposición (población, colegios y centros de salud dentro de la zona afectable)
* `V` = vulnerabilidad (infraestructura, pobreza, accesibilidad)

> WILLAQ entrega **recomendaciones de apoyo a la decisión**.
> **No emite alertas oficiales** ni sustituye a SENAMHI, INDECI o ENFEN.

---

## 2. Estado del proyecto

| Entorno | Estado | Dónde |
|---|---|---|
| **Prototipo en producción** | Desplegado y en línea | https://willaq-production.up.railway.app |
| **Base de datos en producción** | PostgreSQL gestionado | [Neon](https://neon.tech) (región `aws-us-east-1`) |
| **Actualización automática** | Cada 6 h (cron + GitHub Actions) | ver [sección 6](#6-actualización-automática-cron) |
| **Desarrollo local** | Totalmente funcional | `php -S localhost:8000` |

**Producción:** la aplicación corre en **Railway** (contenedor Docker con
`php:8.3-cli-alpine` y `pdo_pgsql`) y la base de datos vive en **Neon**
(PostgreSQL 18 serverless). Ambos son gratuitos y no requieren tarjeta.

**Local:** *todo* el proyecto puede ejecutarse en tu máquina sin depender de
Railway ni de Neon. Basta con PHP y un PostgreSQL local — ver
[sección 4](#4-arranque-en-3-pasos).

---

## 3. Requisitos

* PHP **8.0 o superior** (probado en 8.5) con las extensiones `curl`, `json`,
  `mbstring` y **`pdo_pgsql`**.
* **PostgreSQL 13+** (en producción: Neon). La base de datos guarda usuarios,
  alertas, territorios, caché y snapshots de riesgo.
* **No requiere Composer, Node ni Python.** Todas las librerías JS están en
  `assets/vendor/`.
* Permiso de escritura en `data/`.

Verificación rápida:

```bash
php -v
php -m | grep -E "curl|json|mbstring|pdo_pgsql"
```

### Base de datos: obligatoria, con respaldo en JSON

La arquitectura es **base de datos primero**:

| Dato | Fuente de verdad | Respaldo |
|---|---|---|
| Usuarios y sesiones | tabla `usuarios` | — |
| Alertas emitidas | tabla `alertas` | — |
| Territorios (104) | tabla `territorios` | `config/territorios.json` |
| Caché de geodatos | tabla `cache_geodatos` | `data/cache/*.json` |
| Snapshots de riesgo | tabla `snapshots` | `data/snapshots/*.json` |

**La base de datos ya no es opcional** en la operación normal: es donde vive el
estado. Lo que **sí es opcional es el respaldo en archivos JSON**: si el
proyecto debe correr sin base de datos (por ejemplo, una demostración en una
máquina sin PostgreSQL), el sistema cae automáticamente a `config/territorios.json`
y a los snapshots en `data/snapshots/`, y sigue funcionando — solo pierde la
persistencia de usuarios, alertas y caché nueva.

En otras palabras: **con base de datos funciona completo; con solo JSON sigue
funcionando, en modo de solo lectura sobre datos pregenerados.**

Crear la base en local:

```bash
# 1. Crear rol y base (una sola vez)
psql -U postgres -d postgres -v WILLAQ_DB_PASS='tu-clave' \
     -f database/00_crear_bd_postgresql.sql

# 2. Crear tablas, vistas y semilla
psql -U willaq -d willaq_db -f database/schema_postgresql.sql

# 3. Cargar el catálogo nacional de territorios (104 distritos)
psql -U willaq -d willaq_db -f database/seed_territorios.sql

# 4. Conectar la aplicación (copia .env.example a .env y ajusta DB_*)
php scripts/semear_alertas.php --limpiar
```

Guía completa (DBeaver paso a paso, AWS RDS, Google Cloud SQL, respaldo JSON y
problemas frecuentes): [`database/README_POSTGRESQL.md`](database/README_POSTGRESQL.md).

---

## 4. Arranque en 3 pasos

```bash
cd willaq

# 1) (opcional) configuración
cp .env.example .env

# 2) Calentar caché y generar el snapshot de riesgo  ← hazlo ANTES de la demo
php scripts/actualizar.php

# 3) Levantar el servidor
php -S localhost:8000
```

Abrir <http://localhost:8000>.

> **Importante:** la primera carga sin caché hace ~90 llamadas HTTP y puede
> tardar más de un minuto. Por eso el paso 2 existe: deja todo precalentado.

Despliegue completo en hosting compartido (cPanel, FTP, `.htaccess`,
permisos, PostgreSQL opcional, cron, plan de reversión y lista de problemas
frecuentes): [`docs/MANUAL_INSTALACION.md`](docs/MANUAL_INSTALACION.md).

### Cuentas de demostración

| Correo | Rol | Puede |
|---|---|---|
| `admin@willaq.pe` | Administrador | Todo + consola, caché, snapshots |
| `defensa@willaq.pe` | Autoridad | Emitir recomendaciones y registrar acuses |
| `ciudadano@willaq.pe` | Ciudadano | Consultar mapa, fichas, alertas y simulador |

Contraseña para las tres: `willaq2026` — **cámbiala en producción**; está en
`models/Usuario.php` (`usuario_cuentas_demo()`) y es pensada para la demo.

---

## 5. Respaldo sin conexión

La plataforma **siempre** intenta leer el snapshot de riesgo antes de recalcular.
Ese es el comportamiento normal: la sala de situación responde en ~2 s en vez de
esperar ~70 s de llamadas a Open-Meteo.

Cuando no hay internet, el sistema usa ese mismo snapshot y lo declara con una
franja visible en todas las pantallas (*“Modo offline”*):

```bash
# 1. Generar los snapshots con datos frescos (requiere internet)
php scripts/actualizar.php --ventana=all

# 2. Servir sin red: la app lee data/snapshots/ y no falla
php -S localhost:8000
```

Comprobación rápida:

```bash
curl -s "http://localhost:8000/?action=home" | grep -o "Modo offline\|Datos en línea"
```

> **Nota sobre el "modo feria".** Existió una variable `WILLAQ_MODO_OFFLINE=1`
> que forzaba la lectura del snapshot saltándose el cálculo. Ya **no hace falta
> activarla**: el sistema prioriza el snapshot por diseño, así que el
> comportamiento es el mismo con o sin ella. La variable se conserva por
> compatibilidad y sigue funcionando si alguien la define.

Además, todas las librerías (MapLibre, Chart.js, Tailwind, Montserrat) están
en `assets/vendor/` y `assets/fonts/`: **no hay ninguna dependencia de CDN**.
El diseño se ve igual con o sin internet.

---

## 6. Actualización automática (cron)

Los snapshots de riesgo se regeneran cada 6 horas desde fuera, porque Railway
no ofrece tareas programadas.

### Cómo funciona

```
cron externo  →  GET /?action=cron_actualizar  →  202 inmediato
                        ↓
              scripts/actualizar.php (segundo plano)
                        ↓
        caché de geodatos + snapshots 24/48/72 h
```

El endpoint **responde en menos de 1 s** con `202 Accepted` y lanza el proceso
en segundo plano. Una ejecución completa tarda unos **7 minutos** con los 104
territorios, por eso no puede ejecutarse dentro de la petición HTTP: el cron
externo cortaría la conexión mucho antes.

Si ya hay una ejecución en curso, devuelve `202` sin lanzar otra. Dos procesos
simultáneos duplicarían las llamadas a Open-Meteo sin aportar nada.

### Endpoints

| Endpoint | Qué hace | Respuesta |
|---|---|---|
| `/?action=cron_actualizar` | Lanza la actualización | `202` inmediato |
| `/?action=cron_estado` | Consulta progreso y snapshots | `200` con JSON |

Ambos exigen el token `WILLAQ_CRON_TOKEN`, por cabecera `X-WILLAQ-Token`
(recomendado) o por `?token=`. Sin token configurado en el servidor responden
`503`; con token incorrecto, `401`.

### Estado actual (producción)

Configurado y verificado:

| Elemento | Estado verificado |
|---|---|
| `WILLAQ_CRON_TOKEN` en Railway | ✅ definido |
| `WILLAQ_CRON_TOKEN` como secret en GitHub | ✅ definido |
| Endpoint `cron_actualizar` | ✅ `202` en **0.41 s** |
| Endpoint `cron_estado` | ✅ 104 territorios por ventana (24/48/72) |
| Protección sin token | ✅ `401` |
| Anti-solapamiento | ✅ probado con dos llamadas seguidas |
| Workflow de GitHub Actions | ✅ ejecutado: **success en 38 s** |
| Cierre de la marca al terminar | ✅ `estado: terminado`, duración registrada |

Ejecución real del workflow (run `42402508386`):

```
Token presente (longitud 48)
Intento 1 → HTTP 202
Estado devuelto: lanzado
[30s] estado: terminado
✓ success   (38 s)
```

### Opción A — GitHub Actions (recomendada)

El workflow ya está en el repositorio:
[`.github/workflows/actualizar-snapshots.yml`](.github/workflows/actualizar-snapshots.yml).

**Activarlo (una sola vez):**

1. Ir a **Settings → Secrets and variables → Actions**.
2. **New repository secret**:
   - Nombre: `WILLAQ_CRON_TOKEN`
   - Valor: el mismo token configurado en Railway.
3. Ir a la pestaña **Actions** → *Actualizar snapshots WILLAQ* →
   **Run workflow** para el primer disparo manual.

El workflow corre cada 6 horas (`0 */6 * * *`, es decir 01:00, 07:00, 13:00 y
19:00 hora de Perú), reintenta si Railway está despertando, espera a que el
proceso termine y deja un resumen en la ejecución.

Ventajas: sin cuenta adicional, el token queda cifrado en GitHub y hay
historial de cada ejecución.

### Opción B — cron-job.org

1. Crear cuenta en [cron-job.org](https://cron-job.org) (gratis, sin tarjeta).
2. **Create cronjob** → URL: `https://willaq-production.up.railway.app/?action=cron_actualizar`
3. **Schedule**: `0 */6 * * *`.
4. **Advanced → Headers**: `X-WILLAQ-Token: <TOKEN>` (no poner el token en la URL).
5. **Timeout**: 30 s es suficiente.
6. **TEST RUN** → debe devolver `202`.

Ventajas: independiente de GitHub. Desventaja: el token queda en un tercero.

> Se pueden tener **ambos activos**: el anti-solapamiento evita que se pisen.

### Verificar que está funcionando

```bash
curl -s -H "X-WILLAQ-Token: $TOKEN" \
  "https://willaq-production.up.railway.app/?action=cron_estado"
```

Respuesta esperada:

```json
{
  "ok": true,
  "ultima_ejecucion": { "estado": "corriendo", "inicio": 1794074313 },
  "snapshots": {
    "24": { "total": 104, "generado_en": 1794073245 },
    "48": { "total": 104, "generado_en": 1794073088 },
    "72": { "total": 104, "generado_en": 1794073088 }
  },
  "cache": { "archivos": 536, "vigentes": 534, "origen": "bd" }
}
```

`ultima_ejecucion.estado` pasa de `corriendo` a otro valor al terminar.

### Manual, sin cron

```bash
curl -X POST -H "X-WILLAQ-Token: $TOKEN" \
  "https://willaq-production.up.railway.app/?action=cron_actualizar"
```

O en local, que además permite elegir qué refrescar:

```bash
php scripts/actualizar.php --ventana=all    # todo
php scripts/actualizar.php --ventana=24     # solo 24 h
php scripts/actualizar.php --solo=cache     # solo caché
```

---

## 7. Scripts de línea de comandos


```bash
php scripts/calibrar.php              # climatología p50/p95/p99 por distrito (ERA5)
php scripts/calibrar.php --force      # recalcula todo
php scripts/calibrar.php --solo=piura # solo los distritos que coincidan
php scripts/calibrar.php --ayuda

php scripts/actualizar.php                 # calienta caché + snapshot 24 h
php scripts/actualizar.php --ventana=all   # snapshots 24/48/72 h
php scripts/actualizar.php --limite=5      # prueba rápida
php scripts/actualizar.php --solo=snapshot # recalcula sin tocar la red

./scripts/willaq_cron.sh snapshot          # tarea programada: snapshots 24/48/72 h
./scripts/willaq_cron.sh alertas           # tarea programada: reset diario de alertas
./scripts/willaq_cron.sh todo              # ambas, en orden
./scripts/willaq_cron.sh estado            # frescura de snapshots y alertas

php scripts/semear_alertas.php             # genera recomendaciones desde el snapshot
php scripts/semear_alertas.php --limpiar   # borra y vuelve a sembrar
php scripts/semear_alertas.php --nivel=rojo

php scripts/validar_historico.php          # contraste con El Niño 2017 / 2017-18 / 2023
php scripts/validar_historico.php --ayuda

php scripts/sensibilidad.php               # sensibilidad de los pesos del índice
php scripts/sensibilidad.php --verificar   # comprueba contra el motor real
php scripts/sensibilidad.php --ayuda

php scripts/verificar_enlaces.php          # 0 enlaces rotos en README + docs/
```

| Script | Escribe en |
|---|---|
| `calibrar.php` | `data/climatologia_resumen.json` + caché de climatología |
| `actualizar.php` | `data/cache/*.json` + `data/snapshots/riesgo_nacional_{24,48,72}h.json` |
| `semear_alertas.php` | `data/alertas.json` |
| `validar_historico.php` | `data/validacion_historica.json` |
| `sensibilidad.php` | `data/sensibilidad.json` |
| `verificar_enlaces.php` | No escribe; solo informa (salida 0/1 para CI) |

`navegacion_docs.php` añade el bloque «Documentos relacionados» a los documentos
que aún no lo tengan; es idempotente y se usa solo al crear un documento nuevo.

---

## 7.b Validación histórica

El motor de riesgo se puso a prueba sobre los episodios de El Niño costero de
**2017**, **2017–2018** y **2023**, corriendo el mismo código de producción
sobre la serie ERA5 de 11 años.

| Episodio | Acierto sobre la referencia | Con señal clara en los datos | Fallas del modelo |
|---|---:|---:|---:|
| 2017 | 6/6 (100 %) | 6/6 (100 %) | **0** |
| 2017–18 | 1/5 (20 %) | 1/1 (100 %) | **0** |
| 2023 | 6/7 (86 %) | 6/6 (100 %) | **0** |

Las 5 no detecciones se explican por la **resolución de ERA5 (25 km)**, que no
registra los extremos orográficos y convectivos de la costa norte. El script
clasifica cada caso (`evento_ausente_en_datos`, `evento_debil_en_datos`,
`modelo_no_detecta`) para separar la limitación de datos de la del método.

Detalle completo en [`docs/VALIDACION_HISTORICA.md`](docs/VALIDACION_HISTORICA.md).

---

## 7.c Análisis de sensibilidad

Los pesos del índice (`base`, `exposicion`, `vulnerabilidad`) son una decisión
de diseño, así que se pone a prueba: 40 escenarios (cada peso en ±10/±20 %,
las 26 combinaciones simultáneas de ±20 %, y dos casos límite) sobre los 29
distritos.

| Métrica | Resultado |
|---|---|
| Correlación de rangos de Spearman con el ranking base | mínimo **0.9286**, medio **0.9968** |
| Solapamiento del top-10 (Jaccard) | mínimo **0.667**, medio **0.992** |
| Escenarios sin ningún cambio de nivel | **15 de 40** |
| Equivalencia con el motor real | diferencia máxima **0** |

Conclusión: el **orden** de prioridad es estable ante ±20 % en los pesos. El
único escenario que degrada el top-10 es el límite deliberadamente extremo
`base = 1.00` (todo el peso al peligro, sin amplificación por exposición), que
no es una configuración defendible del modelo.

Detalle en [`docs/VALIDACION_HISTORICA.md`](docs/VALIDACION_HISTORICA.md#6-análisis-de-sensibilidad).

---

## 7.d Backtest de estaciones (compuerta de la fase 2)

¿Las estaciones del SENAMHI detectan lo que el reanálisis ERA5 perdió? La
pregunta se mide, no se estima: el backtest corre **la misma aritmética de
producción** sobre ERA5 y sobre las series de estación (compartida en
`scripts/lib_validacion.php`, sin copias divergentes).

| Métrica (29 distritos×evento comparables, 15 casos de referencia) | ERA5 | Estación |
|---|---:|---:|
| Recall sobre la referencia documentada | 0.7333 | 0.7333 |
| Aciertos | 11 | 11 |
| Falsos positivos | 10 | 10 |

- **Falsos positivos nuevos de la estación: 0.**
- **Casos recuperados por la estación: 1** (`tum-zarumilla`, 2023).
- 4 estaciones cumplen los criterios medibles (≥ 10 años, ≤ 10 % faltantes),
  8 fueron evaluadas, **0 son aptas para fusión**.

Los umbrales (recall ≥ 0.80, 0 falsos positivos nuevos, ≥ 3 evaluaciones por
estación) se fijaron **antes** de ver los resultados. Con ellos, **la compuerta
queda cerrada**: las estaciones detectan lo mismo que ERA5, sin añadir ruido,
pero tampoco aportan la señal que faltaba. `motor_usa_estaciones = false` y el
motor sigue con ERA5.

```bash
php scripts/validar_historico.php      # línea base ERA5
php scripts/backtest_estaciones.php    # medición de la fase 2
```

Es **determinista**: re-ejecutado desde un clon limpio produce un JSON
idéntico. Artefacto: `data/backtest_estaciones.json`. Detalle metodológico en
[`docs/METODOLOGIA.md`](docs/METODOLOGIA.md#9-medición-de-la-fase-2-de-estaciones-y-por-qué-quedó-cerrada).

---

## 8. Estructura del proyecto

```
willaq/
├── index.php                 Enrutador por acciones (patrón heredado del proyecto base)
├── config/
│   ├── env.php               Carga de .env
│   ├── database.php          Conexión PDO opcional
│   ├── territorios.json      Catálogo de 29 distritos piloto
│   └── umbrales.php          Pesos, niveles, protocolos y respaldo por zona
├── models/
│   ├── Cache.php             Caché multinivel (memoria → archivo → respaldo expirado)
│   ├── Clima.php             Open-Meteo forecast/flood/archive + climatología
│   ├── Territorio.php        Catálogo, búsqueda y geometría
│   ├── Riesgo.php            Motor multi-amenaza explicable
│   ├── Zonificacion.php      ISE y evaluación de sitios
│   ├── Simulador.php         Escenarios clima / evacuación / reubicación
│   ├── Alerta.php            Recomendaciones, protocolos y acuses
│   └── Usuario.php           Sesión, roles y registro
├── views/                    Vistas PHP (home, mapa, territorio, alertas,
│                             planificacion, simulador, admin, ayuda, reportes)
├── assets/                   CSS heredado + capa WILLAQ, MapLibre, Chart.js, Tailwind
├── data/                     cache/ · snapshots/ · alertas.json · validación histórica
├── database/
│   ├── 00_crear_bd_postgresql.sql  Crea rol `willaq` y base `willaq_db`
│   ├── schema_postgresql.sql       Esquema PostgreSQL principal (6 tablas, 2 vistas, semilla)
│   ├── README_POSTGRESQL.md        Guía DBeaver local + AWS RDS + Google Cloud SQL
│   └── schema.sql                  Esquema MySQL heredado (alternativa)
├── scripts/                  calibrar.php · actualizar.php · sembear_alertas.php
│                             · validar_historico.php · sensibilidad.php
│                             · willaq_cron.sh · verificar_enlaces.php
├── deploy/                   Unidades systemd para GCP/AWS (snapshots y alertas)
├── docs/                     Metodología, arquitectura, manual de instalación,
│                             automatización, guion de demo, validación histórica,
│                             API, comparativa, requerimientos y manual de diseño
├── .htaccess                 Endurecimiento para hosting compartido
└── .env.example              Variables de entorno documentadas
```

Los directorios `data/`, `config/`, `models/`, `scripts/`, `views/`, `database/`
y `deploy/` llevan su propio `.htaccess` con `Require all denied`: el servidor no
los sirve por HTTP aunque el proyecto se despliegue en un subdirectorio. Detalle
en [`docs/MANUAL_INSTALACION.md`](docs/MANUAL_INSTALACION.md#34-protección-de-carpetas-internas).

---

## 9. API pública (JSON, sin autenticación, CORS abierto)

| Endpoint | Descripción |
|---|---|
| `?action=api_territorios` | Catálogo completo de distritos y metadatos |
| `?action=api_riesgo` | Riesgo nacional (o `&id=piu-catacaos` para uno) |
| `?action=api_simular&id=…&lluvia=1.5&temp=1&modo=clima` | Simulación de escenario |
| `?action=api_evaluar&lat=-5.2667&lon=-80.6833&tipo=colegio` | Evaluación de un sitio |

Ejemplo:

```bash
curl "http://localhost:8000/?action=api_riesgo&id=piu-catacaos"
```

Referencia completa con parámetros, estructura de respuesta y ejemplos
ejecutables: [`docs/API.md`](docs/API.md).

---

## 10. Fuentes de datos

| Fuente | Uso | Costo |
|---|---|---|
| [Open-Meteo Forecast](https://open-meteo.com) | Lluvia, temperatura, viento, ET0 | Gratis |
| Open-Meteo Flood (GloFAS) | Caudal de río | Gratis |
| Open-Meteo Archive (ERA5) | Climatología e histórico 2017/2023 | Gratis |
| [NASA GIBS](https://www.earthdata.nasa.gov/eosdis/science-system-description/eosdis-components/gibs) | Capas satelitales opcionales (IMERG, VIIRS) | Gratis |
| SENAMHI — catálogo y series diarias | Registro de 103 estaciones y series históricas (ingesta con Garúa) | Gratis, sin licencia de reutilización declarada |
| INEI — población proyectada por distrito | Población de referencia de los 104 distritos (overlay) | Gratis |
| SENAMHI / ENFEN / INDECI | Referencia y protocolos | — |
| [OpenStreetMap](https://www.openstreetmap.org) | Mapa base opcional | Gratis |

La exposición es **mixta y lo declara campo por campo**: la **población** es
oficial del INEI (proyección al 30/06/2026, con sha256 del archivo en
`datos/oficial/`); **viviendas, colegios y centros de salud** siguen siendo
estimación del catálogo porque ESCALE y RENIPRESS bloquean la descarga
automatizada. La fracción expuesta siempre es estimada. El importador CSV
(`scripts/importar_exposicion.php`) escribe un overlay con procedencia por
campo y el catálogo base nunca se pisa.

Las series de estaciones son **fase 1**: registro de 103 estaciones, **23
series reales** descargadas con Garúa (2017–2026), normalización y QA. El motor
de riesgo **todavía no las usa** (`motor_usa_estaciones = false`): 4 de las 23
cumplen los criterios medibles y 0 están habilitadas, porque la fase 2 exige
medir falsos positivos en el backtest.

---

## 11. Relación con el proyecto inscrito

WILLAQ vive en su propia carpeta y **no modifica** `mvc_php_poo_config/`.
Se reutiliza la identidad visual (azul estratósfera + dorado + Montserrat), el
patrón de enrutador por acciones, el login con roles, el cliente HTTP con caché
y el esquema de panel administrativo. Todo el motor de riesgo, la
zonificación, el simulador, el mapa offline y la API son código nuevo
desarrollado durante la fase actual.

Detalle completo: `?action=ayuda#reutilizacion`.

---

## 12. Documentación

| Documento | Contenido |
|---|---|
| `docs/METODOLOGIA.md` | Fórmula, normalización, amenazas, pesos, límites |
| `docs/ARQUITECTURA.md` | Estructura, flujo de datos, caché, modo offline, BD |
| `docs/MANUAL_USUARIO.md` | Guía por rol (ciudadano, autoridad, administrador) |
| `docs/MANUAL_INSTALACION.md` | Despliegue local y en hosting compartido, `.htaccess`, reversión |
| `docs/AUTOMATIZACION.md` | Snapshots cada hora y alertas diarias en GCP/AWS: systemd, cron, permisos |
| `docs/MANUAL_DISENO.md` | Paleta, tipografía, componentes, accesibilidad y modo offline |
| `docs/REQUERIMIENTOS.md` | 15 requisitos funcionales + 11 no funcionales, cada uno con prueba |
| `docs/API.md` | Los 4 endpoints JSON con ejemplos ejecutables |
| `docs/GUION_DEMO.md` | Guion de 8 minutos para la feria |
| `docs/VALIDACION_HISTORICA.md` | Contraste con los eventos de 2017 y 2023 + sensibilidad |
| `docs/COMPARATIVA.md` | WILLAQ frente a SENAMHI, ENFEN, Flood Hub e INDECI |
| `docs/PLAN_EXPOSICION_Y_ESTACIONES.md` | Plan y estado de ejecución de exposición oficial y estaciones |

---

## 13. Problemas frecuentes

**La primera carga tarda mucho.**
Falta el paso 2 del arranque. Ejecuta `php scripts/actualizar.php`.

**Los valores no coinciden con los del SENAMHI.**
El motor normaliza contra el percentil histórico local de cada distrito, no
contra un umbral nacional único. Es intencional: 40 mm/día en Tumbes no es lo
mismo que en Puno.

**¿Por qué no sale “alerta oficial” en ninguna parte?**
Porque WILLAQ no es un servicio oficial. Emitir "alerta oficial" sería
incorrecto y el jurado lo detectaría. La plataforma emite *recomendaciones de
apoyo a la decisión*, con protocolo y acuse de recibo.

**¿Dónde se cambia el número de distritos?**
`database/seed_territorios.sql` (base de datos) o `config/territorios.json`
(respaldo). El formato es el mismo para todo el país: es la hoja de ruta del
alcance internacional.

**El cron devuelve `202` pero `cron_estado` sigue diciendo `corriendo`.**
El proceso se lanzó pero murió sin cerrar su marca, o sigue trabajando de
verdad. Mira `data/snapshots/.cron.log` en el servidor. Tras 30 minutos el
anti-solapamiento considera la ejecución abandonada y permite relanzar.

**El cron responde `503`.**
Falta `WILLAQ_CRON_TOKEN` en las variables de Railway. Añadirlo y volver a
desplegar.

**El cron responde `401`.**
El token enviado no coincide. Revisa que el secret `WILLAQ_CRON_TOKEN` de
GitHub sea idéntico al de Railway.

---

## 14. Repositorio público

**<https://github.com/iamisma326-cpu/willaq>**

```bash
git clone https://github.com/iamisma326-cpu/willaq.git
cd willaq
php -S localhost:8000
```

**Qué se publica: todo.** El repositorio contiene el proyecto completo para que
cualquiera pueda reproducir la feria y auditar cada número sin pedir nada a
nadie:

| Incluido | Por qué |
|---|---|
| Código PHP, vistas, estilos y scripts | El sistema completo. |
| `data/estaciones/_garua/csv/` (13 MB) | Los CSV **crudos** del SENAMHI, sin normalizar: la evidencia de origen de cada serie. |
| `data/estaciones/*.json` | Las 23 series normalizadas con su reporte de QA. |
| `data/cache/` | La caché real de Open-Meteo, para poder repetir los cálculos sin red. |
| `data/snapshots/`, `data/alertas.json` | Lo que se mostró en la feria. |
| `data/backups/`, `data/cron.log`, logs de Garúa | Trazabilidad del proceso. |
| `docs/` (14 documentos) | Metodología, auditorías, plan y guion de demo. |

**Única excepción: `.env`.** Contiene la contraseña real de PostgreSQL
(`DB_PASS`), y publicarla en un repositorio público la comprometería de forma
permanente (queda en el historial de Git para siempre). Se versiona
`.env.example`, con los mismos campos y `DB_PASS` vacío. Si alguien necesita
la base de datos, la crea desde
[`database/schema_postgresql.sql`](database/schema_postgresql.sql).

**Advertencia sobre los datos del SENAMHI.** El catálogo y las series del
SENAMHI se distribuyen sin licencia de reutilización declarada (ver §8). Se
publican aquí para trazabilidad y auditoría, no como cesión de derechos: quien
reutilice esos datos debe verificar la condiciones de uso en la fuente.

**Sin dependencias.** PHP puro, sin Composer, sin `vendor/`, sin build. Un
clon limpio arranca con el servidor embebido de PHP.

**Licencia.** [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/deed.es)
(uso educativo). Puedes estudiarlo, auditarlo y modificarlo con fines docentes;
no es comercial y las versiones modificadas se publican bajo la misma licencia.
Texto legal completo en [`LICENSE`](LICENSE) y guía de atribución en
[`LICENSE.md`](LICENSE.md). La licencia cubre **el código**, no los datos de
terceros que el repo redistribuye (SENAMHI, INEI, Open-Meteo, OSM): esos
tienen sus propias condiciones de uso, detalladas en `LICENSE.md`.
