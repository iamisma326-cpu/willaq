# WILLAQ · PostgreSQL (local con DBeaver Community y nube)

> Guía de instalación de la base de datos **PostgreSQL** de WILLAQ.
>
> **Motor objetivo:** PostgreSQL 13 o superior (probado en PostgreSQL 18.6).
> Compatible con Amazon RDS/Aurora PostgreSQL, Google Cloud SQL para PostgreSQL
> y Neon.
>
> **Costos:** cero en local (DBeaver Community + PostgreSQL son libres).
>
> **Importante:** WILLAQ **funciona sin base de datos**. Los archivos JSON de
> `data/` y las cuentas de demostración son el respaldo. La base de datos es
> para cuando ya quieras persistencia real, varias máquinas o usuarios.

---

## 1. Los dos archivos `.sql` del proyecto

| Archivo | Cuándo se ejecuta | Qué hace |
|---|---|---|
| `database/00_crear_bd_postgresql.sql` | Conectado a la base `postgres` | Crea el rol `willaq` y la base `willaq_db` |
| `database/schema_postgresql.sql` | Conectado a `willaq_db` | Crea tablas, vistas, triggers y la semilla (3 usuarios + 29 distritos) |

Orden obligatorio: **primero `00_crear_bd_postgresql.sql`, después
`schema_postgresql.sql`**.

Los dos scripts son **idempotentes**: se pueden volver a ejecutar sin romper
nada ni borrar datos.

---

## 2. Opción A — Local con DBeaver Community (la del proyecto)

### 2.1 Requisitos

| Elemento | Para qué |
|---|---|
| PostgreSQL 13+ (servidor) | La base |
| DBeaver Community | Administrar la base con interfaz gráfica |
| PHP con `pdo_pgsql` | Que la aplicación se conecte |

En Linux (Debian/Ubuntu):

```bash
sudo apt install -y postgresql postgresql-client
```

En Windows: instalador oficial de PostgreSQL (EDB) y DBeaver Community.
En macOS: `brew install postgresql@16` y `brew install --cask dbeaver-community`.

### 2.2 Crear la base con DBeaver

1. Abre **DBeaver Community** → **New Database Connection** → **PostgreSQL**.
2. Configura la conexión:
   - **Host:** `localhost`
   - **Port:** `5432`
   - **Database:** `postgres` (la de sistema, para crear la nuestra)
   - **Authentication:** Database Native → **User:** `postgres`, **Password:** la que definiste al instalar PostgreSQL
3. **Test Connection** → debe decir *Connected*.
4. Abre **File → Open** y selecciona
   `willaq/database/00_crear_bd_postgresql.sql`.
5. Ejecuta el script (**Ctrl+Shift+Execute / F5**). Como este archivo usa
   comandos propios de `psql` (`\gexec`, `\connect`), **DBeaver puede no
   interpretarlos**.

> **Si DBeaver se atora con el script 00:** no hay problema, es una limitación
> del editor de DBeaver con los meta-comandos de `psql`. Crea la base a mano
> desde DBeaver (**New Database** → nombre `willaq_db`) y el usuario desde
> **Security → Users → Create User** (`willaq`, con permiso de owner sobre
> `willaq_db`). Después ejecuta **solo** `schema_postgresql.sql`, que sí es
> SQL estándar y DBeaver lo ejecuta sin problemas.

Alternativa sin DBeaver, con `psql`:

```bash
cd willaq
export WILLAQ_DB_PASS='tu-clave-local'
psql -U postgres -d postgres -v WILLAQ_DB_PASS="$WILLAQ_DB_PASS" \
     -f database/00_crear_bd_postgresql.sql

psql -U willaq -d willaq_db -f database/schema_postgresql.sql
```

### 2.3 Verificar la base

```bash
psql -U willaq -d willaq_db -c "\dt"
```

Debe listar 6 tablas: `alertas`, `auditoria`, `cache_geodatos`, `escenarios`,
`territorios`, `usuarios`.

