# Manual de instalación — WILLAQ

> Instalación completa de WILLAQ en **hosting compartido** (cPanel, Plesk,
> Hostinger, AwardSpace…) o en un equipo local con `php -S`.
>
> **Tiempo estimado:** 10 minutos si el hosting ya tiene PHP; 25 si además
> quieres PostgreSQL.
> **Costo de licencia:** cero. PHP, Open-Meteo, MapLibre, Chart.js y Tailwind
> son libres.

---

## 1. Requisitos

### 1.1 Mínimos (suficientes para la feria)

| Elemento | Versión | Nota |
|---|---|---|
| **PHP** | **8.0 o superior** | Se usa `match` y `str_contains`, que son de PHP 8.0. Verificado en 8.5.8 |
| Extensiones | `curl`, `json`, `mbstring` | `json` viene incorporada desde PHP 8.0 |
| Sesiones | habilitadas | `session.save_path` debe ser escribible |
| Espacio | 20 MB | La caché y los snapshots ocupan ~4 MB |
| Red | no necesaria en modo feria | Solo hace falta para la carga inicial de datos |

### 1.2 Opcionales

| Elemento | Para qué | Si falta |
|---|---|---|
| `pdo_pgsql` + PostgreSQL 13+ | Cuentas de usuario reales, registro e historial de alertas en la base | El sistema **funciona igual** en modo demostración, con las tres cuentas de prueba y el historial en `data/alertas.json` |
| SSH o acceso a cron | Actualización automática de snapshots | Los snapshots se regeneran a mano o desde el panel de administración |

> **No hace falta Composer.** No hay `vendor/`, no hay `composer.json`, no hay
> `package.json`. Las librerías de JavaScript están **descargadas y servidas
> localmente** en `assets/`, precisamente para que la demostración no dependa
> de un CDN. Esto es una decisión deliberada, no una limitación.

### 1.3 Verificar tu PHP antes de subir nada

```bash
php -v                    # debe indicar 8.0 o superior
php -m | grep -E "curl|mbstring|pdo_pgsql"   # curl y mbstring deben salir
```

---

## 2. Opción A — Instalación local (la más rápida, para la feria)

### 2.1 Requisitos y arranque

```bash
cd willaq
php -S localhost:8000
```

Abrir `http://localhost:8000`. **Listo.** No hay base de datos, ni archivos de
configuración obligatorios, ni pasos de compilación.

### 2.2 Preparar los datos (una sola vez, con internet)

```bash
# 1. Calentar la caché de todo (pronóstico, caudal, histórico)
php scripts/actualizar.php --ventana=all

# 2. Generar las recomendaciones coherentes con el snapshot
php scripts/semear_alertas.php --limpiar
```

Tarda entre 30 y 90 segundos con buena conexión. Deja ~4 MB de caché y 3
snapshots (~155 KB cada uno).

### 2.3 Presentar sin depender del wifi

```bash
WILLAQ_MODO_OFFLINE=1 php -S localhost:8000
```

En modo offline el sistema lee `data/snapshots/riesgo_nacional_{24,48,72}h.json`
—un archivo por ventana— y **muestra una franja visible** que declara que los
datos son precalculados. No es un modo silencioso: quien mira la pantalla sabe
lo que está viendo.

> **Comprobar que el modo offline está activo** (si no lo está, la variable de entorno
> no llega a PHP; WILLAQ lo resuelve leyendo también con `getenv()`):
>
> ```bash
> curl -s "http://localhost:8000/?action=home" | grep -o "Modo offline\|Datos en línea"
> ```
>
> Debe salir **Modo offline**.

### 2.4 Datos oficiales y estaciones (opcional, fuera del runtime)

La aplicación web funciona sin estos pasos. Se ejecutan **una vez**, en una
máquina con internet, antes de desplegar.

**Exposición oficial (INEI / MINEDU / MINSA).** El importador es dry-run por
defecto; `--aplicar` escribe el overlay, un respaldo fechado y el reporte:

```bash
# Revisar sin escribir nada
php scripts/importar_exposicion.php datos/exposicion.csv

# Aplicar (lista blanca: poblacion, viviendas, escuelas, centros_salud)
php scripts/importar_exposicion.php datos/exposicion.csv --aplicar

# Revertir: borrar el overlay devuelve el catálogo a la estimación
rm data/exposicion_oficial.json
```

Cada fila se resuelve por `ubigeo` + nombre/provincia/región; si no coincide,
se rechaza y se lista. Nunca se asigna en silencio.

