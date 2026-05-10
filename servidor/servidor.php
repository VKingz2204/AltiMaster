<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../api/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting AltChecks Server...\n";
echo "DB connected: " . (isset($pdo) ? "YES" : "NO") . "\n";

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

$revisionCiclos = 0;
$revisionIntervalo = 5;
$ciclosParaBuscar = 60;
$busquedaEnProgreso = false;

while (true) {
    $ahora = time();

    try {
        $pdo->query("UPDATE servidor_status SET ultimo_check = NOW() WHERE id = 1");

        if (!$busquedaEnProgreso && ($revisionCiclos === 0 || $revisionCiclos >= $ciclosParaBuscar)) {
            echo "[" . date('Y-m-d H:i:s') . "] === NEW CYCLE ===\n";
            echo "[" . date('Y-m-d H:i:s') . "] 1. Updating DuckDNS...\n";
            @file_get_contents("https://www.duckdns.org/update?domains=altimaster.duckdns.org&token=c3763a1c-bc5e-422e-9556-6b75352c6220&ip=");
            
            echo "[" . date('Y-m-d H:i:s') . "] 2. Launching background token search...\n";
            $busquedaEnProgreso = true;
            
            $phpCmd = 'C:\xampp\php\php.exe';
            $scriptPath = __DIR__ . '\buscador_tokens.php';
            $logPath = __DIR__ . '\buscador.log';
            
            pclose(popen("start /B \"\" \"$phpCmd\" \"$scriptPath\" > \"$logPath\" 2>&1", "r"));
            
            $revisionCiclos = 0;
        }

        echo "[" . date('Y-m-d H:i:s') . "] 3. Revisando tokens activos (ciclo $revisionCiclos)...\n";
        monitoreoTokensActivos($pdo, $monitoreoIntervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje);

        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tokens WHERE estado = 'monitoreando'");
        $count = $stmt->fetch();
        $pdo->query("UPDATE servidor_status SET tokens_activos = " . $count['count'] . " WHERE id = 1");

    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        logSistema('error', 'Error en servidor: ' . $e->getMessage());
    }

    $revisionCiclos++;
    sleep($revisionIntervalo);
}

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
                    nombre, simbolo, precio_actual, precio_entrada, precio_crash, precio_maximo,
                    last_check_price, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h,
                    estado, meta_tp, tp_alcanzado, sl_alcanzado, es_reentry,
                    reentry_count, checks_count, laps, timeout_count, fecha_registro,
                    primer_check, ultimo_check, creado_en, actualizado_en
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'nuevo', ?, 0, 0, 0, 0, 0, 0, 0, NOW(), NOW(), NOW(), NOW(), NOW())";
                $params = [
                    $token['chain_id'], $token['token_address'], $token['pair_address'],
                    $token['nombre'], $token['simbolo'], $token['precio'], $token['precio'],
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
        echo "[" . date('Y-m-d H:i:s') . "] 💾 Saving " . count($tokensNuevos) . " new tokens to DB...\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] ✓ No new tokens\n";
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

function monitoreoTokensActivos($pdo, $intervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje) {
    $stmtNuevos = $pdo->query("SELECT * FROM tokens WHERE estado = 'nuevo'");
    $tokensNuevos = $stmtNuevos->fetchAll();
    
    if (count($tokensNuevos) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] ⏳ Waiting for entry: " . count($tokensNuevos) . " tokens...\n";
    }
    
    foreach ($tokensNuevos as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;
        
        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        if ($token['fecha_ingreso']) {
            continue;
        }
        
        if (shouldBanName($pdo, $token['nombre'])) {
            try {
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Nombre baneado: 2x DESTROYED');
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'ban', 'Nombre baneado (2x DESTROYED)'
                );
                $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR BAN NOMBRE: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] ⛔ NOMBRE BANEADO: " . $token['nombre'] . " (2x DESTROYED)\n";
            continue;
        }
        
        $reqEntrada = getEntryRequirement($pdo, $token['nombre']);
        if ($cambio >= $reqEntrada) {
            $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ? WHERE id = ?")
                ->execute([$precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $token['id']]);
            
            actualizarTokenFree($pdo, $token['id']);
            
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'entrada', 'Token entró a monitoreo (+' . round($cambio, 2) . '%)'
            );
            
            echo "[" . date('Y-m-d H:i:s') . "] 🚪 ENTERED: " . $token['nombre'] . " (+" . round($cambio, 2) . "%)\n";
            continue;
        }
        
        $lastCheckPrice = (float)$token['last_check_price'];
        if ($lastCheckPrice > 0) {
            $cambioCheck = abs((($precioActual / $lastCheckPrice) - 1) * 100);
            if ($cambioCheck < 3) {
                $timeoutCount = ($token['timeout_count'] ?? 0) + 1;
                $pdo->prepare("UPDATE tokens SET timeout_count = ?, precio_actual = ?, last_check_price = ? WHERE id = ?")
                    ->execute([$timeoutCount, $precioActual, $precioActual, $token['id']]);
                
                if ($timeoutCount >= 540) {
                    try {
                        registrarCoinRevisada(
                            $pdo, $token['pair_address'], $token['chain_id'],
                            $token['nombre'], $precioActual,
                            $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                            $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                            $pairData['priceChange']['h24'] ?? 0,
                            'timeout', 'Token sin movimiento por 540 ciclos'
                        );
                        $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                    } catch (Exception $e) {
                        echo "[" . date('Y-m-d H:i:s') . "] ERROR TIMEOUT: " . $e->getMessage() . "\n";
                    }
                    echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT: " . $token['nombre'] . " (no change ±3% for 540 cycles)\n";
                    continue;
                }
            } else {
                $pdo->prepare("UPDATE tokens SET timeout_count = 0, precio_actual = ?, last_check_price = ? WHERE id = ?")
                    ->execute([$precioActual, $precioActual, $token['id']]);
            }
        } else {
            $pdo->prepare("UPDATE tokens SET last_check_price = ?, precio_actual = ? WHERE id = ?")
                ->execute([$precioActual, $precioActual, $token['id']]);
        }
        
        $checksCount = ($token['checks_count'] ?? 0) + 1;
        $pdo->prepare("UPDATE tokens SET checks_count = ? WHERE id = ?")->execute([$checksCount, $token['id']]);
        
        if ($checksCount >= 60) {
            $laps = ($token['laps'] ?? 0) + 1;
            $pdo->prepare("UPDATE tokens SET laps = ?, checks_count = 0 WHERE id = ?")->execute([$laps, $token['id']]);
            
            echo "[" . date('Y-m-d H:i:s') . "] 🔄 Lap $laps/9: " . $token['nombre'] . " (still haven't entered)\n";
            
            if ($laps >= 15) {
                $razon = ($cambio > 0) ? 'save_tp' : 'sl';
                $tag = ($cambio > 0) ? '👍OKAY' : '🧐CHECKING';
                $msgTag = ($cambio > 0) ? 'Timeout en + (OKAY)' : 'Timeout en - (CHECKING)';
                
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        $razon, $msgTag
                    );
                    marcarExit($pdo, $token['id'], $precioActual, $razon, $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TIMEOUT 900: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT 900: " . $token['nombre'] . " ($tag, cambio: " . round($cambio, 2) . "%)\n";
                continue;
            }
            
            if ($laps >= 9) {
                try {
                    banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], '9 laps without entering (+1.5%)');
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        'ban', 'Baneado: 9 vuelta sin pasar +1.5%'
                    );
                    $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR BAN: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] ⛔ BAN: " . $token['nombre'] . " (9 laps without entering)\n";
            }
        }
    }
    
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();

    echo "[" . date('Y-m-d H:i:s') . "] DEBUG MONITOREO: Found " . count($tokens) . " monitoreo tokens\n";
    
    if (count($tokens) > 0) {
        foreach ($tokens as $t) {
            echo "[" . date('Y-m-d H:i:s') . "]   - " . $t['nombre'] . " | price=" . $t['precio_actual'] . " | entrada=" . $t['precio_entrada'] . "\n";
        }
        echo "[" . date('Y-m-d H:i:s') . "] 🔄 Monitoring " . count($tokens) . " tokens...\n";
    }

    foreach ($tokens as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
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
            
            $slDesdePeak = getSLForToken($pdo, $token['nombre'], $cambioDesdeEntrada);
            
            $extraTP = getExtraTP($pdo, $token['nombre']);
            $tpFinal = 24 + $extraTP;
            
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
                echo "[" . date('Y-m-d H:i:s') . "] ✅ TP {$tpFinal}%: " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "%)" . ($extraTP > 0 ? " (+$extraTP% extra)" : "") . "\n";
                continue;
            }
            
            if ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= $slDesdePeak) {
                $razonSalida = ($cambio > 0) ? 'save_tp' : 'sl';
                $emojiSalida = ($cambio > 0) ? '⚠️ Save TP' : '🔚 SL';
                $razonMsg = ($cambio > 0) 
                    ? 'Save TP: estaba en +' . round($cambio, 2) . '%, cayó ' . round($cambioDesdePeak, 2) . '%'
                    : 'Stop Loss: ' . round($cambioDesdePeak, 2) . '% from peak (peak: +' . round((($precioMaximo / $precioEntrada) - 1) * 100, 2) . '%)';
                
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        $razonSalida, $razonMsg
                    );
                    marcarExit($pdo, $token['id'], $precioActual, $razonSalida, $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR " . $emojiSalida . ": " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] $emojiSalida: " . $token['nombre'] . " (drop " . round($cambioDesdePeak, 2) . "% from peak, SL: $slDesdePeak%)\n";
                continue;
            }
        }

        $precioMaximo = (float)$token['precio_maximo'];
        if ($precioActual > $precioMaximo) {
            $precioMaximo = $precioActual;
        }

        $caidaDesdePico = $precioMaximo > 0 ? (($precioActual / $precioMaximo) - 1) * 100 : 0;

        if ($caidaDesdePico <= -8) {
            try {
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'exit', 'Caída desde pico: ' . round($caidaDesdePico, 2) . '%'
                );
                $profitDesdeEntrada = (($precioActual / $precioEntrada) - 1) * 100;
                marcarExit($pdo, $token['id'], $precioActual, 'caida_pico', round($profitDesdeEntrada, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXIT: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] 🔚 SL (Caída Pico): " . $token['nombre'] . " (" . round($caidaDesdePico, 2) . "% desde $" . round($precioMaximo, 8) . ")\n";
            continue;
        }

        $tiempoMinutos = (time() - strtotime($token['primer_check'])) / 60;
        $variacion = abs($cambio);
        if ($tiempoMinutos > 90 && $variacion < 5) {
            try {
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidez']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'exit', 'Sin variación en 90+ min: ' . round($cambio, 2) . '%'
                );
                marcarExit($pdo, $token['id'], $precioActual, 'expirado', round($cambio, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXPIRADO: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] ⏰ EXPIRADO (sin variación): " . $token['nombre'] . " ({$tiempoMinutos} min, {$cambio}%)\n";
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
    
    foreach ($tokensNuevos as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;
        
        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        // Ya tiene fecha de ingreso, no procesar como nuevo
        if ($token['fecha_ingreso']) {
            continue;
        }
        
        // Verificar si el nombre está baneado
        if (shouldBanName($pdo, $token['nombre'])) {
            try {
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], 'Nombre baneado: 2x DESTROYED');
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'ban', 'Nombre baneado (2x DESTROYED)'
                );
                $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR BAN NOMBRE: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] ⛔ NOMBRE BANEADO: " . $token['nombre'] . " (2x DESTROYED)\n";
            continue;
        }
        
        // Verificar requisito de entrada según tags
        $reqEntrada = getEntryRequirement($pdo, $token['nombre']);
        if ($cambio >= $reqEntrada) {
            $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ? WHERE id = ?")
                ->execute([$precioActual, $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0, $pairData['priceChange']['h24'] ?? 0, $precioActual, $token['id']]);
            
            actualizarTokenFree($pdo, $token['id']);
            
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'entrada', 'Token entró a monitoreo (+' . round($cambio, 2) . '%)'
            );
            
            echo "[" . date('Y-m-d H:i:s') . "] 🚪 ENTERED: " . $token['nombre'] . " (+" . round($cambio, 2) . "%)\n";
            continue;
        }
        
        // Verificar timeout (9 ciclos sin cambio de ±3%)
        $lastCheckPrice = (float)$token['last_check_price'];
        if ($lastCheckPrice > 0) {
            $cambioCheck = abs((($precioActual / $lastCheckPrice) - 1) * 100);
            if ($cambioCheck < 3) {
                $timeoutCount = ($token['timeout_count'] ?? 0) + 1;
                $pdo->prepare("UPDATE tokens SET timeout_count = ?, precio_actual = ?, last_check_price = ? WHERE id = ?")
                    ->execute([$timeoutCount, $precioActual, $precioActual, $token['id']]);
                
                if ($timeoutCount >= 540) {
                    try {
                        registrarCoinRevisada(
                            $pdo, $token['pair_address'], $token['chain_id'],
                            $token['nombre'], $precioActual,
                            $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                            $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                            $pairData['priceChange']['h24'] ?? 0,
                            'timeout', 'Token sin movimiento por 540 ciclos'
                        );
                        // Timeout sin entrar = eliminar sin historial
                        $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                    } catch (Exception $e) {
                        echo "[" . date('Y-m-d H:i:s') . "] ERROR TIMEOUT: " . $e->getMessage() . "\n";
                    }
                    echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT: " . $token['nombre'] . " (no change ±3% for 540 cycles)\n";
                    continue;
                }
            } else {
                $pdo->prepare("UPDATE tokens SET timeout_count = 0, precio_actual = ?, last_check_price = ? WHERE id = ?")
                    ->execute([$precioActual, $precioActual, $token['id']]);
            }
        } else {
            $pdo->prepare("UPDATE tokens SET last_check_price = ?, precio_actual = ? WHERE id = ?")
                ->execute([$precioActual, $precioActual, $token['id']]);
        }
        
        // Verificar laps (60 ciclos sin entrar = 1 vuelta, 9 vueltas = ban)
        $checksCount = ($token['checks_count'] ?? 0) + 1;
        $pdo->prepare("UPDATE tokens SET checks_count = ? WHERE id = ?")->execute([$checksCount, $token['id']]);
        
        if ($checksCount >= 60) {
            $laps = ($token['laps'] ?? 0) + 1;
            $pdo->prepare("UPDATE tokens SET laps = ?, checks_count = 0 WHERE id = ?")->execute([$laps, $token['id']]);
            
            echo "[" . date('Y-m-d H:i:s') . "] 🔄 Lap $laps/9: " . $token['nombre'] . " (still haven't entered)\n";
            
// 900 ciclos (15 laps) = timeout máximo, sale con tag según resultado
            if ($laps >= 15) {
                $razon = ($cambio > 0) ? 'save_tp' : 'sl';
                $tag = ($cambio > 0) ? '👍OKAY' : '🧐CHECKING';
                $msgTag = ($cambio > 0) ? 'Timeout en + (OKAY)' : 'Timeout en - (CHECKING)';
                
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        $razon, $msgTag
                    );
                    marcarExit($pdo, $token['id'], $precioActual, $razon, $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TIMEOUT 900: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT 900: " . $token['nombre'] . " ($tag, cambio: " . round($cambio, 2) . "%)\n";
                continue;
            }
            
            if ($laps >= 9) {
                try {
                    banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], '9 laps without entering (+1.5%)');
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        'ban', 'Baneado: 9 vuelta sin pasar +1.5%'
                    );
                    // Ban sin entrar = eliminar sin historial
                    $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR BAN: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] ⛔ BAN: " . $token['nombre'] . " (9 laps without entering)\n";
            }
        }
    }
    
    // Ahora procesar tokens que ya están en monitoreo
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();

    echo "[" . date('Y-m-d H:i:s') . "] DEBUG MONITOREO: Found " . count($tokens) . " monitoreo tokens\n";
    
    if (count($tokens) > 0) {
        foreach ($tokens as $t) {
            echo "[" . date('Y-m-d H:i:s') . "]   - " . $t['nombre'] . " | price=" . $t['precio_actual'] . " | entrada=" . $t['precio_entrada'] . "\n";
        }
        echo "[" . date('Y-m-d H:i:s') . "] 🔄 Monitoring " . count($tokens) . " tokens...\n";
    }

    foreach ($tokens as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
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
            
            // Calcular SL basado en tags
            $slDesdePeak = getSLForToken($pdo, $token['nombre'], $cambioDesdeEntrada);
            
            // Calcular TP basado en tags (base 24% + extra por STRONG)
            $extraTP = getExtraTP($pdo, $token['nombre']);
            $tpFinal = 24 + $extraTP;
            
            if ($cambioDesdeEntrada >= $tpFinal) {
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        'tp', 'Take Profit: +' . round($cambioDesdeEntrada, 2) . '%'
                    );
                    marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR TP: " . $e->getMessage() . "\n";
                }
                echo "[" . date('Y-m-d H:i:s') . "] ✅ TP {$tpFinal}%: " . $token['nombre'] . " (+" . round($cambioDesdeEntrada, 2) . "%)" . ($extraTP > 0 ? " (+$extraTP% extra)" : "") . "\n";
                continue;
            }
            
            if ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= $slDesdePeak) {
                $razonSalida = ($cambio > 0) ? 'save_tp' : 'sl';
                $emojiSalida = ($cambio > 0) ? '⚠️ Save TP' : '🔚 SL';
                $razonMsg = ($cambio > 0) 
                    ? 'Save TP: estaba en +' . round($cambio, 2) . '%, cayó ' . round($cambioDesdePeak, 2) . '%'
                    : 'Stop Loss: ' . round($cambioDesdePeak, 2) . '% from peak (peak: +' . round((($precioMaximo / $precioEntrada) - 1) * 100, 2) . '%)';
                
                try {
                    registrarCoinRevisada(
                        $pdo, $token['pair_address'], $token['chain_id'],
                        $token['nombre'], $precioActual,
                        $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                        $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                        $pairData['priceChange']['h24'] ?? 0,
                        $razonSalida, $razonMsg
                    );
                    marcarExit($pdo, $token['id'], $precioActual, $razonSalida, $cambio);
                } catch (Exception $e) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR " . $emojiSalida . ": " . $e->getMessage() . "\n";
                }