```bash
psql -U willaq -d willaq_db -c \
  "SELECT 'usuarios' t, count(*) FROM usuarios
   UNION ALL SELECT 'territorios', count(*) FROM territorios
   UNION ALL SELECT 'alertas', count(*) FROM alertas;"
```

Esperado: `3` usuarios, `29` territorios, `0` alertas al inicio.

### 2.4 Conectar la aplicación

Copia `.env.example` a `.env` y ajústalo:

```ini
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_NAME=willaq_db
DB_USER=willaq
DB_PASS=tu-clave-local
DB_SSLMODE=prefer
DB_CONNEC_TIMEOUT=5
```

Comprueba que PHP tiene el driver:

```bash
php -m | grep pdo_pgsql
```

Si no aparece, instálalo con el gestor de paquetes de tu sistema:

```bash
# Arch / CachyOS
sudo pacman -S php-pgsql

# Debian / Ubuntu
sudo apt install php-pgsql          # o php8.3-pgsql según tu versión

# Fedora / RHEL
sudo dnf install php-pgsql

# Windows: marcar la extensión en el instalador de PHP
```

Si usas Arch/CachyOS y el repositorio está desactualizado, actualiza primero
(`sudo pacman -Sy`) y comprueba que la versión de `php-pgsql` coincida con la
de `php` (`php -v`). Una extensión compilada para otra versión de PHP falla
con `undefined symbol` y no se carga.

En esta máquina quedó instalado así: PHP 8.5.12 + `php-pgsql` desde pacman,
habilitado en `/etc/php/php.ini` con `extension=pdo_pgsql`.

### 2.5 Probar la aplicación

```bash
php -S localhost:8000
```

1. Entra en `http://localhost:8000/?action=admin` con
   `admin@willaq.pe` / `willaq2026`.
2. En la tarjeta **Persistencia** debe decir **PostgreSQL**, no JSON.
3. Genera recomendaciones:
   ```bash
   php scripts/semear_alertas.php --limpiar
   ```
   Debe announce: `Almacenamiento: PostgreSQL (con espejo JSON)`.
4. Comprueba en DBeaver:
   ```sql
   SELECT codigo, distrito, nivel, estado, emitida_en FROM alertas ORDER BY emitida_en DESC;
   ```

---

## 3. Opción B — AWS (RDS / Aurora PostgreSQL)

### 3.1 Crear la instancia

1. Consola AWS → **RDS** → **Create database**.
2. Motor: **PostgreSQL** (o **Aurora → PostgreSQL**).
3. Configuración mínima para una feria:
   - Versión: PostgreSQL 16 o 17
   - Plantilla: **Free tier** o `db.t4g.micro`
   - Almacenamiento: **General Purpose, 20 GB** (mínimo de RDS)
   - **Public access: No** si la VM está en la misma VPC; **Sí** solo si la
     app corre fuera de AWS (y con reglas de seguridad restringidas)
   - VPC: la misma que la máquina donde corre la aplicación
   - Zona de disponibilidad: la de la región

### 3.2 Base, usuario y firewall

```bash
# Crear la base y el usuario (desde el bastion o la máquina de la VPC)
psql -h HOST_RDS -U USUARIO_MAESTRO -d postgres \
  -c "CREATE DATABASE willaq_db OWNER willaq;" \
  -c "CREATE USER willaq WITH PASSWORD 'CLAVE_SEGURA';" \
  -c "GRANT ALL PRIVILEGES ON DATABASE willaq_db TO willaq;"
```

En **RDS → Security Groups**, agrega una regla **Inbound**:
- Tipo: PostgreSQL
- Puerto: 5432
- Origen: el grupo de seguridad o la IP de la máquina de la aplicación
  (nunca `0.0.0.0/0`)

### 3.3 Aplicar el esquema

```bash
psql "host=HOST_RDS port=5432 dbname=willaq_db user=willaq password=CLAVE_SEGURA sslmode=require" \
     -f database/schema_postgresql.sql
```

