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

$stmt = $pdo->query("SELECT id, username, nivel FROM usuarios WHERE activo = 1");
$users = $stmt->fetchAll();

$isAdmin = false;
$userNivel = '';
foreach ($users as $u) {
    $expectedToken = hash('sha256', $u['id'] . $u['username'] . 'altchecks_secret');
    if ($token === $expectedToken) {
        if ($u['nivel'] === 'admin') {
            $isAdmin = true;
            $userNivel = $u['nivel'];
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

            $nivelDetalle = $input['nivel_detalle'] ?? ($input['nivel'] === 'vip' ? 2 : 1);
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

            $nivelDetalle = $input['nivel_detalle'] ?? ($input['nivel'] === 'vip' ? 2 : 1);
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
            
            $stmt = $pdo->prepare("DELETE FROM historial_tokens WHERE id = ?");
            $stmt->execute([$input['historial_id']]);
            
            echo json_encode(['success' => true, 'message' => 'Token baneado del historial']);
            break;

            logSistema('info', 'Configuración actualizada', $input);

            echo json_encode(['success' => true, 'message' => 'Configuración actualizada']);
            break;

        case 'insertar_token_manual':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Solo admin']);
                exit;
            }
            $tokenAddress = trim($input['token_address'] ?? '');
            if (empty($tokenAddress)) {
                http_response_code(400);
                echo json_encode(['error' => 'Falta token_address']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id FROM tokens WHERE token_address = ?");
            $stmt->execute([$tokenAddress]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Token ya existe en el sistema']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO manual_coins (token_address, estado) VALUES (?, 'pendiente')");
            $stmt->execute([$tokenAddress]);
            echo json_encode([
                'success' => true,
                'message' => 'Coin agregada a la cola. El servidor la procesará en breve.',
                'id' => $pdo->lastInsertId()
            ]);
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
            $precioActual = (float)(($tokenData[0]['priceUsd'] ?? 0) ?: $token['precio_actual']);
            $precioEntrada = (float)$token['precio_entrada'];
            $cambio = $precioEntrada > 0 ? round((($precioActual / $precioEntrada) - 1) * 100, 2) : 0;
            manualExitToken($pdo, $token, $precioActual, $cambio);
            echo json_encode(['success' => true, 'message' => 'Token exited manually']);
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

function manualExitToken($pdo, $token, $precioSalida, $profit) {
    $duracionMinutos = 0;
    if ($token['fecha_ingreso']) {
        $stmt = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, '{$token['fecha_ingreso']}', NOW()) as minutos");
        $duracionMinutos = (int)($stmt->fetch()['minutos'] ?? 0);
    }
    $pdo->prepare("UPDATE tokens SET fecha_salida = NOW(), estado = 'exit', tag = NULL WHERE id = ?")
        ->execute([$token['id']]);
    $stmtFecha = $pdo->query("SELECT fecha_salida FROM tokens WHERE id = {$token['id']}");
    $fechaSalidaDb = $stmtFecha->fetch()['fecha_salida'];
    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            es_reentry, fecha_entrada, fecha_salida
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $token['id'], $token['chain_id'], $token['token_address'], $token['pair_address'],
        $token['nombre'], $token['simbolo'], $token['precio_entrada'],
        $token['precio_descubrimiento'] ?? $token['precio_entrada'], $precioSalida,
        $profit, $duracionMinutos, 'manual', null,
        $token['es_reentry'], $token['fecha_ingreso'] ?? $token['fecha_registro'], $fechaSalidaDb
    ]);
    logSistema('info', 'Token exited manually: ' . $token['nombre'], ['id' => $token['id'], 'profit' => $profit . '%']);
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);