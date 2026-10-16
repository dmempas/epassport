<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

try {
    $userId = (int) $_SESSION['user_id'];
    $session = getStudentActiveMonitoringSession($conn, $userId);

    if (!$session) {
        echo json_encode([
            'success' => true,
            'monitoring' => false,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'monitoring' => true,
        'attendance_id' => (int) $session['attendance_id'],
        'event_id' => (int) $session['event_id'],
        'event_name' => $session['event_name'],
        'scan_type' => $session['scan_type'],
        'attendance_status' => $session['status'],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
