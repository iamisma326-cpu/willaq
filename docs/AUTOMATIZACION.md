# Automatización de la actualización

> **Qué resuelve:** mantener los snapshots de riesgo y las recomendaciones al
> día sin intervención manual, en un servidor Linux de Google Cloud o AWS.
> **Qué no resuelve:** no convierte a WILLAQ en un servicio en tiempo real ni
> exige una base de datos. Sigue siendo un modelo con datos de Open-Meteo y
> caché; PostgreSQL es opcional.

---

## 1. Qué se automatiza

| Tarea | Frecuencia | Comando real |
|---|---|---|
| Snapshots 24 / 48 / 72 h | Cada hora, minuto 05 | `actualizar.php --ventana=all` |
| Recomendaciones | Una vez al día, 05:10 | `semear_alertas.php --limpiar` |
| Climatología ERA5 | Cada 30 días, manual | `calibrar.php` |

El punto de entrada es siempre el mismo:

```bash
scripts/willaq_cron.sh snapshot   # hora
scripts/willaq_cron.sh alertas    # día
scripts/willaq_cron.sh todo       # ambas, en orden
scripts/willaq_cron.sh estado     # diagnóstico
```

El wrapper aporta lo que los scripts PHP no tienen:

- **Bloqueo** (`flock`, con respaldo en `mkdir`): si una ejecución se solapa,
  la segunda se omite en lugar de escribir dos veces los mismos archivos.
- **Registro** fechado en `data/cron.log`, con rotación a los 5 MB.
- **Escritura atómica** de snapshots y alertas (temporal + `rename`), para que
  la web nunca lea un JSON a medias.
- **Códigos de salida** claros: `77` permisos, `78` PHP no encontrado, `64` uso.

Las alertas se regeneran **después** del snapshot de las 05:05, para que la
limpieza diaria use datos frescos. `--limpiar` borra las recomendaciones
anteriores y sus acuses; es el comportamiento acordado para la feria.

---

## 2. Arquitectura

```text
systemd timer (o cron)
        │
        ▼
scripts/willaq_cron.sh  ──►  data/cron.log
        │  flock + rotación
        ├──► php scripts/actualizar.php --ventana=all
        └──► php scripts/semear_alertas.php --limpiar
                    │
                    ▼
        data/snapshots/*.json  (escritura atómica)
        data/alertas.json      (escritura atómica)
```

No hace falta base de datos, Composer ni un proceso permanente. El servidor web
solo lee archivos; el timer los regenera. (Si PostgreSQL está configurado, el
script de alertas escribe también en la tabla `alertas` y deja el JSON como
espejo de seguridad.)

---

## 3. Google Cloud o AWS

Para este proyecto las dos nubes son equivalentes: se usa una VM Linux con
systemd. La configuración de los timers es idéntica; solo cambia cómo se crea
la máquina.

| Criterio | Google Cloud | AWS |
|---|---|---|
| Opción mínima | Compute Engine `e2-micro` | Lightsail 512 MB / `t4g.small` |
| Coste | `e2-micro` tiene capa gratuita permanente en `us-west1`, `us-central1` y `us-east1` (verificar vigencia) | El free tier cambió en 2025 a créditos; conviene revisar la consola o usar Lightsail |
| Disco | 30 GB estándar incluidos en la capa gratuita | 20–40 GB según plan |
| Salida | ~1 GB/mes gratis; las teselas del mapa las descarga el navegador, no la VM | Variable según plan |
| SO recomendado | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS |
| Esfuerzo | Medio | Medio |

**Recomendación para un proyecto académico:** Google Cloud `e2-micro` en
`us-central1` con Ubuntu 24.04 LTS. Es la opción con capa gratuita más
predecible y sobra para 29 distritos.

> La VM debe estar **encendida** para que los timers corran. Si se apaga para
> ahorrar, los snapshots se congelan. `Persistent=true` recupera la ejecución
> perdida al arrancar, pero no sustituye a tener la máquina activa.

### 3.1 Crear la VM (GCP, consola)

1. Compute Engine → Crear instancia.
2. Región `us-central1`, tipo `e2-micro`.
3. Disco de arranque: Ubuntu 24.04 LTS, 30 GB estándar.
4. Firewall: permitir HTTP 80 y HTTPS 443. SSH 22 ya viene.
5. IP externa: efímera para pruebas; estática si se publica la URL.
6. Crear y conectarse por SSH.

Con `gcloud` el equivalente es:

```bash
gcloud compute instances create willaq \
  --zone=us-central1-a \
  --machine-type=e2-micro \
  --image-family=ubuntu-2404-lts-amd64 \
  --image-project=ubuntu-os-cloud \
  --boot-disk-size=30GB \
  --tags=http-server,https-server
```

