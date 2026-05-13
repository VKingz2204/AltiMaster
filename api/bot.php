<?php
require_once 'config.php';
require_once 'engine.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$headers = getallheaders();
$apiKey = $headers['X-API-Key'] ?? $_GET['api_key'] ?? null;

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'API key requerida. Envía X-API-Key header o api_key query param.']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM api_keys WHERE `key` = ?");
$stmt->execute([$apiKey]);
$keyRecord = $stmt->fetch();

if (!$keyRecord) {
    http_response_code(401);
    echo json_encode(['error' => 'API key inválida']);
    exit;
}

$userId = $keyRecord['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'criteria') {
    $stmt = $pdo->prepare("SELECT * FROM system_criteria WHERE user_id = ?");
    $stmt->execute([$userId]);
    $criteria = $stmt->fetch();

    if (!$criteria) {
        echo json_encode(['error' => 'Criterios no encontrados']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'criteria' => [
            'stop_loss_pct' => (float)$criteria['stop_loss_pct'],
            'take_profit_pct' => (float)$criteria['take_profit_pct'],
            'max_wait_minutes' => (int)$criteria['max_wait_minutes'],
            'save_profit_pct' => (float)$criteria['save_profit_pct'],
            'min_entry_pct' => (float)$criteria['min_entry_pct']
        ]
    ]);
    exit;
}

if ($method === 'GET' && $action === 'signals') {
    $result = getActiveSignals($pdo, $userId);
    $planData = getUserPlanData($pdo, $userId);
    $limit = getDailyLimit($planData['plan'] ?? 'basic');

    echo json_encode([
        'signals' => $result['signals'],
        'daily_limit_reached' => $result['daily_limit_reached'],
        'accumulated_pct' => $result['accumulated_pct'],
        'limit_pct' => $limit ?? 0.0
    ]);
    exit;
}

if ($method === 'POST' && $action === 'profit-update') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['signal_id']) || !isset($input['profit_pct'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Faltan campos requeridos: signal_id, profit_pct']);
        exit;
    }

    $signalId = (int)$input['signal_id'];
    $profitPct = (float)$input['profit_pct'];
    $closeReason = $input['close_reason'] ?? 'manual';

    $stmt = $pdo->prepare("SELECT id FROM signals WHERE id = ? AND user_id = ?");
    $stmt->execute([$signalId, $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Señal no encontrada o no pertenece a este usuario']);
        exit;
    }

    $tracker = closeSignal($pdo, $signalId, $userId, $profitPct, $closeReason);
    $planData = getUserPlanData($pdo, $userId);
    $limit = getDailyLimit($planData['plan'] ?? 'basic');
    $accumulated = (float)$tracker['accumulated_pct'];
    $limitReached = $limit !== null && $accumulated >= $limit;

    echo json_encode([
        'ok' => true,
        'accumulated_pct' => $accumulated,
        'limit_pct' => $limit ?? 0.0,
        'daily_limit_reached' => $limitReached
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
