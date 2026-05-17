<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/engine.php';

// === SINGLE INSTANCE LOCK ===
$pidFile = __DIR__ . '/.server.pid';
if (file_exists($pidFile)) {
    $existingPid = trim(file_get_contents($pidFile));
    if ($existingPid) {
        exec("tasklist /FI \"PID eq $existingPid\" 2>NUL", $output);
        foreach ($output as $line) {
            if (strpos($line, 'php.exe') !== false || strpos($line, 'php') !== false) {
                die("[ERROR] Server already running (PID: $existingPid)\n");
            }
        }
    }
}
file_put_contents($pidFile, getmypid());
$cleanupPid = function() use ($pidFile) { @unlink($pidFile); };
register_shutdown_function($cleanupPid);

// === DAILY LOG SETUP ===
$dailylogDir = __DIR__ . '/dailylogs';
if (!is_dir($dailylogDir)) @mkdir($dailylogDir, 0777, true);
$dailyLogFile = $dailylogDir . '/' . date('Y-m-d_Hi') . '.log';

function dailyLog($category, $message) {
    global $dailyLogFile;
    $line = "[" . date('Y-m-d H:i:s') . "] " . strtoupper($category) . " | " . $message . "\n";
    @file_put_contents($dailyLogFile, $line, FILE_APPEND | LOCK_EX);
}

dailyLog('SERVER', 'Starting AltChecks Server');
dailyLog('SERVER', 'DB connected: ' . (isset($pdo) ? 'YES' : 'NO'));

$serverStatus = getServerStatus();
updateServerStatus(true);
$pdo->query("UPDATE servidor_status SET ultimo_inicio = NOW() WHERE id = 1");
logSistema('info', 'Servidor iniciado');

$monitoreoIntervalo = (int)getConfig('monitoreo_intervalo') ?: 15;
$busquedaIntervalo = (int)getConfig('busqueda_intervalo') ?: 300;
$tpPorcentaje = (float)getConfig('tp_porcentaje') ?: 40;
$tpReentry = (float)getConfig('tp_reentry_porcentaje') ?: 20;
$slPorcentaje = (float)getConfig('sl_porcentaje') ?: 10;
$reentryMin = (float)getConfig('reentry_subida_min') ?: 5;
$crashPorcentaje = (float)getConfig('crash_porcentaje') ?: -4000;

// === CTRL+C HANDLER ===
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(function($event) use ($pidFile) {
        $msg = "[" . date('Y-m-d H:i:s') . "] SHUTDOWN | Ctrl+C received. Stopping...\n";
        echo $msg;
        dailyLog('SHUTDOWN', 'Ctrl+C received');
        @unlink($pidFile);
        dailyLog('SHUTDOWN', 'Server stopped');
        exit(0);
    });
}

// === SEARCH STATE ===
$ultimaBusqueda = 0;
$busquedaEnProgreso = false;
$searchCountdown = 0;
$ultimoCompletado = 0;

// === STARTUP DASHBOARD ===
$startTime = time();
$revisionIntervalo = 5;

// Check if terminal supports ANSI
$supportsAnsi = (getenv('TERM') && getenv('TERM') !== '') || strpos(php_uname('s'), 'Windows') !== false;

function launchSearch() {
    $phpCmd = 'C:\xampp\php\php.exe';
    $scriptPath = __DIR__ . '\buscador_tokens.php';
    $logPath = __DIR__ . '\buscador.log';
    pclose(popen("start /B \"\" \"$phpCmd\" \"$scriptPath\" > \"$logPath\" 2>&1", "r"));
    dailyLog('SEARCH', 'Launched background search');
}

function drawStatusLine($label, $value, $width = 50) {
    $label = str_pad($label, 8);
    $value = substr($value, 0, $width - 10);
    return " $label│ $value";
}

function renderDashboard($searchStatus, $pendingCount, $activeCount, $todayTP, $todaySL, $coins, $uptime, $footer) {
    $uptimeStr = sprintf('%dh %02dm', floor($uptime / 3600), floor(($uptime % 3600) / 60));
    
    $lines = [];
    $lines[] = "";
    $lines[] = "  ╔══════════════════════════════════════════════════╗";
    $lines[] = "  ║  AltChecks Server  │  " . date('H:i:s') . "  │  Up " . str_pad($uptimeStr, 8) . "  ║";
    $lines[] = "  ╠══════════════════════════════════════════════════╣";
    $lines[] = "  ║  SEARCH  │ " . str_pad(substr($searchStatus, 0, 38), 38) . " ║";
    $lines[] = "  ║  SUMMARY │ Pending: " . str_pad($pendingCount, 3) . " │ Active: " . str_pad($activeCount, 3) . " │ TP: " . str_pad($todayTP, 3) . " │ SL: " . str_pad($todaySL, 3) . " ║";
    $lines[] = "  ╠══════════════════════════════════════════════════╣";
    $lines[] = "  ║  TOKEN           PRICE           PROFIT   TAG   ║";
    $lines[] = "  ╠══════════════════════════════════════════════════╣";
    
    if (empty($coins)) {
        $lines[] = "  ║  (no active coins)                               ║";
    } else {
        foreach ($coins as $c) {
            $name = str_pad(substr($c['nombre'], 0, 12), 12);
            $price = str_pad(substr('$' . $c['precio'], 0, 14), 14);
            $profit = str_pad(($c['profit'] >= 0 ? '+' : '') . number_format($c['profit'], 1) . '%', 9);
            $tag = $c['tag'] ?? 'OK';
            $arrow = $c['profit'] >= 0 ? '▲' : '▼';
            $lines[] = "  ║  $name$price$profit $arrow   ║";
        }
    }
    
    $lines[] = "  ╠══════════════════════════════════════════════════╣";
    $lines[] = "  ║  " . str_pad(substr($footer, 0, 44), 44) . " ║";
    $lines[] = "  ╚══════════════════════════════════════════════════╝";
    
    $output = "\033[H\033[J" . implode("\r\n", $lines) . "\r\n";
    echo $output;
}