---

## 4. Instalación en Ubuntu 24.04

Los mismos pasos sirven en GCP y en AWS.

### 4.1 Paquetes

```bash
sudo apt update
sudo apt install -y php-cli php-fpm php-curl php-mbstring php-pgsql \
                    nginx unzip rsync
php -v
php -m | grep -E 'curl|json|mbstring|pdo_pgsql'
```

> `php-pgsql` es opcional: solo hace falta si vas a usar PostgreSQL. El sistema
> funciona igual sin él, con los archivos JSON de `data/`.

Ubuntu 24.04 trae PHP 8.3, que cumple el requisito de PHP 8.0+.

### 4.2 Copiar el proyecto

Desde tu equipo, con `rsync` (recomendado) o subiendo un ZIP:

```bash
rsync -av --delete \
  --exclude 'data/cache' --exclude 'data/cron.log' \
  ./willaq/ usuario@IP:/var/www/willaq/
```

En la VM:

```bash
sudo mkdir -p /var/www/willaq
# si se subió un ZIP: sudo unzip willaq.zip -d /var/www
```

### 4.3 Permisos

El código lo posee `root`; la carpeta `data/` la posee el usuario del servicio
(`www-data`), porque el timer y PHP-FPM deben escribir ahí.

```bash
sudo chown -R root:www-data /var/www/willaq
sudo find /var/www/willaq -type d -exec chmod 755 {} \;
sudo find /var/www/willaq -type f -exec chmod 644 {} \;
sudo chmod 755 /var/www/willaq/scripts/willaq_cron.sh

sudo chown -R www-data:www-data /var/www/willaq/data
sudo find /var/www/willaq/data -type d -exec chmod 775 {} \;
sudo find /var/www/willaq/data -type f -exec chmod 664 {} \;
```

### 4.4 Zona horaria

Los timers usan la hora local del servidor. Para que “05:10” sea hora de Lima:

```bash
sudo timedatectl set-timezone America/Lima
timedatectl
```

### 4.5 Primera ejecución manual

Calienta la caché (~87 llamadas, 30–90 s) y deja snapshots y alertas listos:

```bash
sudo -u www-data /var/www/willaq/scripts/willaq_cron.sh todo
sudo -u www-data /var/www/willaq/scripts/willaq_cron.sh estado
```

Si `estado` muestra los tres snapshots con pocos minutos de antigüedad y un
número de alertas, la base está bien.

> No uses `--limite` en producción: reescribe el snapshot con un solo distrito.
> Para probar permisos sin red, el wrapper acepta `snapshot --prueba`, que
> ejecuta `actualizar.php --solo=snapshot`.

### 4.6 Instalar los timers

```bash
sudo cp /var/www/willaq/deploy/systemd/willaq-*.service /etc/systemd/system/
sudo cp /var/www/willaq/deploy/systemd/willaq-*.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now willaq-actualizar.timer willaq-alertas.timer
```

Verificar:

```bash
systemctl list-timers --all | grep willaq
systemctl status willaq-actualizar.timer --no-pager
```

Prueba inmediata sin esperar la hora:

```bash
sudo systemctl start willaq-actualizar.service
sudo journalctl -u willaq-actualizar -n 30 --no-pager
tail -n 30 /var/www/willaq/data/cron.log
```

---

## 5. Alternativa con cron

Sirve en hosting compartido o en cualquier Linux sin systemd. El wrapper ya
incluye bloqueo y registro, así que el cron queda mínimo:

```cron
# Snapshots cada hora (minuto 5)
5 * * * * /var/www/willaq/scripts/willaq_cron.sh snapshot

# Recomendaciones una vez al día (05:10, hora local del servidor)
10 5 * * * /var/www/willaq/scripts/willaq_cron.sh alertas
```

En un panel de hosting, usa rutas absolutas y el PHP CLI real:

```cron
5 * * * * /usr/bin/php8.3 /home/USUARIO/willaq/scripts/actualizar.php --ventana=all
```

Si el panel no permite `flock`, el wrapper cae a un bloqueo por directorio
(`data/.willaq_cron.lockdir`) y lo elimina al terminar.

> En hosting compartido no se puede cambiar la zona horaria del servidor. Si
> el cron corre en UTC, ajusta la hora: 05:10 Lima = 10:10 UTC.

---

## 6. Servidor web

### 6.1 Nginx

