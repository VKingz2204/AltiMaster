<?php
require_once __DIR__ . '/../api/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Running backup update...\n";

$monitoreoIntervalo = (int)getConfig('monitoreo_intervalo') ?: 15;
$tpPorcentaje = (float)getConfig('tp_porcentaje') ?: 40;
$tpReentry = (float)getConfig('tp_reentry_porcentaje') ?: 20;
$slPorcentaje = (float)getConfig('sl_porcentaje') ?: 10;
$reentryMin = (float)getConfig('reentry_subida_min') ?: 5;
$busquedaIntervalo = (int)getConfig('busqueda_intervalo') ?: 180;

$lastSearch = getConfig('last_search_time') ?? 0;
$ahora = time();

if (($ahora - $lastSearch) >= $busquedaIntervalo) {
    echo "[" . date('Y-m-d H:i:s') . "] Full search...\n";
    buscarNuevosTokens($pdo, $tpPorcentaje);
    updateConfig('last_search_time', $ahora);
}

monitoreoTokensActivos($pdo, $monitoreoIntervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin);

$stmt = $pdo->query("SELECT COUNT(*) as count FROM tokens WHERE estado = 'monitoreando'");
$count = $stmt->fetch();
$pdo->query("UPDATE servidor_status SET ultimo_check = NOW(), tokens_activos = " . $count['count'] . " WHERE id = 1");

echo "[" . date('Y-m-d H:i:s') . "] Done. Active tokens: " . $count['count'] . "\n";

