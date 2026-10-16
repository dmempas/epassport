<?php

require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/attendance_schema.php';

/**
 * Automatic pass after scan windows close. Throttled so GPS/dashboard traffic
 * can apply absences without an admin clicking Evaluate Pending.
 *
 * @return array{marked_absent: int, penalties_created: int, windows_processed: int}|null
 */
function autoMarkUnscannedStudentsAbsentIfDue(PDO $conn, int $intervalSeconds = 45): ?array
{
    static $ranThisRequest = false;

    if ($ranThisRequest) {
        return null;
    }

    $ranThisRequest = true;

    if (!shouldRunUnscannedAbsentPass($conn, $intervalSeconds)) {
        return null;
    }

    return markUnscannedStudentsAbsentForClosedWindows($conn);
}

function shouldRunUnscannedAbsentPass(PDO $conn, int $intervalSeconds): bool
{
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist.
    }

    $key = 'unscanned_absent_last_run';
    $nowTs = time();

    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $last = $stmt->fetchColumn();

        if (is_string($last) && ctype_digit($last) && ($nowTs - (int) $last) < $intervalSeconds) {
            return false;
        }

        $upsert = $conn->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $upsert->execute([$key, (string) $nowTs]);
    } catch (PDOException $e) {
        return true;
    }

    return true;
}

/**
 * After a login window, wait until 30 minutes before the paired logout window.
 * Then auto-absent + penalize anyone never scanned that day for that stamp.
 * Logout stamps use the same rule after the logout window ends (all students).
 * Also converts leftover pending scans (QR scanned, never confirmed present) to absent.
 *
 * @return array{marked_absent: int, penalties_created: int, windows_processed: int}
 */
