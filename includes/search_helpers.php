<?php

require_once __DIR__ . '/penalty_helpers.php';

function studentFullNameExpr(string $alias = 'u', bool $withMiddle = true): string
{
    if ($withMiddle) {
        return "TRIM(CONCAT_WS(' ', {$alias}.firstname, {$alias}.middlename, {$alias}.lastname))";
    }

    return "TRIM(CONCAT_WS(' ', {$alias}.firstname, {$alias}.lastname))";
}

/**
 * Match student number, first/middle/last, or the full name as typed
 * (e.g. "Junly Love Arcenas" when Love is a middle name).
 *
 * @return array{sql: string, params: list<string>}
 */
function buildStudentSearch(string $query, string $alias = 'u', bool $withMiddle = true): array
{
    $query = trim($query);

    if ($query === '') {
        return ['sql' => '0=1', 'params' => []];
    }

    $fullName = studentFullNameExpr($alias, $withMiddle);
    $clauses = [
        "{$alias}.student_no LIKE ?",
        "{$fullName} LIKE ?",
        "CONCAT({$alias}.lastname, ', ', {$alias}.firstname) LIKE ?",
    ];
    $likeAll = '%' . $query . '%';
    $params = [$likeAll, $likeAll, $likeAll];

    if ($withMiddle) {
        $clauses[] = "CONCAT({$alias}.lastname, ', ', {$alias}.firstname, ' ', {$alias}.middlename) LIKE ?";
        $params[] = $likeAll;
    }

    $tokens = preg_split('/\s+/u', $query) ?: [];
    $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

    if ($tokens === []) {
        return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
    }

    $tokenClauses = [];

    foreach ($tokens as $token) {
        $like = '%' . $token . '%';
        if ($withMiddle) {
            $tokenClauses[] = "(
                {$alias}.firstname LIKE ?
                OR {$alias}.middlename LIKE ?
                OR {$alias}.lastname LIKE ?
                OR {$alias}.student_no LIKE ?
                OR {$fullName} LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like);
        } else {
            $tokenClauses[] = "(
                {$alias}.firstname LIKE ?
                OR {$alias}.lastname LIKE ?
                OR {$alias}.student_no LIKE ?
                OR {$fullName} LIKE ?
            )";
            array_push($params, $like, $like, $like, $like);
        }
    }

    $clauses[] = '(' . implode(' AND ', $tokenClauses) . ')';

    return [
        'sql' => '(' . implode(' OR ', $clauses) . ')',
        'params' => $params,
    ];
}

function studentSearchSql(string $alias = 'u'): string
{
    return buildStudentSearch('__placeholder__', $alias)['sql'];
}

function studentSearchLikeParams(string $query): array
{
    return buildStudentSearch($query)['params'];
}

