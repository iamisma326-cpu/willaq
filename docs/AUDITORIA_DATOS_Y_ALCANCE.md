# Auditoría de datos y alcance real de WILLAQ

> **Fecha de la auditoría:** 5 de noviembre de 2026, 22:55–23:00 (America/Lima)
> **Método:** lectura del código, inspección de `data/`, consultas a MySQL y
> llamadas GET reales a Open-Meteo.
> **Alcance:** no se modificó código; solo se verificó el estado del proyecto.
> **Importante:** las cifras de snapshot, caché y hora son una fotografía de ese
> momento. Mañana serán distintas; lo estructural (fuentes, arquitectura,
> límites) se mantiene.
>
> ---
>
> **Actualización del 6 de noviembre de 2026:** tres secciones ya no describen
> el estado actual y se corrigen más abajo, sin borrar la fotografía original:
>
> 1. **Base de datos (sección 5).** Se activó **PostgreSQL**: existe la base
>    `willaq_db` con 6 tablas, 2 vistas, 2 triggers y la semilla (3 usuarios +
>    29 distritos). La aplicación lee y escribe usuarios y alertas en ella; el
>    JSON sigue funcionando como respaldo. Scripts y guía:
>    [`database/schema_postgresql.sql`](../database/schema_postgresql.sql) y
>    [`database/README_POSTGRESQL.md`](../database/README_POSTGRESQL.md).
> 2. **Exposición oficial (secciones 1 y 4).** El importador CSV está
>    **construido** (`scripts/importar_exposicion.php`) con overlay y
>    procedencia por campo, y la **población ya es oficial**: se descargó el
>    XLSX del INEI (proyecciones distritales 2018-2026, sha256
>    `9436df29…d90d5c8b`), se.crossó con los 29 ubigeos del catálogo y se
>    aplicó el overlay. `viviendas`, `escuelas` y `centros_salud` **siguen
>    estimadas** porque ESCALE y RENIPRESS bloquean la descarga automatizada.
> 3. **Estaciones (secciones 1, 4 y 6).** La **fase 1** está **ejecutada**:
>    registro de 103 estaciones del catálogo SENAMHI, descarga real con Garúa,
>    series normalizadas con QA y cobertura en el panel admin. El **motor de
>    riesgo todavía no las usa** (`motor_usa_estaciones = false`); la fase 2
>    sigue bloqueada por compuerta.

Este documento responde, sin adornos, a cinco preguntas:

1. ¿Qué se hizo realmente en el proyecto?
2. ¿Tiene conexión con una API o servicio?
3. ¿Es a tiempo real o al menos con data actualizada a diario?
4. ¿Solamente hay ejemplos no reales?
5. ¿Tenemos una base de datos?

---

## Respuesta corta

| Pregunta | Respuesta |
|---|---|
| ¿Hay conexión con una API? | **Sí.** Open-Meteo Forecast, Open-Meteo Flood (GloFAS) y Open-Meteo Archive (ERA5). Verificado en vivo: HTTP 200 en 0,82 s. |
| ¿Es tiempo real? | **No.** No hay streaming ni sensores. Hay datos meteorológicos reales con caché de 1 hora, pero el tablero principal lee un **snapshot** generado a mano. |
| ¿Se actualiza a diario? | **No automáticamente.** No hay cron instalado en este entorno. Si se ejecuta `php scripts/actualizar.php`, sí. |
| ¿Son ejemplos no reales? | **Mixto:** el clima, el histórico y las series de estaciones son reales; la población, viviendas, colegios, salud, vulnerabilidad y susceptibilidad siguen siendo **estimaciones** mientras no se cargue el overlay oficial. Los escenarios son simulaciones. |
| ¿Tenemos base de datos? | **Sí, PostgreSQL.** `willaq_db` con 6 tablas, 2 vistas y 2 triggers; la app lee y escribe usuarios y alertas. El JSON queda como respaldo. Ver la corrección al inicio de este documento. |

---

## 1. Qué se hizo realmente

El proyecto no es una maqueta estática. Tiene:

- Enrutador PHP por acciones, vistas, login con roles, panel de administración y
  APIs JSON internas.
