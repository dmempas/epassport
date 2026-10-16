<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/audit_helpers.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $scanId = (int) ($_POST['scan_id'] ?? 0);
    $minutes = (int) ($_POST['minutes'] ?? 15);

    if ($scanId <= 0) {
        throw new Exception('Invalid scan window.');
    }

    if (!in_array($minutes, [15, 30, 45, 60], true)) {
        $minutes = 15;
    }

    $rule = fetchScanRuleById($conn, $scanId);

    if (!$rule) {
        throw new Exception('Scan window not found.');
    }

    $event = fetchEventById($conn, (int) $rule['event_id']);

    if (!$event) {
        throw new Exception('Event not found.');
    }

    if (!isEventSetupLocked($event)) {
        throw new Exception('Scan windows can only be extended while the event is active. Use the setup wizard to edit inactive events.');
    }

    $oldEnd = $rule['end_time'];
    $newEndTs = strtotime($oldEnd) + ($minutes * 60);
    $newEnd = date('H:i:s', $newEndTs);

    $stmt = $conn->prepare("
        UPDATE event_scan_requirements
        SET end_time = ?
        WHERE scan_id = ?
    ");
    $stmt->execute([$newEnd, $scanId]);

    logAudit(
        $conn,
        'extend_scan_window',
        'event_scan_requirements',
        $scanId,
        formatScanTypeLabel($rule['scan_type']) . ': ' . formatAppTime12($oldEnd) . ' → ' . formatAppTime12($newEnd) . " (+{$minutes} min)"
    );

    echo json_encode([
        'success' => true,
        'message' => formatScanTypeLabel($rule['scan_type']) . ' window extended by ' . $minutes . ' minutes.',
        'scan_type' => $rule['scan_type'],
        'end_time' => substr($newEnd, 0, 5),
        'end_time_label' => formatAppTime12($newEnd),
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
