-- ============================================================================
--  BANANO STORE — DATA LAYER MANAGER (Fase 5) · ESQUEMA SQL
-- ============================================================================
--  Ejecutar en phpMyAdmin sobre la base de datos de la tienda.
--  Motor InnoDB + utf8mb4. Todas las tablas usan el prefijo `dl_`.
--  Seguro de re-ejecutar: usa CREATE TABLE IF NOT EXISTS.
-- ============================================================================

-- 1) OVERRIDES DE ÍTEMS -------------------------------------------------------
--  Sobrescrituras manuales por (offer_id, field_key). Se compilan a
--  cuysito_app/api/item_overrides.json y la DAL las aplica en tiempo de lectura.
CREATE TABLE IF NOT EXISTS `dl_shop_overrides` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`    VARCHAR(255)    NOT NULL,
  `field_key`   VARCHAR(100)    NOT NULL,
  `field_value` MEDIUMTEXT      NULL,
  `value_type`  ENUM('string','number','json','color') NOT NULL DEFAULT 'string',
  `activo`      TINYINT(1)      NOT NULL DEFAULT 1,
  `nota`        VARCHAR(255)    NULL,
  `updated_by`  VARCHAR(100)    NULL,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offer_field` (`offer_id`, `field_key`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1b) ENTRADAS PERSONALIZADAS -------------------------------------------------
--  Objetos creados desde cero en el Editor Visual. Guardan la entrada canónica
--  completa (JSON) y se compilan a cuysito_app/cache/custom_entries.json;
--  ShopRepository las inyecta en los ítems del snapshot en tiempo de lectura.
--  (El backend también la crea bajo demanda con CREATE TABLE IF NOT EXISTS.)
CREATE TABLE IF NOT EXISTS `dl_custom_entries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `offer_id`   VARCHAR(255)    NOT NULL,
  `entry_json` MEDIUMTEXT      NOT NULL,
  `activo`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offer` (`offer_id`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) MAPA DE CAMPOS DE LA API -------------------------------------------------
--  Re-mapea un campo renombrado por la API externa hacia el nombre canónico.
--  scope = 'data'  -> data.<source_key>  copiado a  data.<canonical_key>
--  scope = 'entry' -> entry.<source_key> copiado a  entry.<canonical_key>
--  Se compila a cuysito_app/cache/api_field_map.json y lo aplica ShopNormalizer.
CREATE TABLE IF NOT EXISTS `dl_api_field_map` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`         ENUM('data','entry') NOT NULL DEFAULT 'data',
  `source_key`    VARCHAR(150)    NOT NULL,
  `canonical_key` VARCHAR(150)    NOT NULL,
  `activo`        TINYINT(1)      NOT NULL DEFAULT 1,
  `nota`          VARCHAR(255)    NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scope_source` (`scope`, `source_key`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) REGISTRO DE EVENTOS / OBSERVABILIDAD ------------------------------------
--  Anomalías y eventos de ciclo de vida (campos faltantes/renombrados, errores
--  de conexión, snapshots escritos, cambios de modo). Lo escribe DataLayerLogger.
CREATE TABLE IF NOT EXISTS `dl_event_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `severity`   ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
  `event_type` VARCHAR(64)     NOT NULL,
  `message`    VARCHAR(500)    NOT NULL,
  `context`    JSON            NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_created` (`created_at`),
  KEY `idx_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) ESTADO CLAVE/VALOR DEL PANEL --------------------------------------------
--  Almacén genérico para metadatos del panel (último hash visto, conteo
--  esperado de ítems, etc.). Opcional pero útil para detección de cambios.
CREATE TABLE IF NOT EXISTS `dl_state` (
  `k`          VARCHAR(100) NOT NULL,
  `v`          MEDIUMTEXT   NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  EJEMPLOS (OPCIONALES — comentados). Descomenta para probar.
-- ============================================================================
-- Override de nombre de un ítem:
-- INSERT INTO dl_shop_overrides (offer_id, field_key, field_value, value_type, nota)
-- VALUES ('v2:/abc123', 'displayName', 'Nombre Personalizado', 'string', 'demo');
--
-- Si la API renombra data.date -> data.out, sanéalo así:
-- INSERT INTO dl_api_field_map (scope, source_key, canonical_key, nota)
-- VALUES ('data', 'out', 'date', 'API renombró date a out el 2026-06-13');
