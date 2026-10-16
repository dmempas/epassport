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

    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.user_id,
            a.event_id,
            e.is_active,
            e.start_date,
            e.end_date
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE a.status = 'absent'
    ");

    $stmt->execute();
    $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $generated = 0;
    $skipped = 0;

    foreach ($attendanceList as $attendance) {
        $attendance_id = (int) $attendance['attendance_id'];

        if (penaltyExistsForAttendance($conn, $attendance_id)) {
            continue;
        }

        $stmtCheck = $conn->prepare("
            SELECT location_status
            FROM location_logs
            WHERE attendance_id = ?
            ORDER BY location_id DESC
            LIMIT 1
        ");
        $stmtCheck->execute([$attendance_id]);
        $location = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $reason = 'Absent from attendance';

        if ($location) {
            if ($location['location_status'] === 'outside') {
                $reason = 'Geofence violation - outside designated area';
            } elseif ($location['location_status'] === 'denied') {
                $reason = 'Location access denied';
            }
        } else {
            $reason = 'No GPS data provided';
        }

        if (createPenaltyForAttendance(
            $conn,
            $attendance_id,
            (int) $attendance['user_id'],
            (int) $attendance['event_id'],
            $reason
        )) {
            $generated++;
        }
    }

    logAudit(
        $conn,
        'generate_penalties',
        'penalties',
        null,
        "Generated {$generated} penalty record(s); skipped {$skipped} inactive event(s)"
    );

    echo json_encode([
        'success' => true,
        'generated_penalties' => $generated,
        'skipped_inactive_event' => $skipped,
    ]);

} catch (PDOException $e) {

    echo json_encode([
        'success' => false,
        'message' => 'Database Error: ' . $e->getMessage(),
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