El script `00_crear_bd_postgresql.sql` **no se usa en RDS**: la base y el
usuario los administra el proveedor y se crean con los comandos de la
sección 3.2.

### 3.4 `.env` en la VM

```ini
DB_DRIVER=pgsql
DB_HOST=HOST_RDS
DB_PORT=5432
DB_NAME=willaq_db
DB_USER=willaq
DB_PASS=CLAVE_SEGURA
DB_SSLMODE=require
DB_CONNEC_TIMEOUT=8
```

> `DB_SSLMODE=require` es obligatorio en RDS: el tráfico entre la aplicación y
> la base viaja dentro de la VPC, pero el cifrado sigue siendo lo correcto.

### 3.5 Copias de seguridad

- **RDS → Automated backups**: activa las copias automáticas (7 días por
  defecto). Verifica que el *retention* no sea 0.
- **RDS → Export**: para copias puntuales a S3.
- La alternativa barata y portable es `pg_dump` desde la VM:
  ```bash
  pg_dump -h HOST_RDS -U willaq -d willaq_db -Fc -f willaq_$(date +%F).dump
  ```
  Prográmalo en el mismo `systemd timer` o cron que ya usa WILLAQ
  (`scripts/willaq_cron.sh`).

---

## 4. Opción C — Google Cloud SQL para PostgreSQL

### 4.1 Crear la instancia

```bash
gcloud sql instances create willaq-postgres \
  --database-version=POSTGRES_16 \
  --tier=db-f1-micro \
  --region=southamerica-west1 \
  --no-assign-ip \
  --enable-point-to-point-recovery
```

> `--no-assign-ip` deja la instancia sin IP pública: es lo correcto. La
> aplicación se conecta desde una VM de Compute Engine en la **misma red de
> VPC**.

Crear la base y el usuario:

```bash
gcloud sql databases create willaq_db --instance=willaq-postgres

gcloud sql users create willaq \
  --instance=willaq-postgres \
  --password='CLAVE_SEGURA'
```

Otorgar privilegios de owner sobre la base:

```bash
gcloud sql users set-cloudsql-superuser \
  --instance=willaq-postgres willaq
```

### 4.2 Conectar la aplicación

La instancia tiene un **proxy de Cloud SQL**. Con `psql`:

```bash
gcloud sql connect willaq-postgres --user=willaq --database=willaq_db
```

Y desde la aplicación en la VM, lo más simple es el proxy con socket Unix:

```bash
gcloud sql connect willaq-postgres --user=willaq --database=willaq_db \
  --quiet --enable-os-login &
```

```ini
DB_DRIVER=pgsql
DB_HOST=/cloudsql/PROYECTO:REGION:willaq-postgres
DB_PORT=5432
DB_NAME=willaq_db
DB_USER=willaq
DB_PASS=CLAVE_SEGURA
DB_SSLMODE=require
```

> `DB_HOST` con ruta a socket Unix también funciona con PDO: en el DSN,
> `host=/ruta/al/socket`.

### 4.3 Alternativa con IP privada

Si prefieres conectar por TCP a la IP privada:

```bash
gcloud sql instances patch willaq-postgres \
  --authorized-networks=PROJECT_ID/VPC_NAME
```

Y en `.env`:

```ini
DB_HOST=IP_PRIVADA
DB_SSLMODE=require
```

### 4.4 Copias de seguridad

- **Cloud SQL → Backups**: automáticas, 7 días por defecto (configurable hasta
  35 días).
- **Cloud SQL → Export**: a Cloud Storage, para copias puntuales.
- `pg_dump` también funciona igual que en AWS.

---

## 5. Cómo decide la aplicación dónde guardar

La lógica está en `config/database.php` y es deliberadamente simple:

