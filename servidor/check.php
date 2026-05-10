<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/../api/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting AltChecks Monitor (Check Process)...\n";
echo "DB connected: " . (isset($pdo) ? "YES" : "NO") . "\n";

$tpPorcentaje = (int)getConfig('tp_porcentaje') ?: 24;
$tpReentry = (int)getConfig('tp_reentry_porcentaje') ?: 20;
$reentryMin = (int)getConfig('reentry_subida_min') ?: 5;
$revisionIntervalo = 5;

$cycleCount = 0;

while (true) {
    try {
        $cycleCount++;
        
        echo "[" . date('Y-m-d H:i:s') . "] Check cycle $cycleCount...\n";
        
        processNewTokens($pdo);
        
        processCheckingTokens($pdo, $tpPorcentaje);
        
        processMonitoringTokens($pdo, $tpPorcentaje, $tpReentry, $reentryMin);
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tokens WHERE estado = 'monitoreando'");
        $count = $stmt->fetch();
        $pdo->query("UPDATE servidor_status SET tokens_activos = " . $count['count'] . " WHERE id = 1");
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    }
    
    sleep($revisionIntervalo);
}

function processNewTokens($pdo) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'new' ORDER BY fecha_registro ASC LIMIT 50");
    $tokens = $stmt->fetchAll();
    
    if (count($tokens) == 0) return;
    
    echo "[" . date('Y-m-d H:i:s') . "] Processing " . count($tokens) . " new tokens...\n";
    
    foreach ($tokens as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;
        
        $precioEntrada = (float)$token['precio_entrada'];
        
        $pdo->prepare("UPDATE tokens SET estado = 'checking', precio_actual = ?, last_check_price = ?, primer_check = NOW(), checks_count = 1 WHERE id = ?")
            ->execute([$precioActual, $precioActual, $token['id']]);
        
        echo "[" . date('Y-m-d H:i:s') . "] → Checking: " . $token['nombre'] . "\n";
    }
}

function processCheckingTokens($pdo, $tpPorcentaje) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'checking'");
    $tokens = $stmt->fetchAll();
    
    if (count($tokens) == 0) return;
    
    echo "[" . date('Y-m-d H:i:s') . "] Checking " . count($tokens) . " tokens for entry (+1.5%)...\n";
    
    foreach ($tokens as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;
        
        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;
        
        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        $reqEntrada = getEntryRequirement($pdo, $token['nombre']);
        
        if ($cambio >= $reqEntrada) {
            $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, last_check_price = ?, precio_maximo = ? WHERE id = ?")
                ->execute([
                    $precioActual,
                    $pairData['priceChange']['h1'] ?? 0,
                    $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    $precioActual,
                    $precioActual,
                    $token['id']
                ]);
            
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'entrada', 'Token entered monitoring (+' . round($cambio, 2) . '%)'
            );
            
            echo "[" . date('Y-m-d H:i:s') . "] 🚪 ENTERED: " . $token['nombre'] . " (+" . round($cambio, 2) . "%)\n";
            continue;
        }
        
        $lastCheckPrice = (float)$token['last_check_price'];
        if ($lastCheckPrice > 0) {
            $cambioCheck = abs((($precioActual / $lastCheckPrice) - 1) * 100);
            if ($cambioCheck < 3) {
                $timeoutCount = ($token['timeout_count'] ?? 0) + 1;
                $pdo->prepare("UPDATE tokens SET timeout_count = ?, precio_actual = ?, last_check_price = ?, checks_count = checks_count + 1 WHERE id = ?")
                    ->execute([$timeoutCount, $precioActual, $precioActual, $token['id']]);
                
                if ($timeoutCount >= 540) {
                    $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                    echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT: " . $token['nombre'] . " (540 cycles no change)\n";
                    continue;
                }
            } else {
                $pdo->prepare("UPDATE tokens SET timeout_count = 0, precio_actual = ?, last_check_price = ?, checks_count = checks_count + 1 WHERE id = ?")
                    ->execute([$precioActual, $precioActual, $token['id']]);
            }
        } else {
            $pdo->prepare("UPDATE tokens SET last_check_price = ?, precio_actual = ?, checks_count = checks_count + 1 WHERE id = ?")
                ->execute([$precioActual, $precioActual, $token['id']]);
        }
        
        $checksCount = ($token['checks_count'] ?? 0) + 1;
        if ($checksCount >= 60) {
            $laps = ($token['laps'] ?? 0) + 1;
            $pdo->prepare("UPDATE tokens SET laps = ?, checks_count = 0 WHERE id = ?")->execute([$laps, $token['id']]);
            
            echo "[" . date('Y-m-d H:i:s') . "] 🔄 Lap $laps/9: " . $token['nombre'] . "\n";
            
            if ($laps >= 15) {
                $razon = ($cambio > 0) ? 'save_tp' : 'sl';
                $tag = ($cambio > 0) ? 'OKAY' : 'CHECKING';
                $pdo->prepare("UPDATE tokens SET estado = 'monitoreando', fecha_ingreso = NOW(), precio_actual = ? WHERE id = ?")
                    ->execute([$precioActual, $token['id']]);
                registrarCoinRevisada(
                    $pdo, $token['pair_address'], $token['chain_id'],
                    $token['nombre'], $precioActual,
                    $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    $razon, 'Timeout ' . ($cambio > 0 ? 'in profit' : 'in loss')
                );
                echo "[" . date('Y-m-d H:i:s') . "] ⌛ TIMEOUT 900: " . $token['nombre'] . " ($tag, cambio: " . round($cambio, 2) . "%)\n";
                continue;
            }
            
            if ($laps >= 9) {
                banearToken($pdo, $token['token_address'], $token['pair_address'], $token['chain_id'], '9 laps without entry');
                $pdo->exec("DELETE FROM tokens WHERE id = " . $token['id']);
                echo "[" . date('Y-m-d H:i:s') . "] ⛔ BAN: " . $token['nombre'] . " (9 laps without entry)\n";
            }
        }
    }
}

