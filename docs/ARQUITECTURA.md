# Arquitectura de WILLAQ

> PHP puro, sin framework, sin Composer, sin build.
> Hosting compartido y `php -S` como targets idénticos.

---

## 1. Vista general

```
                        ┌──────────────────────────────────────────┐
                        │            NAVEGADOR / JURADO           │
                        └───────────────────┬──────────────────────┘
                                            │ HTTP  ?action=...
                    ┌───────────────────────▼──────────────────────┐
                    │            index.php  (ENRUTADOR)           │
                    │  switch ($accion) → vista / JSON / redirect │
                    └───────┬───────────────────────────┬─────────┘
                            │                           │
              ┌─────────────▼─────────────┐  ┌──────────▼──────────────┐
              │   CAPA DE PRESENTACIÓN     │  │   CAPA DE DOMINIO       │
              │   views/                   │  │   models/               │
              │   home mapa ficha alertas  │  │   Territorio Clima      │
              │   planificacion simulador  │  │   Riesgo Zonificacion   │
              │   admin ayuda reportes     │  │   Simulador Alerta      │
              └────────────────────────────┘  │   Usuario Cache         │
                                            └──────────┬──────────────┘
                                                       │
                              ┌────────────────────────┴───────────────────┐
                              │              CAPA DE DATOS                │
                              │  config/territorios.json  config/umbrales │
                              │  data/cache/*.json        data/snapshots/ │
                              │  data/alertas.json    PostgreSQL (opcional)│
                              └────────────────────────┬───────────────────┘
                                                       │
                                  ┌────────────────────▼────────────────────┐
                                  │  FUENTES EXTERNAS (solo por CLI)       │
                                  │  Open-Meteo Forecast · Archive (ERA5)  │
                                  │  Open-Meteo Flood (GloFAS)             │
                                  └─────────────────────────────────────────┘
```

**Regla de oro:** la capa de presentación nunca habla con una API externa.
Pide datos al dominio; el dominio pide datos a la caché; la caché decide si
llama a la red. Por eso la misma aplicación funciona con internet, sin
internet y en modo feria.

---

## 2. Enrutador por acciones

`index.php` mantiene el patrón procedural heredado del proyecto base: un
`switch ($accion)` que despacha. No hay framework, no hay container, no hay
magic. La decisión de diseño es deliberada: el equipo puede explicar el flujo
completo de una petición línea por línea en la sustentación.

```
?action=<nombre>          GET o POST
```

| Grupo | Acciones |
|---|---|
| **API JSON** | `api_territorios`, `api_riesgo`, `api_simular`, `api_evaluar` |
| **Sesión** | `login`, `logout`, `registro` |
| **Dominio** | `mapa`, `territorio`, `alertas`, `alerta`, `planificacion`, `simulador`, `reporte`, `ayuda`, `home` |
| **Operación** | `emitir_alerta`, `acusar`, `actualizar_datos`, `limpiar_cache`, `admin` |

Convenciones transversales:

* `render($vista, $vars)` envuelve cualquier vista en el layout común.
* `json_salida($datos, $codigo)` emite JSON con CORS abierto y termina la
  petición.
* `redirigir($url)` evita el reenvío de formularios (PRG).
* `e()` escapa **todo** lo que llega a HTML. No hay plantilla que lo haga por
  nosotros.

### Flujo de una página

```
GET /?action=territorio&id=piu-catacaos
  → territorio_por_id()                    config/territorios.json
  → riesgos_para_vista()                  snapshot si coincide, si no calcula
  → zonificacion_ise_territorio()         models/Zonificacion.php
  → render('territorio/ficha', [...])     views/territorio/ficha.php
  → header.php + footer.php
```

---

## 3. Modelos

| Modelo | Responsabilidad | Estado |
|---|---|---|
| `Territorio.php` | Catálogo de 29 distritos: geometría, población expuesta, susceptibilidad, vulnerabilidad, cultivo | `config/territorios.json` |
| `Clima.php` | Pronóstico, caudal, histórico y climatología desde Open-Meteo; cliente HTTP con reintentos | Caché de archivo |
| `Riesgo.php` | **Motor multi-amenaza**: normalización, materialidad, seis amenazas, índice, snapshot | Sin estado |
| `Zonificacion.php` | ISE (Índice de Seguridad de Emplazamiento) y evaluación de un punto | Sin estado |
| `Simulador.php` | Escenarios clima / evacuación / reubicación | Sin estado |
| `Alerta.php` | Recomendaciones, protocolos, acuses de recibo | `data/alertas.json` |
| `Usuario.php` | Sesión, roles (ciudadano / autoridad / administrador) | `data/usuarios.json` o sesión |
| `Cache.php` | Caché de tres niveles | `data/cache/*.json` |