**Lo que ya está descargado y aplicado:** la **población oficial** del INEI
(proyecciones distritales 2018-2026). El XLSX original y su CSV canónico están
en `datos/oficial/`, con su sha256 y el procedimiento de reproducción en
[`datos/oficial/README.md`](../datos/oficial/README.md):

```bash
# Reproducir desde cero (descarga → conversión → overlay → snapshots)
curl -L -o datos/oficial/inei_poblacion_distrital_2018_2026.xlsx \
  "https://cdn.www.gob.pe/uploads/document/file/8261096/6894980-peru-poblacion-total-proyectada-al-30-de-junio-de-cada-ano-segun-departamento-provincia-y-distrito-2018-2026.xlsx"
php scripts/inei_poblacion_xlsx_a_csv.php \
  --xlsx=datos/oficial/inei_poblacion_distrital_2018_2026.xlsx --anio=2026
php scripts/importar_exposicion.php datos/oficial/inei_poblacion.csv --aplicar
php scripts/actualizar.php --ventana=all
php scripts/semear_alertas.php --limpiar
```

`viviendas`, `escuelas` y `centros_salud` siguen siendo **estimación**: los
portales de ESCALE y RENIPRESS bloquean la descarga automatizada. Cuando se
tengan esos CSV, el flujo es el mismo y el overlay **acumula** campos.

**Series de estaciones (SENAMHI).** Requiere Python ≥ 3.11, `uv` y Chrome.
Garúa es **solo ingesta**: nunca se ejecuta desde PHP ni en el servidor web.

```bash
uv tool install garua
garua --health                      # debe detectar Chrome

# Descargar por prioridad (critica → alta → media → baja)
bash scripts/descargar_estaciones_garua.sh --critica

# Normalizar, aplicar QA y actualizar el registro
php scripts/importar_estaciones.php --garua --aplicar

# El registro se puede regenerar en cualquier momento
python3 scripts/generar_registro_estaciones.py
```

Salidas: `data/estaciones/_garua/csv/` (crudo), `data/estaciones/{id}.json`
(serie + QA), `data/estaciones/importacion.json` (reporte) y
`config/estaciones.json` (registro). El importador **no rellena huecos**:
`S/D` es ausencia, `T` es traza y los negativos se descartan y se listan.

> La fase 2 sigue apagada. Cargar series **no** cambia el motor de riesgo:
> `motor_usa_estaciones = false` hasta medir falsos positivos en el backtest.

---

## 3. Opción B — Hosting compartido (Apache)

### 3.1 Subir los archivos

1. Comprimir la carpeta `willaq/` en un ZIP.
2. En el panel del hosting, ir a **Administrador de archivos** → subir el ZIP →
   **extraer**.
3. Verificar que `index.php` quedó en la raíz del dominio (o de un
   subdirectorio, por ejemplo `https://ejemplo.pe/willaq/`).

> **No subas la carpeta `data/cache/`** si quieres empezar limpio: se regenera
> sola. Sí debes subir `data/snapshots/` (son los datos de la feria) y
> `data/alertas.json`.

### 3.2 Permisos

Los únicos directorios que la aplicación necesita escribir son:

| Directorio | Permiso | Para qué |
|---|---|---|
| `data/` | `755` (o `775` si el hosting lo pide) | alertas, climatología, snapshots |
| `data/cache/` | `755` | caché de la API |
| `data/snapshots/` | `755` | snapshots precalculados |
| `config/` | `644` (solo lectura) | solo se lee |
| `models/`, `views/`, `scripts/`, `assets/` | `644` | solo se leen |

En cPanel → **Administrador de archivos** → clic derecho → **Cambiar
permisos**. Si el panel de administración muestra los directorios en rojo,
ajusta el permiso de `data/` a `775` y vuelve a marcar.

### 3.3 Configuración (`.env`)

Copia `.env.example` a `.env` (en File Manager: renombrar) y ajústalo:

```ini
# --- Base de datos (opcional) ---
# Si no las rellenas o PostgreSQL falla, WILLAQ usa archivos JSON y las cuentas
# de demostración. La feria funciona sin base de datos.
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_NAME=willaq_db
DB_USER=willaq
DB_PASS=clave_secreta
DB_SSLMODE=prefer

# --- Modo feria ---
# Ponlo en 1 SOLO si el servidor no tiene salida a internet.
WILLAQ_MODO_OFFLINE=0

# --- Zona horaria ---
WILLAQ_TZ=America/Lima

# --- Límites de red ---
WILLAQ_HTTP_TIMEOUT=12
WILLAQ_HTTP_REINTENTOS=2

# --- Demostración ---
WILLAQ_PERMITIR_SNAPSHOT_RAPIDO=1
```

