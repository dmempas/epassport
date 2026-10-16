<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request');
    }

    $eventId = (int) ($_POST['realtime_event_id'] ?? 0);
    $userId = (int) $_SESSION['user_id'];

    if ($eventId <= 0) {
        echo json_encode(['success' => true]);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE realtime_events
        SET delivered_at = NOW()
        WHERE event_id = ?
          AND user_id = ?
          AND event_type = 'logout_reminder'
          AND delivered_at IS NULL
    ");
    $stmt->execute([$eventId, $userId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
