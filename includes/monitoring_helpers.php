<?php

/**
 * Live geofence monitoring: outside duration, SMS warnings, admin actions.
 */

const MONITORING_WARNING_MINUTES = 30;

const MONITORING_MISSED_PINGS_BEFORE_VIOLATION = 5;

const MONITORING_SILENT_WARNING_MINUTES = 15;

const MONITORING_SILENT_VIOLATION_MINUTES = 30;

function haversineDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Geofence display settings for admin maps and live tracking API.
 *
 * @return array{exit_buffer_m: int, entry_buffer_m: int, gps_stale_seconds: int}
 */
function geofenceDisplayConfig(): array
{
    $config = require __DIR__ . '/../config/realtime.php';

    return [
        'exit_buffer_m' => (int) ($config['geofence_exit_buffer_m'] ?? 10),
        'entry_buffer_m' => (int) ($config['geofence_entry_buffer_m'] ?? 10),
        'gps_stale_seconds' => (int) ($config['monitoring_gps_stale_seconds'] ?? 120),
    ];
}

/**
 * Nominal inside/outside using the configured radius only (matches the map circle).
 *
 * @return array{distance_m: float, visual_inside: int}
 */
function computeVisualGeofenceState(
    float $latitude,
    float $longitude,
    float $centerLat,
    float $centerLng,
    int $radiusMeters
): array {
    $distance = haversineDistanceMeters($latitude, $longitude, $centerLat, $centerLng);

    return [
        'distance_m' => round($distance, 1),
        'visual_inside' => $distance <= $radiusMeters ? 1 : 0,
    ];
}

/**
 * Students visually outside the geofence who are not yet in the confirmed violations list.
 *
 * @param array<int, array<string, mixed>> $students
 * @param array<int, array<string, mixed>> $violations
 * @return array<int, array<string, mixed>>
 */
function buildGeofenceWatchAlerts(
    array $students,
    array $violations,
    int $radiusMeters,
    int $exitBufferM
): array {
    $violationAttendanceIds = [];

    foreach ($violations as $violation) {
        $violationAttendanceIds[(int) ($violation['attendance_id'] ?? 0)] = true;
    }

    $watch = [];

    foreach ($students as $student) {
        $attendanceId = (int) ($student['attendance_id'] ?? 0);
        $status = (string) ($student['attendance_status'] ?? '');

        if (!in_array($status, ['present', 'late'], true)) {
            continue;
        }

        if (isset($violationAttendanceIds[$attendanceId])) {
            continue;
        }

        $seconds = isset($student['seconds_since_update']) ? (int) $student['seconds_since_update'] : null;
        $silent = silentGapConfig();
        $noSignalWatch = $seconds !== null && $seconds >= (int) $silent['silent_warning_seconds'];

        if ($noSignalWatch) {
            $mins = (int) floor($seconds / 60);
            $watch[] = [
                'user_id' => (int) ($student['user_id'] ?? 0),
                'attendance_id' => $attendanceId,
                'firstname' => (string) ($student['firstname'] ?? ''),
                'lastname' => (string) ($student['lastname'] ?? ''),
                'student_no' => (string) ($student['student_no'] ?? ''),
                'distance_m' => (float) ($student['distance_m'] ?? 0),
                'visual_inside' => (int) ($student['visual_inside'] ?? 1),
                'monitoring_inside' => (int) ($student['monitoring_inside'] ?? $student['is_inside_geofence'] ?? 0),
                'gps_stale' => 1,
                'seconds_since_update' => $seconds,
                'watch_type' => 'no_signal',
                'watch_reason' => 'No location upload for ' . $mins . '+ minutes. Student may have turned off data or GPS.',
            ];
            continue;
        }

        if ((int) ($student['visual_inside'] ?? 1) === 1) {
            continue;
        }

        $monitoringInside = (int) ($student['monitoring_inside'] ?? $student['is_inside_geofence'] ?? 0) === 1;
        $gpsStale = !empty($student['gps_stale']);
        $distanceM = (float) ($student['distance_m'] ?? 0);
        $outsideStarted = !empty($student['outside_started_at']);

        if ($gpsStale) {
            $watchType = 'stale_outside';
            $reason = 'Last known position is outside the geofence';
            if ($seconds !== null) {
                $reason .= ' — GPS stale (' . $seconds . 's since last ping)';
            }
            $reason .= '. Awaiting fresh coordinates.';
        } elseif ($monitoringInside) {
            $watchType = 'buffer_zone';
            $reason = sprintf(
                'Outside nominal boundary (%.0fm from center, limit %dm) but still inside the monitoring buffer (+%dm). Exit not confirmed yet.',
                $distanceM,
                $radiusMeters,
                $exitBufferM
            );
        } elseif (!$outsideStarted) {
            $watchType = 'pending_confirmation';
            $reason = 'Outside geofence on map — server has not confirmed exit yet (consistency checks in progress).';
        } else {
            continue;
        }

        $watch[] = [
            'user_id' => (int) ($student['user_id'] ?? 0),
            'attendance_id' => $attendanceId,
            'firstname' => (string) ($student['firstname'] ?? ''),
            'lastname' => (string) ($student['lastname'] ?? ''),
            'student_no' => (string) ($student['student_no'] ?? ''),
            'distance_m' => $distanceM,
            'visual_inside' => 0,
            'monitoring_inside' => $monitoringInside ? 1 : 0,
            'gps_stale' => $gpsStale ? 1 : 0,
            'seconds_since_update' => $seconds,
            'watch_type' => $watchType,
            'watch_reason' => $reason,
        ];
    }

    return $watch;
}

/**
 * Load prior geofence state for hysteresis and consistency tracking.
 */