function getTodayCounts($pdo) {
    $today = date('Y-m-d');
    $tp = 0;
    $sl = 0;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM historial_tokens WHERE DATE(fecha_salida) = '$today' AND razon_salida = 'tp'");
        $tp = (int)$stmt->fetch()['c'];
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM historial_tokens WHERE DATE(fecha_salida) = '$today' AND razon_salida IN ('sl','save_tp','expirado','inestable')");
        $sl = (int)$stmt->fetch()['c'];
    } catch (Exception $e) {}
    return [$tp, $sl];
}

$firstDraw = true;

while (true) {
    $ahora = time();
    $uptime = $ahora - $startTime;

    try {
        $pdo->query("UPDATE servidor_status SET ultimo_check = NOW() WHERE id = 1");

        // === SEARCH MANAGEMENT ===
        $searchStatus = 'Idle';
        if ($busquedaEnProgreso) {
            $doneFile = __DIR__ . '/.search_done';
            if (file_exists($doneFile)) {
                @unlink($doneFile);
                $busquedaEnProgreso = false;
                $ultimoCompletado = $ahora;
                $searchCountdown = 5;
                dailyLog('SEARCH', 'Search completed, countdown 5s');
            } else {
                $searchStatus = 'Searching endpoints...';
            }
        } elseif ($ultimoCompletado > 0) {
            $remaining = $searchCountdown - ($ahora - $ultimoCompletado);
            if ($remaining <= 0) {
                launchSearch();
                $busquedaEnProgreso = true;
                $searchStatus = 'Searching endpoints...';
            } else {
                $searchStatus = "Next search in {$remaining}s...";
            }
        } elseif ($ahora - $ultimaBusqueda >= 5) {
            launchSearch();
            $busquedaEnProgreso = true;
            $ultimaBusqueda = $ahora;
            $searchStatus = 'Searching endpoints...';
        }

        // === MONITORING (capture raw output for daily log) ===
        ob_start();
        monitoreoTokensActivos($pdo, $monitoreoIntervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje);
        $rawLog = ob_get_clean();
        if (trim($rawLog)) {
            dailyLog('MONITOR', trim($rawLog));
        }

        // === COUNT ACTIVE TOKENS ===
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM tokens WHERE estado = 'monitoreando'");
        $monitorCount = (int)$stmt->fetch()['c'];
        $stmt = $pdo->query("SELECT COUNT(*) as c FROM tokens WHERE estado = 'nuevo'");
        $pendingCount = (int)$stmt->fetch()['c'];

        $pdo->query("UPDATE servidor_status SET tokens_activos = $monitorCount WHERE id = 1");

        // === EXPIRE STALE SIGNALS ===
        try {
            $expiredCount = expireStaleSignals($pdo);
            if ($expiredCount > 0) {
                dailyLog('INFO', "Expired $expiredCount stale signals");
            }
        } catch (Exception $e) {
            dailyLog('ERROR', 'Signal expiry: ' . $e->getMessage());
        }

        // === BUILD COIN DISPLAY DATA ===
        $displayCoins = [];
        try {
            $stmt = $pdo->query("SELECT nombre, precio_actual, precio_entrada, tag FROM tokens WHERE estado = 'monitoreando' ORDER BY nombre ASC LIMIT 20");
            while ($row = $stmt->fetch()) {
                $pe = (float)$row['precio_entrada'];
                $profit = $pe > 0 ? ((float)$row['precio_actual'] - $pe) / $pe * 100 : 0;
                $displayCoins[] = [
                    'nombre' => $row['nombre'],
                    'precio' => number_format((float)$row['precio_actual'], 8),
                    'profit' => round($profit, 1),
                    'tag' => $row['tag'] ?? ''
                ];
            }
        } catch (Exception $e) {}

        // === TODAY COUNTS ===
        list($todayTP, $todaySL) = getTodayCounts($pdo);

        // === RENDER DASHBOARD ===
        $footer = "Ctrl+C to stop  |  Last check: " . date('H:i:s') . "  |  Raw:" . strlen($rawLog) . "b";
        if ($firstDraw) {
            renderDashboard($searchStatus, $pendingCount, $monitorCount, $todayTP, $todaySL, $displayCoins, $uptime, $footer);
            $firstDraw = false;
        } else {
            // Re-render by overwriting from top
            renderDashboard($searchStatus, $pendingCount, $monitorCount, $todayTP, $todaySL, $displayCoins, $uptime, $footer);
        }

        // === SIGNAL EXPIRY (console) ===
        if (isset($expiredCount) && $expiredCount > 0) {
            $eMsg = "[" . date('Y-m-d H:i:s') . "] Expired $expiredCount stale signals\n";
            dailyLog('INFO', trim($eMsg));
        }

    } catch (Exception $e) {
        $errMsg = "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        dailyLog('ERROR', $e->getMessage());
        logSistema('error', 'Error en servidor: ' . $e->getMessage());
    }

    sleep($revisionIntervalo);
}