### El motor de riesgo (`models/Riesgo.php`)

Es el corazón del sistema y está organizado así:

```
riesgo_norm($v, $p50, $p95, $p99)      normalización piecewise por percentil
riesgo_rampa($v, $min, $signo)          factor de materialidad 0→1
riesgo_indice_lluvia(...)               H = norm × rampa  ← CAMINO COMPARTIDO
riesgo_exp_poblacion($pob)              exposición con saturación
riesgo_nivel($valor)                    bandas continuas → nivel
riesgo_calcular($t, $ventana, $esc)     orquesta las 6 amenazas
riesgo_nacional($ventana)               los 29 distritos
riesgo_snapshot_leer() / _guardar()     snapshot de riesgo
```

> **Decisión clave:** `riesgo_indice_lluvia()` es el **único** camino de
> código que calcula el peligro por lluvia. Lo usan el motor en producción y
> `scripts/validar_historico.php`. Así, la validación histórica no valida una
> reimplementación del modelo, sino el modelo mismo. Cuando la fórmula cambia,
> cambia en los dos lados a la vez.

Las seis amenazas (`lluvia`, `inundacion`, `huaico`, `sequia`, `calor`,
`agro`) se calculan por separado y cada una devuelve:

```php
[
  'peligro'       => float,   // H
  'exposicion'    => float,   // E
  'vulnerabilidad'=> float,   // V
  'riesgo'        => float,   // 0-100
  'nivel'         => string,  // verde|amarillo|naranja|rojo
  'detalle'       => [        // trazabilidad: variable, valor, umbral, fuente
      ['variable' => '...', 'valor' => 0, 'unidad' => 'mm',
       'umbral' => 0, 'fuente' => 'Open-Meteo', 'peso' => 1.0],
  ],
]
```

El `detalle[]` es lo que hace que el sistema sea auditable: la ficha de
distrito y el reporte PDF muestran el valor, el umbral con el que se
comparó y de dónde salió cada número.

---

## 4. Caché de tres niveles

`models/Cache.php` implementa la estrategia que permite operar sin internet:

| Nivel | Ubicación | TTL | Uso |
|---|---|---|---|
| 1 | Memoria del proceso (array estático) | igual que el archivo | Evita releer disco en la misma petición |
| 2 | `data/cache/<sha1>.json` | 1 h (pronóstico), 24 h (climatología) | Sobrevive entre peticiones y reinicios |
| 3 | El mismo archivo, vencido | — | **Respaldo de último dato bueno**: si la API falla, se usa el valor previo y se marca como expirado |

El nivel 3 es el que hace posible la feria sin wifi: si el servidor no tiene
internet, la aplicación sigue mostrando la última información conocida en
lugar de una pantalla de error.

Las claves de caché se nombran por contenido, no por URL:

```
forecast:<lat>:<lon>:<dias>
flood:<lat>:<lon>
archivo:<lat>:<lon>:<inicio>:<fin>
climatologia:<id_distrito>
```

---

## 5. Modo feria (offline)

Se activa con la variable de entorno `WILLAQ_MODO_OFFLINE=1`:

```bash
WILLAQ_MODO_OFFLINE=1 php -S localhost:8000
```

Efectos:

1. `models/Riesgo.php` deja de calcular riesgo en línea y lee
   `data/snapshots/riesgo_nacional_{24,48,72}h.json` — un archivo por
   ventana, para que el selector 24/48/72 h responda sin recalcular.
2. `views/layout/header.php` muestra una **franja visible** que declara que
   los datos son precalculados. No se oculta el estado del sistema: quien
   mira la pantalla sabe que no hay conexión.
3. El mapa sigue funcionando: usa un estilo propio con fondo vectorial, y
   las teselas de OpenStreetMap y las capas NASA GIBS se cargan solo si hay
   red. Sin red, el mapa muestra los marcadores sobre el fondo oscuro.

### Pipeline de preparación

```bash
php scripts/calibrar.php              # climatología p50/p95/p99 (ERA5, 11 años)
php scripts/actualizar.php             # calienta caché + snapshot 24 h
php scripts/actualizar.php --ventana=all   # snapshots 24/48/72 h
php scripts/semear_alertas.php         # recomendaciones desde el snapshot
php scripts/validar_historico.php      # contraste 2017 / 2017-18 / 2023
php scripts/sensibilidad.php --verificar   # sensibilidad de los pesos
```

