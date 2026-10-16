<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/student_helpers.php';

$db = new Database();
$conn = $db->connect();

ensurePenaltyResolutionSchema($conn);

$eventId = (int) ($_GET['event_id'] ?? 0);
$course = trim($_GET['course'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$status = normalizePenaltyStatusFilter((string) ($_GET['status'] ?? $_GET['settlement_status'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'event_id' => $eventId,
    'course' => $course,
    'year_level' => $yearLevel,
    'status' => $status,
    'q' => $searchQuery,
];

$eventName = 'All Events';

if ($eventId > 0) {
    $event = fetchEventById($conn, $eventId);
    if (!$event) {
        header('Content-Type: text/plain; charset=utf-8', true, 400);
        echo 'Report error: Event not found.';
        exit;
    }
    $eventName = $event['event_name'];
}

$meta = [
    'Event' => $eventName,
    'Course' => $course !== '' ? $course : 'All courses',
    'Year Level' => $yearLevel !== '' ? $yearLevel : 'All year levels',
];

if ($status !== '') {
    $meta['Status'] = $status === 'settled' ? 'Settled' : 'Unsettled';
}

try {
    logAudit(
        $conn,
        'export_penalties_pdf',
        'penalties',
        $eventId > 0 ? $eventId : null,
        'Penalty management PDF for ' . $eventName
    );

    exportPenaltiesPdf($conn, $filters, 'Penalty Management — ' . $eventName, $meta);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    echo 'Report error: ' . $e->getMessage();
}
