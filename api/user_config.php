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

$headers = getallheaders();
$token = $headers['Authorization'] ?? $headers['Token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token requerido']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, nivel, plan, is_admin FROM usuarios WHERE activo = 1");
$stmt->execute();
$userFound = null;
foreach ($stmt->fetchAll() as $u) {
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altchecks_secret');
    if ($token === $expectedToken) {
        $userFound = $u;
        break;
    }
}

if (!$userFound) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'get_api_key') {
    $stmtKey = $pdo->prepare("SELECT `key`, last_regenerated_at FROM api_keys WHERE user_id = ?");
    $stmtKey->execute([$userFound['id']]);
    $apiKey = $stmtKey->fetch();

    if (!$apiKey) {
        $newKey = generateUUID();
        $pdo->prepare("INSERT INTO api_keys (user_id, `key`) VALUES (?, ?)")->execute([$userFound['id'], $newKey]);
        $pdo->prepare("INSERT INTO system_criteria (user_id) VALUES (?)")->execute([$userFound['id']]);
        $apiKey = ['key' => $newKey, 'last_regenerated_at' => null];
    }

    $keyStr = $apiKey['key'];
    $obfuscated = substr($keyStr, 0, 4) . '••••••••' . substr($keyStr, -3);

    echo json_encode([
        'success' => true,
        'api_key' => [
            'key' => $keyStr,
            'key_obfuscated' => $obfuscated,
            'last_regenerated_at' => $apiKey['last_regenerated_at']
        ]
    ]);
    exit;
}

if ($method === 'POST' && $action === 'regenerate_key') {
    $plan = $userFound['plan'] ?? 'basic';
    $isAdmin = (bool)($userFound['is_admin'] ?? false);

    if ($plan === 'basic' && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Los usuarios Basic no pueden regenerar su API key']);
        exit;
    }

    $stmtKey = $pdo->prepare("SELECT `key`, last_regenerated_at FROM api_keys WHERE user_id = ?");
    $stmtKey->execute([$userFound['id']]);
    $existing = $stmtKey->fetch();

    if ($existing && $existing['last_regenerated_at']) {
        $lastTime = strtotime($existing['last_regenerated_at']);
        $diffSeconds = time() - $lastTime;
        $cooldownHours = 72;
        $cooldownSeconds = $cooldownHours * 3600;

        if ($diffSeconds < $cooldownSeconds) {
            $remaining = $cooldownSeconds - $diffSeconds;
            $hours = floor($remaining / 3600);
            $minutes = floor(($remaining % 3600) / 60);

            http_response_code(429);
            echo json_encode([
                'error' => 'too_soon',
                'message' => 'Debes esperar antes de regenerar tu key.',
                'retry_after_hours' => $hours,
                'retry_after_minutes' => $minutes
            ]);
            exit;
        }
    }

    $newKey = generateUUID();
    if ($existing) {
        $pdo->prepare("UPDATE api_keys SET `key` = ?, last_regenerated_at = NOW() WHERE user_id = ?")
            ->execute([$newKey, $userFound['id']]);
    } else {
        $pdo->prepare("INSERT INTO api_keys (user_id, `key`, last_regenerated_at) VALUES (?, ?, NOW())")
            ->execute([$userFound['id'], $newKey]);
        $pdo->prepare("INSERT INTO system_criteria (user_id) VALUES (?)")->execute([$userFound['id']]);
    }

    echo json_encode([
        'success' => true,
        'api_key' => $newKey,
        'regenerated_at' => date('Y-m-d\TH:i:s\Z')
    ]);
    exit;
}

if ($method === 'GET' && $action === 'criteria') {
    $stmt = $pdo->prepare("SELECT * FROM system_criteria WHERE user_id = ?");
    $stmt->execute([$userFound['id']]);
    $criteria = $stmt->fetch();

    if (!$criteria) {
        $pdo->prepare("INSERT INTO system_criteria (user_id) VALUES (?)")->execute([$userFound['id']]);
        $stmt->execute([$userFound['id']]);
        $criteria = $stmt->fetch();
    }

    $plan = $userFound['plan'] ?? 'basic';
    $isAdmin = (bool)($userFound['is_admin'] ?? false);

    if ($plan === 'ultra' || $isAdmin) {
        $editable = ['stop_loss_pct', 'take_profit_pct', 'max_wait_minutes', 'save_profit_pct'];
    } elseif ($plan === 'pro') {
        $editable = ['stop_loss_pct', 'take_profit_pct', 'max_wait_minutes'];
    } else {
        $editable = [];
    }

    echo json_encode([
        'success' => true,
        'criteria' => [
            'stop_loss_pct' => (float)$criteria['stop_loss_pct'],
            'take_profit_pct' => (float)$criteria['take_profit_pct'],
            'max_wait_minutes' => (int)$criteria['max_wait_minutes'],
            'save_profit_pct' => (float)$criteria['save_profit_pct']
        ],
        'editable_fields' => $editable
    ]);
    exit;
}

if ($method === 'PATCH' && $action === 'criteria') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Body inválido']);
        exit;
    }

    $plan = $userFound['plan'] ?? 'basic';
    $isAdmin = (bool)($userFound['is_admin'] ?? false);

    if ($plan === 'ultra' || $isAdmin) {
        $allowed = ['stop_loss_pct', 'take_profit_pct', 'max_wait_minutes', 'save_profit_pct'];
    } elseif ($plan === 'pro') {
        $allowed = ['stop_loss_pct', 'take_profit_pct', 'max_wait_minutes'];
    } else {
        $allowed = [];
    }

    $updates = [];
    $params = [];

    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $val = $input[$field];
            if ($field === 'max_wait_minutes') {
                $val = (int)$val;
                if ($val <= 0) continue;
            } else {
                $val = (float)$val;
                if (in_array($field, ['stop_loss_pct', 'save_profit_pct']) && $val >= 0) continue;
                if ($field === 'take_profit_pct' && $val <= 0) continue;
            }
            $updates[] = "`$field` = ?";
            $params[] = $val;
        }
    }

    if (empty($updates)) {
        echo json_encode(['success' => true, 'message' => 'Sin cambios']);
        exit;
    }

    $params[] = $userFound['id'];
    $pdo->prepare("UPDATE system_criteria SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE user_id = ?")
        ->execute($params);

    echo json_encode(['success' => true, 'message' => 'Criterios actualizados']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
