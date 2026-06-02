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

$stmt = $pdo->prepare("SELECT id, username, nivel FROM usuarios WHERE activo = 1");
$stmt->execute();

$userFound = false;
$allUsers = $stmt->fetchAll();
foreach ($allUsers as $u) {
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altiChecker_secret');
    if ($token === $expectedToken) {
        $userFound = true;
        $nivel = $u['nivel'];
        $username = $u['username'];
        break;
    }
}

if (!$userFound) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get';

    switch ($action) {
        case 'get':
            $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando' ORDER BY creado_en DESC LIMIT 100");
            $tokens = $stmt->fetchAll();
            
            $historialStmt = $pdo->query("SELECT * FROM historial_tokens ORDER BY fecha_salida DESC LIMIT 500");
            $historial = $historialStmt->fetchAll();
            
            $walletStmt = $pdo->query("SELECT saldo, ultima_actualizacion FROM wallet WHERE id = 1");
            $walletData = $walletStmt->fetch();
            $walletSaldo = $walletData ? (float)$walletData['saldo'] : 1000.00;
            
            $profit30d = $pdo->query("SELECT COALESCE(SUM(monto), 0) as total FROM wallet_transactions WHERE tipo = 'profit' AND creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $profit30 = (float)$profit30d->fetch()['total'];
            
            $tagsStmt = $pdo->query("SELECT * FROM coins_tags");
            $coinsTagsMap = [];
            foreach ($tagsStmt->fetchAll() as $ct) {
                $coinsTagsMap[$ct['nombre_normalizado']] = $ct;
            }
            
            echo json_encode([
                'success' => true,
                'tokens' => $tokens,
                'historial' => $historial,
                'wallet' => [
                    'saldo' => $walletSaldo,
                    'profit_30d' => $profit30
                ],
                'coins_tags' => $coinsTagsMap,
                'user' => [
                    'username' => $username,
                    'nivel' => $nivel
                ],
                'server' => [
                    'activo' => isServerActive()
                ]
            ]);
            break;

        case 'historial':
            $stmt = $pdo->query("
                SELECT * FROM historial_tokens
                ORDER BY fecha_salida DESC
                LIMIT 500
            ");
            echo json_encode([
                'success' => true,
                'historial' => $stmt->fetchAll()
            ]);
            break;

        case 'stats':
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total_tokens,
                    SUM(CASE WHEN estado = 'monitoreando' THEN 1 ELSE 0 END) as activos,
                    SUM(CASE WHEN estado = 'exit' THEN 1 ELSE 0 END) as finalizados,
                    (SELECT COUNT(*) FROM historial_tokens WHERE profit_porcentaje > 0) as ganancias,
                    (SELECT COUNT(*) FROM historial_tokens WHERE profit_porcentaje <= 0) as perdidas,
                    (SELECT AVG(profit_porcentaje) FROM historial_tokens) as promedio
                FROM tokens
            ");
            echo json_encode([
                'success' => true,
                'stats' => $stmt->fetch()
            ]);
            break;

        case 'server':
            echo json_encode([
                'success' => true,
                'server' => [
                    'activo' => isServerActive(),
                    'status' => getServerStatus()
                ]
            ]);
            break;

        case 'earnings_by_day':
            $month = $_GET['month'] ?? date('Y-m');
            $stmt = $pdo->prepare("
                SELECT
                    DATE(h.fecha_entrada) as entry_date,
                    SUM(h.profit_porcentaje) as total_earnings_pct,
                    SUM(h.profit_dolares) as total_profit_dollars,
                    COUNT(*) as total_trades
                FROM historial_tokens h
                WHERE h.fecha_entrada IS NOT NULL
                    AND MONTH(h.fecha_entrada) = MONTH(?)
                    AND YEAR(h.fecha_entrada) = YEAR(?)
                GROUP BY DATE(h.fecha_entrada)
                ORDER BY entry_date DESC
            ");
            $stmt->execute([$month . '-01', $month . '-01']);
            $earnings = $stmt->fetchAll();

            // Ensure today's snapshot exists
            $today = date('Y-m-d');
            $snapStmt = $pdo->prepare("SELECT 1 FROM wallet_daily_snapshot WHERE snapshot_date = ?");
            $snapStmt->execute([$today]);
            if (!$snapStmt->fetch()) {
                $walletStmt = $pdo->query("SELECT saldo FROM wallet WHERE id = 1");
                $saldo = $walletStmt->fetch()['saldo'] ?? 1000.00;
                $pdo->prepare("INSERT INTO wallet_daily_snapshot (saldo, snapshot_date) VALUES (?, ?)")
                    ->execute([$saldo, $today]);
            }

            // Calculate starting balance for each day
            $getStartingBalance = function($date) use ($pdo) {
                $snapStmt = $pdo->prepare("SELECT saldo FROM wallet_daily_snapshot WHERE snapshot_date = ?");
                $snapStmt->execute([$date]);
                $snap = $snapStmt->fetch();
                if ($snap) return (float)$snap['saldo'];

                $txStmt = $pdo->prepare("SELECT saldo_resultante FROM wallet_transactions WHERE DATE(creado_en) < ? ORDER BY creado_en DESC LIMIT 1");
                $txStmt->execute([$date]);
                $tx = $txStmt->fetch();
                if ($tx) return (float)$tx['saldo_resultante'];

                return 1000.00;
            };

            foreach ($earnings as &$e) {
                $e['starting_balance'] = $getStartingBalance($e['entry_date']);
                $e['total_earnings_pct'] = (float)($e['total_earnings_pct'] ?? 0);
                $e['total_profit_dollars'] = (float)($e['total_profit_dollars'] ?? 0);
                $e['total_trades'] = (int)($e['total_trades'] ?? 0);
            }
            unset($e);

            echo json_encode([
                'success' => true,
                'earnings' => $earnings,
                'month' => $month,
                'today' => $today
            ]);
            break;

        case 'earnings_detail':
            $date = $_GET['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['success' => false, 'error' => 'Formato de fecha inválido. Use YYYY-MM-DD']);
                break;
            }
            $stmt = $pdo->prepare("
                SELECT id, chain_id, token_address, nombre, simbolo,
                       precio_entrada, precio_salida, profit_porcentaje,
                       profit_dolares, duracion_minutos, razon_salida, tag
                FROM historial_tokens
                WHERE DATE(fecha_entrada) = ?
                ORDER BY fecha_entrada DESC
            ");
            $stmt->execute([$date]);
            $trades = $stmt->fetchAll();
            $totalProfit = 0;
            $totalDollars = 0;
            foreach ($trades as &$t) {
                $totalProfit += (float)($t['profit_porcentaje'] ?? 0);
                $totalDollars += (float)($t['profit_dolares'] ?? 0);
            }
            unset($t);
            echo json_encode([
                'success' => true,
                'date' => $date,
                'trades' => $trades,
                'total_profit_pct' => round($totalProfit, 2),
                'total_profit_dollars' => round($totalDollars, 2)
            ]);
            break;

        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Falta ID']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM historial_tokens WHERE id = ?");
            $stmt->execute([$id]);
            $historial = $stmt->fetch();
            if (!$historial) {
                echo json_encode(['success' => false, 'error' => 'No encontrado']);
                break;
            }
            $tokenExtra = null;
            $stmtToken = $pdo->prepare("SELECT creado_en, fecha_registro, precio_maximo, primer_check FROM tokens WHERE id = ?");
            $stmtToken->execute([$historial['id_token_original']]);
            if ($stmtToken->rowCount() > 0) {
                $tokenExtra = $stmtToken->fetch();
            }
            echo json_encode([
                'success' => true,
                'detail' => $historial,
                'token_extra' => $tokenExtra
            ]);
            break;

        case 'wallet':
            $stmt = $pdo->query("SELECT saldo, ultima_actualizacion FROM wallet WHERE id = 1");
            $walletData = $stmt->fetch();
            $saldo = $walletData ? (float)$walletData['saldo'] : 1000.00;
            
            $profit30d = $pdo->query("SELECT COALESCE(SUM(monto), 0) as total FROM wallet_transactions WHERE tipo = 'profit' AND creado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $profit30 = (float)$profit30d->fetch()['total'];
            
            $txStmt = $pdo->query("SELECT * FROM wallet_transactions ORDER BY creado_en DESC LIMIT 50");
            $transactions = $txStmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'wallet' => [
                    'saldo' => $saldo,
                    'profit_30d' => $profit30
                ],
                'transactions' => $transactions
            ]);
            break;

        case 'token_info':
            $chainId = $_GET['chain_id'] ?? '';
            $tokenAddress = $_GET['token_address'] ?? '';
            if (!$chainId || !$tokenAddress) {
                echo json_encode(['success' => false, 'error' => 'Faltan parámetros']);
                break;
            }

            $pairData = obtenerDatosToken($chainId, $tokenAddress);
            $pair = $pairData[0] ?? null;

            $cacheFile = __DIR__ . '/../servidor/profiles_cache.json';
            $profiles = [];
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                $profiles = json_decode(file_get_contents($cacheFile), true) ?: [];
            } else {
                $resp = @file_get_contents('https://api.dexscreener.com/token-profiles/latest/v1');
                if ($resp) {
                    $profiles = json_decode($resp, true) ?: [];
                    file_put_contents($cacheFile, json_encode($profiles));
                }
            }

            $profile = null;
            foreach ($profiles as $p) {
                if (($p['tokenAddress'] ?? '') === $tokenAddress) {
                    $profile = $p;
                    break;
                }
            }

            $links = [];
            $twitterHandle = null;
            if ($profile && isset($profile['links'])) {
                $links = $profile['links'];
                foreach ($links as $l) {
                    if (($l['type'] ?? '') === 'twitter' && !empty($l['url'])) {
                        $parts = explode('/', rtrim($l['url'], '/'));
                        $twitterHandle = end($parts);
                        break;
                    }
                }
            } elseif ($pair && isset($pair['info'])) {
                if (isset($pair['info']['socials'])) {
                    foreach ($pair['info']['socials'] as $s) {
                        $links[] = ['type' => $s['type'] ?? '', 'url' => $s['url'] ?? ''];
                        if (($s['type'] ?? '') === 'twitter' && !empty($s['url'])) {
                            $parts = explode('/', rtrim($s['url'], '/'));
                            $twitterHandle = end($parts);
                        }
                    }
                }
                if (isset($pair['info']['websites'])) {
                    foreach ($pair['info']['websites'] as $w) {
                        $links[] = ['label' => 'Website', 'url' => $w['url'] ?? ''];
                    }
                }
            }

            $twitterCreatedAt = null;
            if ($twitterHandle) {
                $bearerToken = 'AAAAAAAAAAAAAAAAAAAAALeg9wEAAAAATUzuO0f2yUcKI%2BCo6LHam%2B%2BpZng%3DixkH7p1JH3knOV5mkkQFILMpS32ZCO2AQFQdDJ6msKMgo3G5Bu';
                $url = "https://api.x.com/2/users/by/username/" . urlencode($twitterHandle) . "?user.fields=created_at";
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer $bearerToken"], CURLOPT_SSL_VERIFYPEER => false
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    $twitterCreatedAt = $data['data']['created_at'] ?? null;
                }
            }

            echo json_encode([
                'success' => true,
                'profile' => $profile,
                'pair' => $pair,
                'links' => $links,
                'twitter_created_at' => $twitterCreatedAt
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
    exit;
}

function obtenerDatosToken($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/tokens/v1/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;
    return json_decode($response, true);
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);