El proyecto incluye `.htaccess`, que **solo aplica en Apache**. En Nginx hay
que negar explícitamente los directorios internos:

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/willaq;
    index index.php;
    client_max_body_size 8m;

    location ~ ^/(data|config|models|scripts|views|database)/ {
        deny all;
        return 404;
    }

    location ~ /\.(?!well-known) {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/willaq /etc/nginx/sites-enabled/willaq
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 6.2 Apache

```bash
sudo apt install -y apache2 libapache2-mod-php
```

El `DocumentRoot` debe ser `/var/www/willaq` y el vhost necesita
`AllowOverride All` para que el `.htaccess` raíz aplique sus reglas. El archivo
ya niega `data/`, `config/`, `models/`, `scripts/`, `views/` y `database/`.

### 6.3 HTTPS

Si la URL será pública, usa Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d tu-dominio.pe
```

---

## 7. Operación

| Necesidad | Comando |
|---|---|
| Ver frescura y número de alertas | `sudo -u www-data /var/www/willaq/scripts/willaq_cron.sh estado` |
| Forzar actualización | `sudo -u www-data /var/www/willaq/scripts/willaq_cron.sh snapshot` |
| Regenerar recomendaciones | `sudo -u www-data /var/www/willaq/scripts/willaq_cron.sh alertas` |
| Ver el registro | `tail -f /var/www/willaq/data/cron.log` |
| Ver errores de systemd | `journalctl -u willaq-actualizar -u willaq-alertas -n 80` |
| Desactivar la hora | `sudo systemctl disable --now willaq-actualizar.timer` |
| Desactivar el diario | `sudo systemctl disable --now willaq-alertas.timer` |

El registro rota solo: al pasar de 5 MB se renombra a `data/cron.log.1`.

Si se pulsa “Recalcular snapshot” en el panel de administración mientras corre
el timer, ya no hay riesgo de archivo corrupto: la escritura es atómica. El
wrapper evita el solapamiento entre ejecuciones programadas.

---

## 8. Límites y coste

| Recurso | Consumo | Límite |
|---|---|---|
| Llamadas a Open-Meteo | ~58 por hora en caliente · ~1.400/día | 10.000/día · 5.000/hora · 600/minuto |
| Disco de caché | ~4 MB y estable (se reescribe, no crece sin control) | 30 GB |
| Snapshots | ~465 KB los tres | — |
| CPU | ~1–2 min por hora | — |
| Coste de VM | Capa gratuita de GCP (verificar) | — |

Open-Meteo free es para **uso no comercial**. Un proyecto académico entra en
ese supuesto; si el sistema se comercializa, hace falta licencia y API key. La
atribución ya está en el pie y en el mapa.

La climatología de 11 años **no** va en el timer horario: se ejecuta con
`php scripts/calibrar.php` cada 30 días o cuando cambie el catálogo.

---

## 9. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `status=203/EXEC` | Ruta o permisos del wrapper | Verifica `ls -l scripts/willaq_cron.sh` y la ruta del `ExecStart` |
| `ERROR: data/ no es escribible` | El servicio no es dueño de `data/` | `sudo chown -R www-data:www-data data` |
| “Omitido: otra ejecución sigue en curso” | Un proceso anterior no terminó | Revisa `ps aux | grep actualizar`; el lock se libera al terminar |
| Los snapshots no cambian | Timer desactivado o error PHP | `systemctl list-timers`, `tail data/cron.log` |
| `php: not found` en el servicio | PATH mínimo de systemd | Define `WILLAQ_PHP_BIN` o usa la ruta absoluta en `ExecStart` |
| La web muestra 500 | Permisos o PHP-FPM | `journalctl -u php8.3-fpm`, `nginx -t` |
| Se ven datos de ayer | Snapshot viejo | `scripts/willaq_cron.sh estado` y fuerza `snapshot` |

---

## 10. Checklist antes de la feria

- [ ] `timedatectl` en `America/Lima`.
- [ ] `scripts/willaq_cron.sh todo` ejecutado sin errores.
- [ ] `scripts/willaq_cron.sh estado` con snapshots de menos de 1 hora.
- [ ] `systemctl list-timers | grep willaq` con los dos timers activos.
- [ ] `data/cron.log` con una ejecución reciente y código 0.
- [ ] `curl -I https://tu-dominio/?action=api_riesgo` responde 200 y JSON.
- [ ] `https://tu-dominio/data/alertas.json` responde 403 o 404.
- [ ] Contraseñas demo cambiadas.
- [ ] Copia de seguridad de `data/` y de `config/territorios.json`.

---

## Documentos relacionados

- [`MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md)
- [`AUDITORIA_DATOS_Y_ALCANCE.md`](AUDITORIA_DATOS_Y_ALCANCE.md)
- [`API.md`](API.md)
- [`../README.md`](../README.md)
