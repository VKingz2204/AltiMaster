<?php
error_reporting(E_ERROR | E_PARSE);

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'app2');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

ob_start();

$track = [
    'tablesCreated' => [],
    'tablesExisted' => [],
    'columnsAdded' => [],
    'columnsExisted' => [],
    'columnsUpdated' => [],
    'migrations' => [],
    'errors' => [],
];

function tableExists($pdo, $name) {
    $s = $pdo->query("SHOW TABLES LIKE '$name'");
    return $s && $s->fetch();
}

// ── Database setup ──
$dbOk = false;
try {
    $pdoTmp = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoTmp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $dbOk = true;
    unset($pdoTmp);
} catch (PDOException $e) {
    $track['errors'][] = 'Database connection: ' . $e->getMessage();
}

require_once 'api/config.php';

// ── Detect install type ──
$isUpdate = tableExists($pdo, 'tokens');

// ─────────────────────────────────────────────
//  STEP 1 — Database structure (ALTER TABLE)
// ─────────────────────────────────────────────

$addColMigrations = [
    ['ALTER TABLE usuarios ADD COLUMN plan ENUM(\'basic\',\'pro\',\'ultra\') DEFAULT \'basic\'', 'usuarios.plan'],
    ['ALTER TABLE usuarios ADD COLUMN is_admin BOOLEAN DEFAULT FALSE', 'usuarios.is_admin'],
    ['ALTER TABLE signals ADD COLUMN user_id INT NOT NULL AFTER id', 'signals.user_id'],
    ['ALTER TABLE signals ADD INDEX idx_signals_user (user_id)', 'signals.idx_signals_user'],
    ['ALTER TABLE tokens ADD COLUMN precio_descubrimiento DECIMAL(30,18) DEFAULT 0 AFTER precio_entrada', 'tokens.precio_descubrimiento'],
    ['ALTER TABLE historial_tokens ADD COLUMN precio_descubrimiento DECIMAL(30,18) DEFAULT 0 AFTER precio_entrada', 'historial_tokens.precio_descubrimiento'],
    ['ALTER TABLE tokens ADD COLUMN IF NOT EXISTS lenta BOOLEAN DEFAULT FALSE AFTER passed_15', 'tokens.lenta'],
    ['ALTER TABLE tokens ADD COLUMN confirmacion_count INT DEFAULT 0', 'tokens.confirmacion_count'],
    ['ALTER TABLE coins_tags ADD COLUMN inestable_count INT DEFAULT 0', 'coins_tags.inestable_count'],
    ['ALTER TABLE tokens ADD COLUMN monto_invertido DECIMAL(15,2) DEFAULT NULL AFTER tag', 'tokens.monto_invertido'],
    ['ALTER TABLE tokens ADD COLUMN confianza INT DEFAULT NULL AFTER monto_invertido', 'tokens.confianza'],
    ['ALTER TABLE historial_tokens ADD COLUMN monto_invertido DECIMAL(15,2) DEFAULT NULL AFTER fecha_salida', 'historial_tokens.monto_invertido'],
    ['ALTER TABLE historial_tokens ADD COLUMN profit_dolares DECIMAL(15,2) DEFAULT NULL AFTER monto_invertido', 'historial_tokens.profit_dolares'],
    ['ALTER TABLE tokens_banned ADD COLUMN nombre VARCHAR(100) DEFAULT NULL AFTER razon', 'tokens_banned.nombre'],
];

$modColMigrations = [
    ['ALTER TABLE tokens MODIFY precio_actual DECIMAL(30,18) DEFAULT 0', 'tokens.precio_actual precision'],
    ['ALTER TABLE tokens MODIFY precio_entrada DECIMAL(30,18) DEFAULT 0', 'tokens.precio_entrada precision'],
    ['ALTER TABLE tokens MODIFY precio_descubrimiento DECIMAL(30,18) DEFAULT 0', 'tokens.precio_descubrimiento precision'],
    ['ALTER TABLE tokens MODIFY precio_maximo DECIMAL(30,18) DEFAULT 0', 'tokens.precio_maximo precision'],
    ['ALTER TABLE tokens MODIFY precio_15_peak DECIMAL(30,18) DEFAULT 0', 'tokens.precio_15_peak precision'],
    ['ALTER TABLE historial_tokens MODIFY precio_entrada DECIMAL(30,18)', 'historial_tokens.precio_entrada precision'],
    ['ALTER TABLE historial_tokens MODIFY precio_descubrimiento DECIMAL(30,18) DEFAULT 0', 'historial_tokens.precio_descubrimiento precision'],
    ['ALTER TABLE historial_tokens MODIFY precio_salida DECIMAL(30,18)', 'historial_tokens.precio_salida precision'],
    ["ALTER TABLE historial_tokens MODIFY COLUMN razon_salida ENUM('tp','sl','save_tp','caida_pico','timeout','ban','expirado','manual','inestable') DEFAULT 'expirado'", 'historial_tokens.razon_salida enum'],
];