function buscarNuevosTokens($pdo, $tpPorcentaje) {
    echo "[" . date('Y-m-d H:i:s') . "] ðŸ” Querying DexScreener APIs...\n";

    $endpoints = [
        'token-profiles' => 'https://api.dexscreener.com/token-profiles/latest/v1',
        'community-takeovers' => 'https://api.dexscreener.com/community-takeovers/latest/v1',
        'ads' => 'https://api.dexscreener.com/ads/latest/v1',
        'token-boosts' => 'https://api.dexscreener.com/token-boosts/latest/v1',
        'token-boosts-top' => 'https://api.dexscreener.com/token-boosts/top/v1'
    ];

    $tokensNuevos = [];
    $totalEncontrados = 0;

    foreach ($endpoints as $nombre => $url) {
        echo "[" . date('Y-m-d H:i:s') . "]   - Consultando: $nombre...\n";
        sleep(2);
        $response = @file_get_contents($url);

        if (!$response) {
            echo "[" . date('Y-m-d H:i:s') . "]   âœ— $nombre: Sin respuesta\n";
            continue;
        }

        $data = json_decode($response, true);
        if (!$data) {
            echo "[" . date('Y-m-d H:i:s') . "]   âœ— $nombre: Error al decodificar\n";
            continue;
        }

        $items = [];
        if (isset($data['pairs'])) $items = $data['pairs'];
        elseif (isset($data['profiles'])) $items = $data['profiles'];
        elseif (isset($data['takeovers'])) $items = $data['takeovers'];
        elseif (isset($data['ads'])) $items = $data['ads'];
        elseif (isset($data['tokenBoosts'])) $items = $data['tokenBoosts'];
        elseif (isset($data['tokenBoost'])) $items = $data['tokenBoost'];
        elseif (is_array($data)) $items = $data;

        $count = is_array($items) ? count($items) : 0;
        echo "[" . date('Y-m-d H:i:s') . "]   âœ“ $nombre: $count tokens\n";
        $totalEncontrados += $count;

        if (!is_array($items)) continue;

        foreach ($items as $item) {
            $chainId = $item['chainId'] ?? $item['chain_id'] ?? null;
            $tokenAddress = $item['tokenAddress'] ?? $item['token_address'] ?? null;

            if (!$chainId || !$tokenAddress) continue;

            echo "[" . date('Y-m-d H:i:s') . "]     â†’ Getting data for: $tokenAddress...\n";
            $tokenData = obtenerDatosToken($chainId, $tokenAddress);

            if (!$tokenData || !isset($tokenData[0])) continue;

            $pairData = $tokenData[0];
            $pair = $pairData['pairAddress'] ?? null;

            if (!$pair) continue;

            $stmt = $pdo->prepare("SELECT id FROM tokens_banned WHERE pair_address = ?");
            $stmt->execute([$pair]);
            if ($stmt->fetch()) continue;

            $stmt = $pdo->prepare("SELECT id FROM tokens WHERE pair_address = ? AND estado != 'exit'");
            $stmt->execute([$pair]);
            if ($stmt->fetch()) continue;

            $stmt = $pdo->prepare("SELECT id FROM historial_tokens WHERE pair_address = ?");
            $stmt->execute([$pair]);
            if ($stmt->fetch()) continue;

            if ($chainId !== 'solana') continue;

            $marketCap = floatval($pairData['marketCap'] ?? 0);
            $liquidez = floatval($pairData['liquidity']['usd'] ?? 0);

            if ($marketCap < 300000) continue;

            $liquidezMinima = max(50000, $marketCap * 0.015);
            if ($liquidez < $liquidezMinima) continue;

            $tokensNuevos[] = [
                'chain_id' => $chainId,
                'token_address' => $tokenAddress,
                'pair_address' => $pair,
                'nombre' => $pairData['baseToken']['name'] ?? null,
                'simbolo' => $pairData['baseToken']['symbol'] ?? null,
                'precio' => $pairData['priceUsd'] ?? 0,
                'market_cap' => $marketCap,
                'liquidez' => $liquidez,
                'cambio_1h' => $pairData['priceChange']['h1'] ?? 0,
                'cambio_6h' => $pairData['priceChange']['h6'] ?? 0,
                'cambio_24h' => $pairData['priceChange']['h24'] ?? 0,
                'tp' => $tpPorcentaje
            ];
        }
    }

    if (!empty($tokensNuevos)) {
        foreach ($tokensNuevos as $idx => $token) {
            $stmtCheck = $pdo->prepare("SELECT id FROM tokens WHERE pair_address = ?");
            $stmtCheck->execute([$token['pair_address']]);
            if ($stmtCheck->fetch()) {
                echo "[" . date('Y-m-d H:i:s') . "] Token already exists: " . ($token['nombre'] ?? $token['simbolo']) . "\n";
                continue;
            }
            
            try {
                $sql = "INSERT INTO tokens (
                    chain_id, token_address, pair_address,
                    nombre, simbolo, precio_actual, precio_entrada, precio_descubrimiento, precio_crash, precio_maximo,
                    last_check_price, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h,
                    estado, meta_tp, tp_alcanzado, sl_alcanzado, es_reentry,
                    reentry_count, checks_count, laps, timeout_count, fecha_registro,
                    primer_check, ultimo_check, creado_en, actualizado_en
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'nuevo', ?, 0, 0, 0, 0, 0, 0, 0, NOW(), NOW(), NOW(), NOW(), NOW())";
                $params = [
                    $token['chain_id'], $token['token_address'], $token['pair_address'],
                    $token['nombre'], $token['simbolo'], $token['precio'], $token['precio'], $token['precio'],
                    $token['precio'], $token['precio'], $token['market_cap'], $token['liquidez'],
                    $token['cambio_1h'], $token['cambio_6h'], $token['cambio_24h'], $token['tp']
                ];
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $nuevoId = $pdo->lastInsertId();
                
                logCoinData($token['nombre'] ?? $token['simbolo'], $token['pair_address'], $token['precio'], $token['market_cap'], $token['liquidez']);

                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'] ?? $token['simbolo'], $token['precio'],
                    $token['market_cap'], $token['liquidez'],
                    $token['cambio_1h'], $token['cambio_6h'], $token['cambio_24h'],
                    'nuevo', 'Nuevo token detectado'
                );

                echo "[" . date('Y-m-d H:i:s') . "] New token: " . ($token['nombre'] ?? $token['simbolo']) . "\n";
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Error INSERT token: " . $e->getMessage() . "\n";
                continue;
            }
        }
    }

    if (count($tokensNuevos) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] ðŸ’¾ Saving " . count($tokensNuevos) . " new tokens to DB...\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] âœ“ No new tokens\n";
    }
}