echo "[" . date('Y-m-d H:i:s') . "] $emojiSalida: " . $token['nombre'] . " (drop " . round($cambioDesdePeak, 2) . "% from peak, SL: $slDesdePeak%)\n";
                continue;
            }

        $precioMaximo = (float)$token['precio_maximo'];
        if ($precioActual > $precioMaximo) {
            $precioMaximo = $precioActual;
        }

        $caidaDesdePico = $precioMaximo > 0 ? (($precioActual / $precioMaximo) - 1) * 100 : 0;

        if ($caidaDesdePico <= -8) {
            try {
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'exit', 'Caída desde pico: ' . round($caidaDesdePico, 2) . '%'
                );
                $profitDesdeEntrada = (($precioActual / $precioEntrada) - 1) * 100;
                marcarExit($pdo, $token['id'], $precioActual, 'caida_pico', round($profitDesdeEntrada, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXIT: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] 🔚 SL (Caída Pico): " . $token['nombre'] . " (" . round($caidaDesdePico, 2) . "% desde $" . round($precioMaximo, 8) . ")\n";
            continue;
        }

        // Verificar si lleva más de 90 min sin variar ±5%
        $tiempoMinutos = (time() - strtotime($token['primer_check'])) / 60;
        $variacion = abs($cambio);
        if ($tiempoMinutos > 90 && $variacion < 5) {
            try {
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    'exit', 'Sin variación en 90+ min: ' . round($cambio, 2) . '%'
                );
                marcarExit($pdo, $token['id'], $precioActual, 'expirado', round($cambio, 2));
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] ERROR EXPIRADO: " . $e->getMessage() . "\n";
            }
            echo "[" . date('Y-m-d H:i:s') . "] ⏰ EXPIRADO (sin variación): " . $token['nombre'] . " ({$tiempoMinutos} min, {$cambio}%)\n";
            continue;
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
                $precioActual,
                $precioMaximo,
                $pairData['marketCap'] ?? 0,
                $pairData['liquidity']['usd'] ?? 0,
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
    
    // Si nunca entró (sin fecha_ingreso), no agregar al historial ni contar en stats
    if (!$fechaIngreso) {
        $pdo->exec("DELETE FROM tokens WHERE id = $tokenId");
        echo "[" . date('Y-m-d H:i:s') . "] 🗑️ Eliminado: " . $token['nombre'] . " (nunca entró, razón: $razon)\n";
        return;
    }
    
    // Determinar el tag según la razón de salida
    $tag = null;
    switch ($razon) {
        case 'tp':
            $tag = '💪STRONG';
            break;
        case 'ban':
            $tag = '⛔DESTROYED';
            break;
        case 'sl':
        case 'caida_pico':
            $tag = '🧐CHECKING';
            break;
        case 'save_tp':
            $tag = '👍OKAY';
            break;
        default:
            $tag = null;
    }
    
    // Actualizar contadores de tags por nombre
    if ($tag) {
        updateTagCounts($pdo, $token['nombre'], $tag);
    }
    
    // Calcular duración en minutos usando fechas SQL (TIMESTAMPDIFF)
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
            nombre, simbolo, precio_entrada, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida, tag,
            es_reentry, fecha_entrada, fecha_salida
        ) VALUES (:id, :chain, :token_addr, :pair_addr, :nombre, :simbolo, :entrada, :salida, :profit, :duracion, :razon, :tag, :es_reentry, :fecha_entrada, :fecha_salida)
    ")->execute([
        ':id' => $tokenId,
        ':chain' => $token['chain_id'],
        ':token_addr' => $token['token_address'],
        ':pair_addr' => $token['pair_address'],
        ':nombre' => $token['nombre'],
        ':simbolo' => $token['simbolo'],
        ':entrada' => $token['precio_entrada'],
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
    $destroyed = $existing['destroyed_count'] ?? 0;
    $checking = $existing['checking_count'] ?? 0;
    $okay = $existing['okay_count'] ?? 0;
    
    switch ($tag) {
        case '💪STRONG':
            $strong++;
            break;
        case '⛔DESTROYED':
            $destroyed++;
            if ($checking > 0) $checking--;
            break;
        case '🧐CHECKING':
            $checking++;
            break;
        case '👍OKAY':
            $okay++;
            if ($checking > 0) $checking--;
            break;
    }
    
    if ($existing) {
        $pdo->prepare("UPDATE coins_tags SET strong_count = ?, destroyed_count = ?, checking_count = ?, okay_count = ?, ultimo_tag = ? WHERE nombre_normalizado = ?")
            ->execute([$strong, $destroyed, $checking, $okay, $tag, $nombreNorm]);
    } else {
        $pdo->prepare("INSERT INTO coins_tags (nombre_normalizado, strong_count, destroyed_count, checking_count, okay_count, ultimo_tag) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$nombreNorm, $strong, $destroyed, $checking, $okay, $tag]);
    }
    
    return ['strong' => $strong, 'destroyed' => $destroyed, 'checking' => $checking, 'okay' => $okay];
}

function getEntryRequirement($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) return 1.5;
    
    $destroyed = $counts['destroyed_count'] ?? 0;
    $checking = $counts['checking_count'] ?? 0;
    
    if ($destroyed > 0 || $checking > 0) {
        return 3.0;
    }
    
    return 1.5;
}

function getSLForToken($pdo, $nombre, $cambioDesdeEntrada) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) {
        return ($cambioDesdeEntrada >= 12) ? -6 : -3;
    }
    
    $destroyed = $counts['destroyed_count'] ?? 0;
    
    if ($destroyed > 0) {
        return -3;
    }
    
    return ($cambioDesdeEntrada >= 12) ? -6 : -3;
}

function shouldBanName($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) return false;
    
    return ($counts['destroyed_count'] ?? 0) >= 2;
}

function getExtraTP($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    
    if (!$counts) return 0;
    
    $strong = $counts['strong_count'] ?? 0;
    return min($strong * 6, 60);
}