> **Nunca subes el `.env` real a un repositorio ni lo compartes.** Si el
> hosting tiene "Variables de entorno" en su panel, déjalas ahí y no crees el
> archivo: `.env` tiene prioridad sobre el entorno del proceso.

### 3.4 Protección de carpetas internas

Los archivos `.htaccess` del repositorio ya resuelven esto:

| Archivo | Efecto |
|---|---|
| `.htaccess` (raíz) | Entrada por `index.php`, sin listado de directorios, bloquea `.env`/`.sql`/`.log`, cabeceras de seguridad, caché y compresión |
| `data/.htaccess` | `Require all denied` |
| `config/.htaccess` | `Require all denied` |
| `models/.htaccess` | `Require all denied` |
| `scripts/.htaccess` | `Require all denied` |
| `views/.htaccess` | `Require all denied` |
| `database/.htaccess` | `Require all denied` |

**Comprueba que tu hosting los respeta.** Si tu servidor es Nginx, los
`.htaccess` se ignoran: pide al proveedor que bloquee el acceso directo a
`/data/`, `/config/`, `/models/`, `/scripts/`, `/views/` y `/database/`, o
pide que active la opción equivalente.

Verificación:

```
https://ejemplo.pe/data/snapshots/riesgo_nacional_24h.json  →  404 (NO 200)
https://ejemplo.pe/config/database.php                       →  404 (NO 200)
https://ejemplo.pe/.env                                      →  403/404 (NO 200)
```

Si alguno devuelve **200**, el despliegue tiene un problema de seguridad.
Corrígelo antes de mostrar el sistema.

### 3.5 Base de datos (opcional)

Si quieres cuentas de usuario reales e historial de alertas en la base:

1. Crea una base **PostgreSQL** y un usuario con todos los privilegios sobre ella.
2. Importa `database/schema_postgresql.sql` (DBeaver → **Abrir archivo** → ejecutar).
3. Rellena `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` en `.env`.

El proyecto incluye los dos scripts SQL y una guía completa para DBeaver
Community en local, AWS RDS y Google Cloud SQL:
[`database/README_POSTGRESQL.md`](../database/README_POSTGRESQL.md).

Si no lo haces, el login funciona con las **cuentas de demostración**:

| Correo | Rol | Contraseña |
|---|---|---|
| `admin@willaq.pe` | Administrador (panel, caché, snapshots) | `willaq2026` |
| `defensa@willaq.pe` | Autoridad (recomendaciones, acuse) | `willaq2026` |
| `ciudadano@willaq.pe` | Ciudadano (consulta) | `willaq2026` |

> **Cambia estas contraseñas en producción.** Vienen de
> `models/Usuario.php` (`usuario_cuentas_demo()`) y están pensadas para la
> demo, no para un despliegue real.

### 3.6 Generar los datos en el servidor

Si el hosting tiene **SSH**, lo ideal es ejecutar los scripts en el servidor
(la IP de salida del servidor es la del hosting, no la de tu casa):

```bash
cd ~/public_html/willaq
php scripts/actualizar.php --ventana=all
php scripts/semear_alertas.php --limpiar
```

Si **no hay SSH**, dos opciones:

* **Opción recomendada (subir snapshots):** ejecuta los scripts en tu equipo,
  comprime la carpeta `data/snapshots/` y `data/alertas.json` y súbelos por
  FTP. El sistema quedó con los datos de tu equipo, que es lo que quieres
  para una demo.
* **Opción alternativa (panel web):** entra como `admin@willaq.pe` y usa el
  botón **«Recalcular»** de la consola de administración. Funciona, pero el
  servidor del hosting puede tener límites de tiempo de ejecución.

---

## 4. Actualización automática (opcional)

### 4.1 Con cron o systemd timer (Linux/SSH)

El proyecto incluye un wrapper con bloqueo, registro y escritura atómica.
No llames a `actualizar.php` directamente desde el cron: usa el wrapper.

```cron
# Snapshots cada hora (minuto 5)
5 * * * * /ruta/a/willaq/scripts/willaq_cron.sh snapshot

# Recomendaciones una vez al día (05:10, hora local del servidor)
10 5 * * * /ruta/a/willaq/scripts/willaq_cron.sh alertas
```

