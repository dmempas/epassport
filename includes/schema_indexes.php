<?php

/**
 * Performance indexes and integrity constraints (non-geofence).
 */
function ensureDatabaseIndexes(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $statements = [
        "ALTER TABLE attendance_sessions ADD COLUMN qr_scan_date DATE GENERATED ALWAYS AS (DATE(qr_scanned_at)) STORED",
        "ALTER TABLE location_logs ADD KEY idx_loc_att_logged (attendance_id, logged_at)",
        "ALTER TABLE location_logs ADD KEY idx_loc_att_id (attendance_id, location_id)",
        "ALTER TABLE attendance_sessions ADD KEY idx_att_event_status (event_id, status)",
        "ALTER TABLE attendance_sessions ADD KEY idx_att_pending (status, qr_scanned_at)",
        "ALTER TABLE event_scan_requirements ADD UNIQUE KEY uq_event_scan_day_type (event_id, scan_date, scan_type)",
        "ALTER TABLE alerts ADD KEY idx_alerts_attendance (attendance_id)",
    ];

    foreach ($statements as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Already applied or unsupported.
        }
    }

    dedupeAttendanceDailyStamps($conn);
    dedupeMonitoringSessions($conn);
    dedupePenaltiesByAttendance($conn);

    try {
        $conn->exec("
            ALTER TABLE attendance_sessions
            ADD UNIQUE KEY uq_daily_stamp (user_id, event_id, scan_type, qr_scan_date)
        ");
    } catch (PDOException $e) {
        // May fail if duplicates remain or index exists.
    }

    try {
        $conn->exec("ALTER TABLE monitoring_sessions ADD UNIQUE KEY uq_monitor_attendance (attendance_id)");
    } catch (PDOException $e) {
        // May already exist.
    }

    try {
        $conn->exec(        "ALTER TABLE penalties ADD UNIQUE KEY uq_penalty_attendance (attendance_id)");
    } catch (PDOException $e) {
        // penalties.attendance_id may be nullable on old rows — skip if needed.
    }

    try {
        $conn->exec("ALTER TABLE penalties ADD KEY idx_penalties_user (user_id)");
    } catch (PDOException $e) {
        // Index may already exist.
    }

    try {
        $conn->exec("ALTER TABLE penalties ADD KEY idx_penalties_event_user (event_id, user_id)");
    } catch (PDOException $e) {
        // Index may already exist.
    }
}

function dedupeAttendanceDailyStamps(PDO $conn): void
{
    try {
        $conn->exec("
            DELETE a1 FROM attendance_sessions a1
            INNER JOIN attendance_sessions a2
                ON a1.user_id = a2.user_id
               AND a1.event_id = a2.event_id
               AND a1.scan_type = a2.scan_type
               AND DATE(a1.qr_scanned_at) = DATE(a2.qr_scanned_at)
               AND a1.attendance_id > a2.attendance_id
        ");
    } catch (PDOException $e) {
        // Non-fatal.
    }
}

function dedupeMonitoringSessions(PDO $conn): void
{
    try {
        $conn->exec("
            DELETE ms1 FROM monitoring_sessions ms1
            INNER JOIN monitoring_sessions ms2
                ON ms1.attendance_id = ms2.attendance_id
               AND ms1.monitoring_id > ms2.monitoring_id
        ");
    } catch (PDOException $e) {
        // Non-fatal.
    }
}

function dedupePenaltiesByAttendance(PDO $conn): void
{
    try {
        $conn->exec("
            DELETE p1 FROM penalties p1
            INNER JOIN penalties p2
                ON p1.attendance_id = p2.attendance_id
               AND p1.attendance_id IS NOT NULL
               AND p1.penalty_id > p2.penalty_id
        ");
    } catch (PDOException $e) {
        // Non-fatal.
    }
}
