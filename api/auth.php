<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

session_start();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['username']) || !isset($input['pin'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Faltan username o pin']);
        exit;
    }

    $username = trim($input['username']);
    $pin = trim($input['pin']);

    if (empty($username) || empty($pin)) {
        http_response_code(400);
        echo json_encode(['error' => 'Campos vacíos']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, pin, nivel, nivel_detalle, plan, is_admin, ultimo_login FROM usuarios WHERE username = ? AND activo = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }

    if ($user['pin'] !== $pin) {
        http_response_code(401);
        echo json_encode(['error' => 'PIN incorrecto']);
        exit;
    }

    $updateStmt = $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?");
    $updateStmt->execute([$user['id']]);

    $token = hash('sha256', $user['id'] . $user['username'] . 'altchecks_secret');
    $nivelDetalle = $user['nivel_detalle'] ?? 1;

    // Auto-create API key if not exists
    $stmtKey = $pdo->prepare("SELECT `key` FROM api_keys WHERE user_id = ?");
    $stmtKey->execute([$user['id']]);
    $existingKey = $stmtKey->fetch();
    if ($existingKey) {
        $apiKey = $existingKey['key'];
    } else {
        $apiKey = generateUUID();
        $pdo->prepare("INSERT INTO api_keys (user_id, `key`) VALUES (?, ?)")->execute([$user['id'], $apiKey]);
        $pdo->prepare("INSERT INTO system_criteria (user_id) VALUES (?)")->execute([$user['id']]);
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'nivel' => $user['nivel'],
            'nivel_detalle' => $nivelDetalle,
            'plan' => $user['plan'] ?? 'basic',
            'is_admin' => (bool)($user['is_admin'] ?? false),
            'token' => $token,
            'api_key' => $apiKey
        ],
        'server' => [
            'activo' => isServerActive()
        ]
    ]);
    exit;
}

if ($method === 'GET') {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $headers['Token'] ?? null;

    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Token requerido']);
        exit;
    }

    $stmt = $pdo->query("SELECT id, username, nivel, plan, is_admin FROM usuarios WHERE activo = 1");
    $users = $stmt->fetchAll();

    foreach ($users as $u) {
        $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altchecks_secret');
        if ($token === $expectedToken) {
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'nivel' => $u['nivel'],
                    'plan' => $u['plan'] ?? 'basic',
                    'is_admin' => (bool)($u['is_admin'] ?? false),
                    'token' => $token
                ]
            ]);
            exit;
        }
    }

    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

if ($method === 'DELETE') {
    echo json_encode(['success' => true, 'message' => 'Sesión cerrada']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);