foreach ($addColMigrations as $m) {
    try { $pdo->exec($m[0]); $track['columnsAdded'][] = $m[1]; }
    catch (PDOException $e) {
        if ($e->getCode() == 1060) {
            $track['columnsExisted'][] = $m[1];
        } elseif ($e->getCode() != 1146) {
            $track['errors'][] = $m[1] . ': ' . $e->getMessage();
        }
    }
}

foreach ($modColMigrations as $m) {
    try { $pdo->exec($m[0]); $track['columnsUpdated'][] = $m[1]; }
    catch (PDOException $e) {
        if ($e->getCode() != 1146) {
            $track['errors'][] = $m[1] . ': ' . $e->getMessage();
        }
    }
}

// User data migrations
if ($isUpdate) {
    $userMigrations = [
        "UPDATE usuarios SET is_admin = 1 WHERE nivel = 'admin' AND (is_admin IS NULL OR is_admin = 0)",
        "UPDATE usuarios SET plan = 'ultra' WHERE nivel = 'admin' AND (plan IS NULL OR plan = 'basic')",
        "UPDATE usuarios SET plan = 'basic' WHERE nivel = 'free' AND (plan IS NULL OR plan = 'basic')",
        "UPDATE usuarios SET plan = 'basic' WHERE nivel = 'vip' AND (nivel_detalle = '1' OR nivel_detalle IS NULL) AND (plan IS NULL OR plan = 'basic')",
        "UPDATE usuarios SET plan = 'pro' WHERE nivel = 'vip' AND nivel_detalle = '2' AND (plan IS NULL OR plan = 'basic')",
        "UPDATE usuarios SET plan = 'ultra' WHERE nivel = 'vip' AND nivel_detalle = '3' AND (plan IS NULL OR plan = 'basic')",
    ];
    foreach ($userMigrations as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {}
    }
    $track['migrations'][] = 'User plan migration applied';
}

// ─────────────────────────────────────────────
//  STEP 2 — Tables (CREATE TABLE)
// ─────────────────────────────────────────────

