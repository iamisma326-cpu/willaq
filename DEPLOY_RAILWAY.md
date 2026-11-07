# Despliegue de WILLAQ en Railway

Guía paso a paso. Tiempo estimado: **10-15 minutos**.
El proyecto funciona **sin base de datos** (modo JSON) y **con Neon**.

---

## Archivos ya preparados

En la raíz del repo:

| Archivo | Para qué sirve |
|---|---|
| `nixpacks.toml` | Instala PHP 8.3 + extensiones (`pdo_pgsql`, `curl`, `mbstring`, `zip`, `simplexml`) y define el comando de arranque |
| `railway.json` | Builder Nixpacks, 1 réplica, reinicio ante fallo |
| `.railwayignore` | Excluye `.git/`, `docs/`, `deploy/`, `data/backups/` del build (deja entrar `data/snapshots/` y `data/cache/`) |

**Importante:** `data/snapshots/` y `data/cache/` deben subir al build. Sin ellos la
primera visita tarda ~70 s recalculando contra Open-Meteo. Con ellos responde en ~0,02 s.

---

## Paso 1 — Subir los archivos de despliegue a GitHub

```bash
cd willaq
git add nixpacks.toml railway.json .railwayignore
git commit -m "WILLAQ: configuración de despliegue en Railway"
git push origin main
```

---

## Paso 2 — Crear el proyecto en Railway

En el panel donde estás ahora (`New Project`), elige:

> **Deploy from GitHub repo** → autoriza GitHub si lo pide → selecciona `iamisma326-cpu/willaq`

Railway detecta el `nixpacks.toml` automáticamente y empieza a construir.

Si te pregunta por la rama: `main`.
Si te pide un directorio raíz: **déjalo vacío** (`willaq` es la raíz del repo).

---

## Paso 3 — Variables de entorno

Ve a tu servicio → pestaña **Variables** → **New Variable** → *Raw Editor* y pega:

```env
DB_DRIVER=pgsql
DB_HOST=PENDIENTE
DB_PORT=5432
DB_NAME=neondb
DB_USER=PENDIENTE
DB_PASS=PENDIENTE
DB_SSLMODE=require
DB_CONNEC_TIMEOUT=5
```

> Puedes desplegar **ya, sin Neon**. Con `DB_HOST=PENDIENTE` la conexión falla
> rápido y la app entra en modo JSON. Lo verifiqué: las 4 rutas principales
> responden HTTP 200 sin base de datos.

Cuando tengas Neon (paso 6), reemplaza `DB_HOST`, `DB_USER` y `DB_PASS`.
Usa el host **`-pooler`** de Neon.

---

## Paso 4 — Exponer el sitio

Servicio → **Settings** → **Networking** → **Generate Domain**.

Railway te da algo como `willaq-production.up.railway.app`.

Si el build falla por el puerto, confirma que el comando de arranque usa `${PORT:-8080}`
(ya está en `nixpacks.toml`).

---

## Paso 5 — Verificar

```bash
curl -o /dev/null -w "%{http_code}\n" https://TU-DOMINIO.up.railway.app/
curl -o /dev/null -w "%{http_code}\n" "https://TU-DOMINIO.up.railway.app/?action=api_riesgo"
curl -o /dev/null -w "%{http_code}\n" https://TU-DOMINIO.up.railway.app/assets/css/core/base.css
```

Los tres deben devolver `200`. En el panel admin (`?action=admin`) verás
si PostgreSQL está conectado o si está en modo demo.

---

## Paso 6 — Conectar Neon

1. Crea el proyecto en [neon.com](https://neon.com) (sin tarjeta).
2. Copia el **connection string** desde *Connect* → elige **Pooled connection**.
3. Carga el esquema:

```bash
psql "postgresql://USUARIO:PASS@ep-xxx-pooler.REGION.aws.neon.tech/neondb?sslmode=require" \
     -f database/schema_postgresql.sql
```

4. En Railway, actualiza `DB_HOST`, `DB_USER`, `DB_PASS` con los valores del pooled string.
5. Redeploy.

---

## Advertencias reales

### El filesystem es efímero
Cada redeploy **borra** `data/cache/` y `data/snapshots/`. Al arrancar de nuevo,
la primera visita recalcula desde Open-Meteo y tarda ~70 s.

**Mitigación aplicada:** los snapshots van en el build (están en git), así que
sobreviven al redeploy porque se reconstruyen desde el repo.

**Si la caché se pierde,** el sistema sigue correcto: solo más lento una vez.
Para persistencia real, añade un **Volume** en Railway y monta `/app/data`.

### El crédito de $1/mes es pequeño
Railway da $1/mes tras el trial de 30 días ($5). Un contenedor PHP idle consume poco,
pero si el sitio recibe tráfico constante puede agotarse y detenerse hasta el mes siguiente.

### Sin cron
No hay cron en Railway por defecto. Para refrescar datos, usa **Railway Cron Jobs**
(servicio aparte) o un cron externo que llame por HTTPS a `scripts/actualizar.php`.
