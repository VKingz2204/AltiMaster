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
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altchecks_secret');
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
            $stmtUser = $pdo->prepare("SELECT nivel_detalle FROM usuarios WHERE username = ?");
            $stmtUser->execute([$username]);
            $userData = $stmtUser->fetch();
            $nivelDetalle = $userData['nivel_detalle'] ?? 1;
            
            if ($nivel === 'admin') {
                $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando' ORDER BY creado_en DESC LIMIT 100");
                $tokens = $stmt->fetchAll();
                
                $historialStmt = $pdo->query("SELECT * FROM historial_tokens ORDER BY fecha_salida DESC LIMIT 50");
                $historial = $historialStmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'tokens' => $tokens,
                    'historial' => $historial,
                    'user' => [
                        'username' => $username,
                        'nivel' => $nivel,
                        'nivel_detalle' => $nivelDetalle
                    ],
                    'server' => [
                        'activo' => isServerActive()
                    ]
                ]);
            } elseif ($nivel === 'vip') {
                $totalStmt = $pdo->query("SELECT COUNT(*) as total FROM tokens WHERE estado = 'monitoreando'");
                $total = $totalStmt->fetch()['total'];
                
                if ($nivelDetalle == 1) {
                    $limite = max(5, ceil($total * 0.20));
                } elseif ($nivelDetalle == 2) {
                    $limite = ceil($total * 0.50);
                } else {
                    $limite = 100;
                }
                
                $stmt = $pdo->prepare("SELECT * FROM tokens WHERE estado = 'monitoreando' ORDER BY RAND() LIMIT ?");
                $stmt->bindValue(1, $limite, PDO::PARAM_INT);
                $stmt->execute();
                $tokens = $stmt->fetchAll();
                
                $historialStmt = $pdo->query("SELECT * FROM historial_tokens ORDER BY fecha_salida DESC LIMIT 50");
                $historial = $historialStmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'tokens' => $tokens,
                    'historial' => $historial,
                    'user' => [
                        'username' => $username,
                        'nivel' => $nivel,
                        'nivel_detalle' => $nivelDetalle
                    ],
                    'server' => [
                        'activo' => isServerActive()
                    ]
                ]);
            } elseif ($nivel === 'free') {
                $stmt = $pdo->query("
                    SELECT t.*, tf.mostrar_desde, tf.mostrar_hasta, tf.activo as free_activo
                    FROM tokens t
                    INNER JOIN tokens_free tf ON t.id = tf.id_token
                    WHERE tf.activo = 1
                    ORDER BY tf.mostrar_desde DESC
                    LIMIT 1
                ");
                $tokenFree = $stmt->fetch();

                if ($tokenFree) {
                    $tiempoRestante = strtotime($tokenFree['mostrar_hasta']) - time();
                    if ($tiempoRestante < 0) $tiempoRestante = 0;
                }

                echo json_encode([
                    'success' => true,
                    'token' => $tokenFree,
                    'tiempo_restante' => $tiempoRestante ?? 0,
                    'server' => [
                        'activo' => isServerActive()
                    ],
                    'user' => [
                        'username' => $username,
                        'nivel' => $nivel,
                        'nivel_detalle' => $nivelDetalle
                    ],
                    'mensaje' => 'Actualiza a VIP para ver todos los tokens'
                ]);
            }
            break;

        case 'historial':
            $stmt = $pdo->query("
                SELECT * FROM historial_tokens
                ORDER BY fecha_salida DESC
                LIMIT 50
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
            $stmt = $pdo->query("
                SELECT
                    DATE(fecha_entrada) as entry_date,
                    SUM(profit_porcentaje) as total_earnings,
                    COUNT(*) as total_trades
                FROM historial_tokens
                WHERE fecha_entrada IS NOT NULL
                GROUP BY DATE(fecha_entrada)
                ORDER BY entry_date DESC
                LIMIT 30
            ");
            echo json_encode([
                'success' => true,
                'earnings' => $stmt->fetchAll()
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

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);