function buscarNuevosTokens($pdo, $tpPorcentaje) {
    $endpoints = [
        'https://api.dexscreener.com/token-profiles/latest/v1',
        'https://api.dexscreener.com/community-takeovers/latest/v1',
        'https://api.dexscreener.com/ads/latest/v1',
        'https://api.dexscreener.com/token-boosts/latest/v1',
        'https://api.dexscreener.com/token-boosts/top/v1'
    ];

    foreach ($endpoints as $url) {
        try {
            $response = @file_get_contents($url);
            if (!$response) continue;

            $data = json_decode($response, true);
            if (!$data) continue;

            $items = $data['pairs'] ?? $data ?? [];
            if (isset($data['profiles'])) $items = $data['profiles'];
            if (isset($data['takeovers'])) $items = $data['takeovers'];
            if (isset($data['ads'])) $items = $data['ads'];
            if (isset($data['tokenBoosts'])) $items = $data['tokenBoosts'];
            if (!is_array($items)) $items = [$items];

            foreach ($items as $item) {
                $chainId = $item['chainId'] ?? $item['chain_id'] ?? null;
                $tokenAddress = $item['tokenAddress'] ?? $item['token_address'] ?? null;

                if (!$chainId || !$tokenAddress) continue;

                $pairData = obtenerDatosPair($chainId, $tokenAddress);
                if (!$pairData) continue;

                $pair = $pairData['pairAddress'] ?? null;
                if (!$pair) continue;

                $stmt = $pdo->prepare("SELECT id FROM tokens_banned WHERE pair_address = ?");
                $stmt->execute([$pair]);
                if ($stmt->fetch()) continue;

                $stmt = $pdo->prepare("SELECT id FROM tokens WHERE pair_address = ?");
                $stmt->execute([$pair]);
                if ($stmt->fetch()) continue;

                $stmt = $pdo->prepare("
                    INSERT INTO tokens (
                        chain_id, token_address, pair_address,
                        nombre, simbolo, precio_actual, precio_entrada,
                        market_cap, liquidez, cambio_1h, cambio_6h, cambio_24h,
                        estado, meta_tp, tp_alcanzado, sl_alcanzado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nuevo', ?, 0, 0)
                ");
                $stmt->execute([
                    $chainId, $tokenAddress, $pair,
                    $pairData['baseToken']['name'] ?? null,
                    $pairData['baseToken']['symbol'] ?? null,
                    $pairData['priceUsd'] ?? 0,
                    $pairData['priceUsd'] ?? 0,
                    $pairData['marketCap'] ?? 0,
                    $pairData['liquidity']['usd'] ?? 0,
                    $pairData['priceChange']['h1'] ?? 0,
                    $pairData['priceChange']['h6'] ?? 0,
                    $pairData['priceChange']['h24'] ?? 0,
                    $tpPorcentaje
                ]);

                $nuevoId = $pdo->lastInsertId();
                $pdo->query("UPDATE tokens SET estado = 'monitoreando' WHERE id = $nuevoId");

                actualizarTokenFree($pdo, $nuevoId);

                echo "[" . date('Y-m-d H:i:s') . "] New token: " . ($pairData['baseToken']['name'] ?? $pairData['baseToken']['symbol']) . "\n";
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

function obtenerDatosPair($chainId, $tokenAddress) {
    $url = "https://api.dexscreener.com/latest/dex/pairs/$chainId/$tokenAddress";
    $response = @file_get_contents($url);
    if (!$response) return null;

    $data = json_decode($response, true);
    return $data['pair'] ?? null;
}

function monitoreoTokensActivos($pdo, $intervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE estado = 'monitoreando'");
    $tokens = $stmt->fetchAll();

    foreach ($tokens as $token) {
        $pairData = obtenerDatosPair($token['chain_id'], $token['pair_address']);
        if (!$pairData) continue;

        $precioActual = (float)($pairData['priceUsd'] ?? 0);
        if ($precioActual <= 0) continue;

        $precioEntrada = (float)$token['precio_entrada'];
        $esReentry = (bool)$token['es_reentry'];
        $metaTP = $esReentry ? $tpReentry : $tpPorcentaje;

        $cambio = (($precioActual / $precioEntrada) - 1) * 100;

        if ($precioEntrada > 0) {
            if ($cambio >= $metaTP) {
                marcarExit($pdo, $token['id'], $precioActual, 'tp', $cambio);
                echo "[" . date('Y-m-d H:i:s') . "] TP: " . $token['nombre'] . " (+" . round($cambio, 2) . "%)\n";
                continue;
            }

            if ($cambio <= -$slPorcentaje) {
                $pdo->prepare("UPDATE tokens SET sl_alcanzado = 1, precio_crash = ? WHERE id = ?")
                    ->execute([$precioActual, $token['id']]);
            }

            $precioCrash = $token['precio_crash'] ? (float)$token['precio_crash'] : null;
            if ($precioCrash) {
                $cambioCrash = (($precioActual / $precioCrash) - 1) * 100;
                if ($cambioCrash >= $reentryMin && $token['reentry_count'] < 2) {
                    $nuevoReentry = $token['reentry_count'] + 1;
                    $pdo->prepare("UPDATE tokens SET es_reentry = 1, reentry_count = ?, meta_tp = ?, precio_crash = NULL WHERE id = ?")
                        ->execute([$nuevoReentry, $tpReentry, $token['id']]);
                }
            }
        }

        $pdo->prepare("UPDATE tokens SET precio_actual = ?, market_cap = ?, liquidez = ?, cambio_1h = ?, cambio_6h = ?, cambio_24h = ?, checks_count = checks_count + 1, ultimo_check = NOW() WHERE id = ?")
            ->execute([
                $precioActual,
                $pairData['marketCap'] ?? 0,
                $pairData['liquidity']['usd'] ?? 0,
                $pairData['priceChange']['h1'] ?? 0,
                $pairData['priceChange']['h6'] ?? 0,
                $pairData['priceChange']['h24'] ?? 0,
                $token['id']
            ]);
    }
}

function marcarExit($pdo, $tokenId, $precioSalida, $razon, $profit) {
    $stmt = $pdo->query("SELECT * FROM tokens WHERE id = $tokenId");
    $token = $stmt->fetch();

    $duracion = time() - strtotime($token['primer_check']);
    $duracionMinutos = floor($duracion / 60);

    $pdo->prepare("
        INSERT INTO historial_tokens (
            id_token_original, chain_id, token_address, pair_address,
            nombre, simbolo, precio_entrada, precio_salida,
            profit_porcentaje, duracion_minutos, razon_salida,
            es_reentry, fecha_entrada
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $tokenId, $token['chain_id'], $token['token_address'], $token['pair_address'],
        $token['nombre'], $token['simbolo'], $token['precio_entrada'], $precioSalida,
        $profit, $duracionMinutos, $razon, $token['es_reentry'], $token['primer_check']
    ]);

    $pdo->prepare("UPDATE tokens SET estado = 'exit', ultimo_check = NOW() WHERE id = ?")
        ->execute([$tokenId]);
}

function actualizarTokenFree($pdo, $nuevoTokenId) {
    $pdo->query("UPDATE tokens_free SET activo = 0 WHERE activo = 1");

    $horas = (int)getConfig('free_cambio_horas') ?: 6;
    $mostrarHasta = date('Y-m-d H:i:s', time() + ($horas * 3600));

    $pdo->prepare("INSERT INTO tokens_free (id_token, mostrar_hasta, activo) VALUES (?, ?, 1)")
        ->execute([$nuevoTokenId, $mostrarHasta]);
}