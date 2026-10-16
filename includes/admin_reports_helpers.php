<?php

require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/audit_helpers.php';
require_once __DIR__ . '/attendance_schema.php';
require_once __DIR__ . '/penalty_helpers.php';
require_once __DIR__ . '/admin_export_helpers.php';

/**
 * High-level counts for the admin reports landing page.
 */
function getAdminReportsOverview(PDO $conn): array
{
    ensureAttendanceSchema($conn);

    $activeSchoolYear = getActiveSchoolYear($conn);
    $activeEvent = getActiveEvent($conn);

    $attendanceCount = (int) $conn->query("SELECT COUNT(*) FROM attendance_sessions")->fetchColumn();
    $penaltyCount = (int) $conn->query("SELECT COUNT(DISTINCT user_id) FROM penalties")->fetchColumn();
    $userCount = (int) $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $eventCount = (int) $conn->query("SELECT COUNT(*) FROM events")->fetchColumn();

    $fromDate = appNow('Y-m-01', $conn);
    $toDate = appDate($conn);
    $auditCount = count(fetchAuditLogs($conn, $fromDate, $toDate, 5000));

    return [
        'active_school_year' => $activeSchoolYear,
        'active_event' => $activeEvent,
        'attendance_count' => $attendanceCount,
        'penalty_count' => $penaltyCount,
        'audit_count_month' => $auditCount,
        'user_count' => $userCount,
        'event_count' => $eventCount,
        'default_audit_from' => $fromDate,
        'default_audit_to' => $toDate,
    ];
}

function countAttendanceReportRows(PDO $conn, int $eventId, string $course = '', string $yearLevel = '', string $status = ''): int
{
    if ($eventId <= 0) {
        return 0;
    }

    $sql = "
        SELECT COUNT(*)
        FROM attendance_sessions a
        INNER JOIN users u ON a.user_id = u.user_id
        WHERE a.event_id = ?
    ";
    $params = [$eventId];

    if ($course !== '') {
        $sql .= " AND u.course = ?";
        $params[] = $course;
    }

    if ($yearLevel !== '') {
        $sql .= " AND u.year_level = ?";
        $params[] = $yearLevel;
    }

    if ($status !== '') {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function countPenaltyReportRows(PDO $conn, array $filters = []): int
{
    return countPenaltyStudentsList($conn, $filters);
}

function countAuditReportRows(PDO $conn, string $fromDate, string $toDate, array $filters = []): int
{
    return count(fetchAuditLogs($conn, $fromDate, $toDate, 5000, $filters));
}

function countUsersReportRows(PDO $conn, array $filters = []): int
{
    return count(fetchUsersForExport($conn, $filters));
}

function countEventsReportRows(PDO $conn, array $filters = []): int
{
    return count(fetchEventsForExport($conn, $filters));
}

function reportsDefaultEventId(PDO $conn, array $events, int $requestedId = 0): int
{
    if ($requestedId > 0) {
        return $requestedId;
    }

    $activeEvent = getActiveEvent($conn);
    if ($activeEvent) {
        return (int) $activeEvent['event_id'];
    }

    if (!empty($events)) {
        return (int) $events[0]['event_id'];
    }

    return 0;
}

function renderReportFilterSelect(string $name, string $label, array $options, string $selected = '', bool $required = false, string $idPrefix = ''): void
{
    $fieldId = $idPrefix . $name;
    ?>
    <div class="col-md-6 col-xl-4">
        <label class="form-label" for="<?= htmlspecialchars($fieldId) ?>"><?= htmlspecialchars($label) ?></label>
        <select
            name="<?= htmlspecialchars($name) ?>"
            id="<?= htmlspecialchars($fieldId) ?>"
            class="form-select form-select-sm"
            <?= $required ? 'required' : '' ?>
        >
            <?php foreach ($options as $value => $optionLabel): ?>
                <option value="<?= htmlspecialchars((string) $value) ?>" <?= (string) $value === (string) $selected ? 'selected' : '' ?>>
                    <?= htmlspecialchars($optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}
