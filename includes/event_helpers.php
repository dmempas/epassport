<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Event readiness, schedule types, and scan-window validation.
 */

function eventScheduleTypes(): array
{
    return [
        'full_day' => [
            'label' => 'Whole day (4 stamps per day)',
            'scan_types' => ['AM_LOGIN', 'AM_LOGOUT', 'PM_LOGIN', 'PM_LOGOUT'],
        ],
        'half_day_am' => [
            'label' => 'Half day — morning (2 stamps per day)',
            'scan_types' => ['AM_LOGIN', 'AM_LOGOUT'],
        ],
        'half_day_pm' => [
            'label' => 'Half day — afternoon (2 stamps per day)',
            'scan_types' => ['PM_LOGIN', 'PM_LOGOUT'],
        ],
    ];
}

function getRequiredScanTypes(string $scheduleType): array
{
    $types = eventScheduleTypes();

    return $types[$scheduleType]['scan_types'] ?? $types['full_day']['scan_types'];
}

function eventDurationOptions(): array
{
    $options = [
        '0.5' => 'Half day only (1 date, 2 stamps)',
    ];

    for ($d = 1; $d <= 14; $d++) {
        $options[(string) $d] = $d === 1 ? '1 day (whole day, 4 stamps)' : $d . ' days (whole days, 4 stamps/day)';

        if ($d < 14) {
            $half = $d + 0.5;
            $cal = $d + 1;
            $options[(string) $half] = $d . ' days + half day (' . $half . ' days, ' . $cal . ' dates)';
        }
    }

    ksort($options, SORT_NUMERIC);

    return $options;
}

function eventDurationChoices(): array
{
    return eventDurationOptions();
}

function getExpectedStampsForEvent(array $event): int
{
    return getExpectedStampCountForEvent($event);
}

function calendarDayCountFromDuration(float $durationDays): int
{
    if ($durationDays <= 0) {
        return 0;
    }

    return (int) ceil($durationDays - 0.0001);
}

function durationHasHalfDay(float $durationDays): bool
{
    $frac = $durationDays - floor($durationDays);

    return $frac > 0.4 && $frac < 0.6;
}

function computeEventEndDate(string $startDate, float $durationDays): ?string
{
    $days = calendarDayCountFromDuration($durationDays);

    if ($days < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return null;
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);

    if (!$start) {
        return null;
    }

    $start->modify('+' . ($days - 1) . ' days');

    return $start->format('Y-m-d');
}

/**
 * One row per calendar day: date + full_day / half_day_am / half_day_pm.
 *
 * @return list<array{date: string, schedule_type: string, label: string}>
 */
function getEventDayPlans(array $event): array
{
    $startDate = (string) ($event['start_date'] ?? '');
    $endDate = (string) ($event['end_date'] ?? '');
    $duration = isset($event['duration_days']) ? (float) $event['duration_days'] : 0.0;
    $scheduleType = (string) ($event['schedule_type'] ?? 'full_day');
    $halfPart = (string) ($event['half_day_part'] ?? 'am');

    if ($duration <= 0 && $startDate !== '' && $endDate !== '') {
        $duration = (float) countEventDays($startDate, $endDate);
    }

    $calDays = calendarDayCountFromDuration($duration);

    if ($calDays < 1 || $startDate === '') {
        return [];
    }

    $hasTrailingHalf = durationHasHalfDay($duration);
    $allDaysHalf = in_array($scheduleType, ['half_day_am', 'half_day_pm'], true) && !$hasTrailingHalf;
    $lastHalfType = $halfPart === 'pm' ? 'half_day_pm' : 'half_day_am';
    $plans = [];
    $cursor = DateTime::createFromFormat('Y-m-d', $startDate);

    if (!$cursor) {
        return [];
    }

    for ($i = 0; $i < $calDays; $i++) {
        $date = $cursor->format('Y-m-d');
        $isLast = ($i === $calDays - 1);

        if ($hasTrailingHalf && $isLast) {
            $dayType = $lastHalfType;
        } elseif ($allDaysHalf) {
            $dayType = $scheduleType;
        } else {
            $dayType = 'full_day';
        }

        $meta = eventScheduleTypes()[$dayType] ?? eventScheduleTypes()['full_day'];
        $plans[] = [
            'date' => $date,
            'schedule_type' => $dayType,
            'label' => $meta['label'],
        ];
        $cursor->modify('+1 day');
    }

    return $plans;
}

function countEventDays(string $startDate, string $endDate): int
{
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);

    if ($end < $start) {
        return 0;
    }

    return (int) $start->diff($end)->days + 1;
}

function getExpectedStampCountForEvent(array $event): int
{
    $total = 0;

    foreach (getEventDayPlans($event) as $day) {
        $total += getScansPerDay($day['schedule_type']);
    }

    return $total;
}

function getScansPerDay(string $scheduleType): int
{
    return count(getRequiredScanTypes($scheduleType));
}

function durationHasTrailingHalfDay(float $durationDays): bool
{
    return durationHasHalfDay($durationDays);
}

function getExpectedTotalScans(string $scheduleType, string $startDate, string $endDate): int
{
    return countEventDays($startDate, $endDate) * getScansPerDay($scheduleType);
}

function eventHasGeofence(array $event): bool
{
    $lat = $event['geofence_latitude'] ?? null;
    $lng = $event['geofence_longitude'] ?? null;
    $radius = (int) ($event['geofence_radius'] ?? 0);

    return $lat !== null
        && $lng !== null
        && (float) $lat != 0.0
        && (float) $lng != 0.0
        && $radius > 0;
}

