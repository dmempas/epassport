<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/monitoring_helpers.php';
require_once '../includes/audit_helpers.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $action = trim($_POST['action'] ?? '');
    $monitoringId = (int) ($_POST['monitoring_id'] ?? 0);
    $adminUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'review_location_jump') {
        $result = reviewLocationJumps($conn, (int) ($_POST['attendance_id'] ?? 0), $adminUserId);
        echo json_encode([
            'success' => $result['ok'],
            'message' => $result['message'],
        ]);
        exit;
    }

    if ($monitoringId <= 0) {
        throw new Exception('Invalid monitoring session.');
    }

    if ($action === 'void_warning') {
        $result = voidMonitoringWarning($conn, $monitoringId, $adminUserId);
        if ($result['ok']) {
            logAudit($conn, 'monitoring_void_warning', 'monitoring_sessions', $monitoringId);
        }
    } elseif ($action === 'void_penalty') {
        $result = voidMonitoringPenalty($conn, $monitoringId, $adminUserId);
        if ($result['ok']) {
            logAudit(
                $conn,
                'monitoring_void_penalty',
                'monitoring_sessions',
                $monitoringId,
                'Admin voided monitoring penalty and restored attendance'
            );
        }
    } elseif ($action === 'absent_penalize') {
        $result = penalizeMonitoringViolation($conn, $monitoringId, $adminUserId);
        if ($result['ok']) {
            logAudit($conn, 'monitoring_penalize', 'monitoring_sessions', $monitoringId);
        }
    } else {
        throw new Exception('Unknown action.');
    }

    echo json_encode([
        'success' => $result['ok'],
        'message' => $result['message'],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
