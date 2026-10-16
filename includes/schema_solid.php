<?php

/**
 * Bring a live e_passport database in line with database/e_passport.sql
 * without dropping data. Run from database/setup_schema.php, not on every request.
 */
function ensureSolidSchema(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    $statements = [
        "ALTER TABLE events ADD COLUMN duration_days DECIMAL(4,1) NOT NULL DEFAULT 1.0 AFTER end_date",
        "ALTER TABLE events ADD COLUMN half_day_part ENUM('am','pm') NOT NULL DEFAULT 'am' AFTER duration_days",
        "ALTER TABLE event_scan_requirements ADD COLUMN scan_date DATE NULL AFTER event_id",
        "ALTER TABLE attendance_sessions ADD COLUMN is_late_scan TINYINT(1) NOT NULL DEFAULT 0 AFTER is_rescan",
        "ALTER TABLE penalty_rules ADD COLUMN late_community_service_hours INT NOT NULL DEFAULT 0 AFTER monetary_amount",
        "ALTER TABLE penalty_rules ADD COLUMN late_monetary_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER late_community_service_hours",
        "ALTER TABLE penalties ADD COLUMN penalty_kind ENUM('absent','late','outside','location_denied','location_off','monitoring') DEFAULT NULL AFTER rules_id",
        "ALTER TABLE penalties ADD COLUMN hours_rendered INT NOT NULL DEFAULT 0 AFTER monetary_amount",
        "ALTER TABLE penalties ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER hours_rendered",
        "ALTER TABLE penalty_settlements ADD COLUMN monetary_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_service_hours",
        "ALTER TABLE penalty_settlements ADD COLUMN hours_rendered INT NOT NULL DEFAULT 0 AFTER monetary_paid",
        "ALTER TABLE users ADD KEY idx_users_role_active (role_id, is_active)",
        "ALTER TABLE users ADD KEY idx_users_name (lastname, firstname)",
        "ALTER TABLE users ADD KEY idx_users_course_year (course, year_level)",
        "ALTER TABLE events ADD KEY idx_events_name (event_name)",
        "ALTER TABLE events ADD KEY idx_events_active_dates (is_active, start_date, end_date)",
        "ALTER TABLE penalties ADD KEY idx_penalties_user_status (user_id, settlement_status)",
        "ALTER TABLE penalty_rules ADD UNIQUE KEY uq_penalty_rules_event (event_id)",
        "ALTER TABLE audit_logs ADD KEY idx_audit_created (created_at)",
        "ALTER TABLE audit_logs ADD KEY idx_audit_action (action)",
        "ALTER TABLE audit_logs ADD KEY idx_audit_table_record (table_name, record_id)",
    ];

    foreach ($statements as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Already applied.
        }
    }

    $modifies = [
        "ALTER TABLE users MODIFY course ENUM('BSIT','BSMB','BTLEd- HE','BTLEd-IA') DEFAULT NULL",
        "ALTER TABLE users MODIFY year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') DEFAULT NULL",
        "ALTER TABLE users MODIFY enrollment_status ENUM('regular','irregular') NOT NULL DEFAULT 'regular'",
        "ALTER TABLE attendance_sessions MODIFY status ENUM('pending','present','absent','violated','late') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE penalties MODIFY community_service_hours INT NOT NULL DEFAULT 0",
        "ALTER TABLE penalties MODIFY monetary_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        "ALTER TABLE penalties MODIFY community_service_status ENUM('pending','done') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE penalties MODIFY monetary_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid'",
        "ALTER TABLE penalties MODIFY resolution_choice ENUM('pending','monetary','community_service','mixed') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE penalties MODIFY settlement_status ENUM('pending','partial','settled') NOT NULL DEFAULT 'pending'",
        "ALTER TABLE penalty_settlements MODIFY resolution_choice ENUM('monetary','community_service','mixed') NOT NULL",
        "ALTER TABLE audit_logs MODIFY action VARCHAR(100) NOT NULL",
        "ALTER TABLE student_academic_history MODIFY previous_year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') DEFAULT NULL",
        "ALTER TABLE student_academic_history MODIFY new_year_level ENUM('1st Year','2nd Year','3rd Year','4th Year') DEFAULT NULL",
        "ALTER TABLE student_academic_history MODIFY previous_enrollment_status ENUM('regular','irregular') DEFAULT NULL",
        "ALTER TABLE student_academic_history MODIFY new_enrollment_status ENUM('regular','irregular') DEFAULT NULL",
    ];

    foreach ($modifies as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Ignore if data cannot convert yet.
        }
    }

    foreach (['center_lat', 'center_lng', 'radius'] as $col) {
        try {
            $conn->exec("ALTER TABLE events DROP COLUMN {$col}");
        } catch (PDOException $e) {
            // Column already gone.
        }
    }

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS penalty_settlement_allocations (
                allocation_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                settlement_id INT NOT NULL,
                penalty_id INT NOT NULL,
                monetary_applied DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                hours_applied INT NOT NULL DEFAULT 0,
                KEY idx_alloc_settlement (settlement_id),
                KEY idx_alloc_penalty (penalty_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist.
    }

    try {
        $conn->exec("DROP TABLE IF EXISTS geofence_settings");
        $conn->exec("DROP TABLE IF EXISTS qr_bindings");
    } catch (PDOException $e) {
        // Ignore.
    }

    try {
        $conn->exec("ALTER TABLE event_scan_requirements DROP INDEX uq_event_scan_type");
    } catch (PDOException $e) {
        // Already dropped.
    }

    ensurePerDayScanWindowsBackfill($conn);

    try {
        $conn->exec("
            ALTER TABLE event_scan_requirements
            ADD UNIQUE KEY uq_event_scan_day_type (event_id, scan_date, scan_type)
        ");
    } catch (PDOException $e) {
        // Already applied.
    }

    try {
        $conn->exec("ALTER TABLE event_scan_requirements ADD KEY idx_scan_event_date (event_id, scan_date)");
    } catch (PDOException $e) {
        // Already applied.
    }

    try {
        $conn->exec("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('schema_version', '3')
            ON DUPLICATE KEY UPDATE setting_value = '3'
        ");
    } catch (PDOException $e) {
        // system_settings may not exist yet; other helpers create it.
    }
}

function ensurePerDayScanWindowsBackfill(PDO $conn): void
{
    try {
        $conn->exec("
            UPDATE event_scan_requirements esr
            INNER JOIN events e ON e.event_id = esr.event_id
            SET esr.scan_date = e.start_date
            WHERE esr.scan_date IS NULL
              AND e.start_date IS NOT NULL
        ");
    } catch (PDOException $e) {
        return;
    }

    try {
        $events = $conn->query("
            SELECT event_id, start_date, end_date, duration_days, schedule_type
            FROM events
            WHERE start_date IS NOT NULL AND end_date IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return;
    }

    $insert = $conn->prepare("
        INSERT INTO event_scan_requirements
            (event_id, scan_date, scan_type, start_time, end_time, is_required)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $exists = $conn->prepare("
        SELECT scan_id FROM event_scan_requirements
        WHERE event_id = ? AND scan_date = ? AND scan_type = ?
        LIMIT 1
    ");
    $templates = $conn->prepare("
        SELECT scan_type, start_time, end_time, is_required
        FROM event_scan_requirements
        WHERE event_id = ?
        ORDER BY scan_id ASC
    ");

    foreach ($events as $event) {
        $eventId = (int) $event['event_id'];
        $start = (string) $event['start_date'];
        $end = (string) $event['end_date'];

        if ($start === '' || $end === '' || $end < $start) {
            continue;
        }

        $dayCount = (int) (new DateTime($start))->diff(new DateTime($end))->days + 1;
        $duration = (float) ($event['duration_days'] ?? 0);

        if ($duration < 1 || (abs($duration - 1.0) < 0.001 && $dayCount > 1)) {
            try {
                $conn->prepare("UPDATE events SET duration_days = ? WHERE event_id = ?")
                    ->execute([(float) $dayCount, $eventId]);
            } catch (PDOException $e) {
                // Column may be missing.
            }
        }

        $templates->execute([$eventId]);
        $rows = $templates->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            continue;
        }

        $byType = [];

        foreach ($rows as $row) {
            $type = (string) $row['scan_type'];

            if (!isset($byType[$type])) {
                $byType[$type] = $row;
            }
        }

        $cursor = new DateTime($start);
        $last = new DateTime($end);

        while ($cursor <= $last) {
            $day = $cursor->format('Y-m-d');

            foreach ($byType as $type => $row) {
                $exists->execute([$eventId, $day, $type]);

                if ($exists->fetch(PDO::FETCH_ASSOC)) {
                    continue;
                }

                try {
                    $insert->execute([
                        $eventId,
                        $day,
                        $type,
                        $row['start_time'],
                        $row['end_time'],
                        (int) ($row['is_required'] ?? 1),
                    ]);
                } catch (PDOException $e) {
                    // Unique or missing scan_date.
                }
            }

            $cursor->modify('+1 day');
        }
    }
}
