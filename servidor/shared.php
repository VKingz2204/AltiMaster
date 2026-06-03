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

        $confianza = calcularConfianza($pdo, $nombre, $manual['token_address'], 'solana', $marketCap, $liquidez);
        $saldo = getWalletSaldo($pdo);
        $entryCost = calcularEntryCost($saldo, $confianza);

        try {
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

            // Only deduct wallet after successful INSERT
            updateWallet($pdo, $entryCost, 'salida', $nombre, $manual['token_address'], $confianza, 'Entrada manual coin');

            $pdo->prepare("UPDATE tokens SET monto_invertido = ?, confianza = ? WHERE id = ?")
                ->execute([$entryCost, $confianza, $tokenId]);

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
    $confianza = calcularConfianza($pdo, $token['nombre'], $token['token_address'], $token['chain_id'], $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0);
    $saldo = getWalletSaldo($pdo);
    $entryCost = calcularEntryCost($saldo, $confianza);

    try {
        updateWallet($pdo, $entryCost, 'salida', $token['nombre'], $token['token_address'], $confianza, $razon);
        $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), checks_count = 0, precio_entrada = ?, precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ?, monto_invertido = ?, confianza = ? WHERE id = ?")
            ->execute([$precioActual, $precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $entryCost, $confianza, $token['id']]);

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

function procesarNuevosTokens($pdo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
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
        
        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        if ($token['fecha_ingreso']) continue;
        
        if (shouldBanName($pdo, $token['nombre'])) {
            try {
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Nombre baneado: 2x CAUTION', $token['nombre']);
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
            echo "[" . date('Y-m-d H:i:s') . "] NOMBRE BANEADO: " . $token['nombre'] . " (2x CAUTION)\n";
            continue;
        }
        
        $passed15 = (bool)$token['passed_15'];
        $precioAlerta = (float)$token['precio_15_peak'];

        // Fast path: +3% enter immediately
        if ($cambio >= 3) {
            enterToken($pdo, $token, $pairData, $precioActual, $cambio, 'entrada inmediata +' . round($cambio, 2) . '%');
            continue;
        }

        // First time reaching +1.5%: enter WAITING state
        if ($cambio >= 1.5 && !$passed15) {
            $pdo->prepare("UPDATE tokens SET passed_15 = 1, precio_15_peak = ? WHERE id = ?")
                ->execute([$precioActual, $token['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] [WAIT] " . $token['nombre'] . " (+" . round($cambio, 2) . "%, possible entry, waiting for confirmation)\n";
            continue;
        }

        // Confirmation: price went UP from the 1.5% point
        if ($passed15 && $precioActual > $precioAlerta) {
            enterToken($pdo, $token, $pairData, $precioActual, $cambio, 'confirmed entry +' . round($cambio, 2) . '%');
            continue;
        }

        // Reset if dropped below 1.5%
        if ($passed15 && $cambio < 1.5) {
            $pdo->prepare("UPDATE tokens SET passed_15 = 0, precio_15_peak = 0 WHERE id = ?")
                ->execute([$token['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] [RESET] " . $token['nombre'] . " (dropped to " . round($cambio, 2) . "%, resetting)\n";
            continue;
        }

        // Still waiting: update current price
        $pdo->prepare("UPDATE tokens SET precio_actual = ?, last_check_price = ? WHERE id = ?")
            ->execute([$precioActual, $precioActual, $token['id']]);

        $tiempoVivo = $token['primer_check'] ? (time() - strtotime($token['primer_check'])) / 60 : 0;
        if ($tiempoVivo > 60) {
            try {
                updateTagCounts($pdo, $token['nombre'], '[I]NESTABLE');
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'INESTABLE: Sin actividad', $token['nombre']);
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
}

function monitorearActivos($pdo, $monitoreoIntervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();

    if (count($tokens) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Monitoring " . count($tokens) . " tokens...\n";
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
            $tpBase = 18 + $extraTP;
            $tpFinal = $tpRapido ? min(80, $tpBase * 2) : min(80, $tpBase);

            if ($cambioDesdeEntrada >= $tpFinal) {
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'tp', 'Take Profit: +' . round($cambioDesdeEntrada, 2) . '%');
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] TP " . $tpFinal . "%: " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "%)" . ($extraTP > 0 ? " (+" . $extraTP . "% extra)" : "") . "\n";
                continue;
            }

            $slDesdePeak = getSLForToken($pdo, $token['nombre'], $cambioDesdeEntrada);
            if ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= $slDesdePeak) {
                $razonSalida = ($cambio > 0) ? 'save_tp' : 'sl';
                try {
                    registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $razonSalida, 'Save TP / Stop Loss');
                    marcarExit($pdo, $token['id'], $precioActual, $razonSalida, $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR SL: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] [SL] " . $token['nombre'] . " (drop " . round($cambioDesdePeak, 2) . "% from peak, SL: " . $slDesdePeak . "%)\n";
                continue;
            }
        }

        if ($cambio <= -9) {
            try {
                updateTagCounts($pdo, $token['nombre'], '[I]NESTABLE');
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Inestable', $token['nombre']);
                registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'sl', '-9% desde entrada');
                marcarExit($pdo, $token['id'], $precioActual, 'inestable', $cambio);
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR -9%: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] [INESTABLE] " . $token['nombre'] . " (" . round($cambio, 2) . "% desde entrada)\n";
            continue;
        }

        $tiempoMinutos = (time() - strtotime($token['primer_check'])) / 60;
        if ($tiempoMinutos > 60) {
            try {
                registrarCoinRevisada($pdo, $token['pair_address'], $token['chain_id'], $token['nombre'], $precioActual, $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, 'exit', 'Limite de 60 minutos de monitoreo alcanzado');
                marcarExit($pdo, $token['id'], $precioActual, 'expirado', round($cambio, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXPIRADO: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] EXPIRADO: " . $token['nombre'] . " (" . $tiempoMinutos . " min, profit: " . round($cambio, 2) . "%)\n";
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
                    $precioActual, $precioMaximo,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0, $token['id']
                ]);

            $coinslogDir = __DIR__ . '/coinslog';
            if (!is_dir($coinslogDir)) @mkdir($coinslogDir, 0777, true);
            $safeName = preg_replace('/[^A-Za-z0-9_]/', '', strtoupper($token['nombre']));
            if (!$safeName) $safeName = 'UNKNOWN';
            $logFile = $coinslogDir . '/' . $safeName . '_' . $token['token_address'] . '.log';
            $logLine = "[" . date('Y-m-d H:i:s') . "] PRECIO=" . $precioActual . " MARKETCAP=" . ($pairData['marketCap'] ?? 0) . " LIQUIDEZ=" . ($pairData['liquidez']['usd'] ?? 0) . " CAMBIO_1H=" . ($pairData['priceChange']['h1'] ?? 0) . " CAMBIO_6H=" . ($pairData['priceChange']['h6'] ?? 0) . " CAMBIO_24H=" . ($pairData['priceChange']['h24'] ?? 0) . "\n";
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ERROR ACTUALIZAR: " . $e->getMessage() . "\n";
        }
    }
}

function marcarExit($pdo, $tokenId, $precioSalida, $razon, $profit) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE id = $tokenId");
    $token = $stmt->fetch();

    $fechaIngreso = $token['fecha_ingreso'] ?? null;
    if (!$fechaIngreso) {
        $pdo->exec("DELETE FROM tokens WHERE id = $tokenId");
        echo "[" . date('Y-m-d H:i:s') . "] Eliminado: " . $token['nombre'] . " (nunca entro, razon: $razon)\n";
        return;
    }

    $tag = null;
    switch ($razon) {
        case 'tp': $tag = '[S]TRONG'; break;
        case 'save_tp': $tag = '[OK]OKAY'; break;
        case 'sl': $tag = '[?]CHECKING'; break;
        case 'ban': case 'inestable': $tag = '[I]NESTABLE'; break;
        case 'expirado': default: $tag = ($profit > 0) ? '[OK]OKAY' : '[?]CHECKING'; break;
    }

    if ($tag) updateTagCounts($pdo, $token['nombre'], $tag);

    $duracionMinutos = 0;
    $stmtDur = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, '$fechaIngreso', NOW()) as minutos");
    $duracionMinutos = $stmtDur->fetch()['minutos'] ?? 0;

    $pdo->prepare("UPDATE tokens SET fecha_salida = NOW(), estado = 'exit', tag = ? WHERE id = ?")->execute([$tag, $tokenId]);
    $stmtFecha = $pdo->query("SELECT fecha_salida FROM tokens WHERE id = $tokenId");
    $fechaSalidaDb = $stmtFecha->fetch()['fecha_salida'];

    $montoInvertido = (float)($token['monto_invertido'] ?? 0);
    $profitDolares = 0;
    if ($montoInvertido > 0) {
        $profitDolares = round($montoInvertido * ($profit / 100), 2);
        $montoRetornado = $montoInvertido + $profitDolares;
        $detalleWallet = 'Exit ' . $razon . ': ' . ($profit >= 0 ? '+' : '') . $profit . '% ($' . $profitDolares . ')';
        updateWallet($pdo, $montoRetornado, 'profit', $token['nombre'], $token['token_address'], (int)($token['confianza'] ?? 0), $detalleWallet, $profitDolares);
        echo "[" . date('Y-m-d H:i:s') . "] WALLET: \$" . $montoInvertido . " -> \$" . $montoRetornado . " (" . ($profit >= 0 ? '+' : '') . $profit . "%)\n";
    }

    $nombreConTag = $token['nombre'];

    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_descubrimiento, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            es_reentry, fecha_entrada, fecha_salida,
            monto_invertido, profit_dolares
        ) VALUES (:id, :chain, :token_addr, :pair_addr, :nombre, :simbolo, :entrada, :descubrimiento, :salida, :profit, :duracion, :razon, :tag, :es_reentry, :fecha_entrada, :fecha_salida, :monto_inv, :profit_dol)
    ")->execute([
        ':id' => $tokenId, ':chain' => $token['chain_id'],
        ':token_addr' => $token['token_address'], ':pair_addr' => $token['pair_address'],
        ':nombre' => $token['nombre'], ':simbolo' => $token['simbolo'],
        ':entrada' => $token['precio_entrada'],
        ':descubrimiento' => $token['precio_descubrimiento'] ?? $token['precio_entrada'],
        ':salida' => $precioSalida, ':profit' => $profit,
        ':duracion' => $duracionMinutos, ':razon' => $razon, ':tag' => $tag,
        ':es_reentry' => $token['es_reentry'],
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

    if ($razon === 'tp' || $razon === 'save_tp') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM historial_tokens WHERE pair_address = ? AND DATE(fecha_salida) = CURDATE() AND razon_salida IN ('tp', 'save_tp')");
            $stmt->execute([$token['pair_address']]);
            $dailyCount = (int)$stmt->fetchColumn();
            if ($dailyCount >= 3) {
                $pdo->prepare("INSERT INTO token_cooldowns (pair_address, cooldown_until, profit_dolares) VALUES (?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 0) ON DUPLICATE KEY UPDATE cooldown_until = DATE_ADD(CURDATE(), INTERVAL 1 DAY), profit_dolares = 0")
                    ->execute([$token['pair_address']]);
                echo "[" . date('Y-m-d H:i:s') . "] DAILY LIMIT: " . $token['nombre'] . " hit 3 TP/Save TP today, blocked until tomorrow\n";
            }
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] DAILY LIMIT ERROR: " . $e->getMessage() . "\n";
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

    $strong = $existing['strong_count'] ?? 0;
    $checking = $existing['checking_count'] ?? 0;
    $okay = $existing['okay_count'] ?? 0;
    $inestable = $existing['inestable_count'] ?? 0;

    switch ($tag) {
        case '[S]TRONG': $strong++; break;
        case '[?]CHECKING': $checking++; break;
        case '[OK]OKAY':
            $okay++;
            if ($okay >= 2) { $okay -= 2; $strong++; }
            break;
        case '[I]NESTABLE': $inestable++; break;
    }

    if ($existing) {
        $pdo->prepare("UPDATE coins_tags SET strong_count = ?, checking_count = ?, okay_count = ?, inestable_count = ?, ultimo_tag = ? WHERE nombre_normalizado = ?")
            ->execute([$strong, $checking, $okay, $inestable, $tag, $nombreNorm]);
    } else {
        $pdo->prepare("INSERT INTO coins_tags (nombre_normalizado, strong_count, checking_count, okay_count, inestable_count, ultimo_tag) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$nombreNorm, $strong, $checking, $okay, $inestable, $tag]);
    }
    return ['strong' => $strong, 'checking' => $checking, 'okay' => $okay, 'inestable' => $inestable];
}

