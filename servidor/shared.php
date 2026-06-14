<?php
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/engine.php';

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
    return json_decode($response, true);
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

function procesarManualCoins($pdo) {
    $stmtManual = $pdo->query("SELECT * FROM manual_coins WHERE estado = 'pendiente' LIMIT 5");
    foreach ($stmtManual->fetchAll() as $manual) {
        $searchUrl = "https://api.dexscreener.com/latest/dex/search?q=" . urlencode($manual['token_address']);
        $searchResp = fetchWithCurl($searchUrl);
        $searchData = $searchResp ? json_decode($searchResp, true) : null;
        $tokenData = ($searchData && isset($searchData['pairs'][0])) ? $searchData['pairs'] : null;
        if (!$tokenData || !isset($tokenData[0])) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['No se encontraron datos en DexScreener', $manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Manual coin fetch failed: " . $manual['token_address'] . " (no DexScreener data)\n";
            continue;
        }
        $pair = $tokenData[0];
        $precioActual = (float)($pair['priceUsd'] ?? 0);
        if ($precioActual <= 0) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['Precio invalido', $manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Manual coin skipped: " . $manual['token_address'] . " (invalid price)\n";
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

        // Check by pair_address (the unique constraint column)
        $existing = $pdo->prepare("SELECT id, estado FROM tokens WHERE pair_address = ?");
        $existing->execute([$pairAddress]);
        if ($row = $existing->fetch()) {
            if ($row['estado'] === 'monitoreando') {
                $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                    ->execute(['Token ya en monitoreo', $manual['id']]);
                echo "[" . date('Y-m-d H:i:s') . "] Manual coin skipped (already monitoring): " . $manual['token_address'] . "\n";
                continue;
            }
            $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$row['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Removed existing token for re-study: " . $manual['token_address'] . "\n";
        }

        // Clear all restrictions for manual entry (bypass bans, cooldowns, traded_addresses)
        $pdo->prepare("DELETE FROM token_cooldowns WHERE pair_address = ?")->execute([$pairAddress]);
        $pdo->prepare("DELETE FROM traded_addresses WHERE pair_address = ?")->execute([$pairAddress]);
        $pdo->prepare("DELETE FROM tokens_banned WHERE pair_address = ?")->execute([$pairAddress]);
        echo "[" . date('Y-m-d H:i:s') . "] Manual coin bypassing restrictions: " . $manual['token_address'] . "\n";

        $confianza = calcularConfianza($pdo, $nombre, $pair);
        $saldo = getWalletSaldo($pdo);
        $entryCost = calcularEntryCost($saldo, $confianza);

        try {
            $sql = "INSERT INTO tokens (
                chain_id, token_address, pair_address,
                nombre, simbolo, precio_actual, precio_entrada, precio_descubrimiento, precio_maximo,
                last_check_price, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h,
                estado, meta_tp, tp_alcanzado, sl_alcanzado,
                checks_count, laps, timeout_count, fecha_registro, fecha_ingreso,
                primer_check, ultimo_check, creado_en, actualizado_en
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'monitoreando', ?, 0, 0, 0, 0, 0, NOW(), NOW(), NOW(), NOW(), NOW(), NOW())";
            $params = [
                'solana', $manual['token_address'], $pairAddress,
                $nombre, $simbolo, $precioActual, $precioActual, $precioActual,
                $precioActual, $precioActual, $marketCap, $liquidez,
                $change1h, $change6h, $change24h, $tp
            ];
            $pdo->prepare($sql)->execute($params);
            $tokenId = $pdo->lastInsertId();

            // Set monto_invertido BEFORE wallet debit ensures exit can credit wallet
            $pdo->prepare("UPDATE tokens SET monto_invertido = ?, confianza = ? WHERE id = ?")
                ->execute([$entryCost, $confianza, $tokenId]);

            // Only deduct wallet after monto_invertido is set
            updateWallet($pdo, $entryCost, 'salida', $nombre, $manual['token_address'], $confianza, 'Entrada manual coin');

            $signalUsers = $pdo->query("SELECT id FROM api_keys")->fetchAll();
            foreach ($signalUsers as $su) {
                createSignal($pdo, $su['id'], $manual['token_address'], $precioActual);
            }

            registrarCoinRevisada(
                $pdo, $pairAddress, 'solana',
                $nombre, $precioActual,
                $marketCap, $liquidez,
                $change1h, $change6h, $change24h,
                'manual_entry', 'Token insertado manualmente (Entry: $' . $entryCost . ', Confianza: ' . $confianza . '%)'
            );

            $pdo->prepare("UPDATE manual_coins SET estado = 'procesado', procesado_en = NOW() WHERE id = ?")->execute([$manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Manual coin entered: " . $nombre . " ($simbolo) - Entry: \$" . $entryCost . ", Confianza: " . $confianza . "%\n";
        } catch (Exception $e) {
            $pdo->prepare("UPDATE manual_coins SET estado = 'error', mensaje = ?, procesado_en = NOW() WHERE id = ?")
                ->execute(['Error al insertar: ' . $e->getMessage(), $manual['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Manual coin insert failed: " . $manual['token_address'] . " - " . $e->getMessage() . "\n";
        }
    }
}

function enterToken($pdo, $token, $pairData, $precioActual, $cambio, $razon) {
    $confianza = calcularConfianza($pdo, $token['nombre'], $pairData);
    $saldo = getWalletSaldo($pdo);
    $entryCost = calcularEntryCost($saldo, $confianza);

    try {
        $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), checks_count = 0, precio_entrada = ?, precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ?, monto_invertido = ?, confianza = ? WHERE id = ?")
            ->execute([$precioActual, $precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $entryCost, $confianza, $token['id']]);

        updateWallet($pdo, $entryCost, 'salida', $token['nombre'], $token['token_address'], $confianza, $razon);

        actualizarTokenFree($pdo, $token['id']);

        $signalUsers = $pdo->query("SELECT id FROM api_keys")->fetchAll();
        foreach ($signalUsers as $su) {
            createSignal($pdo, $su['id'], $token['token_address'], $precioActual);
        }

        registrarCoinRevisada(
            $pdo, $token['pair_address'], $token['chain_id'],
            $token['nombre'], $precioActual,
            $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
            $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
            $pairData['priceChange']['h24'] ?? 0,
            'entrada', $razon . ', Entry: $' . $entryCost . ', Confianza: ' . $confianza . '%'
        );

        echo "[" . date('Y-m-d H:i:s') . "] [ENTRY] " . $token['nombre'] . " (+" . round($cambio, 2) . "%, " . $razon . ", \$" . $entryCost . ", confianza: " . $confianza . "%)\n";
    } catch (Exception $e) {
        // Refund wallet if anything failed (wallet deduction + token update both inside try)
        try {
            $refund = updateWallet($pdo, $entryCost, 'entrada', $token['nombre'], $token['token_address'], $confianza, 'Reembolso: fallo al actualizar token');
        } catch (Exception $refundErr) {
            // Silently handle refund failure
        }
        echo "[" . date('Y-m-d H:i:s') . "] [ERROR] " . $token['nombre'] . " entry failed (wallet refunded \${$entryCost}): " . $e->getMessage() . "\n";
    }
}

function countExitsByReason($pdo, $pairAddress, $reasons) {
    $placeholders = implode(',', array_fill(0, count($reasons), '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM historial_tokens WHERE pair_address = ? AND razon_salida IN ($placeholders) AND DATE(fecha_salida) = CURDATE()");
    $stmt->execute(array_merge([$pairAddress], $reasons));
    return (int)$stmt->fetch()['cnt'];
}

function procesarNuevosTokens($pdo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
    $deleted = $pdo->exec("DELETE t FROM tokens t LEFT JOIN token_cooldowns tc ON tc.pair_address = t.pair_address AND tc.cooldown_until > NOW() LEFT JOIN traded_addresses ta ON ta.pair_address = t.pair_address WHERE t.estado = 'nuevo' AND t.fecha_ingreso IS NULL AND (tc.id IS NOT NULL OR ta.id IS NOT NULL)");
    if ($deleted > 0) echo "[" . date('Y-m-d H:i:s') . "] [CLEANUP] Removed {$deleted} blocked token(s)\n";
    $stmtNuevos = $pdo->query("SELECT * FROM tokens WHERE estado = 'nuevo'");
    $tokensNuevos = $stmtNuevos->fetchAll();
    
    if (count($tokensNuevos) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Waiting for entry: " . count($tokensNuevos) . " tokens...\n";
    }
    
    foreach ($tokensNuevos as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;
        
        if ($token['fecha_ingreso']) continue;

        // Daily re-entry limits check
        $slExits = countExitsByReason($pdo, $token['pair_address'], ['sl']);
        $tpExits = countExitsByReason($pdo, $token['pair_address'], ['tp', 'save_tp']);
        if ($slExits >= 2 || $tpExits >= 4) {
            echo "[" . date('Y-m-d H:i:s') . "] [LIMIT] " . $token['nombre'] . " daily limit hit (SL:{$slExits}/2, TP:{$tpExits}/4), dropping\n";
            $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$token['id']]);
            continue;
        }

        // Check minimum requirements (MC & liquidity)
        $mcActual = (float)($pairData['marketCap'] ?? 0);
        $liqActual = (float)($pairData['liquidity']['usd'] ?? 0);
        if ($mcActual < 200000 || $liqActual < 30000) {
            $razon = $mcActual < 200000 ? 'MC' : 'Liquidity';
            echo "[" . date('Y-m-d H:i:s') . "] [MC-LOW] " . $token['nombre'] . " {$razon} dropped below minimum, dropping\n";
            $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$token['id']]);
            continue;
        }

        // Entry via +5.1% from lowest discovery price
        $precioDesc = (float)$token['precio_descubrimiento'];
        if ($precioActual < $precioDesc) {
            $precioDesc = $precioActual;
            $pdo->prepare("UPDATE tokens SET precio_descubrimiento = ? WHERE id = ?")
                ->execute([$precioDesc, $token['id']]);
        }
        $cambio = (($precioActual / $precioDesc) - 1) * 100;

        if ($cambio >= $reentryMin) {
            enterToken($pdo, $token, $pairData, $precioActual, $cambio, 'entry +' . round($cambio, 2) . '%');
            continue;
        }

        // Still waiting: update current price
        $pdo->prepare("UPDATE tokens SET precio_actual = ?, last_check_price = ? WHERE id = ?")
            ->execute([$precioActual, $precioActual, $token['id']]);
    }
}

function reentryAfterExit($pdo, $oldToken, $reentryMin) {
    $stmt = $pdo->prepare("SELECT * FROM tokens WHERE id = ?");
    $stmt->execute([$oldToken['id']]);
    $token = $stmt->fetch();
    if (!$token || $token['estado'] !== 'nuevo') return;

    $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
    if (!$tokenData || !isset($tokenData[0])) return;
    $pairData = $tokenData[0];
    $precioActual = (float)($pairData['priceUsd'] ?? 0);
    if ($precioActual <= 0) return;

    $precioDesc = (float)$token['precio_descubrimiento'];
    if ($precioActual < $precioDesc) {
        $precioDesc = $precioActual;
        $pdo->prepare("UPDATE tokens SET precio_descubrimiento = ? WHERE id = ?")
            ->execute([$precioDesc, $token['id']]);
    }
    $cambio = (($precioActual / $precioDesc) - 1) * 100;

    if ($cambio >= $reentryMin) {
        enterToken($pdo, $token, $pairData, $precioActual, $cambio, 'quick re-entry +' . round($cambio, 2) . '%');
        echo "[" . date('Y-m-d H:i:s') . "] [RE-ENTRY] " . $token['nombre'] . " (immediate re-entry at +" . round($cambio, 2) . "%)\n";
    }
}

function monitorearActivos($pdo, $monitoreoIntervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();

    if (count($tokens) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Monitoring " . count($tokens) . " tokens...\n";
    }

    static $lastWrittenPrice = [];

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

        $pumpResult = detectarPump($pdo, $token['pair_address'], 10, 5);
        if ($pumpResult && $pumpResult['is_pump']) {
            $pumpType = $pumpResult['is_pump_and_dump'] ? 'PumpDump' : 'Pump';
            echo "[" . date('Y-m-d H:i:s') . "] [$pumpType] " . $token['nombre'] . " (+" . $pumpResult['change_pct'] . "% in 5min)\n";
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'pump_detected', $pumpType . ': +' . $pumpResult['change_pct'] . '% in 5min'
            );
        }

        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        $precioMaximo = (float)$token['precio_maximo'];

        if ($precioEntrada > 0) {
            if ($precioActual > $precioMaximo) {
                $precioMaximo = $precioActual;
                $pdo->prepare("UPDATE tokens SET precio_maximo = ? WHERE id = ?")
                    ->execute([$precioMaximo, $token['id']]);
            }

            $cambioDesdePeak = $precioMaximo > 0 ? (($precioActual / $precioMaximo) - 1) * 100 : 0;
            $cambioDesdeEntrada = $cambio;
            $minutosDesdeEntrada = $token['fecha_ingreso'] ? (time() - strtotime($token['fecha_ingreso'])) / 60 : 999;

            // 1. Quick exit: +tpReentry% in < 3 minutes → TP + immediate re-entry
            if ($minutosDesdeEntrada < 3 && $cambioDesdeEntrada >= $tpReentry) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'tp', 'Quick TP: +' . round($cambioDesdeEntrada, 2) . '% in ' . round($minutosDesdeEntrada, 1) . 'min');
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR QUICK TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [QUICK TP] " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "% in " . round($minutosDesdeEntrada, 1) . "min)\n";
                reentryAfterExit($pdo, $token, $reentryMin);
                continue;
            }

            // 2. TP: +tpPorcentaje%
            if ($cambioDesdeEntrada >= $tpPorcentaje) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'tp', 'Take Profit: +' . round($cambioDesdeEntrada, 2) . '%');
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [TP " . $tpPorcentaje . "%] " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "%)\n";
                continue;
            }

            // 3. Hard SL: -slPorcentaje% from entry
            if ($cambioDesdeEntrada <= -$slPorcentaje) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'sl', 'Stop Loss: ' . round($cambioDesdeEntrada, 2) . '%');
                    marcarExit($pdo, $token['id'], $precioActual, 'sl', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR SL: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [SL -" . $slPorcentaje . "%] " . $token['nombre'] . " (" . round($cambioDesdeEntrada, 2) . "%)\n";
                continue;
            }

            // 4. Save TP: -slPorcentaje% from peak (only if overall profit > 0)
            if ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= -$slPorcentaje && $cambio > 0) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'save_tp', 'Save TP: -' . round(abs($cambioDesdePeak), 2) . '% from peak');
                    marcarExit($pdo, $token['id'], $precioActual, 'save_tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR SAVE TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [SAVE TP] " . $token['nombre'] . " (-" . round(abs($cambioDesdePeak), 2) . "% from peak)\n";
                continue;
            }

            // 5. Time-based: > 15 min → exit if in profit; else wait for SL
            if ($minutosDesdeEntrada > 15 && $cambio > 0) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'tp', 'Time exit: +' . round($cambio, 2) . '% after ' . round($minutosDesdeEntrada, 1) . 'min');
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TIME EXIT: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [TIME EXIT] " . $token['nombre'] . " (" . round($minutosDesdeEntrada, 1) . "min, +" . round($cambio, 2) . "%)\n";
                continue;
            }
        }

        try {
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'actualizado', 'Check #' . ($token['checks_count'] + 1)
            );

            $pdo->prepare("UPDATE tokens SET precio_actual = ?, precio_maximo = ?, market_cap = ?, liquidez = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, checks_count = checks_count + 1, ultimo_check = NOW() WHERE id = ?")
                ->execute([
                    $precioActual, $precioMaximo,
                    $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0, $token['id']
                ]);

            $coinslogDir = __DIR__ . '/coinslog';
            if (!is_dir($coinslogDir)) @mkdir($coinslogDir, 0777, true);
            $logFile = $coinslogDir . '/' . $token['token_address'] . '.txt';
            $lastPrice = $lastWrittenPrice[$token['token_address']] ?? null;
            if ($lastPrice === null && file_exists($logFile)) {
                $handle = @fopen($logFile, 'r');
                if ($handle) {
                    fseek($handle, -200, SEEK_END);
                    $last = '';
                    while (!feof($handle)) { $last = fgets($handle); }
                    fclose($handle);
                    if (preg_match('/\[Price=([^\]]+)\]/', $last, $m)) $lastPrice = $m[1];
                }
            }
            if ($lastPrice === null || $lastPrice != $precioActual) {
                $lastWrittenPrice[$token['token_address']] = $precioActual;
                $logLine = "[" . date('m/d/Y H:i:s') . "][Price=" . $precioActual . "][MarketCap=" . ($pairData['marketCap'] ?? 0) . "][Liquidity=" . ($pairData['liquidity']['usd'] ?? 0) . "]\n";
                @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
            }
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR ACTUALIZAR: " . $e->getMessage() . "\n";
        }
    }
}

