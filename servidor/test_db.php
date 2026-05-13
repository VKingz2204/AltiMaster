<?php
require_once 'C:\xampp\htdocs\api\config.php';

echo "DB: " . DB_NAME . "\n";

// Check signals table
$stmt = $pdo->query("SHOW CREATE TABLE signals");
$row = $stmt->fetch();
echo "Signals CREATE:\n" . $row['Create Table'] . "\n\n";

// Test the exact query
try {
    $stmt = $pdo->query("
        UPDATE signals s
        JOIN system_criteria sc ON s.user_id = sc.user_id
        SET s.status = 'expired'
        WHERE s.status = 'active'
            AND TIMESTAMPDIFF(MINUTE, s.created_at, NOW()) >= sc.max_wait_minutes
    ");
    echo "Query OK, rows: " . $stmt->rowCount() . "\n";
} catch (Exception $e) {
    echo "Query ERROR: " . $e->getMessage() . "\n";
}

// Check if engine.php functions work
require_once 'C:\xampp\htdocs\api\engine.php';
try {
    $count = expireStaleSignals($pdo);
    echo "expireStaleSignals() OK: $count\n";
} catch (Exception $e) {
    echo "expireStaleSignals() ERROR: " . $e->getMessage() . "\n";
}
