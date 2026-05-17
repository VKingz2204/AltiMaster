-- =====================================================
-- APP2 - Crypto Token Monitor
-- Fresh Install SQL
-- =====================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS historial_tokens;
DROP TABLE IF EXISTS tokens_free;
DROP TABLE IF EXISTS tokens_banned;
DROP TABLE IF EXISTS coins_revisadas;
DROP TABLE IF EXISTS tokens;
DROP TABLE IF EXISTS manual_coins;
DROP TABLE IF EXISTS signals;
DROP TABLE IF EXISTS daily_profit_tracker;
DROP TABLE IF EXISTS system_criteria;
DROP TABLE IF EXISTS api_keys;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS configuracion;
DROP TABLE IF EXISTS servidor_status;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS coins_tags;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Tabla: usuarios
-- --------------------------------------------------------
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    pin VARCHAR(10) NOT NULL,
    nivel ENUM('admin', 'free', 'vip') NOT NULL DEFAULT 'free',
    nivel_detalle VARCHAR(20) DEFAULT '1',
    plan ENUM('basic', 'pro', 'ultra') DEFAULT 'basic',
    is_admin BOOLEAN DEFAULT FALSE,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimo_login DATETIME DEFAULT NULL,
    activo BOOLEAN DEFAULT TRUE,
    INDEX idx_username (username),
    INDEX idx_nivel (nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: tokens
-- --------------------------------------------------------
CREATE TABLE tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chain_id VARCHAR(20) NOT NULL,
    token_address VARCHAR(100) NOT NULL,
    pair_address VARCHAR(100) NOT NULL UNIQUE,
    nombre VARCHAR(100) DEFAULT NULL,
    simbolo VARCHAR(20) DEFAULT NULL,
    precio_actual DECIMAL(30, 18) DEFAULT 0,
    precio_entrada DECIMAL(30, 18) DEFAULT 0,
    precio_descubrimiento DECIMAL(30, 18) DEFAULT 0,
    precio_crash DECIMAL(30, 18) DEFAULT NULL,
    precio_maximo DECIMAL(30, 18) DEFAULT 0,
    precio_15_peak DECIMAL(30, 18) DEFAULT 0,
    last_check_price DECIMAL(20, 18) DEFAULT 0,
    market_cap DECIMAL(20, 2) DEFAULT 0,
    liquidez DECIMAL(20, 2) DEFAULT 0,
    cambio_1h DECIMAL(10, 2) DEFAULT 0,
    cambio_6h DECIMAL(10, 2) DEFAULT 0,
    cambio_24h DECIMAL(10, 2) DEFAULT 0,
    estado ENUM('nuevo', 'monitoreando', 'exit') DEFAULT 'nuevo',
    meta_tp DECIMAL(10, 2) DEFAULT 15,
    tp_alcanzado BOOLEAN DEFAULT FALSE,
    sl_alcanzado BOOLEAN DEFAULT FALSE,
    passed_15 BOOLEAN DEFAULT FALSE,
    es_reentry BOOLEAN DEFAULT FALSE,
    reentry_count INT DEFAULT 0,
    checks_count INT DEFAULT 0,
    laps INT DEFAULT 0,
    timeout_count INT DEFAULT 0,
    tag VARCHAR(20) DEFAULT NULL,
    confirmacion_count INT DEFAULT 0,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_ingreso DATETIME DEFAULT NULL,
    fecha_salida DATETIME DEFAULT NULL,
    primer_check DATETIME DEFAULT NULL,
    ultimo_check DATETIME DEFAULT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_par (pair_address),
    INDEX idx_fechas (fecha_registro, fecha_ingreso, fecha_salida),
    INDEX idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: tokens_free
-- --------------------------------------------------------
CREATE TABLE tokens_free (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_token INT NOT NULL,
    mostrar_desde DATETIME DEFAULT CURRENT_TIMESTAMP,
    mostrar_hasta DATETIME DEFAULT NULL,
    activo BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_token) REFERENCES tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: tokens_banned
-- --------------------------------------------------------
CREATE TABLE tokens_banned (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_address VARCHAR(100) NOT NULL,
    pair_address VARCHAR(100) NOT NULL,
    chain_id VARCHAR(20) NOT NULL,
    razon VARCHAR(255) DEFAULT NULL,
    banneado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_token (pair_address, chain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: historial_tokens
-- --------------------------------------------------------
CREATE TABLE historial_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_token_original INT NOT NULL,
    chain_id VARCHAR(20),
    token_address VARCHAR(100),
    pair_address VARCHAR(100),
    nombre VARCHAR(100),
    simbolo VARCHAR(20),
    precio_entrada DECIMAL(30, 18),
    precio_descubrimiento DECIMAL(30, 18) DEFAULT 0,
    precio_salida DECIMAL(30, 18),
    profit_porcentaje DECIMAL(10, 2),
    duracion_minutos INT,
    razon_salida ENUM('tp', 'sl', 'save_tp', 'caida_pico', 'timeout', 'ban', 'expirado', 'manual') DEFAULT 'expirado',
    tag VARCHAR(20) DEFAULT NULL,
    es_reentry BOOLEAN DEFAULT FALSE,
    fecha_entrada DATETIME,
    fecha_salida DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fecha (fecha_salida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: coins_tags
-- Contador de tags por nombre de moneda
-- --------------------------------------------------------
CREATE TABLE coins_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_normalizado VARCHAR(100) NOT NULL UNIQUE,
    strong_count INT DEFAULT 0,
    destroyed_count INT DEFAULT 0,
    checking_count INT DEFAULT 0,
    okay_count INT DEFAULT 0,
    inestable_count INT DEFAULT 0,
    ultimo_tag VARCHAR(20) DEFAULT NULL,
    actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nombre (nombre_normalizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: configuracion
-- --------------------------------------------------------
CREATE TABLE configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(50) NOT NULL UNIQUE,
    valor VARCHAR(255) DEFAULT NULL,
    descripcion VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: logs
-- --------------------------------------------------------
CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nivel ENUM('info', 'warning', 'error') DEFAULT 'info',
    mensaje TEXT,
    detalle JSON,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nivel (nivel),
    INDEX idx_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: servidor_status
-- --------------------------------------------------------
CREATE TABLE servidor_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activo BOOLEAN DEFAULT FALSE,
    ultimo_inicio DATETIME DEFAULT NULL,
    ultimo_check DATETIME DEFAULT NULL,
    tokens_activos INT DEFAULT 0,
    ultima_actualizacion DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: coins_revisadas
-- --------------------------------------------------------
CREATE TABLE coins_revisadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pair_address VARCHAR(100) NOT NULL,
    chain_id VARCHAR(20) NOT NULL,
    nombre VARCHAR(100) DEFAULT NULL,
    precio DECIMAL(20, 18) DEFAULT 0,
    market_cap DECIMAL(20, 2) DEFAULT 0,
    liquidez DECIMAL(20, 2) DEFAULT 0,
    cambio_1h DECIMAL(10, 2) DEFAULT 0,
    cambio_6h DECIMAL(10, 2) DEFAULT 0,
    cambio_24h DECIMAL(10, 2) DEFAULT 0,
    accion VARCHAR(20) DEFAULT NULL,
    razon VARCHAR(255) DEFAULT NULL,
    revisado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pair (pair_address),
    INDEX idx_fecha (revisado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- DATOS INICIALES
-- =====================================================

INSERT INTO usuarios (username, pin, nivel, plan, is_admin) VALUES
('admin', '1234', 'admin', 'ultra', 1);

INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES
('freeuser', '1111', 'vip', 'basic', '1');

INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES
('vipuser', '2222', 'vip', 'pro', '2');

INSERT INTO servidor_status (id, activo) VALUES (1, 0);

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('busqueda_intervalo', '60', 'Search interval (seconds)'),
('monitoreo_intervalo', '30', 'Monitoring interval (seconds)'),
('tp_porcentaje', '24', 'Take Profit percentage'),
('tp_reentry_porcentaje', '20', 'TP for re-entry'),
('sl_porcentaje', '10', 'Stop Loss percentage'),
('reentry_subida_min', '5', 'Min rise for re-entry (%)'),
('crash_porcentaje', '-4000', 'Crash percentage'),
('free_cambio_horas', '6', 'Free token change hours');

-- --------------------------------------------------------
-- Tabla: api_keys
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    key VARCHAR(36) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_regenerated_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: system_criteria
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    stop_loss_pct DECIMAL(10, 2) DEFAULT -6.0,
    take_profit_pct DECIMAL(10, 2) DEFAULT 24.0,
    max_wait_minutes INT DEFAULT 60,
    save_profit_pct DECIMAL(10, 2) DEFAULT -6.0,
    min_entry_pct DECIMAL(10, 2) DEFAULT 1.5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: daily_profit_tracker
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_profit_tracker (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    accumulated_pct DECIMAL(10, 2) DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_date (user_id, date),
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: signals
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contract VARCHAR(100) NOT NULL,
    direction ENUM('long', 'short') NOT NULL DEFAULT 'long',
    entry_price DECIMAL(20, 18),
    status ENUM('active', 'closed', 'expired') DEFAULT 'active',
    criteria_snapshot JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_signals_user (user_id),
    INDEX idx_signals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabla: manual_coins
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS manual_coins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_address VARCHAR(100) NOT NULL,
    estado ENUM('pendiente', 'procesado', 'error') DEFAULT 'pendiente',
    mensaje TEXT DEFAULT NULL,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    procesado_en DATETIME DEFAULT NULL,
    INDEX idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