- Motor de riesgo con fórmula reproducible:
  `R = 100 · H · (0.40 + 0.35·E + 0.25·V)`.
- Caché en disco con TTL, respaldo ante fallo de red y modo offline.
- Snapshots de 24/48/72 h para los 29 distritos.
- Simulador climático, de evacuación y de reubicación.
- Evaluador de sitios (ISE) para planificación territorial.
- Backtest histórico con ERA5: 18 casos de 2017, 2017–18 y 2023.
- Importador CSV de exposición con overlay, respaldo y procedencia por campo.
- Fase 1 de estaciones: registro de 103 estaciones, series reales descargadas
  con Garúa, QA y cobertura en el panel.
- 10 documentos técnicos, incluida la validación histórica y la metodología.

Lo que **no** hay todavía: **el motor de riesgo no usa las estaciones** (fase 2
bloqueada por compuerta), **viviendas, colegios y centros de salud oficiales**
(ESCALE y RENIPRESS bloquean la descarga automatizada), capas oficiales de
peligro de CENEPRED/INDECI, nowcast en tiempo real, ni alertas oficiales.

---

## 2. Conexión real con APIs y servicios

Verificado durante la auditoría:

```text
Open-Meteo Forecast  → HTTP 200 en 0,82 s
Piura (-5.19, -80.63) → 24,6 °C · 0,00 mm · 2026-11-05T22:45 (hora local)
```

| Servicio | Uso | Estado | ¿API key? |
|---|---|---|---|
| `api.open-meteo.com/v1/forecast` | Lluvia, temperatura, viento, ET0, probabilidad | Real, caché 1 h | No |
| `flood-api.open-meteo.com/v1/flood` | Caudal de río GloFAS 5 km | Real, caché 1 h | No |
| `archive-api.open-meteo.com/v1/archive` | ERA5 histórico y climatología | Real, caché 7 días / 30 días | No |
| `tile.openstreetmap.org` | Mapa base | Online, requiere red | No |
| `basemaps.cartocdn.com` | Mapa oscuro de planificación | Online, requiere red | No |
| `gibs.earthdata.nasa.gov` | Lluvia IMERG y satélite NASA | Opcional, online | No |

Evidencia de que el caché tiene datos reales:

- **174 archivos** en `data/cache/`.
- **87** de ERA5 Archive, **29** de climatología, **29** de pronóstico,
  **29** de GloFAS.
- Rango de obtención: **2026-11-05 21:22 → 2026-11-06 03:15 UTC**.
- Ejemplo de Piura en vivo: lluvia 4,4 mm, p95 local 1,7 mm, probabilidad 99 %,
  caudal 0,8 vs p95 0,5, temperatura aparente 34,6 °C, 79 días secos.

No hay **API ni conexión en vivo** con SENAMHI, ANA, INEI, MINEDU, MINSA,
INDECI ni CENEPRED. Las series de estaciones del SENAMHI se descargan
**offline** con Garúa y se normalizan antes de desplegar; el runtime web no
consulta esos portales. Tampoco hay correo, SMS, pagos ni autenticación externa.

---

## 3. ¿Tiempo real o actualización diaria?

**No es tiempo real.** Es un modelo con datos meteorológicos reales, pero el
tablero principal no consulta la API en cada visita.

Así funciona hoy:

1. `scripts/actualizar.php` consulta Open-Meteo, llena `data/cache/` y escribe
   los snapshots.
2. El tablero (`riesgos_para_vista()`) **siempre prefiere el snapshot** si
   existe, incluso en modo online.
3. Solo `?action=api_riesgo&id=...` recalcula un distrito en vivo.

Evidencia concreta del desfase:

| Dato | Valor |
|---|---|
| Snapshot 24 h generado | **2026-11-05 23:57 UTC** (18:57 Lima) |
| Riesgo de Veintiséis de Octubre en el snapshot | **91,6** (rojo, lluvia) |
| Riesgo recalculado en vivo a las 03:58 UTC | **49,0** (calor) |