`calibrar.php` y `actualizar.php` **fuerzan** `WILLAQ_MODO_OFFLINE=0`:
son las únicas tareas que necesitan la red, y se ejecutan antes de la demo.

Cada ventana tiene su propio archivo de snapshot
(`riesgo_nacional_24h.json`, `_48h.json`, `_72h.json`). Antes había un único
archivo que se sobrescribía en cada pasada, de modo que al terminar solo
quedaba el de 72 h y el modo offline no encontraba el de 24 h —la ventana por
defecto de la sala de situación.

`validar_historico.php` y `sensibilidad.php` son **tareas de auditoría**, no
de preparación: no hacen falta para la demo, pero son las que sostienen la
defensa técnica del proyecto.

---

## 6. Persistencia: dos rutas, ninguna obligatoria

### Ruta por defecto (sin base de datos)

| Dato | Ubicación | Formato |
|---|---|---|
| Catálogo territorial | `config/territorios.json` | JSON |
| Caché de API | `data/cache/*.json` | JSON |
| Snapshot de riesgo | `data/snapshots/riesgo_nacional_{24,48,72}h.json` | JSON |
| Recomendaciones | `data/alertas.json` | JSON |
| Validación histórica | `data/validacion_historica.json` | JSON |
| Sensibilidad de pesos | `data/sensibilidad.json` | JSON |
| Usuarios | sesión + `data/usuarios.json` | JSON |

Todo es texto plano y versionable. Se puede auditar con `git diff`.

### Ruta opcional (PostgreSQL)

`database/schema_postgresql.sql` define el equivalente relacional:

```
usuarios          → cuentas, roles, hash de contraseña
territorios       → catálogo con geometría
alertas           → recomendaciones emitidas y acuses
cache_geodatos    → espejo de la caché de archivo
escenarios        → simulaciones guardadas
auditoria         → trazabilidad de operaciones
```

`config/database.php` abre la conexión PDO **si** hay credenciales en `.env`
**y** la conexión tiene éxito. Si algo falla, la aplicación sigue con la ruta
JSON. No hay modo "roto": o se usa la base, o se usa el archivo.

> Con la base conectada, `data/alertas.json` se sigue escribiendo como **espejo
> de seguridad**: si el servidor de base de datos cae durante la feria, la
> aplicación muestra la última información conocida en vez de quedar en blanco.

> Para la feria, la ruta JSON es la recomendada: menos piezas, menos formas de
> que algo falle. La base se activa cuando ya haya varios usuarios o varias
> máquinas; guía en [`database/README_POSTGRESQL.md`](../database/README_POSTGRESQL.md).

---

## 7. Seguridad

| Control | Implementación |
|---|---|
| Contraseñas | `password_hash()` con bcrypt (coste 12); nunca en texto plano |
| XSS | `e()` en toda salida; `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` |
| SQL | PDO con consultas preparadas; el SQL solo se usa en el esquema opcional |
| Sesión | `session_start()` con cookies `HttpOnly` y `SameSite=Lax` |
| Autorización | Comprobación de rol por acción: solo `autoridad`/`admin` emiten recomendaciones; solo `admin` entra al panel |
| Escape de datos | Todo lo que llega por GET/POST se filtra y se vuelve a validar en el modelo |
| Cabeceras | `Content-Type` y CORS declarados explícitamente en la salida JSON |

**Credenciales de demostración:** `admin@willaq.pe`, `defensa@willaq.pe`,
`ciudadano@willaq.pe` / `willaq2026`. Son de demostración: **cambiar antes de
cualquier despliegue real**. El hash vive en `models/Usuario.php` y en
`database/schema.sql`.

---

## 8. Frontend

Sin build step. Tailwind se carga como script en runtime
(`assets/vendor/tailwind.js`), igual que MapLibre y Chart.js. Todas las
librerías están **locales** en `assets/vendor/`, sin CDN: la aplicación se ve
igual con o sin internet.

| Pieza | Ubicación | Rol |
|---|---|---|
| Montserrat variable | `assets/fonts/montserrat-var.woff2` | Tipografía (heredada del proyecto base) |
| Tailwind runtime | `assets/vendor/tailwind.js` | Utilidades |
| MapLibre GL | `assets/vendor/maplibre-gl.js` | Mapa vectorial |
| Chart.js | `assets/vendor/chart.umd.min.js` | Gráficos de ficha y simulador |
| Capa WILLAQ | `assets/css/views/willaq.css` | Sistema visual propio (azul/dorado) |