function marcarExit($pdo, $tokenId, $precioSalida, $razon, $profit) {
    $stmt = $pdo->prepare("SELECT * FROM tokens WHERE id = ?");
    $stmt->execute([$tokenId]);
    $token = $stmt->fetch();

    $fechaIngreso = $token['fecha_ingreso'] ?? null;
    if (!$fechaIngreso) {
        if ($razon === 'inestable') {
            $tag = '[I]NESTABLE';
            updateTagCounts($pdo, $token['nombre'], $tag);
            $cambioDesc = $token['precio_descubrimiento'] > 0 ? (($precioSalida / $token['precio_descubrimiento']) - 1) * 100 : $profit;
            $duracionMin = $token['primer_check'] ? round((time() - strtotime($token['primer_check'])) / 60) : 60;
            try {
                $pdo->prepare("INSERT INTO historial_tokens (
                    id_token_original, chain_id, token_address, pair_address,
                    nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
                    profit_porcentaje, duracion_minutos, razon_salida, tag,
                    fecha_entrada, fecha_salida, monto_invertido, profit_dolares
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NULL)")
                    ->execute([
                        $tokenId, $token['chain_id'], $token['token_address'], $token['pair_address'],
                        $token['nombre'], $token['simbolo'], $token['precio_entrada'] ?? 0,
                        $token['precio_descubrimiento'] ?? 0, $precioSalida,
                        round($cambioDesc, 2), $duracionMin, 'inestable', $tag
                    ]);
                $pdo->prepare("INSERT INTO token_cooldowns (pair_address, cooldown_until, profit_dolares) VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 0) ON DUPLICATE KEY UPDATE cooldown_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), profit_dolares = 0")
                    ->execute([$token['pair_address']]);
                $pdo->prepare("INSERT IGNORE INTO traded_addresses (pair_address, token_address, chain_id, nombre, razon_salida) VALUES (?, ?, ?, ?, 'inestable')")
                    ->execute([$token['pair_address'], $token['token_address'], $token['chain_id'], $token['nombre']]);
                echo "[" . date('Y-m-d H:i:s') . "] [INESTABLE] " . $token['nombre'] . " recorded (historial + cooldown + traded)\n";
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR INESTABLE: " . $e->getMessage() . "\n";
            }
        }
        $pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
        echo "[" . date('Y-m-d H:i:s') . "] Eliminado: " . $token['nombre'] . " (nunca entro, razon: $razon)\n";
        return;
    }

    $tag = null;
    switch ($razon) {
        case 'tp': case 'save_tp': $tag = '[OK]OKAY'; break;
        case 'inestable': $tag = '[I]NESTABLE'; break;
        case 'sl': $tag = '[?]CHECKING'; break;
        case 'expirado': default: $tag = ($profit > 0) ? '[OK]OKAY' : '[?]CHECKING'; break;
    }

    if ($tag) updateTagCounts($pdo, $token['nombre'], $tag);

    $duracionMinutos = 0;
    $stmtDur = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) as minutos");
    $stmtDur->execute([$fechaIngreso]);
    $duracionMinutos = $stmtDur->fetch()['minutos'] ?? 0;

    $pdo->prepare("UPDATE tokens SET fecha_salida = NOW(), estado = 'nuevo', tag = ?, fecha_ingreso = NULL, precio_entrada = ?, precio_maximo = 0, passed_15 = 0, precio_15_peak = 0, checks_count = 0, primer_check = NOW() WHERE id = ?")->execute([$tag, $precioSalida, $tokenId]);
    $stmtFecha = $pdo->prepare("SELECT fecha_salida FROM tokens WHERE id = ?");
    $stmtFecha->execute([$tokenId]);
    $fechaSalidaDb = $stmtFecha->fetch()['fecha_salida'];

    $montoInvertido = (float)($token['monto_invertido'] ?? 0);
    $profitDolares = 0;
    if ($montoInvertido > 0) {
        $profitDolares = round($montoInvertido * ($profit / 100), 2);
        $montoRetornado = $montoInvertido + $profitDolares;
        $detalleWallet = 'Exit ' . $razon . ': ' . ($profit >= 0 ? '+' : '') . $profit . '% ($' . $profitDolares . ')';
        updateWallet($pdo, $montoRetornado, 'profit', $token['nombre'], $token['token_address'], (int)($token['confianza'] ?? 0), $detalleWallet, $profitDolares);
        echo "[" . date('Y-m-d H:i:s') . "] WALLET: \$" . $montoInvertido . " -> \$" . $montoRetornado . " (" . ($profit >= 0 ? '+' : '') . $profit . "%)\n";
    } elseif ($token['fecha_ingreso']) {
        echo "[" . date('Y-m-d H:i:s') . "] [WARN] monto_invertido is NULL for " . $token['nombre'] . " - wallet credit skipped\n";
    }

    $nombreConTag = $token['nombre'];

    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            fecha_entrada, fecha_salida,
            monto_invertido, profit_dolares
        ) VALUES (:id, :chain, :token_addr, :pair_addr, :nombre, :simbolo, :entrada, :descubrimiento, :salida, :profit, :duracion, :razon, :tag, :fecha_entrada, :fecha_salida, :monto_inv, :profit_dol)
    ")->execute([
        ':id' => $tokenId, ':chain' => $token['chain_id'],
        ':token_addr' => $token['token_address'], ':pair_addr' => $token['pair_address'],
        ':nombre' => $token['nombre'], ':simbolo' => $token['simbolo'],
        ':entrada' => $token['precio_entrada'],
        ':descubrimiento' => $token['precio_descubrimiento'] ?? $token['precio_entrada'],
        ':salida' => $precioSalida, ':profit' => $profit,
        ':duracion' => $duracionMinutos, ':razon' => $razon, ':tag' => $tag,
        ':fecha_entrada' => $token['fecha_ingreso'] ?? $token['fecha_registro'],
        ':fecha_salida' => $fechaSalidaDb,
        ':monto_inv' => $montoInvertido ?: null,
        ':profit_dol' => $profitDolares ?: null
    ]);

    if ($profitDolares < 0) {
        try {
            $pdo->prepare("INSERT INTO token_cooldowns (pair_address, cooldown_until, profit_dolares)
                VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)
                ON DUPLICATE KEY UPDATE cooldown_until = DATE_ADD(NOW(), INTERVAL 24 HOUR), profit_dolares = ?")
                ->execute([$token['pair_address'], $profitDolares, $profitDolares]);
            echo "[" . date('Y-m-d H:i:s') . "] COOLDOWN: " . $token['nombre'] . " blocked for 24h (negative exit: \${$profitDolares})\n";
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] COOLDOWN ERROR: " . $e->getMessage() . "\n";
        }
    }

    // Daily limits: 2 Checking or 5 Okay -> block pair_address for rest of day
    $checkingHoy = getCheckingCountHoy($pdo, $token['pair_address']);
    $okayHoy = getOkayCountHoy($pdo, $token['nombre']);
    if ($checkingHoy >= 2 || $okayHoy >= 5) {
        try {
            $pdo->prepare("INSERT INTO token_cooldowns (pair_address, cooldown_until, profit_dolares) VALUES (?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 0) ON DUPLICATE KEY UPDATE cooldown_until = DATE_ADD(CURDATE(), INTERVAL 1 DAY), profit_dolares = 0")
                ->execute([$token['pair_address']]);
            echo "[" . date('Y-m-d H:i:s') . "] DAILY LIMIT: " . $token['nombre'] . " hit {$checkingHoy}Ch/{$okayHoy}Ok, blocked until tomorrow\n";
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] DAILY LIMIT ERROR: " . $e->getMessage() . "\n";
        }
    }

    // Record non-TP exits in traded_addresses to prevent re-entry
    if ($razon !== 'tp') {
        try {
            $pdo->prepare("INSERT IGNORE INTO traded_addresses (pair_address, token_address, chain_id, nombre, razon_salida) VALUES (?, ?, ?, ?, ?)")
                ->execute([$token['pair_address'], $token['token_address'], $token['chain_id'], $token['nombre'], $razon]);
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] TRADED ADDRESS ERROR: " . $e->getMessage() . "\n";
        }
    }
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

