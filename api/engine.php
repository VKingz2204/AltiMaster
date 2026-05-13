<?php
/**
 * Signal Engine & Profit Tracker
 * Internal engine for creating/expiring signals and tracking daily profit limits.
 */

require_once __DIR__ . '/config.php';

/**
 * Get daily profit limit percentage by plan.
 */
function getDailyLimit($plan) {
    switch ($plan) {
        case 'basic': return 15.0;
        case 'pro':   return 30.0;
        case 'ultra': return null; // no limit
        default:      return 15.0;
    }
}

/**
 * Get or create a daily_profit_tracker record for (user_id, today).
 * Returns the record with accumulated_pct.
 */
function getDailyTracker($pdo, $userId) {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM daily_profit_tracker WHERE user_id = ? AND date = ?");
    $stmt->execute([$userId, $today]);
    $record = $stmt->fetch();

    if (!$record) {
        $pdo->prepare("INSERT INTO daily_profit_tracker (user_id, date, accumulated_pct) VALUES (?, ?, 0.0)")
            ->execute([$userId, $today]);
        $stmt->execute([$userId, $today]);
        $record = $stmt->fetch();
    }

    return $record;
}

/**
 * Accumulate profit for a user for today. Returns the updated tracker record.
 */
function trackProfit($pdo, $userId, $profitPct) {
    $today = date('Y-m-d');
    $pdo->prepare("
        INSERT INTO daily_profit_tracker (user_id, date, accumulated_pct)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE accumulated_pct = accumulated_pct + VALUES(accumulated_pct)
    ")->execute([$userId, $today, $profitPct]);

    return getDailyTracker($pdo, $userId);
}

/**
 * Check if a user has reached their daily profit limit.
 * Returns false if no limit (ultra/admin), or true/false.
 */
function isDailyLimitReached($pdo, $userId, $plan) {
    $limit = getDailyLimit($plan);
    if ($limit === null) return false; // ultra = no limit

    $tracker = getDailyTracker($pdo, $userId);
    return (float)$tracker['accumulated_pct'] >= $limit;
}

/**
 * Get the user's plan (treating admin as ultra).
 * Returns array with plan and is_admin.
 */
function getUserPlanData($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT plan, is_admin FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) return null;
    $effectivePlan = $u['plan'];
    if ((bool)$u['is_admin']) $effectivePlan = 'ultra';
    return ['plan' => $effectivePlan, 'is_admin' => (bool)$u['is_admin']];
}

/**
 * Create a signal for a user with a snapshot of their current criteria.
 * Returns the signal ID or null on failure.
 */
function createSignal($pdo, $userId, $contract, $entryPrice, $direction = 'long') {
    $planData = getUserPlanData($pdo, $userId);
    if (!$planData) return null;

    // Get user's current criteria for snapshot
    $stmt = $pdo->prepare("SELECT * FROM system_criteria WHERE user_id = ?");
    $stmt->execute([$userId]);
    $criteria = $stmt->fetch();
    if (!$criteria) return null;

    $snapshot = json_encode([
        'stop_loss_pct' => (float)$criteria['stop_loss_pct'],
        'take_profit_pct' => (float)$criteria['take_profit_pct'],
        'max_wait_minutes' => (int)$criteria['max_wait_minutes'],
        'save_profit_pct' => (float)$criteria['save_profit_pct'],
        'min_entry_pct' => (float)$criteria['min_entry_pct']
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO signals (user_id, contract, direction, entry_price, criteria_snapshot)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $contract, $direction, $entryPrice, $snapshot]);

    return $pdo->lastInsertId();
}

/**
 * Expire signals that have exceeded max_wait_minutes since creation.
 * Returns the number of signals expired.
 */
function expireStaleSignals($pdo) {
    try {
        $stmt = $pdo->query("
            UPDATE signals
            SET status = 'expired'
            WHERE status = 'active'
                AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) >= COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(criteria_snapshot, '$.max_wait_minutes')),
                    60
                )
        ");
        return $stmt->rowCount();
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ⚠ expireStaleSignals: " . $e->getMessage() . "\n";
        return 0;
    }
}

/**
 * Get active signals for a user, checking daily limit.
 * Returns array with signals and limit info.
 */
function getActiveSignals($pdo, $userId) {
    $planData = getUserPlanData($pdo, $userId);
    $plan = $planData['plan'] ?? 'basic';

    // Check daily limit
    $tracker = getDailyTracker($pdo, $userId);
    $limit = getDailyLimit($plan);
    $accumulated = (float)$tracker['accumulated_pct'];
    $limitReached = $limit !== null && $accumulated >= $limit;

    $signals = [];
    if (!$limitReached) {
        $stmt = $pdo->prepare("
            SELECT id, contract, direction, entry_price, criteria_snapshot, created_at
            FROM signals
            WHERE user_id = ? AND status = 'active'
            ORDER BY created_at ASC
            LIMIT 10
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $signals[] = [
                'id' => $r['id'],
                'contract' => $r['contract'],
                'direction' => $r['direction'],
                'entry_price' => (float)$r['entry_price'],
                'criteria' => json_decode($r['criteria_snapshot'], true),
                'created_at' => $r['created_at']
            ];
        }
    }

    return [
        'signals' => $signals,
        'daily_limit_reached' => $limitReached,
        'accumulated_pct' => $accumulated,
        'limit_pct' => $limit ?? 0.0
    ];
}

/**
 * Close a signal with profit data. Returns updated tracker.
 */
function closeSignal($pdo, $signalId, $userId, $profitPct, $closeReason) {
    $pdo->prepare("
        UPDATE signals SET status = 'closed', closed_at = NOW() WHERE id = ? AND user_id = ?
    ")->execute([$signalId, $userId]);

    return trackProfit($pdo, $userId, $profitPct);
}