Es decir: el tablero puede mostrar un riesgo que ya cambió. La página sí muestra
“Última actualización: hace 3 h”, pero el encabezado dice **“Datos en línea”**
aunque esté sirviendo el snapshot. Esa es una inconsistencia de transparencia
que conviene corregir antes de la feria.

TTL actual:

| Dato | TTL |
|---|---|
| Pronóstico | 1 hora |
| Caudal | 1 hora |
| Lluvia reciente 90 días | 12 horas |
| Archivo ERA5 | 7 días |
| Climatología | 30 días |
| Snapshot | Se regenera solo si se ejecuta el script o el botón del panel |

En este entorno **no hay `crontab` ni tarea programada**. En hosting compartido
se puede configurar; el manual trae el ejemplo cada 6 horas, pero no está
activo.

**Conclusión:** “casi tiempo real” solo si se ejecuta `actualizar.php` antes de
la demo y/o se configura cron. Hoy, sin eso, es un snapshot con datos de hace
horas.

---

## 4. ¿Datos reales o ejemplos?

Es mixto, y el propio proyecto lo declara en `config/territorios.json`:

```json
"fuente_exposicion": "estimacion_ratio_poblacional"
```

| Componente | ¿Real? | Detalle |
|---|---|---|
| Nombres, regiones y coordenadas de los 29 distritos | **Sí** | Distritos reales del Perú; coordenadas aproximadas. |
| Pronóstico de lluvia/temperatura | **Sí** | Open-Meteo, dato real con caché. |
| Caudal de río | **Sí** | GloFAS 5 km. |
| Climatología p50/p95/p99 | **Sí** | ERA5 2015–2025, ventana ±15 días. |
| Histórico 2017/2017–18/2023 | **Sí** | ERA5 Archive real. |
| Población | **Oficial (parcial)** | Proyección total al 30/06/2026 del INEI para los 29 distritos, con sha256 del XLSX. Es una **proyección**, no un censo. |
| Viviendas, colegios, centros de salud | **Estimación** | Valores del catálogo piloto. ESCALE y RENIPRESS bloquean la descarga automatizada (anti-bot / WAF); siguen marcados como estimados. |
| Kilómetros de vías, hectáreas de cultivo | **No** | Estimaciones. |
| Vulnerabilidad y susceptibilidad | **No** | Calibradas a mano con inventario histórico, no son capa oficial. |
| Capacidad de albergues | **No** | Estimación. |
| Alertas/recomendaciones | **Generadas** | 22 alertas derivadas del snapshot; `emitida_por: Sistema · WILLAQ`. |
| Simulador climático/evacuación/reubicación | **Simulado** | Factores artificiales de lluvia, temperatura y caudal. |
| Evaluador de sitios (ISE) | **Heurístico** | Usa susceptibilidad distrital y distancia; no es un mapa parcelario de peligro. |
| Estaciones SENAMHI/ANA | **Fase 1: serie real · motor: no** | 103 estaciones en el registro, **23 series reales** (2017–2026) normalizadas con QA. El motor sigue en ERA5 (`motor_usa_estaciones = false`). |

Los valores de población de la versión original de esta auditoría eran
estimaciones del catálogo. Hoy **la población es oficial**: se aplicó el
overlay con la proyección distrital del INEI (2026). Lo que sigue siendo
estimación son las **viviendas, los colegios y los centros de salud**, y la
**fracción expuesta** en todos los casos. Cada pantalla dice qué campo es
oficial y cuál no.

La exposición oficial tiene ya separadas las dos piezas que importan:

- **El total** (población, viviendas, escuelas, centros de salud) puede ser
  oficial si se carga el overlay en `data/exposicion_oficial.json`.
- **La fracción expuesta** sigue siendo `total × susceptibilidad`, es decir,
  **siempre estimada**, y así se declara en la ficha, el panel y la API.

Las series de estaciones son una **medición real** del SENAMHI; su traslado a
un distrito (≤ 25 km, |Δaltitud| ≤ 500 m) sigue siendo una **estimación con
supuestos declarados** y todavía no alimenta el motor.

La validación histórica sí es real: 18 casos, ERA5, 13/18 detectados, 5
diagnosticados como limitación del dato de entrada. Eso está documentado y es
defendible.

