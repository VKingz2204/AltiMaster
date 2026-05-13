<?php
error_reporting(E_ERROR | E_PARSE);

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'app2');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

function print_line($text, $type = 'info') {
    $colors = [
        'info' => '36m',
        'success' => '32m',
        'error' => '31m',
        'warning' => '33m',
        'dim' => '90m',
    ];
    $color = $colors[$type] ?? '36m';
    echo "\033[{$color}{$text}\033[0m\n";
}

function step($n, $title) {
    print_line("  ┌─ STEP $n ─────────────────────────────────────────────", 'dim');
    print_line("  │ $title", 'info');
    print_line("  │", 'dim');
}

function step_end() {
    print_line("  └─────────────────────────────────────────────────────────────", 'dim');
    echo "\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                      ║\n";
echo "║     ███╗   ███╗ ██████╗ ██╗     ██████╗ ███████╗ ██████╗ ██████╗     ║\n";
echo "║     ████╗ ████║██╔═══██╗██║     ██╔══██╗██╔════╝██╔═══██╗██╔══██╗    ║\n";
echo "║     ██╔████╔██║██║   ██║██║     ██║  ██║█████╗  ██║   ██║██████╔╝    ║\n";
echo "║     ██║╚██╔╝██║██║   ██║██║     ██║  ██║██╔══╝  ██║   ██║██╔══██╗    ║\n";
echo "║     ██║ ╚═╝ ██║╚██████╔╝███████╗██████╔╝██║     ╚██████╔╝██║  ██║    ║\n";
echo "║     ╚═╝     ╚═╝ ╚═════╝ ╚══════╝╚═════╝ ╚═╝      ╚═════╝ ╚═╝  ╚═╝    ║\n";
echo "║                                                                      ║\n";
echo "║                    CRYPTO TOKEN MONITOR                              ║\n";
echo "║                    ════════════════════                               ║\n";
echo "║                      INSTALLATION WIZARD                             ║\n";
echo "║                                                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

print_line("  ▶ Initializing installation...", 'dim');

try {
    $pdoTmp = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoTmp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    print_line("  ✓ Database 'app2' ready", 'success');
    unset($pdoTmp);
} catch (PDOException $e) {
    print_line("  ✗ Database connection failed: " . $e->getMessage(), 'error');
    exit(1);
}

require_once 'api/config.php';
echo "\n";

step(1, "Ensuring tables and columns...");

// Ensure existing tables have new columns
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN plan ENUM('basic','pro','ultra') DEFAULT 'basic'"); print_line("    • usuarios: plan column added", 'success'); } catch (PDOException $e) { print_line("    • usuarios: plan column OK", 'dim'); }
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN is_admin BOOLEAN DEFAULT FALSE"); print_line("    • usuarios: is_admin column added", 'success'); } catch (PDOException $e) { print_line("    • usuarios: is_admin column OK", 'dim'); }
try { $pdo->exec("ALTER TABLE signals ADD COLUMN user_id INT NOT NULL AFTER id"); print_line("    • signals: user_id column added", 'success'); } catch (PDOException $e) { print_line("    • signals: user_id column OK", 'dim'); }
try { $pdo->exec("ALTER TABLE signals ADD INDEX idx_signals_user (user_id)"); print_line("    • signals: user_id index added", 'success'); } catch (PDOException $e) { print_line("    • signals: user_id index OK", 'dim'); }

// Migrate existing users
$pdo->exec("UPDATE usuarios SET is_admin = 1 WHERE nivel = 'admin' AND (is_admin IS NULL OR is_admin = 0)");
$pdo->exec("UPDATE usuarios SET plan = 'ultra' WHERE nivel = 'admin' AND (plan IS NULL OR plan = 'basic')");
$pdo->exec("UPDATE usuarios SET plan = 'basic' WHERE nivel = 'free' AND (plan IS NULL OR plan = 'basic')");
$pdo->exec("UPDATE usuarios SET plan = 'basic' WHERE nivel = 'vip' AND (nivel_detalle = '1' OR nivel_detalle IS NULL) AND (plan IS NULL OR plan = 'basic')");
$pdo->exec("UPDATE usuarios SET plan = 'pro' WHERE nivel = 'vip' AND nivel_detalle = '2' AND (plan IS NULL OR plan = 'basic')");
$pdo->exec("UPDATE usuarios SET plan = 'ultra' WHERE nivel = 'vip' AND nivel_detalle = '3' AND (plan IS NULL OR plan = 'basic')");

step_end();

step(2, "Creating tables if not exist...");

$pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE,
    pin VARCHAR(10) NOT NULL, nivel ENUM('admin', 'free', 'vip') NOT NULL DEFAULT 'free',
    nivel_detalle VARCHAR(20) DEFAULT '1',
    plan ENUM('basic', 'pro', 'ultra') DEFAULT 'basic',
    is_admin BOOLEAN DEFAULT FALSE,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimo_login DATETIME DEFAULT NULL, activo BOOLEAN DEFAULT TRUE,
    INDEX idx_username (username), INDEX idx_nivel (nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • usuarios", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
    id INT AUTO_INCREMENT PRIMARY KEY, chain_id VARCHAR(20) NOT NULL,
    token_address VARCHAR(100) NOT NULL, pair_address VARCHAR(100) NOT NULL UNIQUE,
    nombre VARCHAR(100) DEFAULT NULL, simbolo VARCHAR(20) DEFAULT NULL,
    precio_actual DECIMAL(20, 18) DEFAULT 0, precio_entrada DECIMAL(20, 18) DEFAULT 0,
    precio_descubrimiento DECIMAL(20, 18) DEFAULT 0,
    precio_crash DECIMAL(20, 18) DEFAULT NULL, precio_maximo DECIMAL(20, 18) DEFAULT 0,
    precio_15_peak DECIMAL(20, 18) DEFAULT 0, last_check_price DECIMAL(20, 18) DEFAULT 0,
    market_cap DECIMAL(20, 2) DEFAULT 0, liquidez DECIMAL(20, 2) DEFAULT 0,
    cambio_1h DECIMAL(10, 2) DEFAULT 0, cambio_6h DECIMAL(10, 2) DEFAULT 0,
    cambio_24h DECIMAL(10, 2) DEFAULT 0,
    estado ENUM('nuevo', 'monitoreando', 'exit') DEFAULT 'nuevo',
    meta_tp DECIMAL(10, 2) DEFAULT 15, tp_alcanzado BOOLEAN DEFAULT FALSE,
    sl_alcanzado BOOLEAN DEFAULT FALSE, passed_15 BOOLEAN DEFAULT FALSE,
    es_reentry BOOLEAN DEFAULT FALSE, reentry_count INT DEFAULT 0,
    checks_count INT DEFAULT 0, laps INT DEFAULT 0, timeout_count INT DEFAULT 0,
    tag VARCHAR(20) DEFAULT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP, fecha_ingreso DATETIME DEFAULT NULL,
    fecha_salida DATETIME DEFAULT NULL, primer_check DATETIME DEFAULT NULL,
    ultimo_check DATETIME DEFAULT NULL, creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado (estado), INDEX idx_par (pair_address), INDEX idx_fechas (fecha_registro, fecha_ingreso, fecha_salida),
    INDEX idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • tokens", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS tokens_free (
    id INT AUTO_INCREMENT PRIMARY KEY, id_token INT NOT NULL,
    mostrar_desde DATETIME DEFAULT CURRENT_TIMESTAMP, mostrar_hasta DATETIME DEFAULT NULL,
    activo BOOLEAN DEFAULT TRUE, FOREIGN KEY (id_token) REFERENCES tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • tokens_free", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS tokens_banned (
    id INT AUTO_INCREMENT PRIMARY KEY, token_address VARCHAR(100) NOT NULL,
    pair_address VARCHAR(100) NOT NULL, chain_id VARCHAR(20) NOT NULL,
    razon VARCHAR(255) DEFAULT NULL, banneado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_token (pair_address, chain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • tokens_banned", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS historial_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY, id_token_original INT NOT NULL,
    chain_id VARCHAR(20), token_address VARCHAR(100), pair_address VARCHAR(100),
    nombre VARCHAR(100), simbolo VARCHAR(20), precio_entrada DECIMAL(20, 18),
    precio_descubrimiento DECIMAL(20, 18) DEFAULT 0,
    precio_salida DECIMAL(20, 18), profit_porcentaje DECIMAL(10, 2),
    duracion_minutos INT, razon_salida ENUM('tp', 'sl', 'save_tp', 'caida_pico', 'timeout', 'ban', 'expirado', 'manual') DEFAULT 'expirado',
    tag VARCHAR(20) DEFAULT NULL,
    es_reentry BOOLEAN DEFAULT FALSE, fecha_entrada DATETIME,
    fecha_salida DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_fecha (fecha_salida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • historial_tokens", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS coins_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_normalizado VARCHAR(100) NOT NULL UNIQUE,
    strong_count INT DEFAULT 0,
    destroyed_count INT DEFAULT 0,
    checking_count INT DEFAULT 0,
    okay_count INT DEFAULT 0,
    ultimo_tag VARCHAR(20) DEFAULT NULL,
    actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nombre (nombre_normalizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • coins_tags", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY, clave VARCHAR(50) NOT NULL UNIQUE,
    valor VARCHAR(255) DEFAULT NULL, descripcion VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • configuracion", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY, nivel ENUM('info', 'warning', 'error') DEFAULT 'info',
    mensaje TEXT, detalle JSON, creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nivel (nivel), INDEX idx_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • logs", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS servidor_status (
    id INT AUTO_INCREMENT PRIMARY KEY, activo BOOLEAN DEFAULT FALSE,
    ultimo_inicio DATETIME DEFAULT NULL, ultimo_check DATETIME DEFAULT NULL,
    tokens_activos INT DEFAULT 0, ultima_actualizacion DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • servidor_status", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS coins_revisadas (
    id INT AUTO_INCREMENT PRIMARY KEY, pair_address VARCHAR(100) NOT NULL,
    chain_id VARCHAR(20) NOT NULL, nombre VARCHAR(100) DEFAULT NULL,
    precio DECIMAL(20, 18) DEFAULT 0, market_cap DECIMAL(20, 2) DEFAULT 0,
    liquidez DECIMAL(20, 2) DEFAULT 0, cambio_1h DECIMAL(10, 2) DEFAULT 0,
    cambio_6h DECIMAL(10, 2) DEFAULT 0, cambio_24h DECIMAL(10, 2) DEFAULT 0,
    accion VARCHAR(20) DEFAULT NULL, razon VARCHAR(255) DEFAULT NULL,
    revisado_en DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_pair (pair_address), INDEX idx_fecha (revisado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • coins_revisadas", 'success');

// New API tables
$pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
    `key` VARCHAR(36) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_regenerated_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • api_keys", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS system_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
    stop_loss_pct DECIMAL(10, 2) DEFAULT -6.0, take_profit_pct DECIMAL(10, 2) DEFAULT 24.0,
    max_wait_minutes INT DEFAULT 60, save_profit_pct DECIMAL(10, 2) DEFAULT -6.0,
    min_entry_pct DECIMAL(10, 2) DEFAULT 1.5, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • system_criteria", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS daily_profit_tracker (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
    date DATE NOT NULL, accumulated_pct DECIMAL(10, 2) DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_date (user_id, date),
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • daily_profit_tracker", 'success');

$pdo->exec("CREATE TABLE IF NOT EXISTS signals (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
    contract VARCHAR(100) NOT NULL,
    direction ENUM('long', 'short') NOT NULL DEFAULT 'long', entry_price DECIMAL(20, 18),
    status ENUM('active', 'closed', 'expired') DEFAULT 'active',
    criteria_snapshot JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_signals_user (user_id), INDEX idx_signals_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
print_line("    • signals", 'success');

step_end();

step(3, "Ensuring default data...");
// Insert default users only if they don't exist
$existingUsers = $pdo->query("SELECT COUNT(*) as c FROM usuarios")->fetch()['c'];
if ($existingUsers == 0) {
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, is_admin) VALUES ('admin', '1234', 'admin', 'ultra', 1)");
    print_line("    • Admin user created (PIN: 1234)", 'success');
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES ('freeuser', '1111', 'vip', 'basic', '1')");
    print_line("    • Free user created (PIN: 1111 | Plan: Basic)", 'success');
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES ('vipuser', '2222', 'vip', 'pro', '2')");
    print_line("    • VIP user created (PIN: 2222 | Plan: Pro)", 'success');
} else {
    print_line("    • Users already exist, skipping default insert", 'dim');
}
$pdo->exec("INSERT IGNORE INTO servidor_status (id, activo) VALUES (1, 0)");
print_line("    • Server status initialized", 'success');

$configs = [
    ['busqueda_intervalo', '60', 'Search interval (seconds)'],
    ['monitoreo_intervalo', '30', 'Monitoring interval (seconds)'],
    ['tp_porcentaje', '24', 'Take Profit percentage'],
    ['tp_reentry_porcentaje', '20', 'TP for re-entry'],
    ['sl_porcentaje', '10', 'Stop Loss percentage'],
    ['reentry_subida_min', '5', 'Min rise for re-entry (%)'],
    ['crash_porcentaje', '-4000', 'Crash percentage'],
    ['free_cambio_horas', '6', 'Free token change hours'],
];
foreach ($configs as $c) {
    $stmt = $pdo->prepare("SELECT 1 FROM configuracion WHERE clave = ?");
    $stmt->execute([$c[0]]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?)")->execute($c);
    }
}
print_line("    • System configuration set", 'success');
step_end();

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                           ✓ COMPLETED                                 ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

print_line("  USERS", 'warning');
echo "  ┌────────────────────────────────────────────────────────────────┐\n";
echo "  │  • admin    │ 1234 │ Administrator                              │\n";
echo "  │  • freeuser │ 1111 │ VIP Basic (20% tokens)                     │\n";
echo "  │  • vipuser  │ 2222 │ VIP Pro (50% tokens)                       │\n";
echo "  └────────────────────────────────────────────────────────────────┘\n\n";

print_line("  NEXT STEPS", 'warning');
echo "  ┌────────────────────────────────────────────────────────────────┐\n";
echo "  │  1. Start the server:                                          │\n";
echo "  │       php servidor/servidor.php                                │\n";
echo "  │                                                                  │\n";
echo "  │  2. Open the dashboard:                                        │\n";
echo "  │       http://your-server/app2/                                  │\n";
echo "  │                                                                  │\n";
echo "  │  3. Default configuration:                                     │\n";
echo "  │       • TP: +24%    • SL: -6% from peak (or -3% if peak <12%)  │\n";
echo "  └────────────────────────────────────────────────────────────────┘\n\n";

print_line("  System ready. Happy trading! 🚀", 'success');
echo "\n";