function processMonitoringTokens($pdo, $tpPorcentaje, $tpReentry, $reentryMin) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();
    
    if (count($tokens) == 0) return;
    
    echo "[" . date('Y-m-d H:i:s') . "] Monitoring " . count($tokens) . " tokens...\n";
    
    foreach ($tokens as $token) {
        $tokenData = obtenerDatosToken($token['chain_id'], $token['token_address']);
        if (!$tokenData || !isset($tokenData[0])) continue;

        $pairData = $tokenData[0];
        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;

        $precioEntrada = (float)$token['precio_entrada'];
        $cambio = (($precioActual / $precioEntrada) - 1) * 100;
        
        $precioMaximo = (float)$token['precio_maximo'];
        if ($precioActual > $precioMaximo) {
            $precioMaximo = $precioActual;
            $pdo->prepare("UPDATE tokens SET precio_maximo = ? WHERE id = ?")
                ->execute([$precioMaximo, $token['id']]);
        }
        
        $cambioDesdePeak = $precioMaximo > 0 ? (($precioActual / $precioMaximo) - 1) * 100 : 0;
        
        $extraTP = getExtraTP($pdo, $token['nombre']);
        $tpFinal = 24 + $extraTP;
        
        if ($cambio >= $tpFinal) {
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'tp', 'Take Profit: +' . round($cambio, 2) . '%'
            );
            marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
            echo "[" . date('Y-m-d H:i:s') . "] ✅ TP {$tpFinal}%: " . $token['nombre'] . " (+" . round($cambio, 2) . "%)\n";
            continue;
        }
        
        $slDesdePeak = getSLForToken($pdo, $token['nombre'], $cambio);
        
        if ($precioMaximo > 0 && $precioActual < $precioMaximo && $cambioDesdePeak <= $slDesdePeak) {
            $razonSalida = ($cambio > 0) ? 'save_tp' : 'sl';
            $emojiSalida = ($cambio > 0) ? 'Save TP' : 'SL';
            
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                $razonSalida, "$emojiSalida: drop " . round($cambioDesdePeak, 2) . '% from peak'
            );
            marcarExit($pdo, $token['id'], $precioActual, $razonSalida, $cambio);
            echo "[" . date('Y-m-d H:i:s') . "] $emojiSalida: " . $token['nombre'] . " (drop " . round($cambioDesdePeak, 2) . "% from peak)\n";
            continue;
        }
        
        if ($cambioDesdePeak <= -8) {
            registrarCoinRevisada(
                $pdo, $token['pair_address'], $token['chain_id'],
                $token['nombre'], $precioActual,
                $pairData['marketCap'] ?? 0, $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0, $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                'exit', 'Drop from peak: ' . round($cambioDesdePeak, 2) . '%'
            );
            marcarExit($pdo, $token['id'], $precioActual, 'caida_pico', $cambio);
            echo "[" . date('Y-m-d H:i:s') . "] 🔚 SL (Drop): " . $token['nombre'] . " (" . round($cambioDesdePeak, 2) . "%)\n";
            continue;
        }
        
        $pdo->prepare("UPDATE tokens SET precio_actual = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, checks_count = checks_count + 1, ultimo_check = NOW() WHERE id = ?")
            ->execute([
                $precioActual,
                $pairData['priceChange']['h1'] ?? 0,
                $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                $token['id']
            ]);
    }
}

function obtenerDatosToken($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/tokens/v1/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;
    $data = json_decode($response, true);
    return $data;
}