$tableDefs = [
    ['usuarios', "CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE,
        pin VARCHAR(10) NOT NULL, nivel ENUM('admin', 'free', 'vip') NOT NULL DEFAULT 'free',
        nivel_detalle VARCHAR(20) DEFAULT '1',
        plan ENUM('basic', 'pro', 'ultra') DEFAULT 'basic',
        is_admin BOOLEAN DEFAULT FALSE,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        ultimo_login DATETIME DEFAULT NULL, activo BOOLEAN DEFAULT TRUE,
        INDEX idx_username (username), INDEX idx_nivel (nivel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['tokens', "CREATE TABLE IF NOT EXISTS tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, chain_id VARCHAR(20) NOT NULL,
        token_address VARCHAR(100) NOT NULL, pair_address VARCHAR(100) NOT NULL UNIQUE,
        nombre VARCHAR(100) DEFAULT NULL, simbolo VARCHAR(20) DEFAULT NULL,
        precio_actual DECIMAL(30, 18) DEFAULT 0, precio_entrada DECIMAL(30, 18) DEFAULT 0,
        precio_descubrimiento DECIMAL(30, 18) DEFAULT 0,
        precio_maximo DECIMAL(30, 18) DEFAULT 0,
        precio_15_peak DECIMAL(30, 18) DEFAULT 0, last_check_price DECIMAL(20, 18) DEFAULT 0,
        market_cap DECIMAL(20, 2) DEFAULT 0, liquidez DECIMAL(20, 2) DEFAULT 0,
        cambio_1h DECIMAL(10, 2) DEFAULT 0, cambio_6h DECIMAL(10, 2) DEFAULT 0,
        cambio_24h DECIMAL(10, 2) DEFAULT 0,
        estado ENUM('nuevo', 'monitoreando', 'exit') DEFAULT 'nuevo',
        meta_tp DECIMAL(10, 2) DEFAULT 15, tp_alcanzado BOOLEAN DEFAULT FALSE,
        sl_alcanzado BOOLEAN DEFAULT FALSE, passed_15 BOOLEAN DEFAULT FALSE, lenta BOOLEAN DEFAULT FALSE,
        checks_count INT DEFAULT 0, laps INT DEFAULT 0, timeout_count INT DEFAULT 0,
        tag VARCHAR(20) DEFAULT NULL,
        confirmacion_count INT DEFAULT 0,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP, fecha_ingreso DATETIME DEFAULT NULL,
        fecha_salida DATETIME DEFAULT NULL, primer_check DATETIME DEFAULT NULL,
        ultimo_check DATETIME DEFAULT NULL, creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_estado (estado), INDEX idx_par (pair_address), INDEX idx_fechas (fecha_registro, fecha_ingreso, fecha_salida),
        INDEX idx_nombre (nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['tokens_free', "CREATE TABLE IF NOT EXISTS tokens_free (
        id INT AUTO_INCREMENT PRIMARY KEY, id_token INT NOT NULL,
        mostrar_desde DATETIME DEFAULT CURRENT_TIMESTAMP, mostrar_hasta DATETIME DEFAULT NULL,
        activo BOOLEAN DEFAULT TRUE, FOREIGN KEY (id_token) REFERENCES tokens(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['tokens_banned', "CREATE TABLE IF NOT EXISTS tokens_banned (
        id INT AUTO_INCREMENT PRIMARY KEY, token_address VARCHAR(100) NOT NULL,
        pair_address VARCHAR(100) NOT NULL, chain_id VARCHAR(20) NOT NULL,
        razon VARCHAR(255) DEFAULT NULL, banneado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_token (pair_address, chain_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['historial_tokens', "CREATE TABLE IF NOT EXISTS historial_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY, id_token_original INT NOT NULL,
        chain_id VARCHAR(20), token_address VARCHAR(100), pair_address VARCHAR(100),
        nombre VARCHAR(100), simbolo VARCHAR(20), precio_entrada DECIMAL(30, 18),
        precio_descubrimiento DECIMAL(30, 18) DEFAULT 0,
        precio_salida DECIMAL(30, 18), profit_porcentaje DECIMAL(10, 2),
        duracion_minutos INT, razon_salida ENUM('tp', 'sl', 'save_tp', 'caida_pico', 'timeout', 'ban', 'expirado', 'manual', 'inestable') DEFAULT 'expirado',
        tag VARCHAR(20) DEFAULT NULL,
        fecha_entrada DATETIME,
        fecha_salida DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_fecha (fecha_salida)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['coins_tags', "CREATE TABLE IF NOT EXISTS coins_tags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_normalizado VARCHAR(100) NOT NULL UNIQUE,
        checking_count INT DEFAULT 0,
        okay_count INT DEFAULT 0, inestable_count INT DEFAULT 0,
        ultimo_tag VARCHAR(20) DEFAULT NULL,
        actualizado_en DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_nombre (nombre_normalizado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['configuracion', "CREATE TABLE IF NOT EXISTS configuracion (
        id INT AUTO_INCREMENT PRIMARY KEY, clave VARCHAR(50) NOT NULL UNIQUE,
        valor VARCHAR(255) DEFAULT NULL, descripcion VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['logs', "CREATE TABLE IF NOT EXISTS logs (
        id INT AUTO_INCREMENT PRIMARY KEY, nivel ENUM('info', 'warning', 'error') DEFAULT 'info',
        mensaje TEXT, detalle JSON, creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_nivel (nivel), INDEX idx_fecha (creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['servidor_status', "CREATE TABLE IF NOT EXISTS servidor_status (
        id INT AUTO_INCREMENT PRIMARY KEY, activo BOOLEAN DEFAULT FALSE,
        ultimo_inicio DATETIME DEFAULT NULL, ultimo_check DATETIME DEFAULT NULL,
        tokens_activos INT DEFAULT 0, ultima_actualizacion DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['coins_revisadas', "CREATE TABLE IF NOT EXISTS coins_revisadas (
        id INT AUTO_INCREMENT PRIMARY KEY, pair_address VARCHAR(100) NOT NULL,
        chain_id VARCHAR(20) NOT NULL, nombre VARCHAR(100) DEFAULT NULL,
        precio DECIMAL(20, 18) DEFAULT 0, market_cap DECIMAL(20, 2) DEFAULT 0,
        liquidez DECIMAL(20, 2) DEFAULT 0, cambio_1h DECIMAL(10, 2) DEFAULT 0,
        cambio_6h DECIMAL(10, 2) DEFAULT 0, cambio_24h DECIMAL(10, 2) DEFAULT 0,
        accion VARCHAR(20) DEFAULT NULL, razon VARCHAR(255) DEFAULT NULL,
        revisado_en DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_pair (pair_address), INDEX idx_fecha (revisado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['api_keys', "CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
        `key` VARCHAR(36) NOT NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_regenerated_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['system_criteria', "CREATE TABLE IF NOT EXISTS system_criteria (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE,
        stop_loss_pct DECIMAL(10, 2) DEFAULT -6.0, take_profit_pct DECIMAL(10, 2) DEFAULT 24.0,
        max_wait_minutes INT DEFAULT 60, save_profit_pct DECIMAL(10, 2) DEFAULT -6.0,
        min_entry_pct DECIMAL(10, 2) DEFAULT 1.5, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['daily_profit_tracker', "CREATE TABLE IF NOT EXISTS daily_profit_tracker (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        date DATE NOT NULL, accumulated_pct DECIMAL(10, 2) DEFAULT 0.0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_user_date (user_id, date),
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['signals', "CREATE TABLE IF NOT EXISTS signals (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        contract VARCHAR(100) NOT NULL,
        direction ENUM('long', 'short') NOT NULL DEFAULT 'long', entry_price DECIMAL(20, 18),
        status ENUM('active', 'closed', 'expired') DEFAULT 'active',
        criteria_snapshot JSON, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        closed_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        INDEX idx_signals_user (user_id), INDEX idx_signals_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['manual_coins', "CREATE TABLE IF NOT EXISTS manual_coins (
        id INT AUTO_INCREMENT PRIMARY KEY, token_address VARCHAR(100) NOT NULL,
        estado ENUM('pendiente', 'procesado', 'error') DEFAULT 'pendiente',
        mensaje TEXT DEFAULT NULL, creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        procesado_en DATETIME DEFAULT NULL, INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['wallet', "CREATE TABLE IF NOT EXISTS wallet (
        id INT AUTO_INCREMENT PRIMARY KEY,
        saldo DECIMAL(15,2) DEFAULT 1000.00,
        ultima_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['wallet_transactions', "CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('entrada', 'salida', 'profit') NOT NULL,
        token_nombre VARCHAR(255) DEFAULT NULL,
        token_address VARCHAR(255) DEFAULT NULL,
        monto DECIMAL(15,2) DEFAULT NULL,
        saldo_resultante DECIMAL(15,2) DEFAULT NULL,
        confianza INT DEFAULT 0,
        detalle TEXT DEFAULT NULL,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['wallet_daily_snapshot', "CREATE TABLE IF NOT EXISTS wallet_daily_snapshot (
        id INT AUTO_INCREMENT PRIMARY KEY,
        saldo DECIMAL(15,2) NOT NULL,
        snapshot_date DATE NOT NULL UNIQUE,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['token_cooldowns', "CREATE TABLE IF NOT EXISTS token_cooldowns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pair_address VARCHAR(100) NOT NULL UNIQUE,
        cooldown_until DATETIME NOT NULL,
        profit_dolares DECIMAL(15,2) NOT NULL,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cooldown (cooldown_until),
        INDEX idx_pair (pair_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
    ['traded_addresses', "CREATE TABLE IF NOT EXISTS traded_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pair_address VARCHAR(100) NOT NULL UNIQUE,
        token_address VARCHAR(100) NOT NULL,
        chain_id VARCHAR(20) NOT NULL,
        nombre VARCHAR(100) DEFAULT NULL,
        razon_salida VARCHAR(20) NOT NULL,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pair (pair_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"],
];

foreach ($tableDefs as $t) {
    $existed = tableExists($pdo, $t[0]);
    try {
        $pdo->exec($t[1]);
        if ($existed) {
            $track['tablesExisted'][] = $t[0];
        } else {
            if (tableExists($pdo, $t[0])) {
                $track['tablesCreated'][] = $t[0];
            } else {
                $track['tablesExisted'][] = $t[0];
            }
        }
    } catch (PDOException $e) {
        $track['errors'][] = $t[0] . ': ' . $e->getMessage();
    }
}

// Wallet initial balance
try {
    $pdo->exec("INSERT IGNORE INTO wallet (id, saldo) VALUES (1, 1000.00)");
} catch (PDOException $e) {}

// ─────────────────────────────────────────────
//  STEP 3 — Default data
// ─────────────────────────────────────────────

$defaultUsersCreated = false;
$usersShown = [];

$existingUsers = $pdo->query("SELECT COUNT(*) as c FROM usuarios")->fetch()['c'];
if ($existingUsers == 0) {
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, is_admin) VALUES ('admin', '1234', 'admin', 'ultra', 1)");
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES ('freeuser', '1111', 'vip', 'basic', '1')");
    $pdo->exec("INSERT INTO usuarios (username, pin, nivel, plan, nivel_detalle) VALUES ('vipuser', '2222', 'vip', 'pro', '2')");
    $defaultUsersCreated = true;
    $usersShown = [
        ['admin', '1234', 'Administrator'],
        ['freeuser', '1111', 'VIP Basic'],
        ['vipuser', '2222', 'VIP Pro'],
    ];
    $track['migrations'][] = 'Default users created';
}

try {
    $pdo->exec("INSERT IGNORE INTO servidor_status (id, activo) VALUES (1, 0)");
} catch (PDOException $e) {}

$configsAdded = 0;
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
        $configsAdded++;
    }
}
if ($configsAdded > 0) $track['migrations'][] = "$configsAdded config values inserted";

// ── Determine install type ──
if (!$isUpdate && count($track['tablesCreated']) > 0) {
    $installType = 'full';
} elseif ($isUpdate && (count($track['columnsAdded']) > 0 || count($track['columnsUpdated']) > 0)) {
    $installType = 'update';
} else {
    $installType = 'none';
}

// ── Labels for display ──
$typeLabel = ['full' => 'FULL INSTALL', 'update' => 'UPDATED', 'none' => 'NO CHANGES'];
$typeClass = ['full' => 'badge-full', 'update' => 'badge-update', 'none' => 'badge-none'];

// ── Count everything for summary ──
$totalTables = count($track['tablesCreated']) + count($track['tablesExisted']);
$totalChanged = count($track['columnsAdded']) + count($track['columnsUpdated']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AltiMaster — Installation</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    background:#0f0f1a;
    color:#e0e0e0;
    font-family:system-ui,-apple-system,sans-serif;
    padding:48px 20px;
    min-height:100vh;
}
.container { max-width:680px; margin:0 auto; }

.header {
    text-align:center;
    margin-bottom:32px;
}
.header h1 {
    font-size:26px;
    font-weight:600;
    letter-spacing:-0.5px;
}
.header .subtitle {
    color:#666;
    font-size:13px;
    margin-top:6px;
}
.header .badge {
    margin-top:10px;
}

.card {
    background:rgba(255,255,255,0.03);
    border:1px solid rgba(255,255,255,0.07);
    border-radius:10px;
    padding:24px;
    margin-bottom:16px;
}
.card-title {
    font-size:11px;
    font-weight:600;
    color:#555;
    text-transform:uppercase;
    letter-spacing:0.8px;
    margin-bottom:14px;
    padding-bottom:8px;
    border-bottom:1px solid rgba(255,255,255,0.05);
}
.card-items {
    display:flex;
    flex-direction:column;
    gap:1px;
}

.item {
    font-family:'SF Mono','Consolas','Monaco',monospace;
    font-size:12px;
    padding:4px 0 4px 14px;
    border-left:2.5px solid transparent;
    line-height:1.5;
}
.item span.label { color:#888; }
.item-added { border-left-color:#4caf50; color:#a5d6a7; }
.item-existed { border-left-color:#333; color:#555; }
.item-updated { border-left-color:#42a5f5; color:#90caf9; }
.item-error { border-left-color:#ef5350; color:#ef9a9a; }

.item .i { display:inline-block; width:18px; text-align:center; font-weight:700; }
.item-added .i { color:#4caf50; }
.item-existed .i { color:#555; }
.item-updated .i { color:#42a5f5; }
.item-error .i { color:#ef5350; }

.badge {
    display:inline-block;
    padding:5px 14px;
    border-radius:6px;
    font-size:11px;
    font-weight:700;
    letter-spacing:0.6px;
    text-transform:uppercase;
}
.badge-full { background:rgba(76,175,80,0.12); color:#4caf50; }
.badge-update { background:rgba(66,165,245,0.12); color:#42a5f5; }
.badge-none { background:rgba(102,102,102,0.1); color:#666; }

.summary {
    text-align:center;
    padding:28px 24px;
}
.summary h2 {
    font-size:18px;
    font-weight:600;
    margin-bottom:20px;
}
.stats {
    display:flex;
    gap:32px;
    justify-content:center;
    margin-bottom:20px;
}
.stat { text-align:center; }
.stat-value {
    font-size:22px;
    font-weight:700;
    color:#e0e0e0;
}
.stat-label {
    font-size:10px;
    color:#555;
    text-transform:uppercase;
    letter-spacing:0.5px;
    margin-top:3px;
}

.users-section {
    margin-top:16px;
    padding-top:14px;
    border-top:1px solid rgba(255,255,255,0.06);
}
.users-section p {
    font-size:11px;
    color:#555;
    text-transform:uppercase;
    letter-spacing:0.5px;
    margin-bottom:6px;
}
.user-row {
    display:inline-block;
    font-family:'SF Mono','Consolas','Monaco',monospace;
    font-size:12px;
    color:#888;
    background:rgba(255,255,255,0.03);
    border:1px solid rgba(255,255,255,0.06);
    border-radius:6px;
    padding:4px 12px;
    margin:0 3px 4px 3px;
}
.user-row strong { color:#b0b0b0; }

.empty-message {
    color:#555;
    font-size:12px;
    font-style:italic;
    padding:8px 0;
}

.error-box {
    background:rgba(239,83,80,0.08);
    border:1px solid rgba(239,83,80,0.15);
    border-radius:10px;
    padding:16px 20px;
    margin-bottom:16px;
}
.error-box h3 {
    font-size:11px;
    font-weight:600;
    color:#ef5350;
    text-transform:uppercase;
    letter-spacing:0.5px;
    margin-bottom:8px;
}
.error-box .item-error { border-left-color:#ef5350; color:#ef9a9a; }
</style>
</head>
<body>
<div class="container">

<div class="header">
    <h1>AltiMaster</h1>
    <div class="subtitle">Installation Wizard</div>
    <div class="badge <?= $typeClass[$installType] ?>"><?= $typeLabel[$installType] ?></div>
</div>

<?php if (!$dbOk): ?>
<div class="error-box">
    <h3>Database Connection Failed</h3>
    <div class="item item-error">
        <span class="i">&#10007;</span>
        <span><?= htmlspecialchars($track['errors'][0] ?? 'Unknown error') ?></span>
    </div>
</div>
<?php else: ?>

<?php
// ── Helper to render items ──
function renderItems($items, $type) {
    if (empty($items)) {
        echo '<div class="empty-message">— none —</div>';
        return;
    }
    $icons = ['added'=>'&#10003;','existed'=>'&rarr;','updated'=>'&#8635;','error'=>'&#10007;'];
    $ic = $icons[$type] ?? '&rarr;';
    echo '<div class="card-items">';
    foreach ($items as $item) {
        echo '<div class="item item-' . $type . '"><span class="i">' . $ic . '</span> ' . htmlspecialchars($item) . '</div>';
    }
    echo '</div>';
}
?>

<?php if (count($track['errors']) > 0 && $dbOk): ?>
<div class="error-box">
    <h3>Errors</h3>
    <?php foreach ($track['errors'] as $err): ?>
        <div class="item item-error"><span class="i">&#10007;</span> <?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-title">Step 1 · Database Structure</div>

    <?php if (count($track['columnsAdded']) > 0 || count($track['columnsExisted']) > 0 || count($track['columnsUpdated']) > 0): ?>
        <?php if (count($track['columnsAdded']) > 0): ?>
            <div style="margin-bottom:6px;">
                <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">New columns</div>
                <?php renderItems($track['columnsAdded'], 'added'); ?>
            </div>
        <?php endif; ?>

        <?php if (count($track['columnsUpdated']) > 0): ?>
            <div style="margin-bottom:6px;">
                <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Updated</div>
                <?php renderItems($track['columnsUpdated'], 'updated'); ?>
            </div>
        <?php endif; ?>

        <?php if (count($track['columnsExisted']) > 0): ?>
            <div>
                <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Already existed</div>
                <?php renderItems($track['columnsExisted'], 'existed'); ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-message">— no changes needed —</div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">Step 2 · Tables</div>

    <?php if (count($track['tablesCreated']) > 0): ?>
        <div style="margin-bottom:6px;">
            <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Created</div>
            <?php renderItems($track['tablesCreated'], 'added'); ?>
        </div>
    <?php endif; ?>

    <?php if (count($track['tablesExisted']) > 0): ?>
        <div>
            <div style="font-size:10px;color:#555;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Already existed</div>
            <?php renderItems($track['tablesExisted'], 'existed'); ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-title">Step 3 · Data</div>

    <?php if (count($track['migrations']) > 0): ?>
        <?php renderItems($track['migrations'], 'added'); ?>
    <?php else: ?>
        <div class="empty-message">— no data changes needed —</div>
    <?php endif; ?>

    <?php if ($defaultUsersCreated): ?>
    <div class="users-section">
        <p>Default users</p>
        <?php foreach ($usersShown as $u): ?>
            <div class="user-row"><strong><?= htmlspecialchars($u[0]) ?></strong> &middot; PIN: <?= htmlspecialchars($u[1]) ?> &middot; <?= htmlspecialchars($u[2]) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card summary">
    <h2>Summary</h2>

    <?php if ($totalTables > 0): ?>
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= $totalTables ?></div>
            <div class="stat-label">Tables</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= count($track['tablesCreated']) ?></div>
            <div class="stat-label">Created</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= $totalChanged ?></div>
            <div class="stat-label">Changes</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= count($track['columnsExisted']) ?></div>
            <div class="stat-label">Skipped</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="badge <?= $typeClass[$installType] ?>" style="margin-bottom:12px;"><?= $typeLabel[$installType] ?></div>

    <?php if ($installType === 'full' || $defaultUsersCreated): ?>
    <div style="font-size:13px;color:#888;line-height:1.6;">
        <div style="margin-bottom:6px;"><strong style="color:#b0b0b0;">Users</strong></div>
        <?php if ($defaultUsersCreated): ?>
            <div class="user-row"><strong>admin</strong> &middot; PIN: 1234 &middot; Administrator</div>
            <div class="user-row"><strong>freeuser</strong> &middot; PIN: 1111 &middot; VIP Basic (20% tokens)</div>
            <div class="user-row"><strong>vipuser</strong> &middot; PIN: 2222 &middot; VIP Pro (50% tokens)</div>
        <?php else: ?>
            <div style="font-size:12px;color:#555;">Existing users preserved (no changes)</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="text-align:center;padding:16px;">
    <div style="font-size:12px;color:#555;line-height:1.8;">
        <strong style="color:#888;">Next steps:</strong><br>
        1. Start servers: <code style="color:#666;">servidor/iniciar.bat</code><br>
        2. Open dashboard: <code style="color:#666;">http://your-server/app2/</code><br>
        3. Stop servers: <code style="color:#666;">servidor/stop.bat</code>
    </div>
</div>

<?php endif; ?>

</div>
</body>
</html>
<?php ob_end_flush(); ?>
