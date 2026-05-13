<?php

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'app2');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

function getConfig($clave) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $stmt->execute([$clave]);
    $result = $stmt->fetch();
    return $result ? $result['valor'] : null;
}

function updateConfig($clave, $valor) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?");
    return $stmt->execute([$valor, $clave]);
}

function logSistema($tipo, $mensaje, $detalles = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO logs (nivel, mensaje, detalle) VALUES (?, ?, ?)");
    $stmt->execute([$tipo, $mensaje, $detalles ? json_encode($detalles) : null]);
}

function getServerStatus() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM servidor_status ORDER BY id DESC LIMIT 1");
    return $stmt->fetch();
}

function updateServerStatus($activo) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE servidor_status SET activo = ?, ultimo_check = NOW() WHERE id = 1");
    $stmt->execute([$activo ? 1 : 0]);
}

function isServerActive() {
    $status = getServerStatus();
    if (!$status) {
        return false;
    }
    return (bool)$status['activo'];
}

function generateUUID() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}