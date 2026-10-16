<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/admin_export_helpers.php';

$db = new Database();
$conn = $db->connect();

$status = trim($_GET['status'] ?? '');
$scheduleType = trim($_GET['schedule_type'] ?? '');
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'status' => $status,
    'schedule_type' => $scheduleType,
    'q' => $searchQuery,
];

try {
    logAudit($conn, 'export_events_pdf', 'events', null, 'Events PDF export');

    exportEventsPdf($conn, $filters);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    echo 'Report error: ' . $e->getMessage();
}
