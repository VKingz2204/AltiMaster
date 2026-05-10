<?php
require_once __DIR__ . '/../api/config.php';

$pdo->query("UPDATE servidor_status SET activo = 0 WHERE id = 1");

echo "Servidor detenido correctamente";