function obtenerDatosPair($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/latest/dex/pairs/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data['pair'] ?? null;
}

function obtenerDatosToken($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/tokens/v1/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data;
}

function fetchWithCurl($url, $timeout = 10) {
    $ch = @curl_init();
    if (!$ch) return null;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    return $result;
}

function monitoreoTokensActivos($pdo, $intervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
    // Process pending manual coins
    $stmtManual = $pdo->query("SELECT * FROM manual_coins WHERE estado = 'pendiente' LIMIT 5");
    foreach ($stmtManual->fetchAll() as $manual) {
        $searchUrl = "https://api.dexscreener.com/latest/dex/search?q=" . urlencode($manual['token_address']);
        $searchResp = fetchWithCurl($searchUrl);
        $searchData = $searchResp ? json_decode($searchResp, true) : null;
        $tokenData = ($searchData && isset($searchData['pairs'][0])) ? $searchData['pairs'] : null;
        if (!$tokenData || !isset($tokenData[0])) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['No se encontraron datos en DexScreener', $manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] âŒ Manual coin fetch failed: " . $manual['token_address'] . " (no DexScreener data)\n";
            continue;
        }
        $pair = $tokenData[0];
        $precioActual = (float)($pair['priceUsd'] ?? 0);
        if ($precioActual <= 0) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['Precio invÃ¡lido', $manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] âŒ Manual coin skipped: " . $manual['token_address'] . " (invalid price)\n";
            continue;
        }

        $dupe = $pdo->prepare("SELECT id FROM tokens WHERE token_address = ?");
        $dupe->execute([$manual['token_address']]);
        if ($dupe->fetch()) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['Token ya existe en el sistema', $manual['id']]);
            continue;
        }

        $pairAddress = $pair['pairAddress'] ?? '';
        $nombre = $pair['baseToken']['name'] ?? 'Manual';
        $simbolo = $pair['baseToken']['symbol'] ?? '';
        $marketCap = $pair['marketCap'] ?? 0;
        $liquidez = $pair['liquidity']['usd'] ?? 0;
        $change1h = $pair['priceChange']['h1'] ?? 0;
        $change6h = $pair['priceChange']['h6'] ?? 0;
        $change24h = $pair['priceChange']['h24'] ?? 0;
        $tp = getConfig('tp_porcentaje') ?: 24;

        $sql = "INSERT INTO tokens (
            chain_id, token_address, pair_address,
            nombre, simbolo, precio_actual, precio_entrada, precio_descubrimiento, precio_crash, precio_maximo,
            last_check_price, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h,
            estado, meta_tp, tp_alcanzado, sl_alcanzado, es_reentry,
            reentry_count, checks_count, laps, timeout_count, fecha_registro, fecha_ingreso,
            primer_check, ultimo_check, creado_en, actualizado_en
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'monitoreando', ?, 0, 0, 0, 0, 0, 0, 0, NOW(), NOW(), NOW(), NOW(), NOW(), NOW())";
        $params = [
            'solana', $manual['token_address'], $pairAddress,
            $nombre, $simbolo, $precioActual, $precioActual, $precioActual,
            $precioActual, $precioActual, $marketCap, $liquidez,
            $change1h, $change6h, $change24h, $tp
        ];
        $pdo->prepare($sql)->execute($params);
        $tokenId = $pdo->lastInsertId();

        $signalUsers = $pdo->query("SELECT id FROM api_keys")->fetchAll();
        foreach ($signalUsers as $su) {
            createSignal($pdo, $su['id'], $manual['token_address'], $precioActual);
        }

        registrarCoinRevisada(
            $pdo, $pairAddress, 'solana',
            $nombre, $precioActual,
            $marketCap, $liquidez,
            $change1h, $change6h, $change24h,
            'manual_entry', 'Token insertado manualmente'
        );

        $pdo->prepare("UPDATE manual_coins SET estado = 'procesado', procesado_en = NOW() WHERE id = ?")->execute([$manual['id']]);
        echo "[" . date('Y-m-d H:i:s') . "] âœ… Manual coin entered: " . $nombre . " ($simbolo)\n";
    }

    $stmtNuevos = $pdo->query("SELECT * FROM tokens WHERE estado = 'nuevo'");
    $tokensNuevos = $stmtNuevos->fetchAll();
    
    if (count($tokensNuevos) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] â³ Waiting for entry: " . count($tokensNuevos) . " tokens...\n";
    }
    
    foreach ($tokensNuevos as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;

        logCoinData($token['nombre'], $token['pair_address'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['txns'] ?? []);
        
        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        if ($token['fecha_ingreso']) {
            continue;
        }
        
        if (shouldBanName($pdo, $token['nombre'])) {
            try {
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Nombre baneado: 2x CAUTION');
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'ban', 'Nombre baneado (2x CAUTION)'
                );
                $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR BAN NOMBRE: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] â›” NOMBRE BANEADO: " . $token['nombre'] . " (2x CAUTION)\n";
            continue;
        }
        
        // Entry requires +2% for at least 6 consecutive cycles, or +4% immediate
        $reqEntrada = getEntryRequirement($pdo, $token['nombre']);
        if ($cambio >= 4) {
            // Immediate entry at +4%
            $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), checks_count = 0, precio_entrada = ?, precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ? WHERE id = ?")
                ->execute([$precioActual, $precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $token['id']]);

            actualizarTokenFree($pdo, $token['id']);

            $signalUsers = $pdo->query("SELECT id FROM api_keys")->fetchAll();
            foreach ($signalUsers as $su) {
                createSignal($pdo, $su['id'], $token['token_address'], $precioActual);
            }

            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'entrada', 'Token entrÃ³ a monitoreo (+' . round($cambio, 2) . '% entrada inmediata)'
            );

            echo "[" . date('Y-m-d H:i:s') . "] [ENTRY] " . $token['nombre'] . " (+" . round($cambio, 2) . "%, immediate entry)\n";
            continue;
        } elseif ($cambio >= $reqEntrada) {
            $newCount = ($token['checks_count'] ?? 0) + 1;
            $pdo->prepare("UPDATE tokens SET checks_count = ?, precio_actual = ?, last_check_price = ? WHERE id = ?")
                ->execute([$newCount, $precioActual, $precioActual, $token['id']]);

            if ($newCount >= 6) {
                $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), checks_count = 0, precio_entrada = ?, precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ? WHERE id = ?")
                    ->execute([$precioActual, $precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $token['id']]);

                actualizarTokenFree($pdo, $token['id']);

                $signalUsers = $pdo->query("SELECT id FROM api_keys")->fetchAll();
                foreach ($signalUsers as $su) {
                    createSignal($pdo, $su['id'], $token['token_address'], $precioActual);
                }

                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'entrada', 'Token entrÃ³ a monitoreo (+' . round($cambio, 2) . '% por ' . $newCount . ' ciclos)'
                );

                echo "[" . date('Y-m-d H:i:s') . "] [ENTRY] " . $token['nombre'] . " (+" . round($cambio, 2) . "%, $newCount consecutive cycles)\n";
                continue;
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] [WAIT] " . $token['nombre'] . " (+" . round($cambio, 2) . "%, cycle $newCount/6)\n";
            }
        } else {
            $pdo->prepare("UPDATE tokens SET checks_count = 0, precio_actual = ?, last_check_price = ? WHERE id = ?")
                ->execute([$precioActual, $precioActual, $token['id']]);
        }

        // 60-minute limit: tag as INESTABLE and ban
        $tiempoVivo = $token['primer_check'] ? (time() - strtotime($token['primer_check'])) / 60 : 0;
        if ($tiempoVivo > 60) {
            try {
                updateTagCounts($pdo, $token['nombre'], '[I]NESTABLE');
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'INESTABLE: Sin actividad');
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'timeout', 'Sin actividad'
                );
                $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR TIMEOUT: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] [INESTABLE] " . $token['nombre'] . " (Sin actividad)\n";
            continue;
        }
    }
    
$stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
$tokens = $stmt->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] DEBUG MONITOREO: Found " . count($tokens) . " monitoreo tokens\n";

if (count($tokens) > 0) {
    foreach ($tokens as $t) {
        echo "[" . date('Y-m-d H:i:s') . "]   - " . $t['nombre'] . " | price=" . $t['precio_actual'] . " | entrada=" . $t['precio_entrada'] . "\n";
    }
    echo "[" . date('Y-m-d H:i:s') . "] ðŸ”„ Monitoring " . count($tokens) . " tokens...\n";
}

foreach ($tokens as $token) {
    $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
    if (!$tokenData || !isset($tokenData[0])) {
        $sr = fetchWithCurl("https://api.dexscreener.com/latest/dex/search?q=" . urlencode($token['token_address']));
        $sd = $sr ? json_decode($sr, true) : null;
        if ($sd && isset($sd['pairs'][0])) $tokenData = $sd['pairs'];
    }
    if (!$tokenData || !isset($tokenData[0])) continue;

    $pairData = $tokenData[0];

    $precioActual = (float)($pairData['priceUsd'] ?? 0);
    if ($precioActual <= 0) continue;

    logCoinData($token['nombre'], $token['pair_address'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['txns'] ?? []);

    $precioEntrada = (float)$token['precio_entrada'];
    $precioCrash = $token['precio_crash'] ? (float)$token['precio_crash'] : null;
    $esReentry = (bool)$token['es_reentry'];
    $metaTP = $esReentry ? $tpReentry : $tpPorcentaje;

    $cambio = (($precioActual / $precioEntrada) - 1) * 100;

        if ($precioCrash) {
            $cambioCrash = (($precioActual / $precioCrash) - 1) * 100;
            if ($cambioCrash >= $reentryMin) {
                if ($token['reentry_count'] < 2) {
                    $nuevoReentry = $token['reentry_count'] + 1;
                    $pdo->prepare("UPDATE tokens SET es_reentry = 1, reentry_count = ?, meta_tp = ?, precio_crash = NULL WHERE id = ?")
                        ->execute([$nuevoReentry, $tpReentry, $token['id']]);
                    echo "[" . date('Y-m-d H:i:s') . "] RE-ENTRY: " . $token['nombre'] . " (Count: $nuevoReentry)\n";
                }
            }
        }

        echo "[" . date('Y-m-d H:i:s') . "] DEBUG: " . $token['nombre'] . " | price=$precioActual entrada=$precioEntrada\n";
        
        if ($precioEntrada > 0) {
            $precioMaximo = (float)$token['precio_maximo'];
            if ($precioActual > $precioMaximo) {
                $precioMaximo = $precioActual;
                $pdo->prepare("UPDATE tokens SET precio_maximo = ? WHERE id = ?")
                    ->execute([$precioMaximo, $token['id']]);
            }
            
            $cambioDesdePeak = $precioMaximo > 0 ? (($precioActual / $precioMaximo) - 1) * 100 : 0;
            $cambioDesdeEntrada = $cambio;

            $minutosDesdeEntrada = $token['fecha_ingreso'] ? (time() - strtotime($token['fecha_ingreso'])) / 60 : 999;
            $tpRapido = $minutosDesdeEntrada < 5;
            $extraTP = getExtraTP($pdo, $token['nombre']);
            $tpFinal = $tpRapido ? 45 : (30 + $extraTP);

            // TP condition
            if ($cambioDesdeEntrada >= $tpFinal) {
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        'tp', 'Take Profit: +' . round($cambioDesdeEntrada, 2) . '%'
                    );
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] âœ… TP {$tpFinal}%: " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "%)" . ($extraTP > 0 ? " (+$extraTP% extra)" : "") . "\n";
                continue;
            }

            // Save TP / SL from peak
            $slDesdePeak = getSLForToken($pdo, $token['nombre'], $cambioDesdeEntrada);
            if ($tpRapido && $cambioDesdeEntrada >= 30) {
                // Fast TP mode: save TP if drops below +30%
                if ($cambioDesdeEntrada < 30) {
                    try {
                        registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'save_tp', 'Save TP rÃ¡pido: bajo +30%');
                        marcarExit($pdo, $token['id'], $precioActual, 'save_tp', $cambio);
                    } catch (Exception $e) { echo "[" . date('Y-m-d H:i:s') . "] ERROR SAVE TP: " . $e->getMessage() . "\n"; }
                    echo "[" . date('Y-m-d H:i:s') . "] [SAVE TP] " . $token['nombre'] . " (bajo +30% en modo rÃ¡pido)\n";
                    continue;
                }
            } elseif ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= $slDesdePeak) {
                $razonSalida = ($cambio > 0) ? 'save_tp' : 'sl';
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $razonSalida, 'Save TP / Stop Loss');
                    marcarExit($pdo, $token['id'], $precioActual, $razonSalida, $cambio);
                } catch (Exception $e) { echo "[" . date('Y-m-d H:i:s') . "] ERROR SL: " . $e->getMessage() . "\n"; }
                echo "[" . date('Y-m-d H:i:s') . "] [SL] " . $token['nombre'] . " (drop " . round($cambioDesdePeak, 2) . "% from peak, SL: $slDesdePeak%)\n";
                continue;
            }
        }

        // -9% from entry price: INESTABLE + ban
        if ($cambio <= -9) {
            try {
                updateTagCounts($pdo, $token['nombre'], '[I]NESTABLE');
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Inestable');
                registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'sl', '-9% desde entrada');
                marcarExit($pdo, $token['id'], $precioActual, 'inestable', $cambio);
            } catch (Exception $e) { echo "[" . date('Y-m-d H:i:s') . "] ERROR -9%: " . $e->getMessage() . "\n"; }
            echo "[" . date('Y-m-d H:i:s') . "] [INESTABLE] " . $token['nombre'] . " (" . round($cambio, 2) . "% desde entrada)\n";
            continue;
        }

        $tiempoMinutos = (time() - strtotime($token['primer_check'])) / 60;
        if ($tiempoMinutos > 60) {
            try {
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'exit', 'LÃ­mite de 60 minutos de monitoreo alcanzado'
                );
                marcarExit($pdo, $token['id'], $precioActual, 'expirado', round($cambio, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXPIRADO: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] â° EXPIRADO: " . $token['nombre'] . " ({$tiempoMinutos} min, profit: " . round($cambio, 2) . "%)\n";
            continue;
        }

        try {
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'actualizado', 'Check #' . ($token['checks_count'] + 1)
            );

            $pdo->prepare("UPDATE tokens SET precio_actual = ?, precio_maximo = ?, market_cap = ?, liquidez = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, checks_count = checks_count + 1, ultimo_check = NOW() WHERE id = ?")
                ->execute([
                    $precioActual,
                    $precioMaximo,
                    $pairData['marketCap'] ?? 0,
                    $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0,
                    $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    $token['id']
                ]);
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR ACTUALIZAR: " . $e->getMessage() . "\n";
        }
    }

}