Para Google Cloud o AWS hay unidades systemd listas en `deploy/systemd/` y una
guía completa en [`AUTOMATIZACION.md`](AUTOMATIZACION.md). Comprobación rápida:

```bash
./scripts/willaq_cron.sh estado
```

### 4.2 Sin SSH, desde la aplicación

El rol **administrador** puede recalcular el snapshot desde la consola de
administración (`?action=admin`), con botones para 24 h, 48 h y 72 h.

---

## 5. Verificación de la instalación

Ejecuta esta lista **antes** de la feria. Son 2 minutos y cubren el 95 % de los
problemas.

### 5.1 Comprobación funcional

```bash
BASE="https://ejemplo.pe"     # o http://localhost:8000

# 1. La sala de situación responde
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/?action=home"        # 200

# 2. La API responde JSON
curl -s "$BASE/?action=api_riesgo" | head -c 120                     # {"ventana":24,...

# 3. Las tres ventanas tienen su snapshot
for v in 24 48 72; do
  curl -s -o /dev/null -w "$v h → %{http_code}\n" "$BASE/?action=api_riesgo&ventana=$v"
done

# 4. El login funciona
curl -s -o /dev/null -w "%{http_code}\n" "$BASE/?action=ayuda"
```

### 5.2 Comprobación de seguridad

```bash
# Debe devolver 404 o 403, NUNCA 200
for p in "/.env" "/data/snapshots/riesgo_nacional_24h.json" "/config/database.php" "/scripts/actualizar.php"; do
  printf "%-50s %s\n" "$p" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE$p")"
done
```

### 5.3 Comprobación desde la consola de administración

Entra como administrador (`?action=admin`) y verifica:

| Indicador | Lo esperado |
|---|---|
| Extensiones | `curl`, `json`, `mbstring` en verde |
| Extensiones opcionales | `pdo_pgsql` verde **o** gris (sin drama) |
| Directorios escribibles | `data/`, `data/cache/`, `data/snapshots/` en verde |
| Snapshots | 24 h, 48 h y 72 h listados, con fecha reciente |
| Caché | archivos vigentes, 0 expirados |
| Procedencia de exposición | «sin overlay» si no se cargó, o distritos/campos con fuente y hash |
| Cobertura de estaciones | registro, series cargadas y **4 criterios medibles / 0 habilitadas** sobre 23 series (no se mezclan) |

### 5.4 Comprobación de la cadena de datos

```bash
# El índice que muestra la ficha debe ser reproducible a mano:
#   R = 100 · H · (0.40 + 0.35·E + 0.25·V)
curl -s "$BASE/?action=api_riesgo&id=piu-catacaos" | python3 -c "
import json,sys
d=json.load(sys.stdin)
a=d['amenazas'][d['principal']]          # 'principal' es el id de la amenaza
r=round(100*a['peligro']*(0.40+0.35*a['exposicion']+0.25*a['vulnerabilidad']),1)
print('mostrado:',d['riesgo'],' recalculado:',r,' → ','OK' if abs(r-d['riesgo'])<0.05 else 'REVISAR')
"
```

Si da `OK`, el motor es reproducible: el número que se muestra en pantalla es
literalmente el que entra en la fórmula.

> Recuerda que `?action=api_riesgo&id=` **recalcula en vivo**: la ventana de 24 h
> avanza con el reloj y el valor cambia durante el día. Para una comprobación
> reproducible, compara contra el snapshot:
>
> ```bash
> php -r '$s=json_decode(file_get_contents("data/snapshots/riesgo_nacional_24h.json"),true);
> foreach($s["riesgos"] as $r){ if($r["territorio"]["id"]==="piu-catacaos"){
>   $a=$r["amenazas"][$r["principal"]];
>   printf("snapshot: %.1f  recalculado: %.1f\n",$r["riesgo"],
>     round(100*$a["peligro"]*(0.40+0.35*$a["exposicion"]+0.25*$a["vulnerabilidad"]),1));}}'
> ```

---

