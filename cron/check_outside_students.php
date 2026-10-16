<?php

/**
 * Optional CLI backup. The same jobs also run automatically while the website is open.
 * Example: php C:\xampp\htdocs\E-PASSPORT\cron\check_outside_students.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/background_jobs.php';

$db = new Database();
$conn = $db->connect();

try {
    $result = runEpassportBackgroundJobs($conn, true);
    echo 'Expired events: ' . (int) $result['expired_events'] . ".\n";
    echo 'Unscanned students marked absent: ' . (int) $result['unscanned_marked_absent'] . ".\n";
    echo 'SMS monitoring executed. Warnings sent: ' . (int) $result['outside_warnings_sent'] . ".\n";
    echo 'No-signal warnings sent: ' . (int) ($result['silent_warnings_sent'] ?? 0) . ".\n";
    echo 'No-signal penalties: ' . (int) ($result['silent_violations'] ?? 0) . ".\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
