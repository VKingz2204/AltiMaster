<?php
require_once __DIR__ . '/../api/config.php';

$pdo->query("UPDATE servidor_status SET activo = 0 WHERE id = 1");

$pidDir = __DIR__;
$pidFiles = [$pidDir . '\monitor.pid', $pidDir . '\alpha.pid'];

$killed = 0;
foreach ($pidFiles as $pidFile) {
    if (file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        if ($pid) {
            exec("taskkill /PID $pid /F 2>nul", $output, $exitCode);
            if ($exitCode === 0) {
                echo "Killed PID $pid\n";
                $killed++;
            }
        }
        @unlink($pidFile);
    }
}

if ($killed === 0) {
    echo "No PID files found. Attempting to kill all php.exe processes with Alpha.php or monitor.php...\n";
    exec("taskkill /F /FI \"WINDOWTITLE eq AltiChecker-*\" 2>nul");
}

echo "Servidor detenido correctamente\n";