function marcarExit($pdo, $tokenId, $precioSalida, $razon, $profit) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE id = $tokenId");
    $token = $stmt->fetch();

    $fechaIngreso = $token['fecha_ingreso'] ?? null;
    
    // Si nunca entrÃ³ (sin fecha_ingreso), no agregar al historial ni contar en stats
    if (!$fechaIngreso) {
        $pdo->exec("DELETE FROM tokens WHERE id = $tokenId");
        echo "[" . date('Y-m-d H:i:s') . "] ðŸ—‘ï¸ Eliminado: " . $token['nombre'] . " (nunca entrÃ³, razÃ³n: $razon)\n";
        return;
    }
    
    // Determinar el tag segÃºn la razÃ³n de salida
    $tag = null;
    switch ($razon) {
        case 'tp':
            $tag = '[S]TRONG';
            break;
        case 'save_tp':
            $tag = '[OK]OKAY';
            break;
        case 'sl':
            $tag = '[?]CHECKING';
            break;
        case 'ban':
        case 'inestable':
            $tag = '[I]NESTABLE';
            break;
        case 'expirado':
        default:
            $tag = ($profit > 0) ? '[OK]OKAY' : '[?]CHECKING';
            break;
    }
    
    // Actualizar contadores de tags por nombre
    if ($tag) {
        updateTagCounts($pdo, $token['nombre'], $tag);
    }
    
    // Calcular duraciÃ³n en minutos usando fechas SQL (TIMESTAMPDIFF)
    $duracionMinutos = 0;
    $stmtDur = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, '$fechaIngreso', NOW()) as minutos");
    $duracionMinutos = $stmtDur->fetch()['minutos'] ?? 0;

    // Actualizar token con fecha_salida y tag
    $pdo->prepare("UPDATE tokens SET fecha_salida = NOW(), estado = 'exit', tag = ? WHERE id = ?")->execute([$tag, $tokenId]);
    $stmtFecha = $pdo->query("SELECT fecha_salida FROM tokens WHERE id = $tokenId");
    $fechaSalidaDb = $stmtFecha->fetch()['fecha_salida'];

    // Guardar nombre sin tag, el tag va en su propia columna
    $nombreConTag = $token['nombre'];

    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            es_reentry, fecha_entrada, fecha_salida
        ) VALUES (:id, :chain, :token_addr, :pair_addr, :nombre, :simbolo, :entrada, :descubrimiento, :salida, :profit, :duracion, :razon, :tag, :es_reentry, :fecha_entrada, :fecha_salida)
    ")->execute([
        ':id' => $tokenId,
        ':chain' => $token['chain_id'],
        ':token_addr' => $token['token_address'],
        ':pair_addr' => $token['pair_address'],
        ':nombre' => $token['nombre'],
        ':simbolo' => $token['simbolo'],
        ':entrada' => $token['precio_entrada'],
        ':descubrimiento' => $token['precio_descubrimiento'] ?? $token['precio_entrada'],
        ':salida' => $precioSalida,
        ':profit' => $profit,
        ':duracion' => $duracionMinutos,
        ':razon' => $razon,
        ':tag' => $tag,
        ':es_reentry' => $token['es_reentry'],
        ':fecha_entrada' => $token['fecha_ingreso'] ?? $token['fecha_registro'],
        ':fecha_salida' => $fechaSalidaDb
    ]);
}

