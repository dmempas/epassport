<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/penalty_helpers.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

try {
    $userId = (int) $_SESSION['user_id'];
    $activeEvent = getActiveEvent($conn);
    $progress = getStudentEventAttendanceProgress($conn, $userId, $activeEvent);
    $penalties = getStudentPenaltySummary($conn, $userId);

    echo json_encode([
        'success' => true,
        'progress' => $progress,
        'penalties' => $penalties,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
