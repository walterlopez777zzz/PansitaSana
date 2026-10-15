CREATE TABLE IF NOT EXISTS sorteo_votacion_ciclos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    estado ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opened_by INT NOT NULL DEFAULT 0,
    closed_by INT NULL,
    KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sorteo_votacion_propuestas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ciclo_id INT NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    items_json LONGTEXT NOT NULL,
    combo_hash CHAR(40) NOT NULL,
    items_count INT NOT NULL,
    total_pavos INT NOT NULL,
    total_soles DECIMAL(10,2) NOT NULL DEFAULT 0,
    estado ENUM('activa','ganadora','rechazada') NOT NULL DEFAULT 'activa',
    votos_count INT NOT NULL DEFAULT 0,
    recompensa_bananos DECIMAL(10,2) NOT NULL DEFAULT 0,
    shop_date VARCHAR(32) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_ciclo_usuario (ciclo_id, usuario_id),
    UNIQUE KEY uq_ciclo_combo (ciclo_id, combo_hash),
    KEY idx_ciclo_estado (ciclo_id, estado),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sorteo_votacion_votos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ciclo_id INT NOT NULL,
    propuesta_id INT NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_ciclo_usuario (ciclo_id, usuario_id),
    KEY idx_propuesta (propuesta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO site_settings (key_name, value) VALUES
    ('sv_votacion_abierta', '0'),
    ('sv_max_propuestas', '4'),
    ('sv_recompensa_bananos', '20'),
    ('sv_max_pavos', '1500'),
    ('sv_meme_url', '');

-- La razón 'sorteo_votacion' se agrega al ENUM vigente dinámicamente en sv_ensure_schema();
-- no se aplica un MODIFY fijo porque los valores en producción pueden diferir del volcado.
