<?php

/**
 * Host cron (every minute) is the primary runner.
 * Example: php C:/xampp/htdocs/E-PASSPORT/cron/expire_events.php
 *
 * Fallback: if this did not run, opening admin/officer pages still marks
 * unscanned students absent (see runEpassportAttendanceFallback).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/background_jobs.php';

$db = new Database();
$conn = $db->connect();

$result = runEpassportBackgroundJobs($conn, true);

echo appNow('Y-m-d H:i:s') . ' (' . appTimezoneLabel() . ') — '
    . 'Expired ' . (int) $result['expired_events'] . ' event(s). '
    . 'Unscanned marked absent: ' . (int) $result['unscanned_marked_absent'] . '. '
    . 'Outside warnings sent: ' . (int) $result['outside_warnings_sent'] . ".\n";