function getEntryRequirement($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    if (!$counts) return 1.5;
    $destroyed = $counts['destroyed_count'] ?? 0;
    $checking = $counts['checking_count'] ?? 0;
    if ($destroyed > 0 || $checking > 0) return 3.0;
    return 1.5;
}

function getTagCounts($pdo, $nombre) {
    $nombreNorm = strtoupper(trim($nombre));
    $stmt = $pdo->prepare("SELECT * FROM coins_tags WHERE nombre_normalizado = ?");
    $stmt->execute([$nombreNorm]);
    return $stmt->fetch();
}

function getExtraTP($pdo, $nombre) {
    $counts = getTagCounts($pdo, $nombre);
    if (!$counts) return 0;
    $strong = $counts['strong_count'] ?? 0;
    return min($strong * 6, 60);
}

function getSLForToken($pdo, $nombre, $cambioDesdeEntrada) {
    $counts = getTagCounts($pdo, $nombre);
    if (!$counts) return ($cambioDesdeEntrada >= 12) ? -6 : -3;
    $destroyed = $counts['destroyed_count'] ?? 0;
    if ($destroyed > 0) return -3;
    return ($cambioDesdeEntrada >= 12) ? -6 : -3;
}

function banearToken($pdo, $tokenAddress, $pairAddress, $chainId, $razon) {
    $stmt = $pdo->prepare("INSERT INTO tokens_banned (token_address, pair_address, chain_id, razon, banneado_en) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$tokenAddress, $pairAddress, $chainId, $razon]);
}

function registrarCoinRevisada($pdo, $pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez, $cambio1h, $cambio6h, $cambio24h, $accion, $razon) {
    $stmt = $pdo->prepare("INSERT INTO coins_revisadas (pair_address, chain_id, nombre, precio, market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h, accion, razon, revisado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$pairAddress, $chainId, $nombre, $precio, $marketCap, $liquidez, $cambio1h, $cambio6h, $cambio24h, $accion, $razon]);
}

function marcarExit($pdo, $tokenId, $precioSalida, $razon, $profit) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE id = $tokenId");
    $token = $stmt->fetch();

    $fechaIngreso = $token['fecha_ingreso'] ?? null;
    
    if (!$fechaIngreso) {
        $pdo->exec("DELETE FROM tokens WHERE id = $tokenId");
        echo "[" . date('Y-m-d H:i:s') . "] Deleted: " . $token['nombre'] . " (never entered)\n";
        return;
    }
    
    $tag = null;
    switch ($razon) {
        case 'tp': $tag = 'STRONG'; break;
        case 'sl': $tag = 'DESTROYED'; break;
        case 'save_tp': $tag = 'OKAY'; break;
        case 'caida_pico': $tag = 'DESTROYED'; break;
    }
    
    if ($tag) {
        updateTagCounts($pdo, $token['nombre'], $tag);
    }
    
    $fechaSalidaDb = date('Y-m-d H:i:s');
    $duracionMinutos = (strtotime($fechaSalidaDb) - strtotime($fechaIngreso)) / 60;

    $pdo->prepare("INSERT INTO historial_tokens (
        id_token_original, chain_id, token_address, pair_address,
        nombre, simbolo, precio_entrada, precio_salida,
        profit_porcentaje, duracion_minutos, razon_salida, tag,
        es_reentry, fecha_entrada, fecha_salida
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
    ->execute([
        $tokenId,
        $token['chain_id'],
        $token['token_address'],
        $token['pair_address'],
        $token['nombre'],
        $token['simbolo'],
        $token['precio_entrada'],
        $precioSalida,
        $profit,
        (int)$duracionMinutos,
        $razon,
        $tag,
        $token['es_reentry'],
        $fechaIngreso
    ]);

    $pdo->exec("DELETE FROM tokens WHERE id = $tokenId");
    echo "[" . date('Y-m-d H:i:s') . "] 💾 Saved to history: " . $token['nombre'] . " (profit: {$profit}%)\n";
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
        case 'STRONG': $strong++; break;
        case 'DESTROYED': $destroyed++; if ($checking > 0) $checking--; break;
        case 'CHECKING': $checking++; break;
        case 'OKAY': $okay++; if ($checking > 0) $checking--; break;
    }
    
    if ($existing) {
        $pdo->prepare("UPDATE coins_tags SET strong_count = ?, destroyed_count = ?, checking_count = ?, okay_count = ?, ultimo_tag = ? WHERE nombre_normalizado = ?")
            ->execute([$strong, $destroyed, $checking, $okay, $tag, $nombreNorm]);
    } else {
        $pdo->prepare("INSERT INTO coins_tags (nombre_normalizado, strong_count, destroyed_count, checking_count, okay_count, ultimo_tag) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$nombreNorm, $strong, $destroyed, $checking, $okay, $tag]);
    }
}