<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/attendance_helpers.php';
require_once __DIR__ . '/monitoring_helpers.php';

/**
 * Primary: host cron every minute (cron/expire_events.php).
 * Fallback: web traffic also runs these jobs; admin/officer pages force
 * the unscanned-absent pass if cron missed the cutoff.
 *
 * @return array{
 *     skipped: bool,
 *     expired_events: int,
 *     unscanned_marked_absent: int,
 *     unscanned_penalties_created: int,
 *     outside_warnings_sent: int
 * }
 */
function runEpassportBackgroundJobs(PDO $conn, bool $force = false): array
{
    static $ranThisRequest = false;
    static $lastResult = null;

    $empty = [
        'skipped' => true,
        'expired_events' => 0,
        'unscanned_marked_absent' => 0,
        'unscanned_penalties_created' => 0,
        'outside_warnings_sent' => 0,
        'silent_warnings_sent' => 0,
        'silent_violations' => 0,
    ];

    if ($ranThisRequest && !$force) {
        return $lastResult ?? $empty;
    }

    $ranThisRequest = true;

    if (!$force && !shouldRunBackgroundJobs($conn, 20)) {
        $lastResult = $empty;

        return $empty;
    }

    $expired = 0;
    $unscanned = [
        'marked_absent' => 0,
        'penalties_created' => 0,
    ];
    $warnings = 0;
    $silent = [
        'warnings_sent' => 0,
        'violations' => 0,
    ];

    try {
        $expired = expirePastEvents($conn);
    } catch (Throwable $e) {
        // Continue remaining jobs.
    }

    try {
        $unscanned = markUnscannedStudentsAbsentForClosedWindows($conn);
    } catch (Throwable $e) {
        // Continue remaining jobs.
    }

    try {
        removeEpassportAttendanceScheduledTask();
    } catch (Throwable $e) {
        // Cleanup must not block jobs.
    }

    try {
        $warnings = processDueOutsideGeofenceWarnings($conn);
    } catch (Throwable $e) {
        // Continue remaining jobs.
    }

    try {
        $silent = processSilentGpsGaps($conn);
    } catch (Throwable $e) {
        // Continue remaining jobs.
    }

    $lastResult = [
        'skipped' => false,
        'expired_events' => (int) $expired,
        'unscanned_marked_absent' => (int) ($unscanned['marked_absent'] ?? 0),
        'unscanned_penalties_created' => (int) ($unscanned['penalties_created'] ?? 0),
        'outside_warnings_sent' => (int) $warnings,
        'silent_warnings_sent' => (int) ($silent['warnings_sent'] ?? 0),
        'silent_violations' => (int) ($silent['violations'] ?? 0),
    ];

    return $lastResult;
}

function shouldRunBackgroundJobs(PDO $conn, int $intervalSeconds): bool
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

    $key = 'background_jobs_last_run';
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
 * Cron is the primary timer. This is the precaution: if cron missed the cutoff,
 * opening the admin (or officer) portal still marks unscanned students absent.
 */
function runEpassportAttendanceFallback(PDO $conn): void
{
    $roleId = (int) ($_SESSION['role_id'] ?? 0);

    if ($roleId !== 1 && $roleId !== 3) {
        return;
    }

    markUnscannedStudentsAbsentForClosedWindows($conn);
}

function epassport_render_background_jobs_script(): void
{
    $script = epassport_url('assets/js/background-jobs.js');
    $tick = epassport_url('ajax/background_tick.php');
    ?>
    <script src="<?= htmlspecialchars($script) ?>" data-tick-url="<?= htmlspecialchars($tick) ?>" defer></script>
    <?php
}

/**
 * Remove the Windows minute-task that flashed a CMD window. Jobs now run via
 * Apache (page load / localhost HTTP) without launching php.exe.
 */
function removeEpassportAttendanceScheduledTask(): void
{
    static $tried = false;

    if ($tried) {
        return;
    }

    $tried = true;

    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }

    $queryOut = [];
    $queryCode = 1;
    @exec('schtasks /Query /TN "E-PASSPORT Auto Attendance" 2>NUL', $queryOut, $queryCode);

    if ((int) $queryCode !== 0) {
        return;
    }

    @exec('schtasks /Delete /TN "E-PASSPORT Auto Attendance" /F 2>NUL');
}
