<?php
require_once 'config.php';
require_once __DIR__ . '/../servidor/shared.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

@session_start();

$headers = getallheaders();
$token = $headers['Authorization'] ?? $headers['Token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token requerido']);
    exit;
}

$stmt = $pdo->query("SELECT id, username, nivel FROM usuarios WHERE activo = 1");
$users = $stmt->fetchAll();

$isAdmin = false;
$userNivel = '';
foreach ($users as $u) {
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altiChecker_secret');
    if ($token === $expectedToken) {
        $userNivel = $u['nivel'];
        if ($u['nivel'] === 'admin') {
            $isAdmin = true;
        }
        break;
    }
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Solo admins']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'dashboard';

    switch ($action) {
        case 'usuarios':
            $stmt = $pdo->query("
                SELECT id, username, nivel, nivel_detalle, activo, creado_en, ultimo_login
                FROM usuarios
                ORDER BY creado_en DESC
            ");
            echo json_encode([
                'success' => true,
                'usuarios' => $stmt->fetchAll()
            ]);
            break;

        case 'config':
            $stmt = $pdo->query("SELECT clave, valor, descripcion FROM configuracion");
            echo json_encode([
                'success' => true,
                'config' => $stmt->fetchAll()
            ]);
            break;
            
        case 'tokens_banned':
            $stmt = $pdo->query("SELECT * FROM tokens_banned ORDER BY banneado_en DESC");
            echo json_encode([
                'success' => true,
                'tokens_banned' => $stmt->fetchAll()
            ]);
            break;

        case 'stats':
            $stmt = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM usuarios) as total_usuarios,
                    (SELECT COUNT(*) FROM usuarios WHERE nivel = 'vip') as vip,
                    (SELECT COUNT(*) FROM usuarios WHERE nivel = 'free') as free,
                    (SELECT COUNT(*) FROM tokens WHERE estado = 'monitoreando') as tokens_activos,
                    (SELECT COUNT(*) FROM tokens) as total_tokens,
                    (SELECT COUNT(*) FROM historial_tokens) as historial
            ");
            echo json_encode([
                'success' => true,
                'stats' => $stmt->fetch()
            ]);
            break;

        case 'logs':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $stmt = $pdo->prepare("SELECT * FROM logs ORDER BY creado_en DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode([
                'success' => true,
                'logs' => $stmt->fetchAll()
            ]);
            break;

        case 'server':
            echo json_encode([
                'success' => true,
                'server' => getServerStatus()
            ]);
            break;

        case 'criterios':
            $configKeys = [
                'busqueda_intervalo' => 'Search Interval (seconds)',
                'monitoreo_intervalo' => 'Monitoring Interval (seconds)',
                'tp_porcentaje' => 'Take Profit (%)',
                'tp_reentry_porcentaje' => 'Take Profit Re-entry (%)',
                'sl_porcentaje' => 'Stop Loss (%)',
                'reentry_subida_min' => 'Re-entry: Min Rise (%)',
                'crash_porcentaje' => 'Crash: Min Drop (%)',
                'free_cambio_horas' => 'Free: Token Change (hours)'
            ];
            $criterios = [];
            foreach ($configKeys as $key => $label) {
                $valor = getConfig($key);
                $criterios[] = [
                    'clave' => $key,
                    'label' => $label,
                    'valor' => $valor
                ];
            }
            echo json_encode([
                'success' => true,
                'criterios' => $criterios
            ]);
            break;

        case 'coins_revisadas':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $stmt = $pdo->query("
                SELECT * FROM coins_revisadas
                ORDER BY revisado_en DESC
                LIMIT $limit
            ");
            echo json_encode([
                'success' => true,
                'coins' => $stmt->fetchAll()
            ]);
            break;

        default:
            echo json_encode(['success' => true, 'message' => 'Admin panel']);
    }
    exit;
}

