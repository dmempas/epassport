<?php



require_once '../includes/session.php';

requireRole(1);



require_once '../config/database.php';

require_once '../includes/event_helpers.php';
require_once '../includes/attendance_helpers.php';
require_once '../includes/audit_helpers.php';



header('Content-Type: application/json');



$db = new Database();

$conn = $db->connect();



$config = require '../config/realtime.php';

$graceSeconds = (int) ($config['location_grace_seconds'] ?? 300);



try {

    $unscannedResult = markUnscannedStudentsAbsentForClosedWindows($conn);

    $stmt = $conn->prepare("

        SELECT

            a.attendance_id,

            a.user_id,

            a.event_id,

            a.status,

            a.scan_type,

            a.scanned_by_officer_id,

            a.qr_scanned_at,

            e.is_active,

            e.start_date,

            e.end_date

        FROM attendance_sessions a

        INNER JOIN events e ON a.event_id = e.event_id

        WHERE a.status = 'pending'

    ");



    $stmt->execute();

    $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;

    $skipped = 0;



    foreach ($attendanceList as $attendance) {



        $event = [

            'is_active' => $attendance['is_active'],

            'start_date' => $attendance['start_date'],

            'end_date' => $attendance['end_date'],

        ];



        if (!isEventCurrentlyActive($event)) {

            $attendance_id = (int) $attendance['attendance_id'];
            $scanType = (string) ($attendance['scan_type'] ?? '');

            if ($scanType === '') {
                $typeStmt = $conn->prepare("SELECT scan_type FROM attendance_sessions WHERE attendance_id = ? LIMIT 1");
                $typeStmt->execute([$attendance_id]);
                $scanType = (string) $typeStmt->fetchColumn();
            }

            if (isLogoutScanType($scanType) && (int) ($attendance['scanned_by_officer_id'] ?? 0) > 0) {
                updateAttendanceStatus($conn, $attendance_id, 'present');
                $updated++;
                continue;
            }

            if (isLoginScanType($scanType) && pendingAttendanceHasConfirmedInsideGps($conn, $attendance_id)) {
                updateAttendanceStatus($conn, $attendance_id, 'present');
                $updated++;
                continue;
            }

            updateAttendanceStatus($conn, $attendance_id, 'absent');
            createPenaltyForAttendance(
                $conn,
                $attendance_id,
                (int) $attendance['user_id'],
                (int) $attendance['event_id'],
                'Attendance stayed pending after the event ended (scanned but never confirmed present)'
            );
            $updated++;
            continue;

        }



        $attendance_id = (int) $attendance['attendance_id'];



        $stmtLogs = $conn->prepare("

            SELECT location_status, is_inside_geofence

            FROM location_logs

            WHERE attendance_id = ?

            ORDER BY location_id DESC

            LIMIT 1

        ");

        $stmtLogs->execute([$attendance_id]);

        $location = $stmtLogs->fetch(PDO::FETCH_ASSOC);



        if (!$location) {

            $scannedAt = strtotime($attendance['qr_scanned_at'] ?? 'now');



            if (time() - $scannedAt < $graceSeconds) {

                continue;

            }



            updateAttendanceStatus($conn, $attendance_id, 'absent');

            createPenaltyForAttendance(

                $conn,

                $attendance_id,

                (int) $attendance['user_id'],

                (int) $attendance['event_id'],

                'No GPS confirmation after the scan — attendance stayed pending'

            );

            $updated++;

            continue;

        }



        if ($location['location_status'] === 'denied') {

            updateAttendanceStatus($conn, $attendance_id, 'absent');

            createPenaltyForAttendance(

                $conn,

                $attendance_id,

                (int) $attendance['user_id'],

                (int) $attendance['event_id'],

                'Location access denied'

            );

            $updated++;

            continue;

        }



        $stmtMonitor = $conn->prepare("

            SELECT status FROM monitoring_sessions

            WHERE attendance_id = ?

            ORDER BY monitoring_id DESC

            LIMIT 1

        ");

        $stmtMonitor->execute([$attendance_id]);

        $monitor = $stmtMonitor->fetch(PDO::FETCH_ASSOC);



        if ($monitor && $monitor['status'] === 'outside_too_long') {

            updateAttendanceStatus($conn, $attendance_id, 'absent');

            createPenaltyForAttendance(

                $conn,

                $attendance_id,

                (int) $attendance['user_id'],

                (int) $attendance['event_id'],

                'Geofence violation - outside designated area'

            );

            $updated++;

            continue;

        }



        if ((int) $location['is_inside_geofence'] === 1) {

            updateAttendanceStatus($conn, $attendance_id, 'present');

            $updated++;

            continue;

        }



        updateAttendanceStatus($conn, $attendance_id, 'absent');

        createPenaltyForAttendance(

            $conn,

            $attendance_id,

            (int) $attendance['user_id'],

            (int) $attendance['event_id'],

            'Geofence violation - outside designated area'

        );

        $updated++;

    }



    logAudit(
        $conn,
        'evaluate_attendance',
        'attendance_sessions',
        null,
        "Evaluated attendance: {$updated} updated, {$skipped} skipped, "
        . (int) ($unscannedResult['marked_absent'] ?? 0) . ' unmarked absents, '
        . (int) ($unscannedResult['penalties_created'] ?? 0) . ' penalties created'
    );

    echo json_encode([

        'success' => true,

        'updated_records' => $updated,

        'skipped_inactive_event' => $skipped,

        'unscanned_marked_absent' => (int) ($unscannedResult['marked_absent'] ?? 0),

        'unscanned_penalties_created' => (int) ($unscannedResult['penalties_created'] ?? 0),

        'unscanned_windows_processed' => (int) ($unscannedResult['windows_processed'] ?? 0),

    ]);



} catch (Exception $e) {



    echo json_encode([

        'success' => false,

        'message' => $e->getMessage(),

    ]);

}



function updateAttendanceStatus($conn, $attendance_id, $status)

{

    $stmt = $conn->prepare("

        UPDATE attendance_sessions

        SET status = ?

        WHERE attendance_id = ?

        AND status = 'pending'

    ");



    $stmt->execute([$status, $attendance_id]);

}

