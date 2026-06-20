<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/shared.php';

$pidFile = __DIR__ . '\monitor.pid';
file_put_contents($pidFile, getmypid());

echo "[" . date('Y-m-d H:i:s') . "] Starting AltiChecker Monitor Server...\n";
echo "[" . date('Y-m-d H:i:s') . "] PID: " . getmypid() . "\n";
echo "DB connected: " . (isset($pdo) ? "YES" : "NO") . "\n";

logSistema('info', 'Monitor server iniciado');

$tpPorcentaje = (float)getConfig('tp_porcentaje') ?: 40;
$tpReentry = (float)getConfig('tp_reentry_porcentaje') ?: 20;
$slPorcentaje = (float)getConfig('sl_porcentaje') ?: 5;
$reentryMin = (float)getConfig('reentry_subida_min') ?: 5;
$crashPorcentaje = (float)getConfig('crash_porcentaje') ?: -4000;

while (true) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tokens WHERE estado = 'monitoreando'");
        $row = $stmt->fetch();
        $tokensActivos = (int)$row['count'];

        $intervalo = max(1.1, $tokensActivos * 1.1);

        echo "[" . date('Y-m-d H:i:s') . "] Monitoring $tokensActivos tokens (interval: " . round($intervalo, 1) . "s)...\n";

        monitorearActivos($pdo, $intervalo, $tpPorcentaje, $tpReentry, $slPorcentaje, $reentryMin, $crashPorcentaje);

        $pdo->query("UPDATE servidor_status SET ultimo_check = NOW(), tokens_activos = $tokensActivos WHERE id = 1");

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
        logSistema('error', 'Error en monitor: ' . $e->getMessage());
    }

    sleep(1);
}