if ($method === 'POST') {
    $action = $input['action'] ?? null;

    switch ($action) {
        case 'crear_usuario':
            if (!isset($input['username']) || !isset($input['pin']) || !isset($input['nivel'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Faltan datos']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $stmt->execute([$input['username']]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Usuario ya existe']);
                exit;
            }

            $nivelDetalle = $input['nivel_detalle'] ?? ($input['nivel'] === 'vip' ? 2 : ($input['nivel'] === 'free' ? 1 : 0));
            $stmt = $pdo->prepare("INSERT INTO usuarios (username, pin, nivel, nivel_detalle) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input['username'], $input['pin'], $input['nivel'], $nivelDetalle]);

            logSistema('info', 'Usuario creado: ' . $input['username'], ['nivel' => $input['nivel'], 'detalle' => $nivelDetalle]);

            echo json_encode([
                'success' => true,
                'message' => 'Usuario creado correctamente',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        case 'editar_usuario':
            if (!isset($input['id']) || !isset($input['pin']) || !isset($input['nivel'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Faltan datos']);
                exit;
            }

            $nivelDetalle = $input['nivel_detalle'] ?? ($input['nivel'] === 'vip' ? 2 : ($input['nivel'] === 'free' ? 1 : 0));
            $stmt = $pdo->prepare("UPDATE usuarios SET pin = ?, nivel = ?, nivel_detalle = ? WHERE id = ?");
            $stmt->execute([$input['pin'], $input['nivel'], $nivelDetalle, $input['id']]);

            logSistema('info', 'Usuario actualizado', $input);

            echo json_encode(['success' => true, 'message' => 'Usuario actualizado']);
            break;

        case 'eliminar_usuario':
            if (!isset($input['id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'ID requerido']);
                exit;
            }

            if ($input['id'] == $_SESSION['user_id']) {
                http_response_code(400);
                echo json_encode(['error' => 'No puedes eliminarte a ti mismo']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$input['id']]);

            logSistema('info', 'Usuario eliminado', ['id' => $input['id']]);

            echo json_encode(['success' => true, 'message' => 'Usuario eliminado']);
            break;

        case 'actualizar_config':
            if (!isset($input['clave']) || !isset($input['valor'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Faltan datos']);
                exit;
            }

            $allowedKeys = [
                'free_cambio_horas',
                'monitoreo_intervalo',
                'busqueda_intervalo',
                'tp_porcentaje',
                'tp_reentry_porcentaje',
                'sl_porcentaje',
                'reentry_subida_min',
                'crash_porcentaje'
            ];

            if (!in_array($input['clave'], $allowedKeys)) {
                http_response_code(400);
                echo json_encode(['error' => 'Configuración no permitida']);
                exit;
            }

            updateConfig($input['clave'], $input['valor']);
            echo json_encode(['success' => true, 'message' => 'Configuración actualizada']);
            break;

        case 'ban_historial':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            if (!isset($input['historial_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT token_address, pair_address, chain_id, nombre, monto_invertido, profit_dolares FROM historial_tokens WHERE id = ?");
            $stmt->execute([$input['historial_id']]);
            $h = $stmt->fetch();
            if ($h) {
                adminBanearToken($pdo, $h['token_address'], $h['pair_address'], $h['chain_id'], 'Banned for admin', $h['nombre']);
                
                $profitDol = (float)($h['profit_dolares'] ?? 0);
                if ($profitDol < 0) {
                    $refund = abs($profitDol);
                    updateWallet($pdo, $refund, 'profit', $h['nombre'], $h['token_address'], 0, 'Ban refund', $refund);
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM historial_tokens WHERE id = ?");
            $stmt->execute([$input['historial_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Token baneado del historial']);
            break;

        case 'insertar_token_manual':
            if (!$isAdmin && $userNivel !== 'vip') {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin o pro']);
                exit;
            }
            $tokenAddress = trim($input['token_address'] ?? '');
            if (empty($tokenAddress)) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta token_address']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO manual_coins (token_address, estado) VALUES (?, 'pendiente')");
            $stmt->execute([$tokenAddress]);
            echo json_encode([
                'success' => true,
                'message' => 'Coin agregada y en procesamiento.',
                'id' => $pdo->lastInsertId()
            ]);
            break;

        case 'import_banned':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            $tokens = $input['tokens'] ?? [];
            if (!is_array($tokens) || empty($tokens)) {
                http_response_code(400);
                echo json_encode(['error' => 'Array de tokens requerido']);
                exit;
            }
            $imported = 0;
            $skipped = 0;
            foreach ($tokens as $t) {
                $addr = trim($t['token_address'] ?? '');
                if (empty($addr)) { $skipped++; continue; }
                $stmt = $pdo->prepare("SELECT 1 FROM tokens_banned WHERE token_address = ?");
                $stmt->execute([$addr]);
                if ($stmt->fetch()) { $skipped++; continue; }
                $nombre = trim($t['nombre'] ?? null);
                $pair = trim($t['pair_address'] ?? $addr);
                $chain = trim($t['chain_id'] ?? 'solana');
                $razon = trim($t['razon'] ?? 'Imported');
                $pdo->prepare("INSERT INTO tokens_banned (token_address, pair_address, chain_id, razon, nombre, banneado_en) VALUES (?, ?, ?, ?, ?, NOW())")
                    ->execute([$addr, $pair, $chain, $razon, $nombre ?: null]);
                $imported++;
            }
            echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
            break;

        case 'delete_banned':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            $id = (int)($input['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta id']);
                exit;
            }
            $pdo->prepare("DELETE FROM tokens_banned WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        case 'force_exit':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            $tokenId = (int)($input['token_id'] ?? 0);
            if (!$tokenId) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta token_id']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM tokens WHERE id = ? AND estado = 'monitoreando'");
            $stmt->execute([$tokenId]);
            $token = $stmt->fetch();
            if (!$token) {
                http_response_code(404);
                echo json_encode(['error' => 'Token no encontrado o no está en monitoreo']);
                exit;
            }
            $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
            if (!$tokenData || !isset($tokenData[0])) {
                $searchResp = fetchWithCurl("https://api.dexscreener.com/latest/dex/search?q=" . urlencode($token['token_address']));
                $searchData = $searchResp ? json_decode($searchResp, true) : null;
                if ($searchData && isset($searchData['pairs'][0])) {
                    $tokenData = $searchData['pairs'];
                }
            }
            $precioActual = (float)(($tokenData[0]['priceUsd'] ?? 0) ?: $token['precio_actual']);
            $precioEntrada = (float)$token['precio_entrada'];
            $cambio = $precioEntrada > 0 ? round((($precioActual / $precioEntrada) - 1) * 100, 2) : 0;
            manualExitToken($pdo, $token, $precioActual, $cambio);
            echo json_encode(['success' => true, 'message' => 'Token exited manually']);
            break;

        case 'wallet_adjust':
            $amount = floatval($input['amount'] ?? 0);
            $tipo = $input['tipo'] ?? 'add';
            if ($amount <= 0) {
                echo json_encode(['error' => 'Amount must be > 0']);
                break;
            }
            $saldo = getWalletSaldo($pdo);
            if ($tipo === 'remove') {
                if ($amount > $saldo) {
                    echo json_encode(['error' => 'Insufficient balance']);
                    break;
                }
                updateWallet($pdo, $amount, 'salida', null, null, 0, 'Admin removed $' . $amount);
            } else {
                updateWallet($pdo, $amount, 'entrada', null, null, 0, 'Admin added $' . $amount);
            }
            $nuevoSaldo = getWalletSaldo($pdo);
            echo json_encode(['success' => true, 'saldo' => $nuevoSaldo]);
            break;

        case 'banear_token':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            $banTokenAddress = trim($input['token_address'] ?? '');
            if (empty($banTokenAddress)) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta token_address']);
                exit;
            }
            $banPairAddress = trim($input['pair_address'] ?? '');
            $banChainId = trim($input['chain_id'] ?? 'solana');
            $banRazon = trim($input['razon'] ?? 'Baneado manualmente');
            $banNombre = trim($input['nombre'] ?? null);
            if (empty($banPairAddress)) $banPairAddress = $banTokenAddress;
            adminBanearToken($pdo, $banTokenAddress, $banPairAddress, $banChainId, $banRazon, $banNombre);
            echo json_encode(['success' => true, 'message' => 'Token baneado']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción no válida']);
    }
    exit;
}

function manualExitToken($pdo, $token, $precioSalida, $profit) {
    $duracionMinutos = 0;
    if ($token['fecha_ingreso']) {
        $stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) as minutos");
        $stmt->execute([$token['fecha_ingreso']]);
        $duracionMinutos = (int)($stmt->fetch()['minutos'] ?? 0);
    }
    $pdo->prepare("UPDATE tokens SET fecha_salida = NOW(), estado = 'exit', tag = NULL WHERE id = ?")
        ->execute([$token['id']]);
    $stmtFecha = $pdo->prepare("SELECT fecha_salida FROM tokens WHERE id = ?");
    $stmtFecha->execute([$token['id']]);
    $fechaSalidaDb = $stmtFecha->fetch()['fecha_salida'];

    $montoInvertido = (float)($token['monto_invertido'] ?? 0);
    $profitDolares = 0;
    if ($montoInvertido > 0) {
        $profitDolares = round($montoInvertido * ($profit / 100), 2);
        $montoRetornado = $montoInvertido + $profitDolares;
        updateWallet($pdo, $montoRetornado, 'profit', $token['nombre'], $token['token_address'], (int)($token['confianza'] ?? 0), 'Force exit: ' . ($profit >= 0 ? '+' : '') . $profit . '%', $profitDolares);
    }

    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            fecha_entrada, fecha_salida,
            monto_invertido, profit_dolares
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $token['id'], $token['chain_id'], $token['token_address'], $token['pair_address'],
        $token['nombre'], $token['simbolo'], $token['precio_entrada'],
        $token['precio_descubrimiento'] ?? $token['precio_entrada'], $precioSalida,
        $profit, $duracionMinutos, 'manual', null,
        $token['fecha_ingreso'] ?? $token['fecha_registro'], $fechaSalidaDb,
        $montoInvertido ?: null, $profitDolares ?: null
    ]);
    logSistema('info', 'Token exited manually: ' . $token['nombre'], ['id' => $token['id'], 'profit' => $profit . '%']);

    if ($profitDolares < 0) {
        try {
            $pdo->prepare("INSERT INTO token_cooldowns (pair_address, cooldown_until, profit_dolares)
                VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)
                ON DUPLICATE KEY UPDATE cooldown_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), profit_dolares = ?")
                ->execute([$token['pair_address'], $profitDolares, $profitDolares]);
        } catch (PDOException $e) {
            // Silently handle
        }
    }
}

function adminBanearToken($pdo, $tokenAddress, $pairAddress, $chainId, $razon, $nombre = null) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM tokens_banned WHERE token_address = ?");
        $stmt->execute([$tokenAddress]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO tokens_banned (token_address, pair_address, chain_id, razon, nombre, banneado_en) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$tokenAddress, $pairAddress, $chainId, $razon, $nombre]);
        }
    } catch (PDOException $e) {
        // Silently handle - ban is best-effort
    }
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);