function markUnscannedStudentsAbsentForClosedWindows(PDO $conn): array
{
    ensureAttendanceSchema($conn);

    $result = [
        'marked_absent' => 0,
        'penalties_created' => 0,
        'windows_processed' => 0,
    ];

    $now = appDateTime($conn);
    $today = $now->format('Y-m-d');

    $events = $conn->query("
        SELECT *
        FROM events
        WHERE start_date IS NOT NULL
        AND end_date IS NOT NULL
        AND start_date <= CURDATE()
        AND end_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($events === []) {
        return $result;
    }

    foreach ($events as $event) {
        $eventId = (int) $event['event_id'];
        $rules = getEventScanRules($conn, $eventId);

        if ($rules === []) {
            continue;
        }

        $startDate = (string) $event['start_date'];
        $endDate = (string) $event['end_date'];
        $lastDay = $endDate < $today ? $endDate : $today;

        $dayPtr = DateTime::createFromFormat('Y-m-d', $startDate, appTimezoneObject());
        $lastPtr = DateTime::createFromFormat('Y-m-d', $lastDay, appTimezoneObject());

        if (!$dayPtr || !$lastPtr) {
            continue;
        }

        while ($dayPtr <= $lastPtr) {
            $dayStr = $dayPtr->format('Y-m-d');
            $dayRules = [];

            foreach ($rules as $rule) {
                if (scanRuleAppliesToDate($rule, $dayStr)) {
                    $dayRules[] = $rule;
                }
            }

            foreach ($dayRules as $rule) {
                if ((int) ($rule['is_required'] ?? 1) !== 1) {
                    continue;
                }

                $scanType = (string) $rule['scan_type'];
                $cutoff = unscannedAbsentCutoffDateTime($dayStr, $rule, $dayRules);

                if (!$cutoff || $now < $cutoff) {
                    continue;
                }

                $result['windows_processed']++;
                $windowResult = applyUnscannedAbsentsForScanWindow(
                    $conn,
                    $eventId,
                    $scanType,
                    $dayStr,
                    $cutoff->format('Y-m-d H:i:s')
                );
                $result['marked_absent'] += $windowResult['marked_absent'];
                $result['penalties_created'] += $windowResult['penalties_created'];
            }

            $dayPtr->modify('+1 day');
        }
    }

    return $result;
}

/**
 * @return array{marked_absent: int, penalties_created: int}
 */
function applyUnscannedAbsentsForScanWindow(
    PDO $conn,
    int $eventId,
    string $scanType,
    string $dayStr,
    string $stampAt
): array {
    $reason = isLoginScanType($scanType)
        ? 'Not scanned for ' . formatScanTypeLabel($scanType)
            . ' before late scanning closed (' . lateScanCutoffMinutes() . ' minutes before logout)'
        : 'Not scanned for ' . formatScanTypeLabel($scanType)
            . ' before the logout window closed';

    $insert = $conn->prepare("
        INSERT IGNORE INTO attendance_sessions
            (user_id, event_id, scanned_by_officer_id, scan_type, status, qr_scanned_at)
        SELECT
            u.user_id,
            ?,
            NULL,
            ?,
            'absent',
            ?
        FROM users u
        WHERE u.role_id = 2
          AND u.is_active = 1
          AND NOT EXISTS (
              SELECT 1
              FROM attendance_sessions existing
              WHERE existing.user_id = u.user_id
                AND existing.event_id = ?
                AND existing.scan_type = ?
                AND DATE(existing.qr_scanned_at) = ?
          )
    ");
    $insert->execute([
        $eventId,
        $scanType,
        $stampAt,
        $eventId,
        $scanType,
        $dayStr,
    ]);
    $marked = $insert->rowCount();

    $pendingClosed = closePendingAttendanceForScanWindow($conn, $eventId, $scanType, $dayStr, $reason);
    $marked += $pendingClosed['marked_absent'];

    $penalties = $pendingClosed['penalties_created'];

    $newRows = $conn->prepare("
        SELECT a.attendance_id, a.user_id
        FROM attendance_sessions a
        WHERE a.event_id = ?
          AND a.scan_type = ?
          AND DATE(a.qr_scanned_at) = ?
          AND a.status = 'absent'
          AND a.scanned_by_officer_id IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM penalties p WHERE p.attendance_id = a.attendance_id
          )
    ");
    $newRows->execute([$eventId, $scanType, $dayStr]);

    foreach ($newRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (createPenaltyForAttendance(
            $conn,
            (int) $row['attendance_id'],
            (int) $row['user_id'],
            $eventId,
            $reason
        )) {
            $penalties++;
        }
    }

    return [
        'marked_absent' => $marked,
        'penalties_created' => $penalties,
    ];
}

/**
 * QR was scanned (status still pending) but the student never became present.
 * After the stamp deadline, treat that as absent unless GPS already proved they were inside.
 *
 * @return array{marked_absent: int, penalties_created: int}
 */
function closePendingAttendanceForScanWindow(
    PDO $conn,
    int $eventId,
    string $scanType,
    string $dayStr,
    string $reason
): array {
    $stmt = $conn->prepare("
        SELECT attendance_id, user_id, scanned_by_officer_id, status
        FROM attendance_sessions
        WHERE event_id = ?
          AND scan_type = ?
          AND DATE(qr_scanned_at) = ?
          AND status = 'pending'
    ");
    $stmt->execute([$eventId, $scanType, $dayStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $marked = 0;
    $penalties = 0;

    foreach ($rows as $row) {
        $attendanceId = (int) $row['attendance_id'];
        $userId = (int) $row['user_id'];

        if (isLogoutScanType($scanType) && (int) ($row['scanned_by_officer_id'] ?? 0) > 0) {
            $conn->prepare("
                UPDATE attendance_sessions
                SET status = 'present'
                WHERE attendance_id = ? AND status = 'pending'
            ")->execute([$attendanceId]);
            continue;
        }

        if (isLoginScanType($scanType) && pendingAttendanceHasConfirmedInsideGps($conn, $attendanceId)) {
            $conn->prepare("
                UPDATE attendance_sessions
                SET status = 'present'
                WHERE attendance_id = ? AND status = 'pending'
            ")->execute([$attendanceId]);
            continue;
        }

        $updated = $conn->prepare("
            UPDATE attendance_sessions
            SET status = 'absent'
            WHERE attendance_id = ? AND status = 'pending'
        ");
        $updated->execute([$attendanceId]);

        if ($updated->rowCount() < 1) {
            continue;
        }

        $marked++;

        if (createPenaltyForAttendance($conn, $attendanceId, $userId, $eventId, $reason)) {
            $penalties++;
        }
    }

    return [
        'marked_absent' => $marked,
        'penalties_created' => $penalties,
    ];
}

function pendingAttendanceHasConfirmedInsideGps(PDO $conn, int $attendanceId): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT location_status, is_inside_geofence
            FROM location_logs
            WHERE attendance_id = ?
            ORDER BY location_id DESC
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }

    if (!$location) {
        return false;
    }

    $status = (string) ($location['location_status'] ?? '');

    if (in_array($status, ['denied', 'off', 'outside'], true)) {
        return false;
    }

    return (int) ($location['is_inside_geofence'] ?? 0) === 1;
}