---

## 5. ¿Tenemos base de datos?

**Sí, PostgreSQL activa** (actualización del 6 de noviembre de 2026; el texto
original de la auditoría describía MySQL sin base, y se conserva como
fotografía del estado inicial).

| Elemento | Estado actual |
|---|---|
| Servidor | **PostgreSQL** con la base `willaq_db` |
| `pdo_pgsql` | **Instalada y en uso** |
| `database/schema_postgresql.sql` | 6 tablas (`usuarios`, `territorios`, `alertas`, `cache_geodatos`, `escenarios`, `auditoria`), 2 vistas y 2 triggers |
| Semilla | 3 usuarios y 29 distritos |
| Archivo `.env` | **Existe** (`DB_DRIVER=pgsql`) |
| Conexión desde la app | La app lee y escribe **usuarios y alertas** en PostgreSQL |
| Respaldo | Archivos JSON: `data/alertas.json`, `data/cache/*.json`, `data/snapshots/*.json`, `data/climatologia_resumen.json`, `data/validacion_historica.json`, `data/sensibilidad.json` |

Es decir: la base ya no es solo un entregable de diseño. Si PostgreSQL no
responde, WILLAQ cae al respaldo JSON y la feria sigue funcionando; ese
comportamiento es deliberado.

Guía de activación y respaldo:
[`database/README_POSTGRESQL.md`](../database/README_POSTGRESQL.md).

### 5.1 Ingesta de datos, aparte de la base

Las series de estaciones **no viven en PostgreSQL**: se guardan como JSON en
`data/estaciones/`, porque son insumo de auditoría y no estado de la aplicación.
El registro está en `config/estaciones.json` y el reporte QA en
`data/estaciones/importacion.json`. La ingesta corre **offline** con Garúa
(Python) y `scripts/importar_estaciones.php`; nunca desde el servidor web.

---

## 6. Qué se puede decir con honestidad en la feria

**Defendible:**

- “WILLAQ se conecta a Open-Meteo para pronóstico, caudal y ERA5; lo verifiqué
  en vivo.”
- “El motor calcula riesgo con una fórmula reproducible y un backtest de
  18 casos.”
- “La exposición es una estimación declarada, no un dato oficial.” → Ahora es
  **mixta y declarada campo por campo**: la población es oficial del INEI; las
  viviendas, los colegios y los centros de salud siguen siendo estimación.
- “El sistema funciona sin internet con snapshots precalculados.”
- “Descargamos series reales del SENAMHI con Garúa, las auditamos y las
  dejamos listas; el motor todavía no las usa.”
- “Las recomendaciones no son alertas oficiales.”

**No defendible:**

- “Es tiempo real.” → No lo es; es snapshot + caché por hora.
- “Usa datos oficiales de INEI/MINEDU/MINSA.” → **Parcial**: la población sí
  es oficial del INEI (proyección 2026, verificada por sha256). Colegios y
  centros de salud no: esos portales bloquean la descarga automatizada.
- “Integra estaciones SENAMHI/ANA en el motor.” → Fase 1 sí (registro, series,
  QA); fase 2 **no**: `motor_usa_estaciones = false`.
- “Tiene base de datos.” → Esta ya **sí** es defendible: PostgreSQL `willaq_db`
  está activa.
- “Emite alertas oficiales.” → No, y el código lo dice explícitamente.

---

## 7. Tres fallas de transparencia detectadas

1. **El encabezado dice “Datos en línea”** cuando `WILLAQ_MODO_OFFLINE=0`, pero
   el tablero puede estar leyendo un snapshot de hace horas. Debería decir
   “Snapshot de las 18:57” o similar.
2. **`api_riesgo` sin `id` devuelve `generado_en = time()`**, aunque los riesgos
   vienen del snapshot. El timestamp superior no corresponde a la generación
   real del dato; los objetos internos sí tienen `actualizado` correcto.
3. **El modo offline sí es honesto** (“Modo feria offline. Los valores no
   reflejan el clima en tiempo real”), pero el modo online no advierte con la
   misma claridad.

Ninguna de las tres es un desastre, pero si el jurado compara la hora del
snapshot con la hora actual, puede preguntar. Es mejor corregirlas antes de la
feria.