function fetchStudentById(PDO $conn, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT user_id, student_no, firstname, middlename, lastname, course, year_level, email, role_id
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT user_id, student_no, firstname, lastname, course, year_level, role_id
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

function searchStudents(PDO $conn, string $query, int $limit = 30): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $run = static function (bool $withMiddle) use ($conn, $query, $limit): array {
        $search = buildStudentSearch($query, 'u', $withMiddle);
        $middleSelect = $withMiddle ? 'u.middlename,' : '';
        $sql = "
            SELECT u.user_id, u.student_no, u.firstname, {$middleSelect} u.lastname, u.course, u.year_level
            FROM users u
            WHERE u.role_id = 2
            AND {$search['sql']}
            ORDER BY u.lastname ASC, u.firstname ASC
            LIMIT {$limit}
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($search['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        return $run(true);
    } catch (PDOException $e) {
        return $run(false);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function listStudentsDirectory(PDO $conn, string $course = '', string $year = '', int $limit = 2000): array
{
    $limit = max(1, min(5000, $limit));
    $run = static function (bool $withMiddle) use ($conn, $course, $year, $limit): array {
        $middleSelect = $withMiddle ? 'u.middlename,' : '';
        $sql = "
            SELECT u.user_id, u.student_no, u.firstname, {$middleSelect} u.lastname, u.course, u.year_level
            FROM users u
            WHERE u.role_id = 2
        ";
        $params = [];

        if ($course !== '') {
            $sql .= " AND u.course = ?";
            $params[] = $course;
        }

        if ($year !== '') {
            $sql .= " AND u.year_level = ?";
            $params[] = $year;
        }

        $sql .= " ORDER BY u.lastname ASC, u.firstname ASC LIMIT {$limit}";
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

/**
 * Officer student directory with pooled penalty remaining balances.
 *
 * @return list<array<string, mixed>>
 */
function fetchOfficerStudentRecords(PDO $conn, array $filters = []): array
{
    ensurePenaltyResolutionSchema($conn);

    $eventId = (int) ($filters['event_id'] ?? 0);
    $course = trim((string) ($filters['course'] ?? ''));
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $statusFilter = normalizePenaltyStatusFilter((string) ($filters['status'] ?? ''));
    $searchQuery = trim((string) ($filters['q'] ?? ''));

    $sql = "
        SELECT
            u.user_id,
            u.student_no,
            u.firstname,
            u.lastname,
            u.course,
            u.year_level,
            COUNT(p.penalty_id) AS penalty_count,
            COALESCE(SUM(p.monetary_amount), 0) AS monetary_amount,
            COALESCE(SUM(p.community_service_hours), 0) AS community_service_hours,
            COALESCE(SUM(COALESCE(p.amount_paid, 0)), 0) AS amount_paid,
            COALESCE(SUM(COALESCE(p.hours_rendered, 0)), 0) AS hours_rendered
        FROM users u
        LEFT JOIN penalties p ON p.user_id = u.user_id
        WHERE u.role_id = 2
    ";
    $params = [];

    if ($course !== '') {
        $sql .= " AND u.course = ?";
        $params[] = $course;
    }

    if ($yearLevel !== '') {
        $sql .= " AND u.year_level = ?";
        $params[] = $yearLevel;
    }

    if ($searchQuery !== '') {
        $search = buildStudentSearch($searchQuery, 'u');
        $sql .= " AND " . $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    if ($eventId > 0) {
        $sql .= " AND EXISTS (
            SELECT 1 FROM penalties pe
            WHERE pe.user_id = u.user_id AND pe.event_id = ?
        )";
        $params[] = $eventId;
    }

    $sql .= "
        GROUP BY u.user_id, u.student_no, u.firstname, u.lastname, u.course, u.year_level
        ORDER BY u.lastname ASC, u.firstname ASC, u.student_no ASC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $sql = str_replace(
            'COALESCE(SUM(COALESCE(p.amount_paid, 0)), 0) AS amount_paid,
            COALESCE(SUM(COALESCE(p.hours_rendered, 0)), 0) AS hours_rendered',
            '0 AS amount_paid,
            0 AS hours_rendered',
            $sql
        );
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $list = [];

    foreach ($rows as $row) {
        $penaltyCount = (int) ($row['penalty_count'] ?? 0);
        $summary = summarizePenaltyObligation([$row]);
        $remaining = ($summary['remaining_fraction'] ?? 0) > 0.001;

        if ($penaltyCount > 0) {
            $status = $remaining ? 'Unsettled' : 'Settled';
        } else {
            $status = 'Clear';
        }

        if ($statusFilter === 'unsettled' && $status !== 'Unsettled') {
            continue;
        }

        if ($statusFilter === 'settled' && $status !== 'Settled') {
            continue;
        }

        $list[] = [
            'user_id' => (int) $row['user_id'],
            'student_no' => (string) ($row['student_no'] ?? ''),
            'firstname' => (string) ($row['firstname'] ?? ''),
            'lastname' => (string) ($row['lastname'] ?? ''),
            'student_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'course' => (string) ($row['course'] ?? ''),
            'year_level' => (string) ($row['year_level'] ?? ''),
            'penalty_count' => $penaltyCount,
            'remaining_monetary' => $summary['remaining_monetary'],
            'remaining_hours' => $summary['remaining_hours'],
            'status' => $status,
        ];
    }

    return $list;
}

function searchStudentsForPenalties(PDO $conn, string $query, int $limit = 30): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $run = static function (bool $withMiddle) use ($conn, $query, $limit): array {
        $search = buildStudentSearch($query, 'u', $withMiddle);
        $middleSelect = $withMiddle ? 'u.middlename,' : '';
        $middleGroup = $withMiddle ? 'u.middlename,' : '';
        $sql = "
            SELECT
                u.user_id,
                u.student_no,
                u.firstname,
                {$middleSelect}
                u.lastname,
                u.course,
                u.year_level,
                COUNT(p.penalty_id) AS penalty_count,
                SUM(CASE WHEN p.settlement_status IN ('pending', 'partial') THEN 1 ELSE 0 END) AS open_count
            FROM users u
            LEFT JOIN penalties p ON p.user_id = u.user_id
            WHERE u.role_id = 2
            AND {$search['sql']}
            GROUP BY u.user_id, u.student_no, u.firstname, {$middleGroup} u.lastname, u.course, u.year_level
            ORDER BY open_count DESC, u.lastname ASC, u.firstname ASC
            LIMIT {$limit}
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($search['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        $rows = $run(true);
    } catch (PDOException $e) {
        $rows = $run(false);
    }

    foreach ($rows as &$row) {
        $open = fetchStudentPendingPenalties($conn, (int) $row['user_id']);
        $row = array_merge($row, summarizePenaltyObligation($open));
    }
    unset($row);

    return $rows;
}

function searchStudentsForAttendance(PDO $conn, string $query, int $limit = 30): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $run = static function (bool $withMiddle) use ($conn, $query, $limit): array {
        $search = buildStudentSearch($query, 'u', $withMiddle);
        $middleSelect = $withMiddle ? 'u.middlename,' : '';
        $middleGroup = $withMiddle ? 'u.middlename,' : '';
        $sql = "
            SELECT
                u.user_id,
                u.student_no,
                u.firstname,
                {$middleSelect}
                u.lastname,
                u.course,
                u.year_level,
                COUNT(a.attendance_id) AS attendance_count
            FROM users u
            LEFT JOIN attendance_sessions a ON a.user_id = u.user_id
            WHERE u.role_id = 2
            AND {$search['sql']}
            GROUP BY u.user_id, u.student_no, u.firstname, {$middleGroup} u.lastname, u.course, u.year_level
            ORDER BY u.lastname ASC, u.firstname ASC
            LIMIT {$limit}
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute($search['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        return $run(true);
    } catch (PDOException $e) {
        return $run(false);
    }
}

function fetchStudentPenaltyLedger(PDO $conn, int $userId): array
{
    $sql = "
        SELECT
            p.penalty_id,
            p.reason,
            p.monetary_amount,
            p.community_service_hours,
            COALESCE(p.amount_paid, 0) AS amount_paid,
            COALESCE(p.hours_rendered, 0) AS hours_rendered,
            p.resolution_choice,
            p.settlement_status,
            p.created_at,
            p.settled_at,
            e.event_id,
            e.event_name,
            e.start_date,
            e.end_date
        FROM penalties p
        INNER JOIN events e ON e.event_id = p.event_id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT
                p.penalty_id,
                p.reason,
                p.monetary_amount,
                p.community_service_hours,
                p.resolution_choice,
                p.settlement_status,
                p.created_at,
                p.settled_at,
                e.event_id,
                e.event_name,
                e.start_date,
                e.end_date
            FROM penalties p
            INNER JOIN events e ON e.event_id = p.event_id
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['amount_paid'] = $row['amount_paid'] ?? 0;
            $row['hours_rendered'] = $row['hours_rendered'] ?? 0;
        }
        unset($row);

        return $rows;
    }
}

function fetchStudentAttendanceLedger(PDO $conn, int $userId, array $filters = []): array
{
    $sql = "
        SELECT
            a.attendance_id,
            a.scan_type,
            a.status,
            a.qr_scanned_at,
            e.event_id,
            e.event_name,
            p.penalty_id,
            p.monetary_status,
            p.community_service_status,
            p.settlement_status
        FROM attendance_sessions a
        INNER JOIN events e ON e.event_id = a.event_id
        LEFT JOIN penalties p ON p.attendance_id = a.attendance_id
        WHERE a.user_id = ?
    ";
    $params = [$userId];

    $eventId = trim((string) ($filters['event_id'] ?? ''));
    $status = trim((string) ($filters['status'] ?? ''));

    if ($eventId !== '') {
        $sql .= " AND a.event_id = ?";
        $params[] = $eventId;
    }

    if ($status !== '') {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY a.qr_scanned_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countStudentsWithOpenPenalties(PDO $conn): int
{
    $stmt = $conn->query("
        SELECT COUNT(DISTINCT user_id)
        FROM penalties
        WHERE settlement_status IN ('pending', 'partial')
    ");

    return (int) $stmt->fetchColumn();
}

function formatStudentDisplayName(array $student): string
{
    $parts = array_filter([
        trim((string) ($student['firstname'] ?? '')),
        trim((string) ($student['middlename'] ?? '')),
        trim((string) ($student['lastname'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return implode(' ', $parts);
}
