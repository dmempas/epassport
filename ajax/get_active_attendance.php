<?php



require_once '../includes/session.php';

requireRole(2);



require_once '../config/database.php';

require_once '../includes/event_helpers.php';

require_once '../includes/realtime_config.php';
require_once '../includes/realtime_notify.php';



header('Content-Type: application/json');



$db = new Database();

$conn = $db->connect();



$config = epassport_realtime_config();

$graceSeconds = (int) ($config['location_grace_seconds'] ?? 300);



try {



    $userId = (int) $_SESSION['user_id'];



    $stmt = $conn->prepare("

        SELECT

            a.attendance_id,

            a.event_id,

            a.scan_type,

            a.status,

            a.qr_scanned_at,

            a.location_requested_at,

            e.is_active,

            e.start_date,

            e.end_date

        FROM attendance_sessions a

        INNER JOIN events e ON a.event_id = e.event_id

        WHERE a.user_id = ?

        AND a.status = 'pending'

        ORDER BY a.qr_scanned_at DESC

        LIMIT 1

    ");



    $stmt->execute([$userId]);

    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);



    if (!$attendance) {

        $logoutReminder = fetchPendingLogoutReminder($conn, $userId);

        if ($logoutReminder) {

            echo json_encode([

                'success' => true,

                'state' => 'logout_reminder',

                'should_prompt_location' => false,

                'should_prompt_logout_reminder' => true,

                'attendance_id' => (int) ($logoutReminder['attendance_id'] ?? 0),

                'scan_type' => $logoutReminder['scan_type'] ?? '',

                'event_name' => $logoutReminder['event_name'] ?? '',

                'mark_realtime_event_id' => (int) ($logoutReminder['realtime_event_id'] ?? 0),

                'message' => 'Logout stamp received — you may turn off location services.',

            ]);

            exit;

        }

        echo json_encode([

            'success' => true,

            'state' => 'idle',

            'should_prompt_location' => false,

            'message' => 'No pending attendance. Show your QR to the officer.',

        ]);

        exit;

    }



    $eventCheck = [

        'is_active' => $attendance['is_active'],

        'start_date' => $attendance['start_date'],

        'end_date' => $attendance['end_date'],

    ];



    if (!isEventCurrentlyActive($eventCheck)) {

        echo json_encode([

            'success' => true,

            'state' => 'idle',

            'should_prompt_location' => false,

            'message' => 'Attendance session is outside the active event period.',

        ]);

        exit;

    }



    // Use location_requested_at, or fall back to qr_scanned_at (scan = location request)

    $requestAnchor = $attendance['location_requested_at'] ?: $attendance['qr_scanned_at'];



    if (empty($requestAnchor)) {

        echo json_encode([

            'success' => true,

            'state' => 'idle',

            'should_prompt_location' => false,

            'message' => 'Waiting for officer scan.',

        ]);

        exit;

    }



    // Backfill location_requested_at when column was missing at scan time

    if (empty($attendance['location_requested_at']) && !empty($attendance['qr_scanned_at'])) {

        try {

            $stmtFix = $conn->prepare("

                UPDATE attendance_sessions

                SET location_requested_at = qr_scanned_at

                WHERE attendance_id = ?

                AND location_requested_at IS NULL

            ");

            $stmtFix->execute([(int) $attendance['attendance_id']]);

            $attendance['location_requested_at'] = $attendance['qr_scanned_at'];

            $requestAnchor = $attendance['qr_scanned_at'];

        } catch (PDOException $e) {

            // Column may not exist yet — qr_scanned_at fallback above is enough

        }

    }



    $hasLocationLog = false;



    try {

        $stmtLog = $conn->prepare("

            SELECT location_id FROM location_logs

            WHERE attendance_id = ?

            AND logged_at >= ?

            LIMIT 1

        ");

        $stmtLog->execute([
            (int) $attendance['attendance_id'],
            $requestAnchor,
        ]);

        $hasLocationLog = (bool) $stmtLog->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        $hasLocationLog = false;

    }



    if ($hasLocationLog) {

        echo json_encode([

            'success' => true,

            'state' => 'processing',

            'should_prompt_location' => false,

            'attendance_id' => (int) $attendance['attendance_id'],

            'message' => 'Location already submitted for this scan.',

        ]);

        exit;

    }



    $requestedAt = strtotime($requestAnchor);

    $secondsSinceRequest = max(0, time() - $requestedAt);



    if ($secondsSinceRequest > $graceSeconds) {

        echo json_encode([

            'success' => true,

            'state' => 'expired',

            'should_prompt_location' => false,

            'attendance_id' => (int) $attendance['attendance_id'],

            'message' => 'Location response window expired.',

        ]);

        exit;

    }



    $pendingEvent = null;



    try {

        $stmtEvent = $conn->prepare("

            SELECT event_id, payload

            FROM realtime_events

            WHERE user_id = ?

            AND event_type = 'location_request'

            AND delivered_at IS NULL

            ORDER BY event_id DESC

            LIMIT 1

        ");

        $stmtEvent->execute([$userId]);

        $pendingEvent = $stmtEvent->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        $pendingEvent = null;

    }



    $scannedAt = strtotime($attendance['qr_scanned_at'] ?? 'now');

    $secondsSinceScan = max(0, time() - $scannedAt);



    echo json_encode([

        'success' => true,

        'state' => 'location_required',

        'should_prompt_location' => true,

        'attendance_id' => (int) $attendance['attendance_id'],

        'event_id' => (int) $attendance['event_id'],

        'scan_type' => $attendance['scan_type'],

        'status' => $attendance['status'],

        'location_requested_at' => $attendance['location_requested_at'] ?: $requestAnchor,

        'seconds_since_scan' => $secondsSinceScan,

        'seconds_since_request' => $secondsSinceRequest,

        'grace_seconds' => $graceSeconds,

        'realtime_event' => $pendingEvent

            ? json_decode($pendingEvent['payload'], true)

            : null,

        'mark_realtime_event_id' => $pendingEvent

            ? (int) $pendingEvent['event_id']

            : null,

    ]);



} catch (Exception $e) {



    echo json_encode([

        'success' => false,

        'state' => 'error',

        'should_prompt_location' => false,

        'message' => $e->getMessage(),

    ]);

}


