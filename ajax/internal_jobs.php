<?php

/**
 * Local-only job tick for Apache/Node. Does not open a console window.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (!in_array($remote, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/background_jobs.php';

try {
    $db = new Database();
    $conn = $db->connect();
    $result = runEpassportBackgroundJobs($conn, true);

    echo json_encode([
        'success' => true,
        'expired_events' => (int) ($result['expired_events'] ?? 0),
        'unscanned_marked_absent' => (int) ($result['unscanned_marked_absent'] ?? 0),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false]);
}