function registrarCoinRevisada($pdo, $pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez, $cambio1h, $cambio6h, $cambio24h, $accion, $razon = null) {
    try {
        $pdo->prepare("INSERT INTO coins_revisadas (pair_address, chain_id, nombre, precio, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h, accion, razon, revisado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez, $cambio1h, $cambio6h, $cambio24h, $accion, $razon]);
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] coins_revisadas error: " . $e->getMessage() . "\n";
    }
}

function banearToken($pdo, $tokenAddress, $pairAddress, $chainId, $razon, $nombre = null) {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM tokens_banned WHERE pair_address = ? AND chain_id = ?");
        $stmt->execute([$pairAddress, $chainId]);
        if (!$stmt->fetch()) {
            $pdo->prepare("INSERT INTO tokens_banned (token_address, pair_address, chain_id, razon, nombre, banneado_en) VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$tokenAddress, $pairAddress, $chainId, $razon, $nombre]);
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

    $checking = $existing['checking_count'] ?? 0;
    $okay = $existing['okay_count'] ?? 0;
    $inestable = $existing['inestable_count'] ?? 0;

    switch ($tag) {
        case '[?]CHECKING': $checking++; break;
        case '[OK]OKAY': $okay++; break;
        case '[I]NESTABLE': $inestable++; break;
    }

    if ($existing) {
        $pdo->prepare("UPDATE coins_tags SET checking_count = ?, okay_count = ?, inestable_count = ?, ultimo_tag = ? WHERE nombre_normalizado = ?")
            ->execute([$checking, $okay, $inestable, $tag, $nombreNorm]);
    } else {
        $pdo->prepare("INSERT INTO coins_tags (nombre_normalizado, checking_count, okay_count, inestable_count, ultimo_tag) VALUES (?, ?, ?, ?, ?)")
            ->execute([$nombreNorm, $checking, $okay, $inestable, $tag]);
    }
    return ['checking' => $checking, 'okay' => $okay, 'inestable' => $inestable];
}



function calcularVolatilidad($pdo, $pairAddress, $minutesWindow = 60) {
    $stmt = $pdo->prepare("SELECT precio, revisado_en FROM coins_revisadas WHERE pair_address = ? AND revisado_en >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY revisado_en ASC");
    $stmt->execute([$pairAddress, $minutesWindow]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2) return null;

    $returns = [];
    for ($i = 1; $i < count($rows); $i++) {
        $prev = (float)$rows[$i - 1]['precio'];
        $curr = (float)$rows[$i]['precio'];
        if ($prev > 0) $returns[] = (($curr - $prev) / $prev) * 100;
    }
    if (empty($returns)) return null;

    $mean = array_sum($returns) / count($returns);
    $variance = 0;
    foreach ($returns as $r) $variance += ($r - $mean) ** 2;
    $variance /= count($returns);

    return [
        'volatility' => round(sqrt($variance), 4),
        'avg_change' => round($mean, 4),
        'max_swing' => round(max($returns), 4),
        'min_swing' => round(min($returns), 4),
        'data_points' => count($returns)
    ];
}

function detectarPump($pdo, $pairAddress, $thresholdPct = 10, $windowMinutes = 5) {
    $stmt = $pdo->prepare("SELECT precio, revisado_en FROM coins_revisadas WHERE pair_address = ? AND revisado_en >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY revisado_en ASC");
    $stmt->execute([$pairAddress, $windowMinutes]);
    $rows = $stmt->fetchAll();
    if (count($rows) < 2) return null;

    $earliest = (float)$rows[0]['precio'];
    $latest = (float)$rows[count($rows) - 1]['precio'];
    if ($earliest <= 0) return null;

    $changePct = (($latest - $earliest) / $earliest) * 100;

    $peak = $earliest;
    $maxDrawdown = 0;
    foreach ($rows as $row) {
        $p = (float)$row['precio'];
        if ($p > $peak) $peak = $p;
        $dd = $peak > 0 ? (($p - $peak) / $peak) * 100 : 0;
        if ($dd < $maxDrawdown) $maxDrawdown = $dd;
    }

    return [
        'is_pump' => $changePct >= $thresholdPct,
        'change_pct' => round($changePct, 2),
        'is_pump_and_dump' => ($changePct >= $thresholdPct && $maxDrawdown < -($thresholdPct * 0.5)),
        'drawdown_from_peak' => round($maxDrawdown, 2),
        'data_points' => count($rows)
    ];
}

function getCheckingCountHoy($pdo, $pairAddress) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM historial_tokens WHERE pair_address = ? AND DATE(fecha_salida) = CURDATE() AND tag = '[?]CHECKING'");
    $stmt->execute([$pairAddress]);
    return (int)$stmt->fetchColumn();
}

function getOkayCountHoy($pdo, $nombre) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM historial_tokens WHERE nombre = ? AND DATE(fecha_salida) = CURDATE() AND tag = '[OK]OKAY'");
    $stmt->execute([$nombre]);
    return (int)$stmt->fetchColumn();
}

