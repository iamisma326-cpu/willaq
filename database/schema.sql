-- ============================================================================
-- WILLAQ — Esquema relacional equivalente
-- ============================================================================
-- IMPORTANTE
--   WILLAQ funciona SIN MySQL: usa JSON en data/ para alertas, caché y
--   snapshots. Este esquema existe para (a) demostrar el diseño relacional
--   y (b) permitir persistencia real en hosting con MySQL/MariaDB.
--
--   Para activarlo: importar este archivo y definir DB_HOST / DB_NAME /
--   DB_USER / DB_PASS en el archivo .env. El login consultará `usuarios`
--   antes de caer a las cuentas de demostración.
--
--   Motor: MySQL 8 / MariaDB 10.4 o superior.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS willaq_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE willaq_db;

-- ----------------------------------------------------------------------------
-- 1. Usuarios y roles
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(120) NOT NULL,
    email           VARCHAR(160) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    rol             ENUM('admin','autoridad','ciudadano') NOT NULL DEFAULT 'ciudadano',
    organizacion    VARCHAR(160) DEFAULT NULL,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    KEY idx_usuarios_rol (rol, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Catálogo de territorios
--    Espejo de config/territorios.json. El JSON es la fuente del piloto;
--    esta tabla permite editar el catálogo desde una interfaz administrativa.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS territorios (
    id                          VARCHAR(40) NOT NULL,
    pais                        VARCHAR(60) NOT NULL DEFAULT 'Perú',
    region                      VARCHAR(80) NOT NULL,
    provincia                   VARCHAR(80) NOT NULL,
    distrito                    VARCHAR(120) NOT NULL,
    lat                         DECIMAL(9,6) NOT NULL,
    lon                         DECIMAL(9,6) NOT NULL,
    altitud                     SMALLINT NOT NULL DEFAULT 0,
    zona                        ENUM('costa_norte','costa_centro','quebrada_andina','sierra','selva') NOT NULL,
    poblacion                   INT UNSIGNED NOT NULL DEFAULT 0,
    viviendas                   INT UNSIGNED NOT NULL DEFAULT 0,
    escuelas                    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    centros_salud               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    km_vias                     DECIMAL(10,2) NOT NULL DEFAULT 0,
    hectareas_cultivo           DECIMAL(10,2) NOT NULL DEFAULT 0,
    vulnerabilidad              DECIMAL(4,3) NOT NULL DEFAULT 0.500,
    susc_inundacion             DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    susc_huaico                 DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    susc_sequia                 DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    pendiente                   DECIMAL(6,2) NOT NULL DEFAULT 0,
    cuenca_km2                  DECIMAL(12,2) NOT NULL DEFAULT 0,
    umbral_caudal               DECIMAL(12,2) NOT NULL DEFAULT 0,
    cultivo                     VARCHAR(40) NOT NULL DEFAULT 'ninguno',
    albergues_capacidad         INT UNSIGNED NOT NULL DEFAULT 0,
    fuente_exposicion           VARCHAR(80) NOT NULL DEFAULT 'estimacion_ratio_poblacional',
    activo                      TINYINT(1) NOT NULL DEFAULT 1,
    creado_en                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_territorios_region (region),
    KEY idx_territorios_zona (zona),
    KEY idx_territorios_coord (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Alertas / recomendaciones
--    Nota legal: son recomendaciones de apoyo a la decisión, no alertas
--    oficiales. El campo `nota_legal` se guarda con cada registro.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertas (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(40) NOT NULL,
    territorio_id   VARCHAR(40) NOT NULL,
    distrito        VARCHAR(120) NOT NULL,
    provincia       VARCHAR(80) NOT NULL,
    region          VARCHAR(80) NOT NULL,
    amenaza         ENUM('lluvia','inundacion','huaico','sequia','calor','agro') NOT NULL,
    nivel           ENUM('verde','amarillo','naranja','rojo') NOT NULL,
    titulo          VARCHAR(255) NOT NULL,
    mensaje         TEXT NOT NULL,
    acciones        JSON DEFAULT NULL,
    estado          ENUM('emitida','acusada','archivada') NOT NULL DEFAULT 'emitida',
    emitida_por     VARCHAR(120) NOT NULL DEFAULT 'Sistema',
    emitida_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acuse_por       VARCHAR(120) DEFAULT NULL,
    acuse_en        DATETIME DEFAULT NULL,
    nota_legal      VARCHAR(255) NOT NULL DEFAULT 'Recomendación de apoyo a la decisión. No reemplaza una alerta oficial.',
    PRIMARY KEY (id),
    UNIQUE KEY uq_alertas_codigo (codigo),
    KEY idx_alertas_territorio (territorio_id, emitida_en),
    KEY idx_alertas_nivel (nivel, estado),
    CONSTRAINT fk_alertas_territorio
        FOREIGN KEY (territorio_id) REFERENCES territorios (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Caché de geodatos
--    Espejo opcional de data/cache/*.json. Guarda la respuesta cruda de cada
--    API para auditoría y para reconstruir un cálculo pasado.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cache_geodatos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave           VARCHAR(255) NOT NULL,
    fuente          VARCHAR(120) NOT NULL DEFAULT 'desconocida',
    payload         LONGTEXT NOT NULL,
    guardado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expira_en       DATETIME NOT NULL,
    ttl             INT UNSIGNED NOT NULL DEFAULT 3600,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cache_clave (clave),
    KEY idx_cache_expira (expira_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Escenarios simulados (auditoría de decisiones)
--    Permite responder: "¿qué escenario se evaluó y con qué resultado?"
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS escenarios (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    territorio_id   VARCHAR(40) NOT NULL,
    modo            ENUM('clima','evacuacion','reubicacion') NOT NULL,
    parametros      JSON NOT NULL,
    resultado       JSON NOT NULL,
    creado_por      VARCHAR(120) DEFAULT NULL,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_escenarios_territorio (territorio_id, creado_en),
    CONSTRAINT fk_escenarios_territorio
        FOREIGN KEY (territorio_id) REFERENCES territorios (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Auditoría de acciones
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED DEFAULT NULL,
    accion          VARCHAR(80) NOT NULL,
    entidad         VARCHAR(80) DEFAULT NULL,
    entidad_id      VARCHAR(80) DEFAULT NULL,
    detalle         TEXT DEFAULT NULL,
    ip              VARCHAR(45) DEFAULT NULL,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_fecha (creado_en),
    KEY idx_auditoria_usuario (usuario_id),
    CONSTRAINT fk_auditoria_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Vista de apoyo: último riesgo calculado por distrito
--    (Se alimenta desde la aplicación; no reemplaza al motor en PHP.)
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_alertas_abiertas AS
SELECT
    a.codigo,
    a.territorio_id,
    a.distrito,
    a.region,
    a.amenaza,
    a.nivel,
    a.titulo,
    a.emitida_en,
    a.estado,
    TIMESTAMPDIFF(HOUR, a.emitida_en, NOW()) AS horas_desde_emision
FROM alertas a
WHERE a.estado = 'emitida'
ORDER BY a.emitida_en DESC;

-- ----------------------------------------------------------------------------
-- 8. Usuarios de demostración
--    Contraseña: willaq2026
--    Hash bcrypt generado con password_hash('willaq2026', PASSWORD_BCRYPT, ['cost'=>12]).
--    CAMBIAR EN PRODUCCIÓN.
-- ----------------------------------------------------------------------------
INSERT INTO usuarios (nombre, email, password_hash, rol, organizacion)
VALUES
    ('Administrador WILLAQ', 'admin@willaq.pe',
     '$2y$12$ZOM92o7A.xmwcaDJwLtNT.veXwq26leBdPloU70w0y7up9NYPD8TW',
     'admin', 'WILLAQ'),
    ('Defensa Civil Piura', 'defensa@willaq.pe',
     '$2y$12$ZOM92o7A.xmwcaDJwLtNT.veXwq26leBdPloU70w0y7up9NYPD8TW',
     'autoridad', 'INDECI — Piura'),
    ('Ciudadano Demo', 'ciudadano@willaq.pe',
     '$2y$12$ZOM92o7A.xmwcaDJwLtNT.veXwq26leBdPloU70w0y7up9NYPD8TW',
     'ciudadano', 'Comunidad')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    rol = VALUES(rol),
    organizacion = VALUES(organizacion);

-- ----------------------------------------------------------------------------
-- 9. Territorios de ejemplo
--    El catálogo completo (29 distritos) vive en config/territorios.json.
--    Estas filas permiten probar las claves foráneas sin importar el JSON.
-- ----------------------------------------------------------------------------
INSERT INTO territorios
    (id, region, provincia, distrito, lat, lon, altitud, zona, poblacion, viviendas,
     escuelas, centros_salud, km_vias, hectareas_cultivo, vulnerabilidad,
     susc_inundacion, susc_huaico, susc_sequia, pendiente, cuenca_km2,
     umbral_caudal, cultivo, albergues_capacidad)
VALUES
    ('piu-catacaos', 'Piura', 'Piura', 'Catacaos', -5.2667, -80.6833, 28, 'costa_norte',
     75000, 18000, 120, 8, 210, 6200, 0.780, 0.95, 0.10, 0.55, 2, 1700, 900, 'arroz', 4000),
    ('lim-chosica', 'Lima', 'Lima', 'Lurigancho-Chosica', -11.9333, -76.7000, 850, 'quebrada_andina',
     240000, 60000, 380, 24, 480, 900, 0.620, 0.40, 0.90, 0.35, 18, 1200, 120, 'hortalizas', 8000),
    ('cus-cusco', 'Cusco', 'Cusco', 'Cusco', -13.5319, -71.9675, 3399, 'sierra',
     128000, 32000, 210, 18, 360, 3400, 0.520, 0.25, 0.45, 0.42, 12, 800, 100, 'papa', 5000)
ON DUPLICATE KEY UPDATE
    poblacion = VALUES(poblacion),
    vulnerabilidad = VALUES(vulnerabilidad);

-- ============================================================================
-- FIN DEL ESQUEMA
-- ============================================================================
