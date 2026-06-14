<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/shared.php';

$pidFile = __DIR__ . '\alpha.pid';
file_put_contents($pidFile, getmypid());

echo "[" . date('Y-m-d H:i:s') . "] Starting AltiChecker Search Server...\n";
echo "[" . date('Y-m-d H:i:s') . "] PID: " . getmypid() . "\n";
echo "DB connected: " . (isset($pdo) ? "YES" : "NO") . "\n";

updateServerStatus(true);
$pdo->query("UPDATE servidor_status SET ultimo_inicio = NOW() WHERE id = 1");
logSistema('info', 'Servidor de busqueda iniciado');

$tpPorcentaje = (float)getConfig('tp_porcentaje') ?: 40;
$tpReentry = (float)getConfig('tp_reentry_porcentaje') ?: 20;
$slPorcentaje = (float)getConfig('sl_porcentaje') ?: 10;
$reentryMin = (float)getConfig('reentry_subida_min') ?: 5;
$crashPorcentaje = (float)getConfig('crash_porcentaje') ?: -4000;

while (true) {
    try {
        $pdo->query("UPDATE servidor_status SET ultimo_check = NOW() WHERE id = 1");

        if (!isSearchRunning()) {
            echo "[" . date('Y-m-d H:i:s') . "] === SEARCH CYCLE ===\n";
            echo "[" . date('Y-m-d H:i:s') . "] 1. Launching background token search...\n";

            $phpCmd = 'C:\xampp\php\php.exe';
            $scriptPath = __DIR__ . '\buscador_tokens.php';
            $logPath = __DIR__ . '\buscador.log';
            pclose(popen("start /B \"\" \"$phpCmd\" \"$scriptPath\" > \"$logPath\" 2>&1", "r"));

        }

        echo "[" . date('Y-m-d H:i:s') . "] 2. Processing manual coins...\n";
        procesarManualCoins($pdo);

        echo "[" . date('Y-m-d H:i:s') . "] 3. Checking new tokens for entry...\n";
        procesarNuevosTokens($pdo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje);

        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tokens WHERE estado = 'monitoreando'");
        $count = $stmt->fetch();
        $pdo->query("UPDATE servidor_status SET tokens_activos = " . $count['count'] . " WHERE id = 1");

        try {
            $expiredCount = expireStaleSignals($pdo);
            if ($expiredCount > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Expired $expiredCount stale signals\n";
            }
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Signal expiry skipped: " . $e->getMessage() . "\n";
        }

    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        logSistema('error', 'Error en servidor busqueda: ' . $e->getMessage());
    }

    sleep(5);
}

function isSearchRunning() {
    $lockFile = __DIR__ . '/.search_lock';
    if (!file_exists($lockFile)) return false;
    if (time() - filemtime($lockFile) > 120) {
        @unlink($lockFile);
        return false;
    }
    return true;
}