function actualizarTokenFree($pdo, $nuevoTokenId) {
    $pdo->query("UPDATE tokens_free SET activo = 0 WHERE activo = 1");

    $horas = (int)getConfig('free_cambio_horas') ?: 6;
    $mostrarHasta = date('Y-m-d H:i:s', time() + ($horas * 3600));

    try {
        $pdo->prepare("INSERT INTO tokens_free (id_token, mostrar_desde, mostrar_hasta, activo) VALUES (?, NOW(), ?, 1)")
            ->execute([$nuevoTokenId, $mostrarHasta]);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] tokens_free error: " . $e->getMessage() . "\n";
    }
}

function logCoinData($nombre, $pairAddress, $precio, $marketCap, $liquidez, $txns = []) {
    $dir = __DIR__ . '/coinslog';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $nombreSafe = preg_replace('/[^A-Z0-9]/', '', strtoupper(substr($nombre, 0, 15)));
    if ($nombreSafe === '') $nombreSafe = 'UNKNOWN';
    $addrShort = substr($pairAddress, 0, 20);
    $filename = $dir . '/' . $nombreSafe . '_' . $addrShort . '.log';

    $b1h = $txns['h1']['buys'] ?? 0;
    $s1h = $txns['h1']['sells'] ?? 0;
    $b6h = $txns['h6']['buys'] ?? 0;
    $s6h = $txns['h6']['sells'] ?? 0;

    $line = sprintf("[%s] PRICE=%.8f | MC=%d | LIQ=%d | B1h=%d S1h=%d | B6h=%d S6h=%d\n",
        date('Y-m-d H:i:s'), $precio, $marketCap, $liquidez, $b1h, $s1h, $b6h, $s6h);

    @file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
}