function eventHasPenaltyRule(PDO $conn, int $eventId): bool
{
    $stmt = $conn->prepare("
        SELECT rules_id FROM penalty_rules WHERE event_id = ? LIMIT 1
    ");
    $stmt->execute([$eventId]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

function getEventScanRules(PDO $conn, int $eventId): array
{
    $stmt = $conn->prepare("
        SELECT scan_id, event_id, scan_date, scan_type, start_time, end_time, is_required
        FROM event_scan_requirements
        WHERE event_id = ?
        ORDER BY scan_date ASC,
            FIELD(scan_type, 'AM_LOGIN', 'AM_LOGOUT', 'PM_LOGIN', 'PM_LOGOUT'),
            start_time
    ");

    try {
        $stmt->execute([$eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT scan_id, scan_type, start_time, end_time, is_required
            FROM event_scan_requirements
            WHERE event_id = ?
            ORDER BY FIELD(scan_type, 'AM_LOGIN', 'AM_LOGOUT', 'PM_LOGIN', 'PM_LOGOUT'), start_time
        ");
        $stmt->execute([$eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function scanRuleAppliesToDate(array $rule, string $date): bool
{
    $scanDate = (string) ($rule['scan_date'] ?? '');

    return $scanDate === '' || $scanDate === $date;
}

function getEventScanRulesForDate(PDO $conn, int $eventId, string $date): array
{
    $rules = [];

    foreach (getEventScanRules($conn, $eventId) as $rule) {
        if (scanRuleAppliesToDate($rule, $date)) {
            $rules[] = $rule;
        }
    }

    return $rules;
}

function validateScanRulesComplete(PDO $conn, int $eventId, string $scheduleType = 'full_day'): array
{
    $event = fetchEventById($conn, $eventId);

    if (!$event) {
        return ['Event not found.'];
    }

    $errors = [];
    $rules = getEventScanRules($conn, $eventId);
    $byDateType = [];

    foreach ($rules as $rule) {
        $date = (string) ($rule['scan_date'] ?? '');
        $type = (string) $rule['scan_type'];
        $key = $date . '|' . $type;

        if (isset($byDateType[$key])) {
            $errors[] = "Duplicate scan window for {$type} on {$date}.";
            continue;
        }

        $byDateType[$key] = $rule;

        if ($rule['start_time'] >= $rule['end_time']) {
            $errors[] = formatScanTypeLabel($type) . " on {$date}: end time must be later than start time.";
        }
    }

    foreach (getEventDayPlans($event) as $day) {
        $date = $day['date'];
        $required = getRequiredScanTypes($day['schedule_type']);

        foreach ($required as $type) {
            if (!isset($byDateType[$date . '|' . $type])) {
                $errors[] = 'Missing ' . formatScanTypeLabel($type) . ' window on ' . $date . '.';
            }
        }
    }

    return $errors;
}

function getEventReadiness(PDO $conn, int $eventId): array
{
    $stmt = $conn->prepare("SELECT * FROM events WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        return ['ok' => false, 'event' => null, 'checks' => [], 'errors' => ['Event not found.']];
    }

    $dayPlans = getEventDayPlans($event);
    $expectedStamps = getExpectedStampCountForEvent($event);
    $dayCount = count($dayPlans);
    $scanErrors = validateScanRulesComplete($conn, $eventId);

    $checks = [
        'details' => [
            'label' => 'Event details (name, duration, dates)',
            'ok' => trim($event['event_name'] ?? '') !== ''
                && !empty($event['start_date'])
                && !empty($event['end_date'])
                && $dayCount > 0,
        ],
        'geofence' => [
            'label' => 'Geofence boundary (center + radius)',
            'ok' => eventHasGeofence($event),
        ],
        'scan_rules' => [
            'label' => "Scan windows ({$expectedStamps} stamps across {$dayCount} date(s))",
            'ok' => empty($scanErrors),
        ],
        'penalty_rules' => [
            'label' => 'Penalty rules',
            'ok' => eventHasPenaltyRule($conn, $eventId),
        ],
    ];

    $errors = [];

    if (!$checks['details']['ok']) {
        $errors[] = 'Complete event name, duration, and start date.';
    }

    if (!$checks['geofence']['ok']) {
        $errors[] = 'Set the geofence on the Geofence Map before activating.';
    }

    if (!$checks['scan_rules']['ok']) {
        $errors = array_merge($errors, $scanErrors);
    }

    if (!$checks['penalty_rules']['ok']) {
        $errors[] = 'Configure penalty rules for this event.';
    }

    $ok = $checks['details']['ok']
        && $checks['geofence']['ok']
        && $checks['scan_rules']['ok']
        && $checks['penalty_rules']['ok'];

    return [
        'ok' => $ok,
        'event' => $event,
        'checks' => $checks,
        'errors' => $errors,
        'expected_stamps' => $expectedStamps,
        'scans_per_day' => $dayCount > 0 ? (int) round($expectedStamps / $dayCount) : 0,
        'day_count' => $dayCount,
        'day_plans' => $dayPlans,
    ];
}

function canActivateEvent(PDO $conn, int $eventId): array
{
    $readiness = getEventReadiness($conn, $eventId);

    return [
        'ok' => $readiness['ok'],
        'errors' => $readiness['errors'],
    ];
}

function deactivateAllEvents(PDO $conn): void
{
    $conn->exec("UPDATE events SET is_active = 0");
}

function activateEvent(PDO $conn, int $eventId): array
{
    $event = fetchEventById($conn, $eventId);

    if (!$event) {
        return ['ok' => false, 'errors' => ['Event not found.']];
    }

    if (isEventExpired($event)) {
        deactivateEventOnConfigChange($conn, $eventId);

        return [
            'ok' => false,
            'errors' => ['Cannot activate: this event has already ended (' . $event['end_date'] . ').'],
        ];
    }

    $check = canActivateEvent($conn, $eventId);

    if (!$check['ok']) {
        return $check;
    }

    deactivateAllEvents($conn);

    $stmt = $conn->prepare("UPDATE events SET is_active = 1 WHERE event_id = ?");
    $stmt->execute([$eventId]);

    return ['ok' => true, 'errors' => []];
}

function fetchEventById(PDO $conn, int $eventId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM events WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function isWithinEventDates(array $event, ?string $date = null, ?PDO $conn = null): bool
{
    $date = $date ?? appDate($conn);
    $start = $event['start_date'] ?? '';
    $end = $event['end_date'] ?? '';

    return $start !== '' && $end !== '' && $date >= $start && $date <= $end;
}

function isEventCurrentlyActive(array $event, ?PDO $conn = null): bool
{
    return (int) ($event['is_active'] ?? 0) === 1 && isWithinEventDates($event, null, $conn);
}

function timeInScanWindow(string $startTime, string $endTime, string $currentTime): bool
{
    $start = normalizeScanClock($startTime);
    $end = normalizeScanClock($endTime);
    $now = normalizeScanClock($currentTime);

    if ($start === '' || $end === '' || $now === '') {
        return false;
    }

    if ($start <= $end) {
        return $now >= $start && $now <= $end;
    }

    return $now >= $start || $now <= $end;
}

function normalizeScanClock(string $time): string
{
    $time = trim($time);

    if ($time === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $time, $match)) {
        return sprintf(
            '%02d:%02d:%02d',
            (int) $match[1],
            (int) $match[2],
            (int) ($match[3] ?? 0)
        );
    }

    try {
        return (new DateTime($time))->format('H:i:s');
    } catch (Throwable $e) {
        return $time;
    }
}

function resolveCurrentScanRule(PDO $conn, int $eventId, ?string $currentTime = null): ?array
{
    $currentTime = $currentTime ?? appTime($conn);
    $today = appDate($conn);

    foreach (getEventScanRulesForDate($conn, $eventId, $today) as $rule) {
        if ((int) ($rule['is_required'] ?? 1) !== 1) {
            continue;
        }

        if (timeInScanWindow($rule['start_time'], $rule['end_time'], $currentTime)) {
            return $rule;
        }
    }

    return null;
}

function formatScanTypeLabel(string $scanType): string
{
    return str_replace('_', ' ', $scanType);
}

function isLoginScanType(string $scanType): bool
{
    return in_array($scanType, ['AM_LOGIN', 'PM_LOGIN'], true);
}

function isLogoutScanType(string $scanType): bool
{
    return in_array($scanType, ['AM_LOGOUT', 'PM_LOGOUT'], true);
}

function pairedLogoutScanType(string $loginScanType): ?string
{
    return match ($loginScanType) {
        'AM_LOGIN' => 'AM_LOGOUT',
        'PM_LOGIN' => 'PM_LOGOUT',
        default => null,
    };
}

function pairedLoginScanType(string $logoutScanType): ?string
{
    return match ($logoutScanType) {
        'AM_LOGOUT' => 'AM_LOGIN',
        'PM_LOGOUT' => 'PM_LOGIN',
        default => null,
    };
}

function lateScanCutoffMinutes(): int
{
    return 30;
}

function findScanRuleByType(array $dayRules, string $scanType): ?array
{
    foreach ($dayRules as $rule) {
        if ((string) ($rule['scan_type'] ?? '') === $scanType) {
            return $rule;
        }
    }

    return null;
}

/**
 * When unscanned students are auto-absent for a stamp.
 * Login: 30 minutes before the paired logout window starts (late scans stay open until then).
 * Logout: when that logout window itself ends.
 */
function unscannedAbsentCutoffDateTime(string $date, array $rule, array $dayRules): ?DateTime
{
    $scanType = (string) ($rule['scan_type'] ?? '');

    if (isLoginScanType($scanType)) {
        $logoutType = pairedLogoutScanType($scanType);
        $logoutRule = $logoutType ? findScanRuleByType($dayRules, $logoutType) : null;

        if ($logoutRule) {
            $logoutStart = scanWindowDateTime($date, (string) $logoutRule['start_time']);

            if ($logoutStart) {
                $logoutStart->modify('-' . lateScanCutoffMinutes() . ' minutes');

                return $logoutStart;
            }
        }
    }

    return scanWindowEndDateTime($date, (string) ($rule['start_time'] ?? ''), (string) ($rule['end_time'] ?? ''));
}

function scanWindowEndDateTime(string $date, string $startTime, string $endTime): ?DateTime
{
    $start = scanWindowDateTime($date, $startTime);
    $end = scanWindowDateTime($date, $endTime);

    if (!$start || !$end) {
        return null;
    }

    if ($start > $end) {
        $end->modify('+1 day');
    }

    return $end;
}

function campusMapConfig(): array
{
    $cfg = appConfig()['campus'] ?? [];

    return [
        'name' => $cfg['name'] ?? 'USTP Panaon Campus, Punta, Panaon, Misamis Occidental',
        'postal_code' => $cfg['postal_code'] ?? '7205',
        'latitude' => (float) ($cfg['latitude'] ?? 8.38485),
        'longitude' => (float) ($cfg['longitude'] ?? 123.80435),
        'bounds' => $cfg['map_bounds'] ?? [
            'north' => 8.3885,
            'south' => 8.3810,
            'west' => 123.7985,
            'east' => 123.8110,
        ],
        'focus_points' => $cfg['focus_points'] ?? [
            ['lat' => 8.3842, 'lng' => 123.8015, 'label' => 'USTP Panaon'],
            ['lat' => 8.3855, 'lng' => 123.8072, 'label' => 'Punta Meriam'],
        ],
        'default_radius' => (int) ($cfg['default_radius'] ?? 200),
        'default_zoom' => (int) ($cfg['default_zoom'] ?? 17),
        'min_zoom' => (int) ($cfg['min_zoom'] ?? 16),
        'max_opening_zoom' => (int) ($cfg['max_opening_zoom'] ?? 18),
    ];
}

/**
 * Deactivate events whose end date has passed. Safe to call on every request.
 */
function expirePastEvents(PDO $conn): int
{
    $stmt = $conn->prepare("
        UPDATE events
        SET is_active = 0
        WHERE is_active = 1
        AND end_date < CURDATE()
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

function isEventExpired(array $event, ?string $date = null): bool
{
    $date = $date ?? appDate();
    $end = $event['end_date'] ?? '';

    return $end !== '' && $date > $end;
}

function deactivateEventOnConfigChange(PDO $conn, int $eventId): void
{
    $conn->prepare("UPDATE events SET is_active = 0 WHERE event_id = ?")
        ->execute([$eventId]);
}

function eventSetupLockedReason(): string
{
    return 'This event is active. Deactivate it first to change setup. You can still extend scan windows while it is live.';
}

function isEventSetupLocked(array $event): bool
{
    return (int) ($event['is_active'] ?? 0) === 1;
}

function eventHasLinkedRecords(PDO $conn, int $eventId): bool
{
    try {
        $stmt = $conn->prepare("SELECT 1 FROM attendance_sessions WHERE event_id = ? LIMIT 1");
        $stmt->execute([$eventId]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    } catch (PDOException $e) {
        // Older schemas may not have attendance_sessions.
    }

    try {
        $stmt = $conn->prepare("SELECT 1 FROM penalties WHERE event_id = ? LIMIT 1");
        $stmt->execute([$eventId]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }
    } catch (PDOException $e) {
        // Older schemas may not have penalties.event_id.
    }

    return false;
}

/**
 * @return array{ok: bool, errors: list<string>, event?: array}
 */
function rejectIfEventSetupLocked(PDO $conn, int $eventId): array
{
    $event = fetchEventById($conn, $eventId);

    if (!$event) {
        return ['ok' => false, 'errors' => ['Event not found.']];
    }

    if (isEventSetupLocked($event)) {
        return ['ok' => false, 'errors' => [eventSetupLockedReason()], 'event' => $event];
    }

    return ['ok' => true, 'errors' => [], 'event' => $event];
}

/**
 * @return array{ok: bool, error: string}
 */
function canDeleteEvent(PDO $conn, array $event): array
{
    if (isEventSetupLocked($event)) {
        return [
            'ok' => false,
            'error' => 'Cannot delete an active event. Deactivate it first.',
        ];
    }

    $eventId = (int) ($event['event_id'] ?? 0);

    if ($eventId > 0 && eventHasLinkedRecords($conn, $eventId)) {
        return [
            'ok' => false,
            'error' => 'Cannot delete an event that already has attendance or penalties.',
        ];
    }

    return ['ok' => true, 'error' => ''];
}

function getSetupWizardSteps(): array
{
    return [
        1 => ['key' => 'details', 'label' => 'Event Details', 'short' => 'Details'],
        2 => ['key' => 'geofence', 'label' => 'Geofence Boundary', 'short' => 'Geofence'],
        3 => ['key' => 'scans', 'label' => 'Scan Windows', 'short' => 'Scans'],
        4 => ['key' => 'penalty', 'label' => 'Penalty Rules', 'short' => 'Penalties'],
        5 => ['key' => 'review', 'label' => 'Review & Activate', 'short' => 'Review'],
    ];
}

function getSuggestedSetupStep(array $readiness): int
{
    $checks = $readiness['checks'] ?? [];

    if (empty($checks['details']['ok'])) {
        return 1;
    }

    if (empty($checks['geofence']['ok'])) {
        return 2;
    }

    if (empty($checks['scan_rules']['ok'])) {
        return 3;
    }

    if (empty($checks['penalty_rules']['ok'])) {
        return 4;
    }

    return 5;
}

function getDefaultScanTimeWindows(): array
{
    return [
        'AM_LOGIN' => ['start' => '07:30', 'end' => '08:30'],
        'AM_LOGOUT' => ['start' => '11:30', 'end' => '12:00'],
        'PM_LOGIN' => ['start' => '12:30', 'end' => '13:30'],
        'PM_LOGOUT' => ['start' => '16:30', 'end' => '17:00'],
    ];
}

function normalizePostedScanTime(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}

function saveEventScanRulesBulk(PDO $conn, int $eventId, array $windows): array
{
    $lock = rejectIfEventSetupLocked($conn, $eventId);

    if (!$lock['ok']) {
        return $lock;
    }

    $event = $lock['event'];

    $plans = getEventDayPlans($event);
    $errors = [];
    $normalized = [];

    foreach ($plans as $day) {
        $date = $day['date'];
        $required = getRequiredScanTypes($day['schedule_type']);

        foreach ($required as $type) {
            $start = trim((string) ($windows[$date][$type]['start'] ?? ''));
            $end = trim((string) ($windows[$date][$type]['end'] ?? ''));

            if ($start === '' || $end === '') {
                $errors[] = $date . ' — set start and end time for ' . formatScanTypeLabel($type) . '.';
                continue;
            }

            if ($start >= $end) {
                $errors[] = $date . ' — ' . formatScanTypeLabel($type) . ': end time must be later than start time.';
                continue;
            }

            $normalized[] = [
                'date' => $date,
                'type' => $type,
                'start' => normalizePostedScanTime($start),
                'end' => normalizePostedScanTime($end),
            ];
        }
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $find = $conn->prepare("
        SELECT scan_id FROM event_scan_requirements
        WHERE event_id = ? AND scan_date = ? AND scan_type = ?
        LIMIT 1
    ");
    $findLegacy = $conn->prepare("
        SELECT scan_id FROM event_scan_requirements
        WHERE event_id = ? AND scan_type = ? AND (scan_date IS NULL OR scan_date = '')
        LIMIT 1
    ");
    $update = $conn->prepare("
        UPDATE event_scan_requirements
        SET scan_date = ?, start_time = ?, end_time = ?, is_required = 1
        WHERE scan_id = ?
    ");
    $insert = $conn->prepare("
        INSERT INTO event_scan_requirements
            (event_id, scan_date, scan_type, start_time, end_time, is_required)
        VALUES (?, ?, ?, ?, ?, 1)
    ");

    $keepIds = [];

    foreach ($normalized as $row) {
        $find->execute([$eventId, $row['date'], $row['type']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $findLegacy->execute([$eventId, $row['type']]);
            $existing = $findLegacy->fetch(PDO::FETCH_ASSOC);
        }

        if ($existing) {
            $update->execute([$row['date'], $row['start'], $row['end'], $existing['scan_id']]);
            $keepIds[] = (int) $existing['scan_id'];
        } else {
            $insert->execute([$eventId, $row['date'], $row['type'], $row['start'], $row['end']]);
            $keepIds[] = (int) $conn->lastInsertId();
        }
    }

    pruneEventScanRulesNotInList($conn, $eventId, $keepIds);
    deactivateEventOnConfigChange($conn, $eventId);

    return ['ok' => true, 'errors' => []];
}

function pruneEventScanRulesNotInList(PDO $conn, int $eventId, array $keepIds): void
{
    $keepIds = array_values(array_unique(array_filter(array_map('intval', $keepIds))));

    if ($keepIds === []) {
        $conn->prepare("DELETE FROM event_scan_requirements WHERE event_id = ?")->execute([$eventId]);

        return;
    }

    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
    $params = array_merge([$eventId], $keepIds);
    $conn->prepare("
        DELETE FROM event_scan_requirements
        WHERE event_id = ?
        AND scan_id NOT IN ({$placeholders})
    ")->execute($params);
}

function saveEventPenaltyRule(
    PDO $conn,
    int $eventId,
    int $communityHours,
    float $monetaryAmount,
    string $description,
    int $lateCommunityHours = 0,
    float $lateMonetaryAmount = 0.0
): array {
    $lock = rejectIfEventSetupLocked($conn, $eventId);

    if (!$lock['ok']) {
        return $lock;
    }

    if ($communityHours < 0 || $monetaryAmount < 0 || $lateCommunityHours < 0 || $lateMonetaryAmount < 0) {
        return ['ok' => false, 'errors' => ['Penalty values cannot be negative.']];
    }

    if ($communityHours === 0 && $monetaryAmount <= 0) {
        return ['ok' => false, 'errors' => ['Set at least community service hours or a monetary amount for missed stamps.']];
    }

    if ($lateCommunityHours === 0 && $lateMonetaryAmount <= 0) {
        return ['ok' => false, 'errors' => ['Set at least community service hours or a monetary amount for late scans.']];
    }

    $stmt = $conn->prepare("SELECT rules_id FROM penalty_rules WHERE event_id = ? LIMIT 1");
    $stmt->execute([$eventId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        try {
            $stmt = $conn->prepare("
                UPDATE penalty_rules
                SET community_service_hours = ?, monetary_amount = ?,
                    late_community_service_hours = ?, late_monetary_amount = ?,
                    description = ?
                WHERE rules_id = ?
            ");
            $stmt->execute([
                $communityHours,
                $monetaryAmount,
                $lateCommunityHours,
                $lateMonetaryAmount,
                $description,
                $existing['rules_id'],
            ]);
        } catch (PDOException $e) {
            $stmt = $conn->prepare("
                UPDATE penalty_rules
                SET community_service_hours = ?, monetary_amount = ?, description = ?
                WHERE rules_id = ?
            ");
            $stmt->execute([$communityHours, $monetaryAmount, $description, $existing['rules_id']]);
        }
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO penalty_rules
                    (event_id, community_service_hours, monetary_amount,
                     late_community_service_hours, late_monetary_amount, description)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $eventId,
                $communityHours,
                $monetaryAmount,
                $lateCommunityHours,
                $lateMonetaryAmount,
                $description,
            ]);
        } catch (PDOException $e) {
            $stmt = $conn->prepare("
                INSERT INTO penalty_rules (event_id, community_service_hours, monetary_amount, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$eventId, $communityHours, $monetaryAmount, $description]);
        }
    }

    deactivateEventOnConfigChange($conn, $eventId);

    return ['ok' => true, 'errors' => []];
}

function getSetupProgress(array $readiness): array
{
    $checks = $readiness['checks'] ?? [];
    $total = count($checks);
    $completed = 0;

    foreach ($checks as $check) {
        if (!empty($check['ok'])) {
            $completed++;
        }
    }

    return [
        'completed' => $completed,
        'total' => $total,
        'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
    ];
}

function fetchScanRuleById(PDO $conn, int $scanId): ?array
{
    $stmt = $conn->prepare("
        SELECT scan_id, event_id, scan_date, scan_type, start_time, end_time, is_required
        FROM event_scan_requirements
        WHERE scan_id = ?
        LIMIT 1
    ");
    $stmt->execute([$scanId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getActiveEvent(PDO $conn): ?array
{
    expirePastEvents($conn);

    $stmt = $conn->prepare("
        SELECT * FROM events WHERE is_active = 1 ORDER BY event_id DESC LIMIT 1
    ");
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function eventIsReadyForOfficerScanning(PDO $conn, array $event): array
{
    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'reason' => 'This event is not active. Ask an administrator to activate it after setup is complete.',
        ];
    }

    if (!isWithinEventDates($event, null, $conn)) {
        $today = appDate($conn);

        return [
            'ok' => false,
            'reason' => "Scanning is only allowed between {$event['start_date']} and {$event['end_date']}. Today is {$today}.",
        ];
    }

    $ruleErrors = validateScanRulesComplete($conn, (int) $event['event_id']);

    if (!empty($ruleErrors)) {
        return [
            'ok' => false,
            'reason' => 'Event scan schedule is incomplete: ' . $ruleErrors[0],
        ];
    }

    if (!eventHasGeofence($event)) {
        return [
            'ok' => false,
            'reason' => 'Event geofence is not configured.',
        ];
    }

    if (!eventHasPenaltyRule($conn, (int) $event['event_id'])) {
        return [
            'ok' => false,
            'reason' => 'Event penalty rules are not configured.',
        ];
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * Whether officers may scan right now for the given event.
 */
function getScanAvailability(PDO $conn, array $event): array
{
    $ready = eventIsReadyForOfficerScanning($conn, $event);

    if (!$ready['ok']) {
        return [
            'allowed' => false,
            'reason' => $ready['reason'],
            'scan_rule' => null,
        ];
    }

    $scanRule = resolveCurrentScanRule($conn, (int) $event['event_id']);

    if (!$scanRule) {
        $windows = getEventScanRulesForDate($conn, (int) $event['event_id'], appDate($conn));
        $summary = [];

        foreach ($windows as $w) {
            $summary[] = formatScanTypeLabel($w['scan_type'])
                . ' ' . formatAppTimeRange($w['start_time'], $w['end_time']);
        }

        return [
            'allowed' => false,
            'reason' => 'No scan window is open right now (' . appTimezoneLabel() . ' ' . appNow('g:i A', $conn) . '). '
                . 'Scheduled windows: ' . implode('; ', $summary) . '.',
            'scan_rule' => null,
        ];
    }

    return [
        'allowed' => true,
        'reason' => '',
        'scan_rule' => $scanRule,
    ];
}

function getMostRecentlyClosedScanRule(PDO $conn, array $event): ?array
{
    $today = appDate($conn);
    $now = appTime($conn);
    $closed = [];

    foreach (getEventScanRulesForDate($conn, (int) $event['event_id'], $today) as $rule) {
        if ((int) ($rule['is_required'] ?? 1) !== 1) {
            continue;
        }

        $start = (string) $rule['start_time'];
        $end = (string) $rule['end_time'];

        if (timeInScanWindow($start, $end, $now)) {
            continue;
        }

        if ($start <= $end && $now > $end) {
            $closed[] = $rule;
        }
    }

    if ($closed === []) {
        return null;
    }

    usort($closed, static function (array $a, array $b): int {
        return strcmp((string) $b['end_time'], (string) $a['end_time']);
    });

    return $closed[0];
}

function getLateScanAvailability(PDO $conn, array $event): array
{
    $ready = eventIsReadyForOfficerScanning($conn, $event);

    if (!$ready['ok']) {
        return [
            'allowed' => false,
            'reason' => $ready['reason'],
            'scan_rule' => null,
        ];
    }

    if (resolveCurrentScanRule($conn, (int) $event['event_id'])) {
        return [
            'allowed' => false,
            'reason' => 'A regular scan window is still open. Use the normal scanner until it closes.',
            'scan_rule' => null,
        ];
    }

    $rule = getEligibleLateScanRule($conn, $event);

    if (!$rule) {
        return [
            'allowed' => false,
            'reason' => 'Late scanning is closed. Unscanned students are marked absent '
                . lateScanCutoffMinutes()
                . ' minutes before the next logout window.',
            'scan_rule' => null,
        ];
    }

    return [
        'allowed' => true,
        'reason' => '',
        'scan_rule' => $rule,
    ];
}

function getEligibleLateScanRule(PDO $conn, array $event): ?array
{
    $today = appDate($conn);
    $now = appDateTime($conn);
    $rules = getEventScanRulesForDate($conn, (int) $event['event_id'], $today);
    $eligible = [];

    foreach ($rules as $rule) {
        if ((int) ($rule['is_required'] ?? 1) !== 1) {
            continue;
        }

        $windowEnd = scanWindowEndDateTime($today, (string) $rule['start_time'], (string) $rule['end_time']);
        $cutoff = unscannedAbsentCutoffDateTime($today, $rule, $rules);

        if (!$windowEnd || !$cutoff) {
            continue;
        }

        if ($now <= $windowEnd) {
            continue;
        }

        if ($now >= $cutoff) {
            continue;
        }

        $eligible[] = $rule;
    }

    if ($eligible === []) {
        return null;
    }

    usort($eligible, static function (array $a, array $b): int {
        return strcmp((string) $b['end_time'], (string) $a['end_time']);
    });

    return $eligible[0];
}

/**
 * Next upcoming or currently open scan window for an active event.
 *
 * @return array{
 *     scan_type: string,
 *     start_time: string,
 *     end_time: string,
 *     starts_at: DateTime,
 *     ends_at: DateTime,
 *     is_open_now: bool,
 *     label: string,
 *     window_label: string
 * }|null
 */
function getNextScanWindow(PDO $conn, array $event): ?array
{
    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return null;
    }

    $eventId = (int) $event['event_id'];
    $rules = getEventScanRules($conn, $eventId);

    if ($rules === []) {
        return null;
    }

    $startDate = (string) ($event['start_date'] ?? '');
    $endDate = (string) ($event['end_date'] ?? '');

    if ($startDate === '' || $endDate === '') {
        return null;
    }

    $now = appDateTime($conn);
    $today = $now->format('Y-m-d');

    if ($today > $endDate) {
        return null;
    }

    if (isWithinEventDates($event, $today, $conn)) {
        $currentRule = resolveCurrentScanRule($conn, $eventId, appTime($conn));

        if ($currentRule) {
            return buildNextScanWindowResult(
                $currentRule,
                scanWindowDateTime($today, $currentRule['start_time']),
                scanWindowDateTime($today, $currentRule['end_time']),
                true
            );
        }
    }

    $fromDate = $today < $startDate ? $startDate : $today;
    $dayPtr = DateTime::createFromFormat('Y-m-d', $fromDate, appTimezoneObject()) ?: null;

    if (!$dayPtr) {
        return null;
    }

    $endDay = DateTime::createFromFormat('Y-m-d', $endDate, appTimezoneObject()) ?: null;

    if (!$endDay) {
        return null;
    }

    while ($dayPtr <= $endDay) {
        $dayStr = $dayPtr->format('Y-m-d');
        $candidates = [];

        foreach ($rules as $rule) {
            if ((int) ($rule['is_required'] ?? 1) !== 1) {
                continue;
            }

            if (!scanRuleAppliesToDate($rule, $dayStr)) {
                continue;
            }

            $startsAt = scanWindowDateTime($dayStr, $rule['start_time']);

            if (!$startsAt) {
                continue;
            }

            if ($dayStr === $today && $startsAt <= $now) {
                continue;
            }

            $endsAt = scanWindowDateTime($dayStr, $rule['end_time']);

            if (!$endsAt) {
                continue;
            }

            $candidates[] = [
                'rule' => $rule,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        }

        if ($candidates !== []) {
            usort($candidates, static fn(array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);
            $pick = $candidates[0];

            return buildNextScanWindowResult(
                $pick['rule'],
                $pick['starts_at'],
                $pick['ends_at'],
                false
            );
        }

        $dayPtr->modify('+1 day');
    }

    return null;
}

function scanWindowDateTime(string $date, string $time): ?DateTime
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . normalizeScanTime($time), appTimezoneObject());

    return $dt ?: null;
}

function normalizeScanTime(string $time): string
{
    $time = trim($time);

    if ($time === '') {
        return '00:00:00';
    }

    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    return $time;
}

function buildNextScanWindowResult(
    array $rule,
    DateTime $startsAt,
    DateTime $endsAt,
    bool $isOpenNow
): array {
    return [
        'scan_type' => (string) $rule['scan_type'],
        'start_time' => (string) $rule['start_time'],
        'end_time' => (string) $rule['end_time'],
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'is_open_now' => $isOpenNow,
        'label' => formatScanTypeLabel((string) $rule['scan_type']),
        'window_label' => formatScanTypeLabel((string) $rule['scan_type'])
            . ' ' . substr((string) $rule['start_time'], 0, 5)
            . '–' . substr((string) $rule['end_time'], 0, 5),
    ];
}

function formatNextScanDisplay(?array $nextScan, ?PDO $conn = null): ?string
{
    if (!$nextScan) {
        return null;
    }

    $today = appDate($conn);
    $tomorrow = (clone appDateTime($conn))->modify('+1 day')->format('Y-m-d');
    $scanDay = $nextScan['starts_at']->format('Y-m-d');
    $timeLabel = $nextScan['starts_at']->format('g:i A');
    $endTimeLabel = $nextScan['ends_at']->format('g:i A');
    $stampLabel = $nextScan['label'];

    if ($nextScan['is_open_now']) {
        return "Scanning open now — {$stampLabel} until {$endTimeLabel}";
    }

    if ($scanDay === $today) {
        return "Next scan: {$stampLabel} at {$timeLabel} today";
    }

    if ($scanDay === $tomorrow) {
        return "Next scan: {$stampLabel} at {$timeLabel} tomorrow";
    }

    return 'Next scan: ' . $stampLabel . ' at ' . $timeLabel . ' on ' . $nextScan['starts_at']->format('M j, Y');
}

/**
 * Resolve which event an officer may use for scanning.
 */
function resolveOfficerScanEvent(PDO $conn, ?int $officerSelectedEventId): array
{
    $active = getActiveEvent($conn);

    if (!$active) {
        return [
            'event' => null,
            'error' => 'There is no active event. Attendance scanning is disabled until an administrator activates a fully configured event.',
        ];
    }

    if ($officerSelectedEventId > 0 && (int) $officerSelectedEventId !== (int) $active['event_id']) {
        return [
            'event' => null,
            'error' => 'Your selected event does not match the system active event. Open the officer dashboard and select the active event.',
        ];
    }

    $availability = getScanAvailability($conn, $active);

    if (!$availability['allowed']) {
        return [
            'event' => $active,
            'error' => $availability['reason'],
            'availability' => $availability,
        ];
    }

    return [
        'event' => $active,
        'error' => null,
        'availability' => $availability,
    ];
}

function fetchAttendancePenalty(PDO $conn, int $attendanceId): ?array
{
    if ($attendanceId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT penalty_id, settlement_status, amount_paid, hours_rendered
            FROM penalties
            WHERE attendance_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function attendancePenaltyHasRecordedPayment(PDO $conn, int $attendanceId): bool
{
    $penalty = fetchAttendancePenalty($conn, $attendanceId);

    if (!$penalty) {
        return false;
    }

    $status = (string) ($penalty['settlement_status'] ?? 'pending');

    if (in_array($status, ['partial', 'settled'], true)) {
        return true;
    }

    return (float) ($penalty['amount_paid'] ?? 0) > 0
        || (int) ($penalty['hours_rendered'] ?? 0) > 0;
}

function clearUnsettledPenaltyForAttendance(PDO $conn, int $attendanceId): void
{
    if ($attendanceId <= 0) {
        return;
    }

    try {
        $stmt = $conn->prepare("
            DELETE FROM penalties
            WHERE attendance_id = ?
            AND COALESCE(settlement_status, 'pending') = 'pending'
            AND COALESCE(amount_paid, 0) = 0
            AND COALESCE(hours_rendered, 0) = 0
        ");
        $stmt->execute([$attendanceId]);
    } catch (PDOException $e) {
        try {
            $stmt = $conn->prepare("
                DELETE FROM penalties
                WHERE attendance_id = ?
                AND (settlement_status = 'pending' OR settlement_status IS NULL)
            ");
            $stmt->execute([$attendanceId]);
        } catch (PDOException $e2) {
            // Older schemas without settlement_status.
        }
    }
}

function penaltyExistsForAttendance(PDO $conn, int $attendanceId): bool
{
    try {
        $stmt = $conn->prepare("
            SELECT penalty_id FROM penalties WHERE attendance_id = ? LIMIT 1
        ");
        $stmt->execute([$attendanceId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT p.penalty_id
            FROM penalties p
            INNER JOIN attendance_sessions a
                ON p.user_id = a.user_id AND p.event_id = a.event_id
            WHERE a.attendance_id = ?
            LIMIT 1
        ");
        $stmt->execute([$attendanceId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

function createPenaltyForAttendance(
    PDO $conn,
    int $attendanceId,
    int $userId,
    int $eventId,
    string $reason,
    string $kind = 'absent'
): bool {

    if (penaltyExistsForAttendance($conn, $attendanceId)) {
        return false;
    }

    $event = fetchEventById($conn, $eventId);

    if (!$event) {
        return false;
    }

    $stmtRule = $conn->prepare("
        SELECT rules_id, monetary_amount, community_service_hours,
               late_community_service_hours, late_monetary_amount
        FROM penalty_rules WHERE event_id = ? LIMIT 1
    ");

    try {
        $stmtRule->execute([$eventId]);
        $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmtRule = $conn->prepare("
            SELECT rules_id, monetary_amount, community_service_hours
            FROM penalty_rules WHERE event_id = ? LIMIT 1
        ");
        $stmtRule->execute([$eventId]);
        $rule = $stmtRule->fetch(PDO::FETCH_ASSOC);
    }

    if (!$rule) {
        return false;
    }

    $hours = (int) $rule['community_service_hours'];
    $amount = (float) $rule['monetary_amount'];

    if ($kind === 'late') {
        $hours = (int) ($rule['late_community_service_hours'] ?? 0);
        $amount = (float) ($rule['late_monetary_amount'] ?? 0);
    }

    if ($hours <= 0 && $amount <= 0) {
        return false;
    }

    $kindSql = in_array($kind, ['absent', 'late', 'outside', 'location_denied', 'location_off', 'monitoring'], true)
        ? $kind
        : 'absent';

    try {
        $stmtInsert = $conn->prepare("
            INSERT INTO penalties
            (user_id, event_id, attendance_id, rules_id, penalty_kind, reason,
             community_service_hours, monetary_amount,
             community_service_status, monetary_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
        ");
        $stmtInsert->execute([
            $userId,
            $eventId,
            $attendanceId,
            $rule['rules_id'],
            $kindSql,
            $reason,
            $hours,
            $amount,
        ]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062 || (int) $e->getCode() === 23000) {
            return false;
        }

        try {
            $stmtInsert = $conn->prepare("
                INSERT INTO penalties
                (user_id, event_id, attendance_id, rules_id, reason,
                 community_service_hours, monetary_amount,
                 community_service_status, monetary_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
            ");
            $stmtInsert->execute([
                $userId,
                $eventId,
                $attendanceId,
                $rule['rules_id'],
                $reason,
                $hours,
                $amount,
            ]);
        } catch (PDOException $e2) {
            $stmtInsert = $conn->prepare("
                INSERT INTO penalties
                (user_id, event_id, rules_id, reason,
                 community_service_hours, monetary_amount,
                 community_service_status, monetary_status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', 'unpaid')
            ");
            $stmtInsert->execute([
                $userId,
                $eventId,
                $rule['rules_id'],
                $reason,
                $hours,
                $amount,
            ]);
        }
    }

    try {
        require_once __DIR__ . '/monitoring_helpers.php';
        notifyStudentPenaltySms($conn, $userId, $eventId, $reason);
    } catch (Throwable $e) {
        // SMS is best-effort.
    }

    return true;
}

function getStudentActiveMonitoringSession(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.event_id,
            a.scan_type,
            a.status,
            e.event_name,
            ms.monitoring_id,
            ms.status AS monitoring_status
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id AND e.is_active = 1
        LEFT JOIN monitoring_sessions ms ON ms.attendance_id = a.attendance_id
        WHERE a.user_id = ?
        AND a.scan_type IN ('AM_LOGIN', 'PM_LOGIN')
        AND a.status IN ('present', 'late')
        AND NOT EXISTS (
            SELECT 1
            FROM attendance_sessions newer
            WHERE newer.user_id = a.user_id
              AND newer.event_id = a.event_id
              AND newer.qr_scanned_at > a.qr_scanned_at
        )
        AND (
            ms.monitoring_id IS NULL
            OR (
                ms.status IN ('active', 'warning', 'returned')
                AND ms.ended_at IS NULL
            )
        )
        ORDER BY a.qr_scanned_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