function getSLForToken($pdo, $nombre, $cambioDesdeEntrada) {
    if ($cambioDesdeEntrada >= 12) return -3;
    return -6;
}

function shouldBanName($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    if (!$counts) return false;
    return ($counts['checking_count'] ?? 0) >= 2 || ($counts['inestable_count'] ?? 0) > 0;
}

function getExtraTP($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    if (!$counts) return 0;
    $extra = (($counts['okay_count'] ?? 0) * 3) + (($counts['strong_count'] ?? 0) * 6);
    return min($extra, 62);
}

function getWalletSaldo($pdo) {
    $stmt = $pdo->query("SELECT saldo FROM wallet WHERE id = 1");
    $row = $stmt->fetch();
    return $row ? (float)$row['saldo'] : 1000.00;
}

function updateWallet($pdo, $monto, $tipo, $tokenNombre, $tokenAddress, $confianza = 0, $detalle = '', $montoRegistrado = null) {
    $saldoActual = getWalletSaldo($pdo);
    $nuevoSaldo = $tipo === 'salida' ? max(0, $saldoActual - $monto) : $saldoActual + $monto;
    $pdo->prepare("UPDATE wallet SET saldo = ?, ultima_actualizacion = NOW() WHERE id = 1")->execute([$nuevoSaldo]);
    $montoReg = $montoRegistrado ?? $monto;
    $pdo->prepare("INSERT INTO wallet_transactions (tipo, token_nombre, token_address, monto, saldo_resultante, confianza, detalle, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$tipo, $tokenNombre, $tokenAddress, $montoReg, $nuevoSaldo, $confianza, $detalle]);
    return $nuevoSaldo;
}

function calcularConfianza($pdo, $nombre, $tokenAddress, $chainId, $marketCap, $liquidez) {
    $score = 0;
    $score += getTwitterAgeScore($tokenAddress, $chainId);
    $counts = getTagCounts($pdo, $nombre);
    $tagPts = 25;
    if ($counts) {
        $tagPts -= ($counts['checking_count'] ?? 0) * 5;
        $tagPts += ($counts['okay_count'] ?? 0) * 5;
        $tagPts += ($counts['strong_count'] ?? 0) * 10;
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

function getTwitterAgeScore($tokenAddress, $chainId) {
    $tokenData = obtenerDatosToken($chainId, $tokenAddress);
    if (!$tokenData || !isset($tokenData[0])) return 0;
    $pair = $tokenData[0];
    $socials = $pair['info']['socials'] ?? [];
    $twitterHandle = null;
    foreach ($socials as $s) {
        if (($s['type'] ?? '') === 'twitter') {
            $url = $s['url'] ?? '';
            if ($url) {
                $parts = explode('/', rtrim($url, '/'));
                $twitterHandle = end($parts);
            }
            break;
        }
    }
    if (!$twitterHandle) return 0;

    $bearerToken = 'AAAAAAAAAAAAAAAAAAAAALeg9wEAAAAATUzuO0f2yUcKI%2BCo6LHam%2B%2BpZng%3DixkH7p1JH3knOV5mkkQFILMpS32ZCO2AQFQdDJ6msKMgo3G5Bu';
    $url = "https://api.x.com/2/users/by/username/" . urlencode($twitterHandle) . "?user.fields=created_at";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $bearerToken"], CURLOPT_SSL_VERIFYPEER => false
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return 0;
    $data = json_decode($response, true);
    $createdAt = $data['data']['created_at'] ?? null;
    if (!$createdAt) return 0;
    $daysOld = (time() - strtotime($createdAt)) / 86400;
    if ($daysOld > 365) return 50;
    if ($daysOld > 90) return 40;
    if ($daysOld > 30) return 30;
    if ($daysOld > 7) return 20;
    return 10;
}

function calcularEntryCost($saldo, $confianza) {
    $minPct = 0.05;
    $maxPct = 0.10;
    $pct = $minPct + ($confianza / 100) * ($maxPct - $minPct);
    return round($saldo * $pct, 2);
}
