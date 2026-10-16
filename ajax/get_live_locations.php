<?php



require_once '../includes/session.php';

requireRole(1);



require_once '../config/database.php';

require_once '../includes/event_helpers.php';

require_once '../includes/monitoring_helpers.php';

require_once '../includes/penalty_helpers.php';



header('Content-Type: application/json');



$db = new Database();

$conn = $db->connect();



ensurePenaltyResolutionSchema($conn);



try {

    $activeEvent = getActiveEvent($conn);



    if (!$activeEvent) {

        echo json_encode([

            'success' => true,

            'event' => null,

            'students' => [],

            'violations' => [],

            'watch' => [],

            'message' => 'No active event.',

        ]);

        exit;

    }



    $eventId = (int) $activeEvent['event_id'];

    $geofenceDisplay = geofenceDisplayConfig();
    $gpsStaleSeconds = $geofenceDisplay['gps_stale_seconds'];
    $exitBufferM = $geofenceDisplay['exit_buffer_m'];
    $centerLat = (float) $activeEvent['geofence_latitude'];
    $centerLng = (float) $activeEvent['geofence_longitude'];
    $radiusMeters = (int) $activeEvent['geofence_radius'];

    $latestScanJoin = sqlJoinLatestScanPerUser($eventId);



    $stmt = $conn->prepare("

        SELECT

            u.user_id,

            u.firstname,

            u.lastname,

            u.student_no,

            a.attendance_id,

            a.scan_type,

            a.status AS attendance_status,

            latest.latitude,

            latest.longitude,

            latest.is_inside_geofence,

            latest.location_status,

            latest.accuracy,

            latest.logged_at,

            e.event_name,

            ms.monitoring_id,

            ms.status AS monitoring_status,

            ms.outside_started_at,

            ms.warning_sent_at,

            ms.silent_warning_sent_at,

            COALESCE(ms.admin_action, 'none') AS admin_action,

            ms.admin_action_at,

            ms.last_ping_at,

            COALESCE(ms.missed_ping_count, 0) AS missed_ping_count,

            TIMESTAMPDIFF(SECOND, latest.logged_at, NOW()) AS seconds_since_update

        FROM attendance_sessions a

        {$latestScanJoin}

        INNER JOIN events e ON a.event_id = e.event_id

        INNER JOIN users u ON a.user_id = u.user_id

        LEFT JOIN monitoring_sessions ms ON ms.attendance_id = a.attendance_id

        LEFT JOIN (

            SELECT l1.*

            FROM location_logs l1

            INNER JOIN (

                SELECT attendance_id, MAX(location_id) AS max_location_id

                FROM location_logs

                WHERE latitude IS NOT NULL

                  AND longitude IS NOT NULL

                  AND latitude != 0

                  AND longitude != 0

                GROUP BY attendance_id

            ) l2 ON l1.attendance_id = l2.attendance_id AND l1.location_id = l2.max_location_id

        ) latest ON latest.attendance_id = a.attendance_id

        WHERE a.event_id = ?

        AND a.status IN ('present', 'late', 'violated')

        AND latest.location_id IS NOT NULL

        ORDER BY u.lastname ASC, u.firstname ASC

    ");

    $stmt->execute([$eventId]);

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);



    foreach ($students as &$student) {

        $seconds = isset($student['seconds_since_update']) ? (int) $student['seconds_since_update'] : null;

        $student['gps_stale'] = $seconds !== null && $seconds > $gpsStaleSeconds;

        $lat = (float) ($student['latitude'] ?? 0);
        $lng = (float) ($student['longitude'] ?? 0);
        $visual = computeVisualGeofenceState($lat, $lng, $centerLat, $centerLng, $radiusMeters);

        $student['distance_m'] = $visual['distance_m'];
        $student['visual_inside'] = $visual['visual_inside'];
        $student['monitoring_inside'] = (int) ($student['is_inside_geofence'] ?? 0);

        $student['display_status'] = ($student['attendance_status'] === 'violated')

            ? 'violated'

            : ($visual['visual_inside'] === 1 ? 'inside' : 'outside');

    }

    unset($student);



    $stmt = $conn->prepare("

        SELECT

            u.user_id,

            u.firstname,

            u.lastname,

            u.student_no,

            u.contact_number,

            a.attendance_id,

            a.status AS attendance_status,

            COALESCE(a.is_late_scan, 0) AS is_late_scan,

            ms.monitoring_id,

            ms.status AS monitoring_status,

            ms.outside_started_at,

            ms.warning_sent_at,

            ms.silent_warning_sent_at,

            COALESCE(ms.admin_action, 'none') AS admin_action,

            ms.admin_action_at,

            COALESCE(ms.missed_ping_count, 0) AS missed_ping_count,

            l.is_inside_geofence,

            l.location_status,

            l.logged_at,

            TIMESTAMPDIFF(MINUTE, ms.outside_started_at, NOW()) AS minutes_outside,

            (

                SELECT al.is_sent

                FROM alerts al

                WHERE al.attendance_id = a.attendance_id

                AND al.message LIKE 'E-PASSPORT SSC ALERT:%'

                ORDER BY al.created_at DESC

                LIMIT 1

            ) AS sms_is_sent,

            (

                SELECT p.reason

                FROM penalties p

                WHERE p.attendance_id = a.attendance_id

                ORDER BY p.created_at DESC

                LIMIT 1

            ) AS penalty_reason,

            (

                SELECT 1

                FROM location_logs lg

                WHERE lg.attendance_id = a.attendance_id

                  AND lg.latitude IS NOT NULL

                  AND lg.longitude IS NOT NULL

                  AND lg.latitude != 0

                  AND lg.longitude != 0

                  AND lg.location_status IN ('inside', 'outside')

                  AND lg.logged_at >= DATE_SUB(NOW(), INTERVAL {$gpsStaleSeconds} SECOND)

                LIMIT 1

            ) AS has_recent_gps

        FROM monitoring_sessions ms

        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id

        {$latestScanJoin}

        INNER JOIN users u ON a.user_id = u.user_id

        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1

        LEFT JOIN (

            SELECT l1.*

            FROM location_logs l1

            INNER JOIN (

                SELECT attendance_id, MAX(location_id) AS max_location_id

                FROM location_logs

                WHERE latitude IS NOT NULL

                  AND longitude IS NOT NULL

                  AND latitude != 0

                  AND longitude != 0

                GROUP BY attendance_id

            ) l2 ON l1.attendance_id = l2.attendance_id AND l1.location_id = l2.max_location_id

        ) l ON l.attendance_id = a.attendance_id

        WHERE a.event_id = ?

        AND (

            (

                ms.ended_at IS NULL

                AND (

                    (

                        ms.outside_started_at IS NOT NULL

                        AND COALESCE(l.is_inside_geofence, 0) = 0

                        AND a.status IN ('present', 'late')

                    )

                    OR (

                        ms.warning_sent_at IS NOT NULL

                        AND COALESCE(ms.admin_action, 'none') = 'none'

                    )

                    OR (

                        ms.silent_warning_sent_at IS NOT NULL

                        AND a.status IN ('present', 'late')

                        AND COALESCE(ms.admin_action, 'none') = 'none'

                    )

                    OR (

                        a.status IN ('present', 'late')

                        AND COALESCE(ms.missed_ping_count, 0) >= 2

                    )

                )

            )

            OR (

                a.status = 'violated'

                AND COALESCE(ms.admin_action, 'none') = 'penalized'

            )

        )

        ORDER BY

            CASE COALESCE(ms.admin_action, 'none')

                WHEN 'none' THEN 0

                ELSE 1

            END,

            ms.outside_started_at DESC

    ");

    $stmt->execute([$eventId]);

    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);



    foreach ($violations as &$row) {

        $minutes = isset($row['minutes_outside']) ? (int) $row['minutes_outside'] : null;

        $adminAction = $row['admin_action'] ?? 'none';

        $attendanceStatus = $row['attendance_status'] ?? 'present';

        $missedPings = (int) ($row['missed_ping_count'] ?? 0);

        $locationStatus = $row['location_status'] ?? '';

        $penaltyReason = (string) ($row['penalty_reason'] ?? '');

        $hasRecentGps = !empty($row['has_recent_gps']);
        $silentWarningAt = $row['silent_warning_sent_at'] ?? null;
        $penaltyLooksNoSignal = stripos($penaltyReason, '30 minutes') !== false
            || stripos($penaltyReason, 'signal lost') !== false
            || stripos($penaltyReason, 'location updates') !== false;



        if (
            ($attendanceStatus === 'violated' || $penaltyLooksNoSignal)
            && !$hasRecentGps
            && ($locationStatus === 'unavailable' || $penaltyLooksNoSignal)
            && (stripos($penaltyReason, 'location service') !== false || $penaltyLooksNoSignal)
        ) {

            if ($penaltyLooksNoSignal && stripos($penaltyReason, 'location service') === false) {
                $row['violation_type'] = 'no_signal';
                $row['violation_reason'] = $penaltyReason !== ''
                    ? $penaltyReason
                    : 'No location updates for more than 30 minutes';
            } else {
            $row['violation_type'] = 'location_off';

            $row['violation_reason'] = $penaltyReason !== ''

                ? $penaltyReason

                : 'Location service disabled - no GPS coordinates collected';
            }

        } elseif ($silentWarningAt && $attendanceStatus !== 'violated') {

            $row['violation_type'] = 'no_signal_warning';
            $row['violation_reason'] = 'No GPS ping for 15+ minutes — restore Location Services and internet';
            $row['sms_sent'] = true;

        } elseif ($missedPings >= 1 && $attendanceStatus === 'present' && !$hasRecentGps) {

            $row['violation_type'] = 'gps_unavailable';

            $row['violation_reason'] = 'No GPS coordinates collected — location service may be off';

        } elseif ($attendanceStatus === 'violated' && $hasRecentGps) {

            $row['violation_type'] = 'gps_active_penalized';

            $row['violation_reason'] = 'GPS is active — this penalty may have been triggered incorrectly';

        } else {

            $row['violation_type'] = 'outside_geofence';

            $row['violation_reason'] = 'Outside campus geofence boundary';

        }



        $row['minutes_outside_label'] = formatMinutesOutside($minutes);

        $row['is_currently_outside'] = (int) ($row['is_inside_geofence'] ?? 0) === 0;

        $row['sms_sent'] = !empty($row['warning_sent_at'])
            || !empty($row['silent_warning_sent_at'])
            || ($row['violation_type'] ?? '') === 'no_signal'
            || ($row['violation_type'] ?? '') === 'no_signal_warning';

        $row['can_void'] = $adminAction === 'none'

            && $row['violation_type'] === 'outside_geofence'

            && (!empty($row['outside_started_at']) || !empty($row['warning_sent_at']));

        $row['can_penalize'] = $adminAction === 'none'

            && $row['violation_type'] === 'outside_geofence'

            && (

                !empty($row['warning_sent_at'])

                || ($minutes !== null && $minutes >= MONITORING_WARNING_MINUTES)

            );

        $row['can_void_penalty'] = $adminAction === 'penalized'

            && $attendanceStatus === 'violated'

            && in_array($row['violation_type'] ?? '', ['no_signal', 'location_off', 'gps_active_penalized'], true);

        $row['restore_status'] = !empty($row['is_late_scan']) ? 'late' : 'present';

    }

    unset($row);



    $watchAlerts = buildGeofenceWatchAlerts($students, $violations, $radiusMeters, $exitBufferM);



    echo json_encode([

        'success' => true,

        'event' => [

            'event_id' => $eventId,

            'event_name' => $activeEvent['event_name'],

            'geofence_latitude' => $activeEvent['geofence_latitude'],

            'geofence_longitude' => $activeEvent['geofence_longitude'],

            'geofence_radius' => $radiusMeters,

            'exit_buffer_m' => $exitBufferM,

        ],

        'students' => $students,

        'watch' => $watchAlerts,

        'location_jumps' => fetchUnreviewedLocationJumps($conn, $eventId),

        'violations' => $violations,

    ]);

} catch (Exception $e) {

    echo json_encode([

        'success' => false,

        'message' => $e->getMessage(),

    ]);

}