**Carga de MapLibre:** el script se incluye en el `<head>` cuando la vista lo
necesita (`$requiereMapa`), **no** en el pie. La vista del mapa lo usa durante
su propio `<script>`, que se ejecuta antes que los del pie: cargarlo al final
provocaba un `maplibregl is not defined` que rompía el mapa en silencio.

---

## 9. Rendimiento

| Medida | Efecto |
|---|---|
| Snapshot de riesgo | La sala de situación y el mapa leen un archivo, no recalculan 29 distritos |
| Caché de archivo | El pronóstico se lee en milisegundos en vez de ~3 s por distrito |
| Climatología offline | El cálculo de percentiles (≈150 s) ocurre una vez en `calibrar.php`, nunca en la web |
| CSS único de WILLAQ | Se hereda el CSS del proyecto base; no hay dos capas compitiendo |

Medición del arranque en frío (29 distritos, sin caché): **~143 s**. Con caché
calentada: **< 1 s** por página.

---

## 10. Estructura de archivos

```
willaq/
├── index.php                    Enrutador por acciones
├── .env.example                 Plantilla de configuración
├── config/
│   ├── env.php                  Cargador de .env
│   ├── database.php             PDO opcional
│   ├── territorios.json         Catálogo de 29 distritos
│   ├── estaciones.json          Registro de 103 estaciones SENAMHI
│   └── umbrales.php             Pesos, niveles, protocolos, materialidad
├── models/                      Lógica de dominio (10 archivos)
├── views/                       Presentación (12 vistas + layout)
├── scripts/                     Tareas CLI e ingesta (12 scripts)
├── data/
│   ├── cache/                   Caché de API (archivos JSON)
│   ├── snapshots/               Riesgo nacional precalculado (24/48/72 h)
│   ├── estaciones/              Series normalizadas, QA y catálogo SENAMHI
│   ├── exposicion_oficial.json  Overlay de exposición (procedencia por campo)
│   ├── alertas.json             Recomendaciones emitidas
│   ├── climatologia_resumen.json
│   ├── validacion_historica.json
│   └── sensibilidad.json
├── datos/
│   ├── ejemplo/                 Fixtures sintéticos del importador (NO oficiales)
│   └── oficial/                 XLSX/CSV oficiales (INEI) + procedencia
├── assets/                      CSS, JS, fuentes, imágenes (todo local)
├── database/
│   ├── 00_crear_bd_postgresql.sql  Rol y base
│   ├── schema_postgresql.sql       Esquema PostgreSQL principal
│   ├── README_POSTGRESQL.md        Guía DBeaver / AWS / GCP
│   └── schema.sql                  Esquema MySQL heredado
└── docs/                        METODOLOGIA · ARQUITECTURA · MANUAL_USUARIO
                                 MANUAL_INSTALACION · MANUAL_DISENO
                                 REQUERIMIENTOS · GUION_DEMO
                                 VALIDACION_HISTORICA · API · COMPARATIVA
```

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

## 11. Decisiones de diseño y su porqué

| Decisión | Alternativa descartada | Por qué |
|---|---|---|
| Enrutador `switch` en `index.php` | Framework MVC | Patrón heredado, cero dependencias, explicable en sustentación |
| PHP nativo | Composer + Laravel/Slim | Hosting compartido sin SSH; el enunciado no permite instalar |
| JSON en archivos (con PostgreSQL opcional) | Base de datos obligatoria | Sin base de datos la aplicación funciona; menos piezas = menos fallos en feria |
| PostgreSQL como motor principal | MySQL | Mismo script `.sql` sirve en local (DBeaver) y en AWS RDS / Google Cloud SQL; `JSONB`, `TIMESTAMPTZ` e identidades nativas |
| Percentiles locales | Umbrales nacionales | 40 mm/día en Tumbes y en Puno no son lo mismo |
| Materialidad | Percentil solo | "1.3 mm en Lima" era estadísticamente extremo y operativamente absurdo |
| Snapshot precalculado | Cálculo en cada visita | 29 distritos × 6 amenazas en cada carga es inviable en hosting compartido |
| Un snapshot por ventana | Un único archivo | Con un solo archivo, `--ventana=all` dejaba solo el de 72 h y el modo offline no encontraba el de 24 h |
| Redondeo de H/E/V antes de la fórmula | Cálculo con más precisión | El índice de la ficha tiene que poder rehacerse a mano con los valores que se muestran |
| Una sola función de lluvia | Dos implementaciones | La validación histórica debe probar **el** modelo, no una copia |
| Sin CDN | CDN para las librerías | En la feria puede no haber internet; el diseño debe verse igual siempre |
| CORS abierto en la API | Autenticación | La API es de consulta pública para el mapa; la escritura sí exige rol |
