<?php

/**
 * Attendance schema migrations (late status, rescan flag).
 */
function ensureAttendanceSchema(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $conn->exec("
            ALTER TABLE attendance_sessions
            MODIFY status ENUM('pending','present','absent','violated','late') NOT NULL DEFAULT 'pending'
        ");
    } catch (PDOException $e) {
        // Already applied or unsupported — continue.
    }

    $alters = [
        "ALTER TABLE attendance_sessions ADD COLUMN is_rescan TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
    ];

    foreach ($alters as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Column may already exist.
        }
    }
}

function attendanceStatusesForMonitoring(): array
{
    return ['present', 'late', 'violated'];
}

function attendanceStatusesCountedAsComplete(): array
{
    return ['present', 'late'];
}

function formatAttendanceStatusLabel(string $status): string
{
    $labels = [
        'pending' => 'Pending',
        'present' => 'Present',
        'absent' => 'Absent',
        'violated' => 'Violated',
        'late' => 'Late',
    ];

    return $labels[$status] ?? ucfirst($status);
}

function attendanceStudentsListWhere(array $filters): array
{
    require_once __DIR__ . '/search_helpers.php';

    $eventId = (int) ($filters['event_id'] ?? 0);
    $course = trim((string) ($filters['course'] ?? ''));
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));
    $searchQuery = trim((string) ($filters['q'] ?? ''));

    $sql = '1=1';
    $params = [];

    if ($eventId > 0) {
        $sql .= ' AND a.event_id = ?';
        $params[] = $eventId;
    }

    if ($course !== '') {
        $sql .= ' AND u.course = ?';
        $params[] = $course;
    }

    if ($yearLevel !== '') {
        $sql .= ' AND u.year_level = ?';
        $params[] = $yearLevel;
    }

    if ($status !== '') {
        $sql .= ' AND a.status = ?';
        $params[] = $status;
    }

    if ($searchQuery !== '') {
        $search = buildStudentSearch($searchQuery, 'u');
        $sql .= ' AND ' . $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    return ['sql' => $sql, 'params' => $params];
}

function countAttendanceStudentsList(PDO $conn, array $filters = []): int
{
    $where = attendanceStudentsListWhere($filters);
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM (
            SELECT a.user_id
            FROM attendance_sessions a
            INNER JOIN users u ON a.user_id = u.user_id
            INNER JOIN events e ON a.event_id = e.event_id
            WHERE {$where['sql']}
            GROUP BY a.user_id
        ) counted
    ");
    $stmt->execute($where['params']);

    return (int) $stmt->fetchColumn();
}

function fetchAttendanceStudentsList(PDO $conn, array $filters = [], ?int $limit = null, int $offset = 0): array
{
    $where = attendanceStudentsListWhere($filters);
    $offset = max(0, $offset);
    $limitSql = ' ORDER BY u.lastname ASC, u.firstname ASC, u.student_no ASC';

    if ($limit !== null) {
        $limitSql .= ' LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . $offset;
    }

    $sql = "
        SELECT
            u.user_id,
            u.student_no,
            u.firstname,
            u.lastname,
            u.course,
            u.year_level,
            COUNT(a.attendance_id) AS stamp_count,
            MAX(a.qr_scanned_at) AS last_scanned_at,
            SUBSTRING_INDEX(GROUP_CONCAT(a.status ORDER BY a.qr_scanned_at DESC), ',', 1) AS last_status,
            SUM(a.status = 'present') AS present_count,
            SUM(a.status = 'late') AS late_count,
            SUM(a.status = 'absent') AS absent_count,
            SUM(a.status = 'violated') AS violated_count
        FROM attendance_sessions a
        INNER JOIN users u ON a.user_id = u.user_id
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE {$where['sql']}
        GROUP BY u.user_id, u.student_no, u.firstname, u.lastname, u.course, u.year_level
        {$limitSql}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($where['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['user_id'] = (int) ($row['user_id'] ?? 0);
        $row['student_name'] = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
        $row['stamp_count'] = (int) ($row['stamp_count'] ?? 0);
        $row['present_count'] = (int) ($row['present_count'] ?? 0);
        $row['late_count'] = (int) ($row['late_count'] ?? 0);
        $row['absent_count'] = (int) ($row['absent_count'] ?? 0);
        $row['violated_count'] = (int) ($row['violated_count'] ?? 0);
        $row['course'] = (string) ($row['course'] ?? '');
        $row['year_level'] = (string) ($row['year_level'] ?? '');
        $row['last_status'] = (string) ($row['last_status'] ?? '');
    }
    unset($row);

    return $rows;
}

function fetchStudentAttendanceRecords(PDO $conn, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.scan_type,
            a.status,
            a.qr_scanned_at,
            e.event_id,
            e.event_name,
            e.start_date,
            e.end_date,
            (
                SELECT p.penalty_id
                FROM penalties p
                WHERE p.user_id = a.user_id
                  AND p.event_id = a.event_id
                LIMIT 1
            ) AS penalty_id
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE a.user_id = ?
        ORDER BY a.qr_scanned_at DESC, a.attendance_id DESC
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