function registrarCoinRevisada($pdo, $pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez, $cambio1h, $cambio6h, $cambio24h, $accion, $razon = null) {
    try {
        $pdo->prepare("
            INSERT INTO coins_revisadas (
                pair_address, chain_id, nombre, precio, market_cap, liquidez,
                cambio_1h, cambio_6h, cambio_24h, accion, razon, revisado_en
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez,
            $cambio1h, $cambio6h, $cambio24h, $accion, $razon
        ]);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] coins_revisadas error: " . $e->getMessage() . "\n";
    }
}

function banearToken($pdo, $tokenAddress, $pairAddress, $chainId, $razon) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM tokens_banned WHERE pair_address = ? AND chain_id = ?");
        $stmt->execute([$pairAddress, $chainId]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO tokens_banned (token_address, pair_address, chain_id, razon, banneado_en) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$tokenAddress, $pairAddress, $chainId, $razon]);
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] banearToken error: " . $e->getMessage() . "\n";
    }
}

function getTagCounts($pdo, $nombre) {
    $nombreNorm = strtoupper(trim($nombre));
    $stmt = $pdo->prepare("SELECT * FROM coins_tags WHERE nombre_normalizado = ?");
    $stmt->execute([$nombreNorm]);
    return $stmt->fetch();
}

function updateTagCounts($pdo, $nombre, $tag) {
    $nombreNorm = strtoupper(trim($nombre));
    
    $stmt = $pdo->prepare("SELECT * FROM coins_tags WHERE nombre_normalizado = ?");
    $stmt->execute([$nombreNorm]);
    $existing = $stmt->fetch();
    
    $strong = $existing['strong_count'] ?? 0;
    $checking = $existing['checking_count'] ?? 0;
    $okay = $existing['okay_count'] ?? 0;
    $caution = $existing['caution_count'] ?? 0;
    $inestable = $existing['inestable_count'] ?? 0;
    
    switch ($tag) {
        case '[S]TRONG':
            $strong++;
            break;
        case '[?]CHECKING':
            if ($okay > 0) {
                $okay--; // cancel with existing okay
            } elseif ($checking >= 1) {
                // 2nd checking -> 1 caution
                $checking--;
                $caution++;
            } else {
                $checking++;
            }
            break;
        case '[OK]OKAY':
            if ($checking > 0) {
                $checking--; // cancel with existing checking
            } else {
                $okay++;
            }
            break;
        case '[C]AUTION':
            $caution++;
            break;
        case '[I]NESTABLE':
            $inestable++;
            break;
    }
    
    if ($existing) {
        $pdo->prepare("UPDATE coins_tags SET strong_count = ?, checking_count = ?, okay_count = ?, caution_count = ?, inestable_count = ?, ultimo_tag = ? WHERE nombre_normalizado = ?")
            ->execute([$strong, $checking, $okay, $caution, $inestable, $tag, $nombreNorm]);
    } else {
        $pdo->prepare("INSERT INTO coins_tags (nombre_normalizado, strong_count, checking_count, okay_count, caution_count, inestable_count, ultimo_tag) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$nombreNorm, $strong, $checking, $okay, $caution, $inestable, $tag]);
    }
    
    return ['strong' => $strong, 'checking' => $checking, 'okay' => $okay, 'caution' => $caution, 'inestable' => $inestable];
}

function getEntryRequirement($pdo, $nombre) {
    return 2;
}

function getSLForToken($pdo, $nombre, $cambioDesdeEntrada) {
    if ($cambioDesdeEntrada >= 15) return -4;
    return -8;
}

function shouldBanName($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) return false;
    
    return ($counts['caution_count'] ?? 0) >= 2 || ($counts['inestable_count'] ?? 0) > 0;
}

function getExtraTP($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) return 0;
    
    $strong = $counts['strong_count'] ?? 0;
    return min($strong * 5, 50);
}