```
1. ¿Hay variables DB_* en .env?
   NO  → modo JSON: data/alertas.json + cuentas demo
   SÍ  → paso 2

2. ¿Responde el servidor PostgreSQL en DB_CONNEC_TIMEOUT segundos?
   NO  → modo JSON (se registra el error en el log, no se interrumpe la demo)
   SÍ  → modo base de datos

3. En modo base de datos:
   - usuarios    → tabla `usuarios` (PostgreSQL)
   - alertas     → tabla `alertas` (PostgreSQL)
   - y ADEMÁS se escribe data/alertas.json como espejo de seguridad
```

**Por qué el espejo JSON:** si el servidor de base de datos se cae durante la
feria, la aplicación sigue mostrando la última información conocida en vez de
quedarse en blanco. Es el mismo criterio que ya se usa para los snapshots y la
caché.

**Cambiar de motor:** `DB_DRIVER=mysql` activa el motor heredado y usa
`database/schema.sql`. PostgreSQL es el motor recomendado.

---

## 6. Problemas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| `could not find driver` | Falta la extensión | `php -m \| grep pdo_pgsql`. Instala `php-pgsql` o recompila la extensión |
| `FATAL: role "willaq" does not exist` | No se ejecutó el script 00 | Ejecuta `database/00_crear_bd_postgresql.sql` con el usuario `postgres` |
| `FATAL: database "willaq_db" does not exist` | La base no se creó | Revisa el paso 2.2; en DBeaver, crea la base a mano |
| `password authentication failed for user "willaq"` | Contraseña distinta | Vuelve a ejecutar el script 00 con `-v WILLAQ_DB_PASS=...`, o `ALTER ROLE willaq WITH PASSWORD '...'` |
| El panel dice **JSON** en vez de **PostgreSQL** | Credenciales o red | Revisa `.env`; mira el error real en el log del servidor (`error_log`) |
| `no pg_hba.conf entry ... SSL encryption` | Falta SSL en la nube | `DB_SSLMODE=require` (AWS/GCP) o `verify-full` si tienes el certificado |
| La siembra dice `Almacenamiento: archivo JSON` | La base no está accesible | Revisa `.env` y que el servidor PostgreSQL esté encendido |
| `duplicate key value violates unique constraint` al sembrar | Corre dos siembras a la vez | El wrapper `scripts/willaq_cron.sh` ya usa `flock`; no lances dos procesos a mano |
| DBeaver no ejecuta el script 00 | Meta-comandos de `psql` | Crea la base y el usuario a mano en DBeaver (sección 2.2) |

---

## 7. Verificación completa (checklist)

```bash
# 1. La base responde
psql -U willaq -d willaq_db -c "SELECT count(*) FROM territorios;"

# 2. PHP tiene el driver
php -m | grep pdo_pgsql

# 3. La aplicación conecta y siembra
php scripts/semear_alertas.php --limpiar

# 4. Los datos llegaron a la tabla
psql -U willaq -d willaq_db -c "SELECT count(*) FROM alertas;"

# 5. El espejo JSON existe (respaldo de la feria)
ls -l data/alertas.json

# 6. El panel muestra PostgreSQL
curl -s "http://localhost:8000/?action=admin" | grep -o "PostgreSQL\|JSON"
```

---

## 8. Datos y honestidad

- Los **29 distritos** de la tabla `territorios` son un **catálogo piloto**.
- `poblacion`, `viviendas`, `escuelas`, `centros_salud` y
  `albergues_capacidad` son **estimaciones** de referencia, no cifras oficiales.
  La columna `fuente_exposicion` lo declara en cada fila
  (`estimacion_ratio_poblacional`).
- La tabla `usuarios` trae 3 cuentas de demostración con la contraseña
  `willaq2026`. **Cámbialas antes de publicar la base.**
- Las alertas son **recomendaciones de apoyo a la decisión**, nunca alertas
  oficiales de SENAMHI, INDECI o ENFEN. El texto legal viaja en cada fila
  (columna `nota_legal`) y se muestra en la interfaz.

---

*Documento redactado el 6 de noviembre de 2026. Comandos verificados sobre
PostgreSQL 18.6 local con PHP 8.5.8 y `pdo_pgsql`.*
