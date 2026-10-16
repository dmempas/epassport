<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/tracking_helpers.php';

header('Content-Type: application/json');

$attendanceId = (int) ($_GET['attendance_id'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if ($attendanceId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'attendance_id is required.',
    ]);
    exit;
}

if ($dateFrom === '') {
    $dateFrom = date('Y-m-d');
}

if ($dateTo === '') {
    $dateTo = $dateFrom;
}

try {
    $db = new Database();
    $conn = $db->connect();

    $detail = fetchStudentTrackingDetail($conn, $attendanceId, $dateFrom, $dateTo);

    if (!$detail) {
        echo json_encode([
            'success' => false,
            'message' => 'Attendance record not found.',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'detail' => $detail,
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
