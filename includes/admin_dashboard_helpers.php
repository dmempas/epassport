<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/penalty_helpers.php';
require_once __DIR__ . '/audit_helpers.php';
require_once __DIR__ . '/attendance_schema.php';

/**
 * Aggregate metrics and context for the admin home dashboard.
 */
function getAdminDashboardSummary(PDO $conn): array
{
    ensureAttendanceSchema($conn);
    ensureStudentAcademicSchema($conn);

    $today = appDate($conn);
    $activeSchoolYear = getActiveSchoolYear($conn);
    $activeEvent = getActiveEvent($conn);

    $userCount = (int) $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $activeUserCount = (int) $conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

    $roleStmt = $conn->query("
        SELECT role_id, COUNT(*) AS total
        FROM users
        WHERE is_active = 1
        GROUP BY role_id
    ");
    $roleCounts = [1 => 0, 2 => 0, 3 => 0];
    foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roleCounts[(int) $row['role_id']] = (int) $row['total'];
    }

    $eventCount = (int) $conn->query("SELECT COUNT(*) FROM events")->fetchColumn();
    $activeEventCount = (int) $conn->query("SELECT COUNT(*) FROM events WHERE is_active = 1")->fetchColumn();

    $validationStmt = $conn->prepare("
        SELECT COUNT(*) FROM users
        WHERE role_id IN (2, 3)
          AND is_active = 1
          AND (validated_school_year IS NULL OR validated_school_year != ?)
    ");
    $validationStmt->execute([$activeSchoolYear]);
    $validationNeedsCount = (int) $validationStmt->fetchColumn();

    $attendanceSql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN status = 'violated' THEN 1 ELSE 0 END) AS violated,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            COUNT(DISTINCT user_id) AS unique_students
        FROM attendance_sessions
        WHERE DATE(qr_scanned_at) = ?
    ";
    $attendanceParams = [$today];

    if ($activeEvent) {
        $attendanceSql .= " AND event_id = ?";
        $attendanceParams[] = (int) $activeEvent['event_id'];
    }

    $attendanceStmt = $conn->prepare($attendanceSql);
    $attendanceStmt->execute($attendanceParams);
    $todayAttendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $penaltyStats = getAdminPenaltyStatistics($conn, $activeSchoolYear);
    $recentAudit = fetchAuditLogs($conn, null, null, 8);

    $incompleteEvents = [];
    $eventStmt = $conn->prepare("
        SELECT event_id, event_name, start_date, end_date, is_active
        FROM events
        WHERE is_active = 0 AND end_date >= ?
        ORDER BY start_date ASC
        LIMIT 12
    ");
    $eventStmt->execute([$today]);

    foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $eventRow) {
        $readiness = getEventReadiness($conn, (int) $eventRow['event_id']);
        if (empty($readiness['ok'])) {
            $incompleteEvents[] = [
                'event' => $eventRow,
                'progress' => getSetupProgress($readiness),
                'suggested_step' => getSuggestedSetupStep($readiness),
            ];
        }
    }

    $liveAlertCount = 0;
    if ($activeEvent && eventHasGeofence($activeEvent)) {
        $alertStmt = $conn->prepare("
            SELECT COUNT(DISTINCT ms.monitoring_id)
            FROM monitoring_sessions ms
            INNER JOIN attendance_sessions a ON ms.attendance_id = a.attendance_id
            WHERE a.event_id = ?
              AND ms.ended_at IS NULL
              AND (
                  (ms.outside_started_at IS NOT NULL AND a.status IN ('present', 'late'))
                  OR (ms.warning_sent_at IS NOT NULL AND COALESCE(ms.admin_action, 'none') = 'none')
                  OR a.status = 'violated'
              )
        ");
        $alertStmt->execute([(int) $activeEvent['event_id']]);
        $liveAlertCount = (int) $alertStmt->fetchColumn();
    }

    $scanAvailability = $activeEvent ? getScanAvailability($conn, $activeEvent) : null;
    $scanWindows = $activeEvent
        ? getEventScanRulesForDate($conn, (int) $activeEvent['event_id'], $today)
        : [];
    $nextScan = $activeEvent ? getNextScanWindow($conn, $activeEvent) : null;

    return [
        'today' => $today,
        'active_school_year' => $activeSchoolYear,
        'user_count' => $userCount,
        'active_user_count' => $activeUserCount,
        'role_counts' => $roleCounts,
        'event_count' => $eventCount,
        'active_event_count' => $activeEventCount,
        'active_event' => $activeEvent,
        'scan_availability' => $scanAvailability,
        'scan_windows' => $scanWindows,
        'next_scan' => $nextScan,
        'today_attendance' => [
            'total' => (int) ($todayAttendance['total'] ?? 0),
            'present' => (int) ($todayAttendance['present'] ?? 0),
            'late' => (int) ($todayAttendance['late'] ?? 0),
            'absent' => (int) ($todayAttendance['absent'] ?? 0),
            'violated' => (int) ($todayAttendance['violated'] ?? 0),
            'pending' => (int) ($todayAttendance['pending'] ?? 0),
            'unique_students' => (int) ($todayAttendance['unique_students'] ?? 0),
        ],
        'penalty_stats' => $penaltyStats,
        'validation_needs_count' => $validationNeedsCount,
        'incomplete_events' => $incompleteEvents,
        'recent_audit' => $recentAudit,
        'live_alert_count' => $liveAlertCount,
    ];
}

function formatAuditActor(array $row): string
{
    $firstname = trim((string) ($row['firstname'] ?? ''));
    $lastname = trim((string) ($row['lastname'] ?? ''));

    if ($firstname !== '' || $lastname !== '') {
        return trim($firstname . ' ' . $lastname);
    }

    return auditRoleLabel(isset($row['role_id']) ? (int) $row['role_id'] : null);
}
