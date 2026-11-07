-- ============================================================================
-- WILLAQ · CREACIÓN DE ROL Y BASE DE DATOS EN POSTGRESQL
-- ============================================================================
-- Este script se ejecuta ANTES de database/schema_postgresql.sql.
--
-- QUÉ HACE
--   1. Crea el rol (usuario) de la aplicación `willaq` si no existe.
--   2. Crea la base `willaq_db` si no existe, con el rol `willaq` como dueño.
--   3. Asigna privilegios sobre el esquema `public`.
--
-- CÓMO EJECUTAR (local, con psql)
--   sudo -u postgres psql -d postgres -f database/00_crear_bd_postgresql.sql
--
-- CÓMO EJECUTAR (DBeaver Community)
--   1. Conexión → PostgreSQL → host localhost → base `postgres` → usuario postgres.
--   2. Abrir el archivo database/00_crear_bd_postgresql.sql y ejecutarlo (F5).
--   3. Recargar la lista de bases de datos: aparecerá `willaq_db`.
--
-- CÓMO EJECUTAR (AWS RDS / Google Cloud SQL)
--   En la nube administrada NO se usa este script para crear la base: la base
--   y el usuario los crea el proveedor (RDS CreateDBInstance / Cloud SQL
--   CreateInstance) y se conecta por TLS. Este script queda como referencia y
--   para entornos PostgreSQL que el equipo administra a mano (un servidor de la
--   IESTP, un contenedor, una VM con el paquete postgresql).
--   En esos casos se ejecuta con el usuario administrador del servidor.
--
-- CONTRASEÑA
--   La contraseña se define en el script con una variable psql. Para no dejarla
--   escrita en el repositorio se lee de una variable de entorno:
--
--     export WILLAQ_DB_PASS='cambia-esta-clave'
--     sudo -u postgres psql -d postgres -v WILLAQ_DB_PASS="$WILLAQ_DB_PASS" \
--       -f database/00_crear_bd_postgresql.sql
--
--   Si no se define la variable, el script usa `willaq_dev` (solo local) y
--   avisa por pantalla para que nadie la use en producción sin cambiarla.
--
-- IDEMPOTENTE
--   Se puede ejecutar varias veces: no rompe ni borra datos existentes.
--   `CREATE DATABASE` no puede ir dentro de una transacción; por eso este
--   script usa `\gexec` de psql en lugar de un bloque DO.
-- ============================================================================

\set ON_ERROR_STOP on

\if :{?WILLAQ_DB_PASS}
\else
  \set WILLAQ_DB_PASS 'willaq_dev'
  \echo ''
  \echo 'AVISO: no se pasó WILLAQ_DB_PASS. Se usará "willaq_dev".'
  \echo '       Para local basta, pero cámbiala antes de publicar la base.'
  \echo ''
\endif

-- ----------------------------------------------------------------------------
-- 1. Rol de la aplicación
-- ----------------------------------------------------------------------------
SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L',
    'willaq',
    :'WILLAQ_DB_PASS'
)
WHERE NOT EXISTS (
    SELECT 1 FROM pg_roles WHERE rolname = 'willaq'
)
\gexec

-- Si el rol ya existía, actualizamos su contraseña para que coincida con la
-- variable entregada (así el .env y la base nunca se desincronizan).
SELECT format(
    'ALTER ROLE %I WITH LOGIN PASSWORD %L',
    'willaq',
    :'WILLAQ_DB_PASS'
)
\gexec

-- ----------------------------------------------------------------------------
-- 2. Base de datos
-- ----------------------------------------------------------------------------
SELECT 'CREATE DATABASE willaq_db OWNER willaq ENCODING ''UTF8'''
WHERE NOT EXISTS (
    SELECT 1 FROM pg_database WHERE datname = 'willaq_db'
)
\gexec

-- ----------------------------------------------------------------------------
-- 3. Privilegios
-- ----------------------------------------------------------------------------
-- La conexión por defecto de PostgreSQL es `postgres` y el esquema `public`
-- pertenece al usuario que creó la base. Con la configuración actual de
-- PostgreSQL 15+ el dueño de la base es `willaq`, así que ya tiene todos los
-- permisos sobre `public`. Estos GRANT son idempotentes y cubren el caso de
-- bases creadas por otro administrador.
\connect willaq_db

GRANT CONNECT, TEMPORARY ON DATABASE willaq_db TO willaq;
GRANT USAGE, CREATE ON SCHEMA public TO willaq;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO willaq;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO willaq;

-- ----------------------------------------------------------------------------
-- 4. Verificación
-- ----------------------------------------------------------------------------
\echo ''
\echo '--- Estado final ---'
SELECT rolname AS rol, rolcanlogin AS puede_entrar
FROM pg_roles
WHERE rolname = 'willaq';

SELECT datname AS base, pg_get_userbyid(datdba) AS dueno
FROM pg_database
WHERE datname = 'willaq_db';

\echo ''
\echo 'Listo. Siguiente paso:'
\echo '  psql -U willaq -d willaq_db -f database/schema_postgresql.sql'
\echo 'O en DBeaver: abrir database/schema_postgresql.sql sobre willaq_db y ejecutar.'
\echo ''
