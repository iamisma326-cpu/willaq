# =============================================================================
# WILLAQ — imagen de producción para Railway
#
# Por qué Dockerfile y no Nixpacks:
#   Nixpacks dejaba un `/usr/bin/php` compilado desde fuente que SOLO incluye
#   el driver sqlite. Resultado: `conectar_db()` fallaba con
#   "could not find driver" aunque se declarara php83Extensions.pdo_pgsql,
#   y el panel seguía en modo demostración en vez de usar Neon.
#   Con esta imagen el driver está garantizado y verificable.
# =============================================================================
FROM php:8.3-cli-alpine

# Extensiones REQUERIDAS por el código (ver views/admin/panel.php):
#   pdo_pgsql -> Neon (cuentas, alertas, territorios)
#   curl      -> Open-Meteo (models/Clima.php)
#   mbstring  -> texto en español
#   zip       -> scripts/inei_poblacion_xlsx_a_csv.php
#   simplexml -> lectura de XLSX
RUN apk add --no-cache postgresql-dev libzip-dev libxml2-dev curl-dev oniguruma-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql zip simplexml \
 && docker-php-ext-enable pdo_pgsql

# El servidor integrado de PHP no necesita nginx ni php-fpm.
WORKDIR /app

COPY . .

# `data/` debe existir y ser escribible: cache y snapshots se regeneran aquí.
RUN mkdir -p data/cache data/snapshots \
 && chmod -R 777 data \
 && php -r 'echo "pdo_pgsql: ".(extension_loaded("pdo_pgsql")?"OK":"FALTA").PHP_EOL;'

EXPOSE 8080

# Railway inyecta $PORT. El router de PHP sirve index.php y los estáticos de
# assets/ directamente (resuelve archivos existentes antes del router).
CMD php -S 0.0.0.0:${PORT:-8080} -t .