function fetchGeofenceStateForAttendance(PDO $conn, int $attendanceId): array
{
    ensureLocationJumpSchema($conn);

    $previousStatus = null;
    $consistencyCount = 0;

    $monitor = fetchMonitoringSession($conn, $attendanceId);

    if ($monitor) {
        $msStatus = (string) ($monitor['status'] ?? 'active');

        if (in_array($msStatus, ['active', 'returned'], true)) {
            $previousStatus = 'inside';
        } elseif (in_array($msStatus, ['warning', 'outside_too_long'], true)) {
            $previousStatus = 'outside';
        }

        $consistencyCount = (int) ($monitor['consistency_count'] ?? 0);
    }

    if ($previousStatus === null) {
        try {
            $stmt = $conn->prepare("
                SELECT location_status
                FROM location_logs
                WHERE attendance_id = ?
                  AND location_status IN ('inside', 'outside')
                  AND is_location_jump = 0
                ORDER BY location_id DESC
                LIMIT 1
            ");
            $stmt->execute([$attendanceId]);
            $last = $stmt->fetchColumn();

            if ($last) {
                $previousStatus = (string) $last;
            }
        } catch (PDOException $e) {
            // Non-fatal.
        }
    }

    if ($consistencyCount === 0 && !empty($_SESSION['geofence_consistency'][$attendanceId])) {
        $consistencyCount = (int) $_SESSION['geofence_consistency'][$attendanceId];
    }

    return [
        'previous_status' => $previousStatus,
        'consistency_count' => $consistencyCount,
    ];
}

/**
 * Persist consistency counter between GPS pings.
 */
function persistGeofenceConsistency(PDO $conn, int $attendanceId, int $consistencyCount): void
{
    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET consistency_count = ?, last_ping_at = NOW()
            WHERE attendance_id = ?
        ");
        $stmt->execute([$consistencyCount, $attendanceId]);

        if ($stmt->rowCount() > 0) {
            return;
        }
    } catch (PDOException $e) {
        // Column may not exist yet.
    }

    if (!isset($_SESSION['geofence_consistency'])) {
        $_SESSION['geofence_consistency'] = [];
    }

    $_SESSION['geofence_consistency'][$attendanceId] = $consistencyCount;
}

function clearGeofenceConsistencyState(int $attendanceId): void
{
    if (!empty($_SESSION['geofence_consistency'][$attendanceId])) {
        unset($_SESSION['geofence_consistency'][$attendanceId]);
    }
}

function ensureLocationJumpSchema(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    foreach ([
        "ALTER TABLE location_logs ADD COLUMN is_location_jump TINYINT(1) NOT NULL DEFAULT 0 AFTER location_status",
        "ALTER TABLE location_logs ADD COLUMN jump_reviewed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_location_jump",
    ] as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Column already exists.
        }
    }
}

