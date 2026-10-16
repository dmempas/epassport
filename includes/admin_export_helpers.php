<?php

require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/student_helpers.php';
require_once __DIR__ . '/search_helpers.php';

function eventExportDurationLabel(array $event): string
{
    $duration = (float) ($event['duration_days'] ?? 0);

    if ($duration > 0) {
        $options = eventDurationOptions();
        $key = (string) (fmod($duration, 1.0) === 0.0 ? (int) $duration : $duration);

        if (isset($options[$key])) {
            return $options[$key];
        }

        return rtrim(rtrim(number_format($duration, 1, '.', ''), '0'), '.') . ' day(s)';
    }

    $start = (string) ($event['start_date'] ?? '');
    $end = (string) ($event['end_date'] ?? '');

    if ($start !== '' && $end !== '') {
        return $start === $end ? '1 day' : $start . ' to ' . $end;
    }

    return '';
}

function eventStatusLabel(array $event): string
{
    if (isEventExpired($event)) {
        return 'Expired';
    }

    return (int) ($event['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive';
}

function fetchEventsForExport(PDO $conn, array $filters = []): array
{
    $status = trim((string) ($filters['status'] ?? ''));
    $scheduleType = trim((string) ($filters['schedule_type'] ?? ''));
    $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));

    $sql = "SELECT * FROM events WHERE 1=1";
    $params = [];

    if ($scheduleType !== '') {
        $sql .= " AND schedule_type = ?";
        $params[] = $scheduleType;
    }

    if ($search !== '') {
        $sql .= " AND event_name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    if ($status === 'active') {
        $sql .= " AND is_active = 1 AND end_date >= CURDATE()";
    } elseif ($status === 'inactive') {
        $sql .= " AND is_active = 0 AND end_date >= CURDATE()";
    } elseif ($status === 'expired') {
        $sql .= " AND end_date < CURDATE()";
    }

    $sql .= " ORDER BY is_active DESC, created_at DESC, event_id DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildEventPdfExportRows(PDO $conn, array $events): array
{
    $rows = [];

    foreach ($events as $event) {
        $scheduleType = $event['schedule_type'] ?? 'full_day';
        $scheduleLabel = eventScheduleTypes()[$scheduleType]['label'] ?? 'Whole day';

        $rows[] = [
            'event_name' => $event['event_name'],
            'duration' => eventExportDurationLabel($event),
            'start_date' => $event['start_date'] ?? '',
            'end_date' => $event['end_date'] ?? '',
            'schedule_type' => $scheduleLabel,
            'status' => eventStatusLabel($event),
        ];
    }

    return $rows;
}

function eventPdfExportHeaders(): array
{
    return [
        'event_name' => 'Event',
        'duration' => 'Duration',
        'start_date' => 'Start',
        'end_date' => 'End',
        'schedule_type' => 'Schedule',
        'status' => 'Status',
    ];
}

function buildEventPdfSummaryMeta(array $events, PDO $conn): array
{
    $active = 0;
    $inactive = 0;
    $expired = 0;
    $setupComplete = 0;

    foreach ($events as $event) {
        $label = eventStatusLabel($event);
        if ($label === 'Active') {
            $active++;
        } elseif ($label === 'Expired') {
            $expired++;
        } else {
            $inactive++;
        }

        $readiness = getEventReadiness($conn, (int) $event['event_id']);
        if (!empty($readiness['ok'])) {
            $setupComplete++;
        }
    }

    return [
        'Total Events' => (string) count($events),
        'Active' => (string) $active,
        'Inactive' => (string) $inactive,
        'Expired' => (string) $expired,
        'Setup Complete' => (string) $setupComplete,
    ];
}

function exportEventsPdf(PDO $conn, array $filters, array $extraMeta = []): void
{
    require_once __DIR__ . '/table_pdf.php';

    $events = fetchEventsForExport($conn, $filters);
    $exportRows = buildEventPdfExportRows($conn, $events);

    renderTablePdf(
        'Events Report',
        $exportRows,
        eventPdfExportHeaders(),
        [],
        false
    );
}

function userRoleLabel(int $roleId, ?string $roleName = null): string
{
    if ($roleName !== null && $roleName !== '') {
        return ucfirst($roleName);
    }

    return match ($roleId) {
        1 => 'Admin',
        2 => 'Student',
        3 => 'Officer',
        default => 'Unknown',
    };
}

function fetchUsersForExport(PDO $conn, array $filters = []): array
{
    $roleId = (int) ($filters['role_id'] ?? 0);
    $course = trim((string) ($filters['course'] ?? ''));
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $activeFilter = trim((string) ($filters['is_active'] ?? ''));
    $searchQuery = trim((string) ($filters['q'] ?? ''));

    if (strlen($searchQuery) > 100) {
        $searchQuery = substr($searchQuery, 0, 100);
    }

    $run = static function (bool $withMiddle) use ($conn, $roleId, $course, $yearLevel, $activeFilter, $searchQuery): array {
        $sql = "
            SELECT
                u.user_id,
                u.student_no,
                u.firstname,
                u.lastname,
                u.course,
                u.year_level,
                u.email,
                u.contact_number,
                u.is_active,
                u.role_id,
                r.role_name
            FROM users u
            INNER JOIN roles r ON u.role_id = r.id
            WHERE 1=1
        ";
        $params = [];

        if ($roleId > 0) {
            $sql .= " AND u.role_id = ?";
            $params[] = $roleId;
        }

        if ($course !== '') {
            $sql .= " AND u.course = ?";
            $params[] = $course;
        }

        if ($yearLevel !== '') {
            $sql .= " AND u.year_level = ?";
            $params[] = $yearLevel;
        }

        if ($activeFilter === 'active') {
            $sql .= " AND u.is_active = 1";
        } elseif ($activeFilter === 'inactive') {
            $sql .= " AND u.is_active = 0";
        }

        if ($searchQuery !== '') {
            $search = buildStudentSearch($searchQuery, 'u', $withMiddle);
            $sql .= " AND ({$search['sql']} OR u.email LIKE ? OR u.contact_number LIKE ?)";
            $like = '%' . $searchQuery . '%';
            $params = array_merge($params, $search['params'], [$like, $like]);
        }

        $sql .= " ORDER BY u.lastname ASC, u.firstname ASC, u.student_no ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        return $run(true);
    } catch (PDOException $e) {
        return $run(false);
    }
}

function buildUserPdfExportRows(array $rows): array
{
    $exportRows = [];

    foreach ($rows as $row) {
        $exportRows[] = [
            'student_no' => $row['student_no'] ?? '',
            'name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'course' => $row['course'] ?: 'N/A',
            'year_level' => $row['year_level'] ?: 'N/A',
            'role' => userRoleLabel((int) ($row['role_id'] ?? 0), $row['role_name'] ?? null),
            'status' => (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive',
        ];
    }

    return $exportRows;
}

function userPdfExportHeaders(): array
{
    return [
        'student_no' => 'Student No',
        'name' => 'Name',
        'course' => 'Course',
        'year_level' => 'Year',
        'role' => 'Role',
        'status' => 'Status',
    ];
}

function buildUserPdfSummaryMeta(array $rows): array
{
    $active = 0;
    $inactive = 0;
    $admin = 0;
    $student = 0;
    $officer = 0;

    foreach ($rows as $row) {
        if ((int) ($row['is_active'] ?? 0) === 1) {
            $active++;
        } else {
            $inactive++;
        }

        $roleId = (int) ($row['role_id'] ?? 0);
        if ($roleId === 1) {
            $admin++;
        } elseif ($roleId === 2) {
            $student++;
        } elseif ($roleId === 3) {
            $officer++;
        }
    }

    return [
        'Total Users' => (string) count($rows),
        'Active' => (string) $active,
        'Inactive' => (string) $inactive,
        'Admins' => (string) $admin,
        'Students' => (string) $student,
        'Officers' => (string) $officer,
    ];
}

function exportUsersPdf(PDO $conn, array $filters, array $extraMeta = []): void
{
    require_once __DIR__ . '/table_pdf.php';

    $rows = fetchUsersForExport($conn, $filters);
    $exportRows = buildUserPdfExportRows($rows);

    renderTablePdf(
        'Users Report',
        $exportRows,
        userPdfExportHeaders(),
        [],
        false
    );
}