---

## 8. Lo que conviene hacer antes de la demo

1. Ejecutar `php scripts/actualizar.php --ventana=all` el mismo día de la feria,
   idealmente 1 hora antes.
2. Configurar cron cada 6 horas si el hosting lo permite; si no, hacerlo manual.
3. Corregir la etiqueta “Datos en línea” y el `generado_en` de la API para que
   muestren la hora real del snapshot.
4. Verificar PostgreSQL antes de la feria (`psql` + `?action=admin`); si falla,
   el respaldo JSON mantiene la demo en pie.
5. Mantener el lenguaje de “estimación” y “recomendación de apoyo a la
   decisión”.
6. No presentar las estaciones como integradas en el motor: mostrar la sección
   «Cobertura de estaciones» con **4 criterios medibles / 0 habilitadas**.

---

## 9. Cómo reproducir esta auditoría

```bash
cd willaq

# 1. ¿Hay conexión real con Open-Meteo?
curl -s -m 12 -o /tmp/om.json -w 'HTTP %{http_code} en %{time_total}s\n' \
  'https://api.open-meteo.com/v1/forecast?latitude=-5.1945&longitude=-80.6328&current=temperature_2m,precipitation&timezone=America%2FLima'
cat /tmp/om.json

# 2. ¿Qué fuentes hay en caché?
php -r '$f=glob("data/cache/*.json");$x=[];foreach($f as $p){$d=json_decode(file_get_contents($p),true);$s=$d["payload"]["fuente"]??"?";$x[$s]=($x[$s]??0)+1;}arsort($x);print_r($x);'

# 3. ¿Cuándo se generaron los snapshots?
php -r 'foreach(glob("data/snapshots/*.json") as $f){$d=json_decode(file_get_contents($f),true);echo basename($f)," => ",date("Y-m-d H:i:s T",$d["generado_en"]),PHP_EOL;}'

# 4. ¿El tablero usa snapshot o dato vivo?
curl -s 'http://localhost:8000/?action=api_riesgo&id=piu-26-octubre' | php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["riesgo"]," | ",date("Y-m-d H:i:s T",$d["actualizado"]),PHP_EOL;'

# 5. ¿Existe la base de datos?
psql -h localhost -U willaq -d willaq_db -c "\dt"

# 6. Exposición: ¿hay overlay oficial cargado?
ls -la data/exposicion_oficial.json 2>/dev/null || echo "sin overlay: exposición estimada"
php -r 'require "models/Exposicion.php"; print_r(exposicion_resumen());'

# 7. Estaciones: ¿qué series hay y cuántas pasan la compuerta medible?
php -r 'require "models/Estacion.php"; $r=estacion_resumen();
 echo "registro={$r["total_estaciones"]} con_serie={$r["con_serie"]} ";
 echo "criterios={$r["cumplen_criterios_medibles"]} habilitadas={$r["habilitadas"]}\n";'
```

---

## 10. Resumen de una línea

WILLAQ es un prototipo funcional con **datos meteorológicos reales de
Open-Meteo**, **series reales de estaciones del SENAMHI en fase 1**,
**población oficial del INEI** (el resto de la exposición sigue estimado),
**snapshots actualizables a mano** y **PostgreSQL activa con respaldo JSON**.
No es un servicio operativo en tiempo real, el motor no usa todavía las
estaciones y la fracción expuesta sigue siendo una estimación: así debe
presentarse.

---

## Documentos relacionados

- [`PLAN_EXPOSICION_Y_ESTACIONES.md`](PLAN_EXPOSICION_Y_ESTACIONES.md)
- [`REQUERIMIENTOS.md`](REQUERIMIENTOS.md)
- [`METODOLOGIA.md`](METODOLOGIA.md)
- [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md)
- [`COMPARATIVA.md`](COMPARATIVA.md)
- [`MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md)
- [`API.md`](API.md)
- [`../README.md`](../README.md)
- [`../../PLAN_IMPLEMENTACION_WILLAQ.md`](../../PLAN_IMPLEMENTACION_WILLAQ.md)