function fetchLastAcceptedGpsFix(PDO $conn, int $attendanceId): ?array
{
    ensureLocationJumpSchema($conn);

    try {
        $stmt = $conn->prepare("
            SELECT latitude, longitude, logged_at
            FROM location_logs
            WHERE attendance_id = ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND latitude != 0
              AND longitude != 0
              AND location_status IN ('inside', 'outside')
              AND is_location_jump = 0
            ORDER BY location_id DESC
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * A point that is far from the last accepted fix and too fast to be real travel.
 * The first fix has nothing to compare, so it is never a jump.
 *
 * @return array{distance_m: float, elapsed_seconds: int, speed_kmh: float}|null
 */
function detectLocationJump(PDO $conn, int $attendanceId, float $latitude, float $longitude): ?array
{
    $previous = fetchLastAcceptedGpsFix($conn, $attendanceId);

    if (!$previous) {
        return null;
    }

    $config = require __DIR__ . '/../config/realtime.php';
    $minDistance = (float) ($config['gps_jump_min_distance_m'] ?? 500);
    $maxSpeed = (float) ($config['gps_jump_max_speed_kmh'] ?? 120);
    $distance = haversineDistanceMeters(
        $latitude,
        $longitude,
        (float) $previous['latitude'],
        (float) $previous['longitude']
    );

    if ($distance < $minDistance) {
        return null;
    }

    $previousTs = strtotime((string) $previous['logged_at']);
    $elapsed = $previousTs ? max(1, time() - $previousTs) : 1;
    $speedKmh = ($distance / $elapsed) * 3.6;

    if ($speedKmh <= $maxSpeed) {
        return null;
    }

    return [
        'distance_m' => round($distance, 1),
        'elapsed_seconds' => $elapsed,
        'speed_kmh' => round($speedKmh, 1),
    ];
}

function insertGpsLocationLog(
    PDO $conn,
    int $attendanceId,
    float $latitude,
    float $longitude,
    float $accuracy,
    int $inside,
    string $locationStatus,
    bool $isJump
): void {
    ensureLocationJumpSchema($conn);

    try {
        $stmt = $conn->prepare("
            INSERT INTO location_logs
            (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status, is_location_jump)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $attendanceId,
            $latitude,
            $longitude,
            $accuracy,
            $inside,
            $locationStatus,
            $isJump ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            INSERT INTO location_logs
            (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $attendanceId,
            $latitude,
            $longitude,
            $accuracy,
            $inside,
            $locationStatus,
        ]);
    }
}

function hasUnreviewedLocationJump(PDO $conn, int $attendanceId): bool
{
    ensureLocationJumpSchema($conn);

    try {
        $stmt = $conn->prepare("
            SELECT 1
            FROM location_logs
            WHERE attendance_id = ?
              AND is_location_jump = 1
              AND jump_reviewed = 0
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);

        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function reviewLocationJumps(PDO $conn, int $attendanceId, int $adminUserId): array
{
    ensureLocationJumpSchema($conn);

    if ($attendanceId <= 0) {
        return ['ok' => false, 'message' => 'Invalid attendance stamp.'];
    }

    $stmt = $conn->prepare("
        UPDATE location_logs
        SET jump_reviewed = 1
        WHERE attendance_id = ?
          AND is_location_jump = 1
          AND jump_reviewed = 0
    ");
    $stmt->execute([$attendanceId]);

    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'message' => 'No location jump is waiting for review.'];
    }

    try {
        require_once __DIR__ . '/audit_helpers.php';
        logAudit(
            $conn,
            'location_jump_reviewed',
            'attendance_sessions',
            $attendanceId,
            'Admin noted a location jump. Attendance status was not changed.',
            $adminUserId
        );
    } catch (Throwable $e) {
        // The review itself still stands.
    }

    return [
        'ok' => true,
        'message' => 'Location jump noted. The attendance stamp was not changed.',
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchUnreviewedLocationJumps(PDO $conn, int $eventId): array
{
    ensureLocationJumpSchema($conn);

    try {
        $stmt = $conn->prepare("
            SELECT
                j.location_id,
                j.attendance_id,
                j.latitude,
                j.longitude,
                j.logged_at,
                a.user_id,
                a.status AS attendance_status,
                u.firstname,
                u.lastname,
                u.student_no
            FROM location_logs j
            INNER JOIN (
                SELECT attendance_id, MAX(location_id) AS location_id
                FROM location_logs
                WHERE is_location_jump = 1
                  AND jump_reviewed = 0
                GROUP BY attendance_id
            ) latest ON latest.location_id = j.location_id
            INNER JOIN attendance_sessions a ON a.attendance_id = j.attendance_id
            INNER JOIN users u ON u.user_id = a.user_id
            WHERE a.event_id = ?
            ORDER BY j.logged_at DESC
        ");
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    $jumps = [];

    foreach ($rows as $row) {
        $attendanceId = (int) $row['attendance_id'];
        $accepted = fetchLastAcceptedGpsFix($conn, $attendanceId);
        $distance = null;
        $elapsed = null;

        if ($accepted) {
            $distance = round(haversineDistanceMeters(
                (float) $row['latitude'],
                (float) $row['longitude'],
                (float) $accepted['latitude'],
                (float) $accepted['longitude']
            ), 0);
            $previousTs = strtotime((string) $accepted['logged_at']);
            $jumpTs = strtotime((string) $row['logged_at']);

            if ($previousTs && $jumpTs) {
                $elapsed = max(0, $jumpTs - $previousTs);
            }
        }

        $jumps[] = [
            'attendance_id' => $attendanceId,
            'user_id' => (int) $row['user_id'],
            'firstname' => (string) $row['firstname'],
            'lastname' => (string) $row['lastname'],
            'student_no' => (string) $row['student_no'],
            'attendance_status' => (string) $row['attendance_status'],
            'logged_at' => (string) $row['logged_at'],
            'distance_m' => $distance,
            'elapsed_seconds' => $elapsed,
            'label' => 'Location jumped',
        ];
    }

    return $jumps;
}

/**
 * Evaluate geofence with hysteresis and consistency checks to reduce false positives.
 */
function evaluateGeofencePosition(
    float $latitude,
    float $longitude,
    float $centerLat,
    float $centerLng,
    int $radius,
    float $accuracy = 0.0,
    ?string $previousStatus = null,
    ?int $consistencyCount = null
): array {
    if ($centerLat == 0.0 && $centerLng == 0.0) {
        throw new Exception(
            'Event geofence center is not configured. Ask an administrator to set it in Geofence Map.'
        );
    }

    if ($radius <= 0) {
        throw new Exception(
            'Event geofence radius is not configured. Ask an administrator to set it in Geofence Map.'
        );
    }

    $config = require __DIR__ . '/../config/realtime.php';
    $maxBuffer = (int) ($config['geofence_accuracy_buffer_max'] ?? 40);
    $entryBuffer = (int) ($config['geofence_entry_buffer_m'] ?? 10);
    $exitBuffer = (int) ($config['geofence_exit_buffer_m'] ?? 10);
    $consistencyThreshold = (int) ($config['geofence_consistency_threshold'] ?? 3);

    $distance = haversineDistanceMeters($latitude, $longitude, $centerLat, $centerLng);
    $accuracyBuffer = min(max(0.0, $accuracy), (float) $maxBuffer);
    $effectiveRadius = $radius + $accuracyBuffer;

    if ($previousStatus === 'inside' && $distance <= $radius + $exitBuffer) {
        $newStatus = 'inside';
    } elseif ($previousStatus === 'outside' && $distance >= $radius - $entryBuffer) {
        $newStatus = 'outside';
    } elseif ($distance < $radius - $entryBuffer) {
        $newStatus = 'inside';
    } elseif ($distance > $radius + $exitBuffer) {
        $newStatus = 'outside';
    } elseif ($previousStatus !== null) {
        $newStatus = $previousStatus;
    } else {
        // First reading with no history: keep accuracy-adjusted decision.
        $newStatus = ($distance <= $effectiveRadius) ? 'inside' : 'outside';
    }

    $consistency = $consistencyCount ?? 0;
    $statusConfirmed = true;

    if ($previousStatus !== null && $newStatus !== $previousStatus) {
        $consistency++;

        if ($consistency < $consistencyThreshold) {
            $newStatus = $previousStatus;
            $statusConfirmed = false;
        } else {
            $consistency = 0;
        }
    } else {
        $consistency = 0;
    }

    $inside = ($newStatus === 'inside') ? 1 : 0;

    return [
        'distance' => $distance,
        'inside' => $inside,
        'location_status' => $newStatus,
        'accuracy_buffer' => $accuracyBuffer,
        'effective_radius' => $effectiveRadius,
        'consistency_count' => $consistency,
        'status_confirmed' => $statusConfirmed,
        'entry_buffer' => $entryBuffer,
        'exit_buffer' => $exitBuffer,
    ];
}

/**
 * Shared geofence evaluation for attendance location updates.
 */
function evaluateAttendanceGeofence(
    PDO $conn,
    int $attendanceId,
    float $latitude,
    float $longitude,
    float $centerLat,
    float $centerLng,
    int $radius,
    float $accuracy
): array {
    $state = fetchGeofenceStateForAttendance($conn, $attendanceId);

    $geo = evaluateGeofencePosition(
        $latitude,
        $longitude,
        $centerLat,
        $centerLng,
        $radius,
        $accuracy,
        $state['previous_status'],
        $state['consistency_count']
    );

    persistGeofenceConsistency($conn, $attendanceId, (int) $geo['consistency_count']);

    return $geo;
}

function buildOutsideWarningSmsMessage(string $firstName, string $lastName): string
{
    require_once __DIR__ . '/../config/sms.php';

    return SMS::outsideWarningMessage($firstName, $lastName);
}

function buildNoSignalWarningSmsMessage(string $firstName, string $lastName): string
{
    require_once __DIR__ . '/../config/sms.php';

    return SMS::noSignalWarningMessage($firstName, $lastName);
}

function buildNoSignalViolationSmsMessage(string $firstName, string $lastName): string
{
    require_once __DIR__ . '/../config/sms.php';

    return SMS::noSignalViolationMessage($firstName, $lastName);
}

function buildLocationOffViolationSmsMessage(string $firstName, string $lastName): string
{
    require_once __DIR__ . '/../config/sms.php';

    return SMS::locationOffViolationMessage($firstName, $lastName);
}

/**
 * @return array{silent_warning_seconds: int, silent_violation_seconds: int}
 */
function silentGapConfig(): array
{
    $config = require __DIR__ . '/../config/realtime.php';

    return [
        'silent_warning_seconds' => (int) (
            $config['monitoring_silent_warning_seconds']
            ?? (MONITORING_SILENT_WARNING_MINUTES * 60)
        ),
        'silent_violation_seconds' => (int) (
            $config['monitoring_silent_violation_seconds']
            ?? (MONITORING_SILENT_VIOLATION_MINUTES * 60)
        ),
    ];
}

function logMonitoringAlert(
    PDO $conn,
    int $userId,
    int $eventId,
    int $attendanceId,
    string $message,
    bool $smsSent
): void {
    $stmt = $conn->prepare("
        INSERT INTO alerts (user_id, event_id, attendance_id, message, is_sent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $eventId, $attendanceId, $message, $smsSent ? 1 : 0]);
}

function fetchMonitoringSession(PDO $conn, int $attendanceId): ?array
{
    $stmt = $conn->prepare("
        SELECT * FROM monitoring_sessions WHERE attendance_id = ? LIMIT 1
    ");
    $stmt->execute([$attendanceId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * SQL join that limits rows to each student's most recent scan for an event.
 */
function sqlJoinLatestScanPerUser(int $eventId): string
{
    return "
        INNER JOIN (
            SELECT a1.user_id, MAX(a1.attendance_id) AS attendance_id
            FROM attendance_sessions a1
            INNER JOIN (
                SELECT user_id, MAX(qr_scanned_at) AS max_scanned
                FROM attendance_sessions
                WHERE event_id = " . (int) $eventId . "
                GROUP BY user_id
            ) latest_scan ON a1.user_id = latest_scan.user_id
                AND a1.qr_scanned_at = latest_scan.max_scanned
                AND a1.event_id = " . (int) $eventId . "
            GROUP BY a1.user_id
        ) current_scan ON a.attendance_id = current_scan.attendance_id
    ";
}

/**
 * End monitoring from prior scans and prepare a clean session for the new scan.
 */
function resetMonitoringForNewScan(
    PDO $conn,
    int $userId,
    int $eventId,
    int $currentAttendanceId
): void {
    require_once __DIR__ . '/penalty_helpers.php';
    ensurePenaltyResolutionSchema($conn);

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions ms
            INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
            SET ms.status = 'completed',
                ms.ended_at = NOW(),
                ms.outside_started_at = NULL,
                ms.warning_sent_at = NULL
            WHERE a.user_id = ?
              AND a.event_id = ?
              AND a.attendance_id != ?
              AND ms.ended_at IS NULL
        ");
        $stmt->execute([$userId, $eventId, $currentAttendanceId]);
    } catch (PDOException $e) {
        // Non-fatal if schema is mid-migration.
    }

    refreshMonitoringAfterLocation($conn, $currentAttendanceId, 'inside', true);
}

/**
 * End live GPS monitoring when a student receives a logout stamp.
 */
function completeMonitoringForLogout(
    PDO $conn,
    int $userId,
    int $eventId
): void {
    require_once __DIR__ . '/penalty_helpers.php';
    ensurePenaltyResolutionSchema($conn);

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions ms
            INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
            SET ms.status = 'completed',
                ms.ended_at = NOW(),
                ms.outside_started_at = NULL,
                ms.warning_sent_at = NULL,
                ms.missed_ping_count = 0,
                ms.consistency_count = 0,
                ms.last_ping_at = NOW()
            WHERE a.user_id = ?
              AND a.event_id = ?
              AND ms.ended_at IS NULL
        ");
        $stmt->execute([$userId, $eventId]);
    } catch (PDOException $e) {
        // Non-fatal if schema is mid-migration.
    }
}

/**
 * Reset monitoring counters when a scan completes or a new scan starts.
 */
function refreshMonitoringAfterLocation(
    PDO $conn,
    int $attendanceId,
    string $locationStatus,
    bool $isNewScan = false
): void {
    require_once __DIR__ . '/penalty_helpers.php';
    ensurePenaltyResolutionSchema($conn);

    $monitor = fetchMonitoringSession($conn, $attendanceId);
    $isOutside = $locationStatus === 'outside';

    if (!$monitor) {
        try {
            if ($isOutside) {
                $stmt = $conn->prepare("
                    INSERT INTO monitoring_sessions
                    (attendance_id, status, started_at, outside_started_at, missed_ping_count)
                    VALUES (?, 'warning', NOW(), NOW(), 0)
                ");
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO monitoring_sessions
                    (attendance_id, status, started_at, missed_ping_count)
                    VALUES (?, 'active', NOW(), 0)
                ");
            }
            $stmt->execute([$attendanceId]);
        } catch (PDOException $e) {
            // Duplicate row or schema mid-migration — existing row will be updated on next ping.
        }

        return;
    }

    if ($isOutside) {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET status = 'warning',
                outside_started_at = COALESCE(outside_started_at, NOW()),
                warning_sent_at = NULL,
                admin_action = 'none',
                admin_action_at = NULL,
                admin_action_by = NULL,
                missed_ping_count = 0,
                last_ping_at = NOW(),
                ended_at = NULL,
                started_at = IF(? = 1, NOW(), started_at)
            WHERE attendance_id = ?
        ");
        $stmt->execute([$isNewScan ? 1 : 0, $attendanceId]);

        return;
    }

    $stmt = $conn->prepare("
        UPDATE monitoring_sessions
        SET status = 'active',
            outside_started_at = NULL,
            warning_sent_at = NULL,
            admin_action = 'none',
            admin_action_at = NULL,
            admin_action_by = NULL,
            missed_ping_count = 0,
            last_ping_at = NOW(),
            ended_at = NULL,
            started_at = IF(? = 1, NOW(), started_at)
        WHERE attendance_id = ?
    ");
    $stmt->execute([$isNewScan ? 1 : 0, $attendanceId]);
}

function processDueOutsideGeofenceWarnings(PDO $conn): int
{
    $stmt = $conn->prepare("
        SELECT ms.attendance_id
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        WHERE a.status IN ('present', 'late')
        AND ms.outside_started_at IS NOT NULL
        AND ms.warning_sent_at IS NULL
        AND (ms.admin_action IS NULL OR ms.admin_action = 'none')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;

    foreach ($rows as $row) {
        if (sendOutsideWarningIfDue($conn, (int) $row['attendance_id'])) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * Silence is measured from the later of the last real GPS fix and the
 * monitoring start. A void sets started_at to now, so an old GPS fix does
 * not immediately recreate the same penalty.
 */
function silentGapAnchorTimestamp(PDO $conn, int $attendanceId, string $startedAt, string $lastPingAt): int
{
    $gpsAt = lastValidGpsLoggedAt($conn, $attendanceId);
    $gpsTs = $gpsAt ? strtotime($gpsAt) : false;
    $startedTs = $startedAt !== '' ? strtotime($startedAt) : false;

    if ($gpsTs && $startedTs) {
        return max($gpsTs, $startedTs);
    }

    if ($gpsTs) {
        return $gpsTs;
    }

    $pingTs = $lastPingAt !== '' ? strtotime($lastPingAt) : false;

    if ($pingTs) {
        return $pingTs;
    }

    return $startedTs ?: 0;
}

function lastValidGpsLoggedAt(PDO $conn, int $attendanceId): ?string
{
    ensureLocationJumpSchema($conn);

    try {
        $stmt = $conn->prepare("
            SELECT MAX(logged_at)
            FROM location_logs
            WHERE attendance_id = ?
              AND latitude IS NOT NULL
              AND longitude IS NOT NULL
              AND latitude != 0
              AND longitude != 0
              AND location_status IN ('inside', 'outside')
              AND is_location_jump = 0
        ");
        $stmt->execute([$attendanceId]);
        $value = $stmt->fetchColumn();

        return $value ? (string) $value : null;
    } catch (PDOException $e) {
        return null;
    }
}

function sendMonitoringSms(
    PDO $conn,
    int $userId,
    int $eventId,
    int $attendanceId,
    string $number,
    string $message
): bool {
    $smsSent = false;
    $response = null;

    if ($number !== '') {
        require_once __DIR__ . '/../config/sms.php';
        $response = SMS::send($number, $message);
        $smsSent = SMS::wasSendSuccessful($response);
    }

    if (!$smsSent) {
        if ($number === '') {
            logMonitoringAlert(
                $conn,
                $userId,
                $eventId,
                $attendanceId,
                'SMS not sent: no contact number on file.',
                false
            );
        } else {
            require_once __DIR__ . '/../config/sms.php';
            $parsed = SMS::parseResponse($response ?? '');
            logMonitoringAlert(
                $conn,
                $userId,
                $eventId,
                $attendanceId,
                'SMS not sent: ' . ($parsed['message'] ?: 'SMS API request failed.'),
                false
            );
        }

        return false;
    }

    logMonitoringAlert($conn, $userId, $eventId, $attendanceId, $message, true);

    return true;
}

/**
 * 15-minute no-signal SMS, then 30-minute auto-penalty when no GPS upload arrives.
 *
 * @return array{warnings_sent: int, violations: int}
 */
function processSilentGpsGaps(PDO $conn): array
{
    require_once __DIR__ . '/penalty_helpers.php';
    ensurePenaltyResolutionSchema($conn);

    $thresholds = silentGapConfig();
    $warningSeconds = max(60, $thresholds['silent_warning_seconds']);
    $violationSeconds = max($warningSeconds + 60, $thresholds['silent_violation_seconds']);

    $stmt = $conn->prepare("
        SELECT
            ms.monitoring_id,
            ms.attendance_id,
            ms.last_ping_at,
            ms.started_at,
            ms.silent_warning_sent_at,
            COALESCE(ms.admin_action, 'none') AS admin_action,
            a.user_id,
            a.event_id,
            a.status AS attendance_status,
            u.firstname,
            u.lastname,
            u.contact_number
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        INNER JOIN users u ON a.user_id = u.user_id
        WHERE a.status IN ('present', 'late')
          AND a.scan_type IN ('AM_LOGIN', 'PM_LOGIN')
          AND ms.ended_at IS NULL
          AND (ms.admin_action IS NULL OR ms.admin_action = 'none')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $warnings = 0;
    $violations = 0;

    foreach ($rows as $row) {
        $attendanceId = (int) $row['attendance_id'];
        $anchorTs = silentGapAnchorTimestamp(
            $conn,
            $attendanceId,
            (string) ($row['started_at'] ?? ''),
            (string) ($row['last_ping_at'] ?? '')
        );

        if (!$anchorTs) {
            continue;
        }

        $silenceSeconds = time() - $anchorTs;

        if ($silenceSeconds >= $violationSeconds) {
            if (applyNoSignalViolation($conn, $row)) {
                $violations++;
            }

            continue;
        }

        if ($silenceSeconds >= $warningSeconds && empty($row['silent_warning_sent_at'])) {
            if (sendNoSignalWarningIfDue($conn, $row)) {
                $warnings++;
            }
        }
    }

    return [
        'warnings_sent' => $warnings,
        'violations' => $violations,
    ];
}

function sendNoSignalWarningIfDue(PDO $conn, array $row): bool
{
    $attendanceId = (int) $row['attendance_id'];
    $message = buildNoSignalWarningSmsMessage(
        (string) $row['firstname'],
        (string) $row['lastname']
    );
    $number = trim((string) ($row['contact_number'] ?? ''));

    $sent = sendMonitoringSms(
        $conn,
        (int) $row['user_id'],
        (int) $row['event_id'],
        $attendanceId,
        $number,
        $message
    );

    if (!$sent) {
        return false;
    }

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET silent_warning_sent_at = NOW(),
                status = 'warning'
            WHERE attendance_id = ?
              AND ended_at IS NULL
        ");
        $stmt->execute([$attendanceId]);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET status = 'warning'
            WHERE attendance_id = ?
              AND ended_at IS NULL
        ");
        $stmt->execute([$attendanceId]);
    }

    try {
        require_once __DIR__ . '/audit_helpers.php';
        logAudit(
            $conn,
            'student_no_signal_warning',
            'attendance_sessions',
            $attendanceId,
            'No GPS ping for 15+ minutes'
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }

    return true;
}

function applyNoSignalViolation(PDO $conn, array $row): bool
{
    require_once __DIR__ . '/penalty_helpers.php';
    require_once __DIR__ . '/event_helpers.php';

    ensurePenaltyResolutionSchema($conn);

    $attendanceId = (int) $row['attendance_id'];
    $userId = (int) $row['user_id'];
    $eventId = (int) $row['event_id'];

    if (!in_array((string) $row['attendance_status'], ['present', 'late'], true)) {
        return false;
    }

    if (($row['admin_action'] ?? 'none') === 'penalized') {
        return false;
    }

    $reason = 'No location updates for more than 30 minutes - signal lost during event';

    try {
        $stmt = $conn->prepare("
            INSERT INTO location_logs
            (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status)
            VALUES (?, NULL, NULL, NULL, 0, 'unavailable')
        ");
        $stmt->execute([$attendanceId]);
    } catch (PDOException $e) {
        // Continue even if the log insert fails.
    }

    $stmt = $conn->prepare("
        UPDATE attendance_sessions
        SET status = 'violated'
        WHERE attendance_id = ?
          AND status IN ('present', 'late')
    ");
    $stmt->execute([$attendanceId]);

    createPenaltyForAttendance(
        $conn,
        $attendanceId,
        $userId,
        $eventId,
        $reason,
        'location_off'
    );

    $message = buildNoSignalViolationSmsMessage(
        (string) $row['firstname'],
        (string) $row['lastname']
    );
    sendMonitoringSms(
        $conn,
        $userId,
        $eventId,
        $attendanceId,
        trim((string) ($row['contact_number'] ?? '')),
        $message
    );

    if (!empty($row['monitoring_id'])) {
        try {
            $stmt = $conn->prepare("
                UPDATE monitoring_sessions
                SET status = 'completed',
                    admin_action = 'penalized',
                    admin_action_at = NOW(),
                    ended_at = NOW()
                WHERE monitoring_id = ?
            ");
            $stmt->execute([(int) $row['monitoring_id']]);
        } catch (PDOException $e) {
            // Non-fatal.
        }
    }

    try {
        require_once __DIR__ . '/audit_helpers.php';
        logAudit(
            $conn,
            'student_no_signal_penalized',
            'attendance_sessions',
            $attendanceId,
            $reason
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }

    return true;
}

function sendOutsideWarningIfDue(PDO $conn, int $attendanceId): bool
{
    $stmt = $conn->prepare("
        SELECT
            ms.*,
            a.user_id,
            a.event_id,
            a.status AS attendance_status,
            u.firstname,
            u.lastname,
            u.contact_number
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN users u ON a.user_id = u.user_id
        WHERE ms.attendance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    if ($row['attendance_status'] !== 'present' && $row['attendance_status'] !== 'late') {
        return false;
    }

    if (!empty($row['warning_sent_at'])) {
        return false;
    }

    if (empty($row['outside_started_at'])) {
        return false;
    }

    $adminAction = $row['admin_action'] ?? 'none';

    if ($adminAction === 'penalized') {
        return false;
    }

    $outsideStart = strtotime($row['outside_started_at']);

    if (!$outsideStart) {
        return false;
    }

    $minutesOutside = (time() - $outsideStart) / 60;

    if ($minutesOutside < MONITORING_WARNING_MINUTES) {
        return false;
    }

    $message = buildOutsideWarningSmsMessage(
        (string) $row['firstname'],
        (string) $row['lastname']
    );

    $number = trim((string) ($row['contact_number'] ?? ''));
    $smsSent = false;
    $response = null;

    if ($number !== '') {
        require_once __DIR__ . '/../config/sms.php';
        $response = SMS::send($number, $message);
        $smsSent = SMS::wasSendSuccessful($response);
    }

    if (!$smsSent) {
        if ($number === '') {
            logMonitoringAlert(
                $conn,
                (int) $row['user_id'],
                (int) $row['event_id'],
                $attendanceId,
                'Outside-geofence warning not sent: no contact number on file.',
                false
            );
        } else {
            $parsed = SMS::parseResponse($response ?? '');
            logMonitoringAlert(
                $conn,
                (int) $row['user_id'],
                (int) $row['event_id'],
                $attendanceId,
                'Outside-geofence warning not sent: ' . ($parsed['message'] ?: 'SMS API request failed.'),
                false
            );
        }

        return false;
    }

    $stmt = $conn->prepare("
        UPDATE monitoring_sessions
        SET warning_sent_at = NOW(), status = 'warning'
        WHERE monitoring_id = ?
    ");
    $stmt->execute([(int) $row['monitoring_id']]);

    logMonitoringAlert(
        $conn,
        (int) $row['user_id'],
        (int) $row['event_id'],
        $attendanceId,
        $message,
        $smsSent
    );

    return true;
}

function handleMonitoringPing(PDO $conn, int $attendanceId, string $locationStatus): void
{
    $monitor = fetchMonitoringSession($conn, $attendanceId);

    if (!$monitor) {
        return;
    }

    $adminAction = $monitor['admin_action'] ?? 'none';

    if ($adminAction === 'penalized') {
        return;
    }

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET last_ping_at = NOW(), missed_ping_count = 0, silent_warning_sent_at = NULL
            WHERE attendance_id = ?
        ");
        $stmt->execute([$attendanceId]);
    } catch (PDOException $e) {
        try {
            $stmt = $conn->prepare("
                UPDATE monitoring_sessions
                SET last_ping_at = NOW(), missed_ping_count = 0
                WHERE attendance_id = ?
            ");
            $stmt->execute([$attendanceId]);
        } catch (PDOException $ignored) {
            // Columns may not exist yet.
        }
    }

    if ($locationStatus === 'outside') {
        if (empty($monitor['outside_started_at'])) {
            $stmt = $conn->prepare("
                UPDATE monitoring_sessions
                SET outside_started_at = NOW(),
                    status = 'warning',
                    admin_action = 'none',
                    admin_action_at = NULL,
                    admin_action_by = NULL
                WHERE attendance_id = ?
            ");
            $stmt->execute([$attendanceId]);

            try {
                require_once __DIR__ . '/audit_helpers.php';
                logAudit(
                    $conn,
                    'student_left_geofence',
                    'attendance_sessions',
                    $attendanceId,
                    'Continuous monitoring detected outside boundary'
                );
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }

        sendOutsideWarningIfDue($conn, $attendanceId);

        return;
    }

    $stmt = $conn->prepare("
        UPDATE monitoring_sessions
        SET outside_started_at = NULL,
            warning_sent_at = NULL,
            status = 'active'
        WHERE attendance_id = ?
    ");
    $stmt->execute([$attendanceId]);
}

function voidMonitoringWarning(PDO $conn, int $monitoringId, int $adminUserId): array
{
    $stmt = $conn->prepare("
        SELECT ms.*, a.user_id, a.event_id, a.status AS attendance_status
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        WHERE ms.monitoring_id = ?
        LIMIT 1
    ");
    $stmt->execute([$monitoringId]);
    $monitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$monitor) {
        return ['ok' => false, 'message' => 'Monitoring session not found.'];
    }

    if (($monitor['admin_action'] ?? 'none') !== 'none') {
        return ['ok' => false, 'message' => 'This case has already been resolved.'];
    }

    if (empty($monitor['outside_started_at']) && empty($monitor['warning_sent_at'])) {
        return ['ok' => false, 'message' => 'No active outside-geofence warning to void.'];
    }

    $stmt = $conn->prepare("
        UPDATE monitoring_sessions
        SET outside_started_at = NULL,
            warning_sent_at = NULL,
            status = 'returned',
            admin_action = 'voided',
            admin_action_at = NOW(),
            admin_action_by = ?
        WHERE monitoring_id = ?
    ");
    $stmt->execute([$adminUserId, $monitoringId]);

    return ['ok' => true, 'message' => 'Warning voided. Student may continue monitoring.'];
}

function voidMonitoringPenalty(PDO $conn, int $monitoringId, int $adminUserId): array
{
    require_once __DIR__ . '/event_helpers.php';
    require_once __DIR__ . '/penalty_helpers.php';

    ensurePenaltyResolutionSchema($conn);

    $stmt = $conn->prepare("
        SELECT
            ms.*,
            a.attendance_id,
            a.user_id,
            a.event_id,
            a.status AS attendance_status,
            COALESCE(a.is_late_scan, 0) AS is_late_scan
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        WHERE ms.monitoring_id = ?
        LIMIT 1
    ");
    $stmt->execute([$monitoringId]);
    $monitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$monitor) {
        return ['ok' => false, 'message' => 'Monitoring session not found.'];
    }

    if (($monitor['admin_action'] ?? 'none') !== 'penalized') {
        return ['ok' => false, 'message' => 'There is no applied penalty to void for this session.'];
    }

    if ((string) ($monitor['attendance_status'] ?? '') !== 'violated') {
        return ['ok' => false, 'message' => 'This attendance session is not marked violated.'];
    }

    try {
        $stmt = $conn->prepare("
            SELECT penalty_id, settlement_status, reason
            FROM penalties
            WHERE attendance_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([(int) $monitor['attendance_id']]);
        $penalty = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($penalty && attendancePenaltyHasRecordedPayment($conn, (int) $monitor['attendance_id'])) {
            return ['ok' => false, 'message' => 'This penalty already has a recorded payment and cannot be voided here.'];
        }
    } catch (PDOException $e) {
        $penalty = null;
    }

    $restoreStatus = !empty($monitor['is_late_scan']) ? 'late' : 'present';

    $stmt = $conn->prepare("
        UPDATE attendance_sessions
        SET status = ?
        WHERE attendance_id = ?
    ");
    $stmt->execute([$restoreStatus, (int) $monitor['attendance_id']]);

    clearUnsettledPenaltyForAttendance($conn, (int) $monitor['attendance_id']);

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET status = 'active',
                admin_action = 'none',
                admin_action_at = NULL,
                admin_action_by = NULL,
                ended_at = NULL,
                outside_started_at = NULL,
                warning_sent_at = NULL,
                silent_warning_sent_at = NULL,
                missed_ping_count = 0,
                last_ping_at = NOW(),
                started_at = NOW()
            WHERE monitoring_id = ?
        ");
        $stmt->execute([$monitoringId]);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET status = 'active',
                admin_action = 'none',
                admin_action_at = NULL,
                admin_action_by = NULL,
                ended_at = NULL,
                outside_started_at = NULL,
                warning_sent_at = NULL,
                missed_ping_count = 0,
                last_ping_at = NOW(),
                started_at = NOW()
            WHERE monitoring_id = ?
        ");
        $stmt->execute([$monitoringId]);
    }

    return [
        'ok' => true,
        'message' => 'Penalty voided. Attendance restored to ' . $restoreStatus
            . '. Live monitoring is active again. Location must stay on, or the same penalty can return after 30 minutes.',
    ];
}

function penalizeMonitoringViolation(PDO $conn, int $monitoringId, int $adminUserId): array
{
    require_once __DIR__ . '/event_helpers.php';

    $stmt = $conn->prepare("
        SELECT ms.*, a.attendance_id, a.user_id, a.event_id, a.status AS attendance_status
        FROM monitoring_sessions ms
        INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        WHERE ms.monitoring_id = ?
        LIMIT 1
    ");
    $stmt->execute([$monitoringId]);
    $monitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$monitor) {
        return ['ok' => false, 'message' => 'Monitoring session not found.'];
    }

    if (($monitor['admin_action'] ?? 'none') !== 'none') {
        return ['ok' => false, 'message' => 'This case has already been resolved.'];
    }

    $minutesOutside = 0;

    if (!empty($monitor['outside_started_at'])) {
        $minutesOutside = (int) floor((time() - strtotime($monitor['outside_started_at'])) / 60);
    }

    if (empty($monitor['warning_sent_at']) && $minutesOutside < MONITORING_WARNING_MINUTES) {
        return [
            'ok' => false,
            'message' => 'Penalty can only be applied after the 30-minute SMS warning has been sent.',
        ];
    }

    $attendanceId = (int) $monitor['attendance_id'];
    $userId = (int) $monitor['user_id'];
    $eventId = (int) $monitor['event_id'];

    $stmt = $conn->prepare("
        UPDATE attendance_sessions
        SET status = 'absent'
        WHERE attendance_id = ?
    ");
    $stmt->execute([$attendanceId]);

    createPenaltyForAttendance(
        $conn,
        $attendanceId,
        $userId,
        $eventId,
        'Left event venue for over 30 minutes without reporting to SSC'
    );

    $stmt = $conn->prepare("
        UPDATE monitoring_sessions
        SET status = 'outside_too_long',
            admin_action = 'penalized',
            admin_action_at = NOW(),
            admin_action_by = ?,
            ended_at = NOW()
        WHERE monitoring_id = ?
    ");
    $stmt->execute([$adminUserId, $monitoringId]);

    return ['ok' => true, 'message' => 'Student marked absent and penalized.'];
}

function hasRecentValidGpsLog(PDO $conn, int $attendanceId, ?int $maxAgeSeconds = null): bool
{
    if ($maxAgeSeconds === null) {
        $config = require __DIR__ . '/../config/realtime.php';
        $maxAgeSeconds = (int) ($config['monitoring_gps_stale_seconds'] ?? 120);
    }

    ensureLocationJumpSchema($conn);

    $stmt = $conn->prepare("
        SELECT 1
        FROM location_logs
        WHERE attendance_id = ?
          AND latitude IS NOT NULL
          AND longitude IS NOT NULL
          AND latitude != 0
          AND longitude != 0
          AND location_status IN ('inside', 'outside')
          AND is_location_jump = 0
          AND logged_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        LIMIT 1
    ");
    $stmt->execute([$attendanceId, $maxAgeSeconds]);

    return (bool) $stmt->fetchColumn();
}

function handleLocationOffDuringMonitoring(PDO $conn, int $attendanceId): array
{
    require_once __DIR__ . '/event_helpers.php';
    require_once __DIR__ . '/penalty_helpers.php';
    require_once __DIR__ . '/../config/sms.php';

    ensurePenaltyResolutionSchema($conn);

    if (hasRecentValidGpsLog($conn, $attendanceId)) {
        try {
            $stmt = $conn->prepare("
                UPDATE monitoring_sessions
                SET missed_ping_count = 0, last_ping_at = NOW()
                WHERE attendance_id = ?
            ");
            $stmt->execute([$attendanceId]);
        } catch (PDOException $e) {
            // Continue even if columns are missing.
        }

        return [
            'ok' => true,
            'message' => 'Recent GPS received — location service is active.',
            'violation' => false,
        ];
    }

    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.user_id,
            a.event_id,
            a.status AS attendance_status,
            u.firstname,
            u.lastname,
            u.contact_number,
            ms.monitoring_id,
            COALESCE(ms.admin_action, 'none') AS admin_action,
            COALESCE(ms.missed_ping_count, 0) AS missed_ping_count
        FROM attendance_sessions a
        INNER JOIN users u ON a.user_id = u.user_id
        LEFT JOIN monitoring_sessions ms ON ms.attendance_id = a.attendance_id
        WHERE a.attendance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && empty($row['monitoring_id'])) {
        $stmt = $conn->prepare("
            INSERT INTO monitoring_sessions (attendance_id, status, started_at, missed_ping_count)
            VALUES (?, 'active', NOW(), 0)
        ");
        $stmt->execute([$attendanceId]);

        $stmt = $conn->prepare("
            SELECT
                a.attendance_id,
                a.user_id,
                a.event_id,
                a.status AS attendance_status,
                u.firstname,
                u.lastname,
                u.contact_number,
                ms.monitoring_id,
                COALESCE(ms.admin_action, 'none') AS admin_action,
                COALESCE(ms.missed_ping_count, 0) AS missed_ping_count
            FROM attendance_sessions a
            INNER JOIN users u ON a.user_id = u.user_id
            LEFT JOIN monitoring_sessions ms ON ms.attendance_id = a.attendance_id
            WHERE a.attendance_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row || !in_array($row['attendance_status'], ['present', 'late'], true)) {
        return ['ok' => false, 'message' => 'No active present session to penalize.'];
    }

    if (($row['admin_action'] ?? 'none') === 'penalized') {
        return ['ok' => false, 'message' => 'Already penalized.'];
    }

    $config = require __DIR__ . '/../config/realtime.php';
    $missedThreshold = (int) (
        $config['monitoring_missed_pings_before_violation']
        ?? MONITORING_MISSED_PINGS_BEFORE_VIOLATION
    );

    try {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET missed_ping_count = missed_ping_count + 1,
                last_ping_at = NOW()
            WHERE attendance_id = ?
        ");
        $stmt->execute([$attendanceId]);
    } catch (PDOException $e) {
        // Continue even if columns are missing.
    }

    $stmt = $conn->prepare("
        SELECT COALESCE(missed_ping_count, 0) AS missed_ping_count
        FROM monitoring_sessions
        WHERE attendance_id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendanceId]);
    $missed = (int) ($stmt->fetchColumn() ?: 0);

    if ($missed < $missedThreshold) {
        return [
            'ok' => true,
            'message' => 'Location unavailable recorded.',
            'violation' => false,
            'missed_pings' => $missed,
        ];
    }

    $reason = 'Location service disabled - no GPS coordinates collected';

    $stmt = $conn->prepare("
        INSERT INTO location_logs
        (attendance_id, latitude, longitude, accuracy, is_inside_geofence, location_status)
        VALUES (?, NULL, NULL, NULL, 0, 'unavailable')
    ");
    $stmt->execute([$attendanceId]);

    $stmt = $conn->prepare("
        UPDATE attendance_sessions
        SET status = 'violated'
        WHERE attendance_id = ?
    ");
    $stmt->execute([$attendanceId]);

    createPenaltyForAttendance(
        $conn,
        (int) $attendanceId,
        (int) $row['user_id'],
        (int) $row['event_id'],
        $reason,
        'location_off'
    );

    sendMonitoringSms(
        $conn,
        (int) $row['user_id'],
        (int) $row['event_id'],
        (int) $attendanceId,
        trim((string) ($row['contact_number'] ?? '')),
        buildLocationOffViolationSmsMessage(
            (string) ($row['firstname'] ?? ''),
            (string) ($row['lastname'] ?? '')
        )
    );

    if (!empty($row['monitoring_id'])) {
        $stmt = $conn->prepare("
            UPDATE monitoring_sessions
            SET status = 'completed',
                admin_action = 'penalized',
                admin_action_at = NOW(),
                ended_at = NOW()
            WHERE monitoring_id = ?
        ");
        $stmt->execute([(int) $row['monitoring_id']]);
    }

    return [
        'ok' => true,
        'message' => 'Location service off — attendance marked violated with penalty.',
        'violation' => true,
    ];
}

function notifyStudentPenaltySms(PDO $conn, int $userId, int $eventId, string $reason): void
{
    // No generic penalty SMS. No-signal and location-off send their own messages.
}

function formatMinutesOutside(?int $minutes): string
{
    if ($minutes === null || $minutes < 0) {
        return '—';
    }

    if ($minutes < 60) {
        return $minutes . ' min';
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($mins === 0) {
        return $hours . ' hr';
    }

    return $hours . ' hr ' . $mins . ' min';
}
