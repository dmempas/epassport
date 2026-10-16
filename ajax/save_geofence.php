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

    $event_id = (int) ($_POST['event_id'] ?? 0);
    $lat = (float) ($_POST['lat'] ?? 0);
    $lng = (float) ($_POST['lng'] ?? 0);
    $radius = (int) ($_POST['radius'] ?? 0);

    if ($event_id <= 0) {
        throw new Exception("Invalid event");
    }

    if ($lat == 0.0 || $lng == 0.0 || $radius <= 0) {
        throw new Exception("Invalid geofence coordinates or radius");
    }

    $lock = rejectIfEventSetupLocked($conn, $event_id);

    if (!$lock['ok']) {
        throw new Exception($lock['errors'][0] ?? eventSetupLockedReason());
    }

    $stmt = $conn->prepare("
        UPDATE events
        SET geofence_latitude = ?,
            geofence_longitude = ?,
            geofence_radius = ?
        WHERE event_id = ?
    ");

    $stmt->execute([$lat, $lng, $radius, $event_id]);

    deactivateEventOnConfigChange($conn, $event_id);
    logAudit(
        $conn,
        'geofence_saved',
        'events',
        $event_id,
        "Saved geofence lat {$lat}, lng {$lng}, radius {$radius}m"
    );

    echo json_encode([
        "success" => true,
        "message" => "Geofence saved. Event was deactivated — review setup and activate again when ready."
    ]);

} catch (Exception $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}