<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

session_start();

$headers = getallheaders();
$token = $headers['Authorization'] ?? $headers['Token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token requerido']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, nivel FROM usuarios WHERE activo = 1");
$stmt->execute();
$userFound = false;
foreach ($stmt->fetchAll() as $u) {
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altiChecker_secret');
    if ($token === $expectedToken) {
        $userFound = true;
        break;
    }
}

if (!$userFound) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$action = $_GET['action'] ?? '';
$tokenAddress = $_GET['token_address'] ?? '';

if (!$tokenAddress) {
    echo json_encode(['success' => false, 'error' => 'Falta token_address']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9]+$/', $tokenAddress)) {
    echo json_encode(['success' => false, 'error' => 'token_address inválido']);
    exit;
}

$logFile = __DIR__ . '/../servidor/coinslog/' . $tokenAddress . '.txt';

switch ($action) {
    case 'log':
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'error' => 'No log data found']);
            exit;
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, -200);
        $entries = [];
        foreach ($lines as $line) {
            $entry = parseLogLine($line);
            if ($entry) $entries[] = $entry;
        }
        echo json_encode(['success' => true, 'entries' => $entries]);
        break;

    case 'download':
        if (!file_exists($logFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $tokenAddress . '.txt"');
        readfile($logFile);
        exit;

    case 'download_csv':
        if (!file_exists($logFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $tokenAddress . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Price', 'MarketCap', 'Liquidity']);
        foreach ($lines as $line) {
            $entry = parseLogLine($line);
            if ($entry) fputcsv($out, [$entry['date'], $entry['price'], $entry['marketCap'], $entry['liquidity']]);
        }
        fclose($out);
        exit;

    case 'download_json':
        if (!file_exists($logFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];
        foreach ($lines as $line) {
            $entry = parseLogLine($line);
            if ($entry) $entries[] = $entry;
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $tokenAddress . '.json"');
        echo json_encode(['token_address' => $tokenAddress, 'count' => count($entries), 'entries' => $entries], JSON_PRETTY_PRINT);
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
}

function parseLogLine($line) {
    $line = rtrim($line, "\r\n");
    if (preg_match('/^\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})\]\[Price=([^\]]+)\]\[MarketCap=([^\]]+)\]\[Liquidity=([^\]]+)\]$/', $line, $m)) {
        return [
            'date' => $m[1],
            'price' => (float)$m[2],
            'marketCap' => (float)$m[3],
            'liquidity' => (float)$m[4]
        ];
    }
    if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] PRECIO=([^\s]+) MARKETCAP=([^\s]+) LIQUIDEZ=([^\s]+)/', $line, $m)) {
        return [
            'date' => $m[1],
            'price' => (float)$m[2],
            'marketCap' => (float)$m[3],
            'liquidity' => (float)$m[4]
        ];
    }
    return null;
}
