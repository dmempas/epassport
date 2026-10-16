<?php

require_once '../includes/session.php';
requireLogin();

require_once '../config/database.php';
require_once '../includes/background_jobs.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $db = new Database();
    $conn = $db->connect();
    $roleId = (int) ($_SESSION['role_id'] ?? 0);
    $forceFallback = $roleId === 1 || $roleId === 3;
    $result = runEpassportBackgroundJobs($conn, $forceFallback);

    if ($forceFallback) {
        runEpassportAttendanceFallback($conn);
    }

    echo json_encode([
        'success' => true,
        'skipped' => (bool) ($result['skipped'] ?? false),
        'expired_events' => (int) ($result['expired_events'] ?? 0),
        'unscanned_marked_absent' => (int) ($result['unscanned_marked_absent'] ?? 0),
        'outside_warnings_sent' => (int) ($result['outside_warnings_sent'] ?? 0),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Background jobs failed.',
    ]);
}