function getWalletSaldo($pdo) {
    $stmt = $pdo->query("SELECT saldo FROM wallet WHERE id = 1");
    $row = $stmt->fetch();
    return $row ? (float)$row['saldo'] : 1000.00;
}

function updateWallet($pdo, $monto, $tipo, $tokenNombre, $tokenAddress, $confianza = 0, $detalle = '', $montoRegistrado = null) {
    $pdo->exec("INSERT IGNORE INTO wallet (id, saldo) VALUES (1, 1000.00)");
    if ($tipo === 'salida') {
        $pdo->prepare("UPDATE wallet SET saldo = GREATEST(0, saldo - ?), ultima_actualizacion = NOW() WHERE id = 1")->execute([$monto]);
    } else {
        $pdo->prepare("UPDATE wallet SET saldo = saldo + ?, ultima_actualizacion = NOW() WHERE id = 1")->execute([$monto]);
    }
    $saldoRow = $pdo->query("SELECT saldo FROM wallet WHERE id = 1")->fetch();
    $nuevoSaldo = $saldoRow ? (float)$saldoRow['saldo'] : 1000.00;
    $montoReg = $montoRegistrado ?? $monto;
    $pdo->prepare("INSERT INTO wallet_transactions (tipo, token_nombre, token_address, monto, saldo_resultante, confianza, detalle, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$tipo, $tokenNombre, $tokenAddress, $montoReg, $nuevoSaldo, $confianza, $detalle]);
    return $nuevoSaldo;
}

function calcularConfianza($pdo, $nombre, $pairData) {
    $marketCap = $pairData['marketCap'] ?? 0;
    $liquidez = $pairData['liquidity']['usd'] ?? 0;
    $score = 0;
    $score += getSocialPresenceScore($pairData);
    $counts = getTagCounts($pdo, $nombre);
    $tagPts = 25;
    if ($counts) {
        $tagPts -= ($counts['checking_count'] ?? 0) * 5;
        $tagPts += ($counts['okay_count'] ?? 0) * 5;
    }
    $tagPts = max(0, min(35, $tagPts));
    $score += $tagPts;
    if ($marketCap > 0) {
        $ratio = ($liquidez / $marketCap) * 100;
        $liqPts = min(15, $ratio);
        if ($ratio >= 10) $liqPts = max($liqPts, 10);
        $score += $liqPts;
    }
    return max(0, min(100, round($score)));
}

function getSocialPresenceScore($pairData) {
    $score = 0;

    // Pair age: how long this pool has been live and tradeable
    $pairCreatedAt = $pairData['pairCreatedAt'] ?? 0; // Unix ms from DexScreener
    if ($pairCreatedAt > 0) {
        $hoursOld = (time() - ($pairCreatedAt / 1000)) / 3600;
        if ($hoursOld > 168) $score += 30;      // >7 days
        elseif ($hoursOld > 72)  $score += 25;  // >3 days
        elseif ($hoursOld > 24)  $score += 15;  // >1 day
        elseif ($hoursOld > 12)  $score += 10;  // >12 hours
        elseif ($hoursOld > 3)   $score += 5;   // >3 hours
        // <3 hours: 0 — too new to trust
    }

    // Social presence: channels already set up signal an established project
    $socials = $pairData['info']['socials'] ?? [];
    $websites = $pairData['info']['websites'] ?? [];
    $types = array_column($socials, 'type');
    if (in_array('twitter', $types))   $score += 10;
    if (in_array('telegram', $types))  $score += 5;
    if (in_array('discord', $types))   $score += 3;
    if (count($websites) > 0)          $score += 7;

    return min($score, 50); // cap at 50, same range as before
}

function calcularEntryCost($saldo, $confianza) {
    $minPct = 0.08;
    $maxPct = 0.16;
    $pct = $minPct + ($confianza / 100) * ($maxPct - $minPct);
    return round($saldo * $pct, 2);
}