## 6. Problemas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| «No se pudo conectar con la base de datos» | PostgreSQL no está levantado o las credenciales fallan | **No es un error fatal**: WILLAQ cae al modo demostración (JSON + cuentas demo). Revisa `.env` solo si quieres cuentas reales |
| La primera carga tarda más de 30 s | Sin caché: 29 distritos × 3 APIs | Espera la primera vez; después la caché de 1 h hace que sea inmediata. O sube la caché ya generada |
| La franja «Modo offline» no aparece | `WILLAQ_MODO_OFFLINE` no llega a PHP | Comprueba con el `grep` de la §2.3. WILLAQ lee el entorno con `getenv()` además de `$_ENV` precisamente por esto |
| El mapa se ve negro y sin calles | Sin internet: no cargan las teselas | **Es el comportamiento esperado.** El fondo vectorial propio y los marcadores de riesgo sí se ven; las teselas OSM son opcionales |
| «No existe snapshot» | Nunca se ejecutó `actualizar.php` | Ejecuta `php scripts/actualizar.php --ventana=all` |
| Los datos del panel no coinciden con los del mapa | Ventanas distintas | Comprueba que el selector 24/48/72 h sea el mismo en ambas pantallas |
| Error 500 al entrar | Permisos de `data/` | `chmod -R 755 data/` |
| El panel avisa de que falta `pdo_pgsql` | Extensión no instalada | Es **opcional**. El sistema funciona sin ella, con JSON y cuentas demo |
| `could not find driver` al sembrar | Falta `pdo_pgsql` en el PHP CLI | `php -m \| grep pdo_pgsql`. Instala `php-pgsql` o recompila la extensión |
| En la consola: `zip` o `dom` en rojo | Extensiones no instaladas | Ya no se comprueban: el código no las usa. Si tu versión antigua del panel las muestra, actualiza el archivo `views/admin/panel.php` |

---

## 7. Actualizar el sistema

```bash
# 1. Copia de seguridad de lo que puede perderse
cp -r data /tmp/willaq-data-backup

# 2. Sube los archivos nuevos por FTP (sobrescribe index.php, models/, views/,
#    assets/, config/, scripts/, .htaccess)

# 3. Regenera los datos con la versión nueva
php scripts/actualizar.php --ventana=all
php scripts/semear_alertas.php --limpiar

# 4. Verifica (sección 5)
```

---

## 8. Plan de reversión

Si la actualización rompe algo, en hosting compartido:

1. Restaura `data/` desde la copia de seguridad.
2. Vuelve a subir la versión anterior de `index.php`, `models/` y `views/`.
3. Ejecuta `php scripts/actualizar.php --ventana=all` de nuevo.

Los datos de la aplicación (alertas, snapshots, climatología) están **todos en
`data/`**, y la calibración histórica está en `data/climatologia_resumen.json`.
Ninguna migración de base de datos es obligatoria: los dos esquemas
(`database/schema_postgresql.sql` y `database/schema.sql`) son idempotentes y
compatibles con el modo JSON, que sigue siendo el respaldo de la feria.

---

## 9. Costos

| Concepto | Costo |
|---|---|
| Licencias de software | **Cero** |
| Hosting compartido | El que ya tengas, o planes desde ~USD 3/mes |
| Dominio | Opcional (funciona en una IP o en un subdirectorio) |
| Datos de Open-Meteo, MapLibre, Chart.js, Tailwind | **Cero** |
| **Total para un piloto funcional** | **~USD 0–3 / mes** |

El único costo real es **tiempo de desarrollo**, ya invertido.

---

## 10. Documentos relacionados

| Documento | Para qué |
|---|---|
| [`README.md`](../README.md) | Vista general, scripts, estructura |
| [`ARQUITECTURA.md`](ARQUITECTURA.md) | Cómo está construido y por qué |
| [`METODOLOGIA.md`](METODOLOGIA.md) | La fórmula, los umbrales, la materialidad |
| [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md) | El backtest y el análisis de sensibilidad |
| [`API.md`](API.md) | Los 4 endpoints JSON con ejemplos ejecutables |
| [`COMPARATIVA.md`](COMPARATIVA.md) | WILLAQ frente a SENAMHI, ENFEN, Flood Hub e INDECI |
| [`MANUAL_USUARIO.md`](MANUAL_USUARIO.md) | Cómo se usa la aplicación, por rol |
| [`GUION_DEMO.md`](GUION_DEMO.md) | Guion de sustentación de 8 minutos |

---

*Manual verificado el 5 de noviembre de 2026 sobre PHP 8.5.8. Los comandos y
rutas que se indican se ejecutaron en el repositorio; los pasos de hosting
compartido se derivan de la estructura real de archivos y de los `.htaccess`
incluidos.*

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
