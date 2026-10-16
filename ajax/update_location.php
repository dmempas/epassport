<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/monitoring_helpers.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/attendance_schema.php';
require_once '../includes/audit_helpers.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->connect();

ensurePenaltyResolutionSchema($conn);
ensureAttendanceSchema($conn);

$config = require '../config/realtime.php';
$graceSeconds = (int) ($config['location_grace_seconds'] ?? 300);

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request");
    }

    $attendance_id = (int) ($_POST['attendance_id'] ?? 0);

    if ($attendance_id <= 0) {
        throw new Exception("Invalid attendance ID");
    }

    $denied = isset($_POST['denied']) ? 1 : 0;
    $explicitDenied = isset($_POST['explicit_denied']) && $_POST['explicit_denied'] === '1';
    $isMonitoring = isset($_POST['monitoring']) && $_POST['monitoring'] === '1';
    $locationOff = isset($_POST['location_off']) && $_POST['location_off'] === '1';

    $latitude = isset($_POST['lat']) ? (float) $_POST['lat'] : 0;
    $longitude = isset($_POST['lng']) ? (float) $_POST['lng'] : 0;
    $accuracy = isset($_POST['accuracy']) ? (float) $_POST['accuracy'] : 0;

    if (!$denied && !$locationOff) {
        if (
            !is_finite($latitude) || !is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180
            || ($latitude == 0.0 && $longitude == 0.0)
        ) {
            throw new Exception("Invalid GPS coordinates received from device.");
        }
    }

    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.user_id,
            a.event_id,
            a.status AS current_status,
            a.qr_scanned_at,
            a.location_requested_at,
            COALESCE(a.is_rescan, 0) AS is_rescan,
            COALESCE(a.is_late_scan, 0) AS is_late_scan,
            e.geofence_latitude,
            e.geofence_longitude,
            e.geofence_radius
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE a.attendance_id = ?
        LIMIT 1
    ");

    $stmt->execute([$attendance_id]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        throw new Exception("Attendance session not found");
    }

    if ((int) $attendance['user_id'] !== (int) $_SESSION['user_id']) {
        throw new Exception("Unauthorized request");
    }

    $eventRow = fetchEventById($conn, (int) $attendance['event_id']);

    if (!$eventRow || !isEventCurrentlyActive($eventRow)) {
        throw new Exception(
            "This attendance session is outside the active event period. Location updates are not accepted."
        );
    }

    if ($attendance['current_status'] !== 'pending') {
        $monitorStatuses = attendanceStatusesForMonitoring();
        if (!$isMonitoring || !in_array($attendance['current_status'], $monitorStatuses, true)) {
            echo json_encode([
                'success' => true,
                'attendance_status' => $attendance['current_status'],
                'message' => 'Attendance already finalized.',
            ]);
            exit;
        }

        if ($locationOff) {
            $result = handleLocationOffDuringMonitoring($conn, $attendance_id);

            echo json_encode([
                'success' => true,
                'attendance_status' => !empty($result['violation']) ? 'violated' : 'present',
                'location_status' => 'unavailable',
                'monitoring' => true,
                'violation' => !empty($result['violation']),
                'message' => $result['message'] ?? 'Location unavailable.',
            ]);
            exit;
        }

        $centerLat = $attendance['geofence_latitude'] !== null
            ? (float) $attendance['geofence_latitude']
            : 0.0;
        $centerLng = $attendance['geofence_longitude'] !== null
            ? (float) $attendance['geofence_longitude']
            : 0.0;
        $radius = $attendance['geofence_radius'] !== null
            ? (int) $attendance['geofence_radius']
            : 0;

        $jump = detectLocationJump($conn, $attendance_id, $latitude, $longitude);

        if ($jump !== null) {
            $alreadyFlagged = hasUnreviewedLocationJump($conn, $attendance_id);
            $visual = computeVisualGeofenceState($latitude, $longitude, $centerLat, $centerLng, $radius);
            insertGpsLocationLog(
                $conn,
                $attendance_id,
                $latitude,
                $longitude,
                $accuracy,
                (int) $visual['visual_inside'],
                $visual['visual_inside'] ? 'inside' : 'outside',
                true
            );

            if (!$alreadyFlagged) {
                logAudit(
                    $conn,
                    'location_jump_flagged',
                    'attendance_sessions',
                    $attendance_id,
                    'Location jumped ' . $jump['distance_m'] . ' m in ' . $jump['elapsed_seconds']
                        . ' s (' . $jump['speed_kmh'] . ' km/h). Attendance status was not changed.'
                );
            }

            echo json_encode([
                'success' => true,
                'attendance_status' => $attendance['current_status'],
                'location_status' => $visual['visual_inside'] ? 'inside' : 'outside',
                'is_inside_geofence' => (bool) $visual['visual_inside'],
                'distance' => round($visual['distance_m'], 2),
                'monitoring' => true,
                'location_jump' => true,
                'event_name' => $eventRow['event_name'] ?? '',
            ]);
            exit;
        }

        $geo = evaluateAttendanceGeofence(
            $conn,
            $attendance_id,
            $latitude,
            $longitude,
            $centerLat,
            $centerLng,
            $radius,
            $accuracy
        );
        $distance = $geo['distance'];
        $inside = (int) $geo['inside'];
        $locationStatus = $geo['location_status'];

        $stmt = $conn->prepare("
            INSERT INTO location_logs
            (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $attendance_id,
            $latitude,
            $longitude,
            $accuracy,
            $inside,
            $locationStatus,
        ]);

        handleMonitoringPing($conn, $attendance_id, $locationStatus);

        $currentStatus = $attendance['current_status'];

        echo json_encode([
            'success' => true,
            'attendance_status' => $currentStatus,
            'location_status' => $locationStatus,
            'is_inside_geofence' => (bool) $inside,
            'distance' => round($distance, 2),
            'monitoring' => true,
            'event_name' => $eventRow['event_name'] ?? '',
        ]);
        exit;
    }

    // Deny only when student explicitly refuses — never auto-deny from timeouts
    if ($denied && !$explicitDenied) {
        echo json_encode([
            'success' => true,
            'attendance_status' => 'pending',
            'location_status' => 'awaiting',
            'message' => 'Please allow or deny location using the dialog on your dashboard.',
        ]);
        exit;
    }

    $centerLat = $attendance['geofence_latitude'] !== null
        ? (float) $attendance['geofence_latitude']
        : 0.0;

    $centerLng = $attendance['geofence_longitude'] !== null
        ? (float) $attendance['geofence_longitude']
        : 0.0;

    $radius = $attendance['geofence_radius'] !== null
        ? (int) $attendance['geofence_radius']
        : 0;

    $inside = 0;
    $locationStatus = 'denied';
    $distance = 0;

    if (!$denied) {
        $jump = detectLocationJump($conn, $attendance_id, $latitude, $longitude);

        if ($jump !== null) {
            $alreadyFlagged = hasUnreviewedLocationJump($conn, $attendance_id);
            $visual = computeVisualGeofenceState($latitude, $longitude, $centerLat, $centerLng, $radius);
            insertGpsLocationLog(
                $conn,
                $attendance_id,
                $latitude,
                $longitude,
                $accuracy,
                (int) $visual['visual_inside'],
                $visual['visual_inside'] ? 'inside' : 'outside',
                true
            );

            if (!$alreadyFlagged) {
                logAudit(
                    $conn,
                    'location_jump_flagged',
                    'attendance_sessions',
                    $attendance_id,
                    'Location jumped ' . $jump['distance_m'] . ' m in ' . $jump['elapsed_seconds']
                        . ' s (' . $jump['speed_kmh'] . ' km/h). Attendance status was not changed.'
                );
            }

            echo json_encode([
                'success' => true,
                'attendance_status' => 'pending',
                'location_status' => 'awaiting',
                'location_jump' => true,
                'message' => 'Processing your location…',
            ]);
            exit;
        }

        $geo = evaluateAttendanceGeofence(
            $conn,
            $attendance_id,
            $latitude,
            $longitude,
            $centerLat,
            $centerLng,
            $radius,
            $accuracy
        );
        $distance = $geo['distance'];
        $inside = (int) $geo['inside'];
        $locationStatus = $geo['location_status'];
    }

    $stmt = $conn->prepare("
        INSERT INTO location_logs
        (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $attendance_id,
        $latitude,
        $longitude,
        $accuracy,
        $inside,
        $locationStatus,
    ]);

    if ($inside) {
        $attendanceStatus = !empty($attendance['is_late_scan']) ? 'late' : 'present';
    } else {
        $attendanceStatus = 'absent';
    }

    $stmt = $conn->prepare("
        UPDATE attendance_sessions
        SET status = ?
        WHERE attendance_id = ?
        AND status = 'pending'
    ");

    $stmt->execute([$attendanceStatus, $attendance_id]);

    refreshMonitoringAfterLocation($conn, $attendance_id, $locationStatus, true);
    clearGeofenceConsistencyState($attendance_id);

    if ($attendanceStatus === 'absent') {
        $penaltyReason = 'Absent from attendance';

        if ($locationStatus === 'outside') {
            $penaltyReason = 'Geofence violation - outside designated area';
        } elseif ($locationStatus === 'denied') {
            $penaltyReason = 'Location access denied';
        }

        createPenaltyForAttendance(
            $conn,
            (int) $attendance_id,
            (int) $attendance['user_id'],
            (int) $attendance['event_id'],
            $penaltyReason,
            $locationStatus === 'outside' ? 'outside' : ($locationStatus === 'denied' ? 'location_denied' : 'absent')
        );
    }

    if ($attendanceStatus === 'late') {
        clearUnsettledPenaltyForAttendance($conn, (int) $attendance_id);
        createPenaltyForAttendance(
            $conn,
            (int) $attendance_id,
            (int) $attendance['user_id'],
            (int) $attendance['event_id'],
            'Late attendance (scanned after the window closed)',
            'late'
        );
        logAudit(
            $conn,
            'attendance_marked_late',
            'attendance_sessions',
            $attendance_id,
            'GPS verified inside geofence after late scan'
        );
    } elseif ($attendanceStatus === 'present') {
        clearUnsettledPenaltyForAttendance($conn, (int) $attendance_id);
        logAudit(
            $conn,
            'attendance_marked_present',
            'attendance_sessions',
            $attendance_id,
            'GPS verified inside geofence'
        );
    } elseif ($attendanceStatus === 'absent') {
        if ($locationStatus === 'denied') {
            logAudit(
                $conn,
                'location_access_denied',
                'attendance_sessions',
                $attendance_id,
                $penaltyReason ?? 'Location access denied'
            );
        } else {
            logAudit(
                $conn,
                'attendance_marked_absent',
                'attendance_sessions',
                $attendance_id,
                $penaltyReason ?? 'Absent from attendance'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'attendance_status' => $attendanceStatus,
        'location_status' => $locationStatus,
        'distance' => round($distance, 2),
        'geofence_radius' => $radius,
        'inside_geofence' => (bool) $inside,
        'event_name' => $eventRow['event_name'] ?? '',
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'attendance_status' => 'pending',
    ]);
}

