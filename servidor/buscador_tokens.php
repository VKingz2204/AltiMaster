<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../api/config.php';

$tpPorcentaje = (float)getConfig('tp_porcentaje') ?: 40;

echo "[" . date('Y-m-d H:i:s') . "] 🔍 Background search started (PID: " . getmypid() . ")\n";

buscarNuevosTokens($pdo, $tpPorcentaje);

echo "[" . date('Y-m-d H:i:s') . "] Background search finished\n";
file_put_contents(__DIR__ . '/.search_done', date('Y-m-d H:i:s'));

function buscarNuevosTokens($pdo, $tpPorcentaje) {
    echo "[" . date('Y-m-d H:i:s') . "] 🔍 Querying DexScreener APIs...\n";

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
            echo "[" . date('Y-m-d H:i:s') . "]   ✗ $nombre: Sin respuesta\n";
            continue;
        }

        $data = json_decode($response, true);
        if (!$data) {
            echo "[" . date('Y-m-d H:i:s') . "]   ✗ $nombre: Error al decodificar\n";
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
        echo "[" . date('Y-m-d H:i:s') . "]   ✓ $nombre: $count tokens\n";
        $totalEncontrados += $count;

        if (!is_array($items)) continue;

        foreach ($items as $item) {
            $chainId = $item['chainId'] ?? $item['chain_id'] ?? null;
            $tokenAddress = $item['tokenAddress'] ?? $item['token_address'] ?? null;

            if (!$chainId || !$tokenAddress) continue;

            echo "[" . date('Y-m-d H:i:s') . "]     → Getting data for: $tokenAddress...\n";
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

            $pairCreatedAt = $pairData['pairCreatedAt'] ?? 0;
            if ($pairCreatedAt > 0 && (time() * 1000 - $pairCreatedAt) > 6 * 3600 * 1000) {
                echo "[" . date('Y-m-d H:i:s') . "]     -> Skipped (older than 6h): " . ($pairData['baseToken']['name'] ?? $pairData['baseToken']['symbol'] ?? $tokenAddress) . "\n";
                continue;
            }

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
                
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'] ?? $token['simbolo'], $token['precio'],
                    $token['market_cap'], $token['liquidez'],
                    $token['cambio_1h'], $token['cambio_6h'], $token['cambio_24h'],
                    'nuevo', 'Nuevo token detectado (esperando +1.5%)'
                );
                
                echo "[" . date('Y-m-d H:i:s') . "] 🆕 New token: " . ($token['nombre'] ?? $token['simbolo']) . " (waiting +1.5%)\n";
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Error INSERT token: " . $e->getMessage() . "\n";
                continue;
            }
        }
    }

    if (count($tokensNuevos) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] 💾 Saving " . count($tokensNuevos) . " new tokens to DB...\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ✓ No new tokens\n";
    }
}

function obtenerDatosToken($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/tokens/v1/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data;
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