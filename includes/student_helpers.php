<?php

require_once __DIR__ . '/audit_helpers.php';

const STUDENT_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

const STUDENT_COURSES = ['BSIT', 'BSMB', 'BTLEd- HE', 'BTLEd-IA'];

const ENROLLMENT_STATUSES = ['regular', 'irregular'];

function academicValidationRoleIds(): array
{
    return [2, 3];
}

function usersTableHasColumn(PDO $conn, string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];

        try {
            $stmt = $conn->query('SHOW COLUMNS FROM users');

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[strtolower((string) ($row['Field'] ?? ''))] = true;
            }
        } catch (PDOException $e) {
            $columns = [];
        }
    }

    return isset($columns[strtolower($column)]);
}

function ensureStudentAcademicSchema(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    if (!usersTableHasColumn($conn, 'enrollment_status')) {
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN enrollment_status VARCHAR(20) NOT NULL DEFAULT 'regular' AFTER year_level");
        } catch (PDOException $e) {
            // Column may already exist.
        }
    }

    if (!usersTableHasColumn($conn, 'validated_school_year')) {
        try {
            $conn->exec("ALTER TABLE users ADD COLUMN validated_school_year VARCHAR(20) NULL AFTER enrollment_status");
        } catch (PDOException $e) {
            // Column may already exist.
        }
    }

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

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS student_academic_history (
                history_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                school_year VARCHAR(20) NOT NULL,
                previous_year_level VARCHAR(20) DEFAULT NULL,
                new_year_level VARCHAR(20) DEFAULT NULL,
                previous_enrollment_status VARCHAR(20) DEFAULT NULL,
                new_enrollment_status VARCHAR(20) DEFAULT NULL,
                action_type VARCHAR(50) NOT NULL,
                notes TEXT DEFAULT NULL,
                validated_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_history_user (user_id),
                KEY idx_history_year (school_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist.
    }
}

function currentSchoolYearLabel(): string
{
    $year = (int) date('Y');
    $month = (int) date('n');

    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }

    return ($year - 1) . '-' . $year;
}

function getActiveSchoolYear(PDO $conn): string
{
    ensureStudentAcademicSchema($conn);

    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'active_school_year' LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();

    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    return currentSchoolYearLabel();
}

function setActiveSchoolYear(PDO $conn, string $schoolYear): void
{
    ensureStudentAcademicSchema($conn);

    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('active_school_year', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([trim($schoolYear)]);
}

function nextYearLevel(?string $current): ?string
{
    $map = [
        '1st Year' => '2nd Year',
        '2nd Year' => '3rd Year',
        '3rd Year' => '4th Year',
    ];

    return $map[$current] ?? null;
}

function buildValidationAccountFilters(array $filters): array
{
    require_once __DIR__ . '/search_helpers.php';

    $sql = ' WHERE u.role_id IN (' . implode(',', academicValidationRoleIds()) . ')';
    $params = [];

    $roleId = (int) ($filters['role_id'] ?? 0);

    if (in_array($roleId, academicValidationRoleIds(), true)) {
        $sql .= ' AND u.role_id = ?';
        $params[] = $roleId;
    }

    if (!empty($filters['course'])) {
        $sql .= ' AND u.course = ?';
        $params[] = $filters['course'];
    }

    if (!empty($filters['year_level'])) {
        $sql .= ' AND u.year_level = ?';
        $params[] = $filters['year_level'];
    }

    if (!empty($filters['enrollment_status'])) {
        $sql .= " AND COALESCE(u.enrollment_status, 'regular') = ?";
        $params[] = $filters['enrollment_status'];
    }

    if (isset($filters['needs_validation'])) {
        $sql .= ' AND (u.validated_school_year IS NULL OR u.validated_school_year <> ?)';
        $params[] = $filters['needs_validation'];
    }

    $query = trim((string) ($filters['q'] ?? ''));

    if ($query !== '') {
        $search = buildStudentSearch($query);
        $sql .= ' AND ' . $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    return ['sql' => $sql, 'params' => $params];
}

function countValidationBreakdown(PDO $conn, array $filters, string $activeSchoolYear): array
{
    ensureStudentAcademicSchema($conn);

    $where = buildValidationAccountFilters($filters);

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN u.validated_school_year = ? THEN 1 ELSE 0 END) AS validated_count,
            SUM(CASE WHEN u.validated_school_year IS NULL OR u.validated_school_year <> ? THEN 1 ELSE 0 END) AS needs_count
        FROM users u
        {$where['sql']}
    ");
    $stmt->execute(array_merge([$activeSchoolYear, $activeSchoolYear], $where['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'validated' => (int) ($row['validated_count'] ?? 0),
        'needs' => (int) ($row['needs_count'] ?? 0),
    ];
}

function fetchStudentsForValidation(PDO $conn, array $filters = [], ?int $limit = null, int $offset = 0): array
{
    ensureStudentAcademicSchema($conn);

    $where = buildValidationAccountFilters($filters);

    $sql = "
        SELECT
            u.user_id,
            u.role_id,
            u.student_no,
            u.firstname,
            u.lastname,
            u.course,
            u.year_level,
            COALESCE(u.enrollment_status, 'regular') AS enrollment_status,
            u.validated_school_year,
            u.is_active
        FROM users u
        {$where['sql']}
        ORDER BY u.role_id DESC, u.course ASC, u.year_level ASC, u.lastname ASC, u.firstname ASC
    ";
    $params = $where['params'];

    if ($limit !== null) {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function studentValidationPageUrl(array $listQuery, int $page): string
{
    $query = $listQuery;

    if ($page > 1) {
        $query['page'] = $page;
    } else {
        unset($query['page']);
    }

    $qs = http_build_query($query);

    return 'student_validation.php' . ($qs !== '' ? '?' . $qs : '');
}

function penaltyManagementPageUrl(array $listQuery, int $page): string
{
    $query = $listQuery;

    if ($page > 1) {
        $query['page'] = $page;
    } else {
        unset($query['page']);
    }

    $qs = http_build_query($query);

    return 'penalty_management.php' . ($qs !== '' ? '?' . $qs : '');
}

function attendanceManagementPageUrl(array $listQuery, int $page): string
{
    $query = $listQuery;

    if ($page > 1) {
        $query['page'] = $page;
    } else {
        unset($query['page']);
    }

    $qs = http_build_query($query);

    return 'attendance_management.php' . ($qs !== '' ? '?' . $qs : '');
}

function applyStudentValidation(PDO $conn, int $userId, string $schoolYear, ?string $newYearLevel, string $enrollmentStatus, int $adminUserId, string $actionType = 'individual'): bool
{
    ensureStudentAcademicSchema($conn);

    $stmt = $conn->prepare("
        SELECT user_id, year_level, enrollment_status
        FROM users
        WHERE user_id = ? AND role_id IN (2, 3)
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return false;
    }

    $previousYear = (string) ($student['year_level'] ?? '');
    $previousStatus = (string) ($student['enrollment_status'] ?? 'regular');
    $targetYear = $newYearLevel ?: $previousYear;

    if (!in_array($enrollmentStatus, ENROLLMENT_STATUSES, true)) {
        $enrollmentStatus = 'regular';
    }

    $stmt = $conn->prepare("
        UPDATE users
        SET year_level = ?,
            enrollment_status = ?,
            validated_school_year = ?
        WHERE user_id = ?
    ");
    $stmt->execute([$targetYear, $enrollmentStatus, $schoolYear, $userId]);

    $stmt = $conn->prepare("
        INSERT INTO student_academic_history
        (user_id, school_year, previous_year_level, new_year_level, previous_enrollment_status, new_enrollment_status, action_type, validated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $schoolYear,
        $previousYear,
        $targetYear,
        $previousStatus,
        $enrollmentStatus,
        $actionType,
        $adminUserId,
    ]);

    return true;
}

function bulkPromoteStudents(PDO $conn, string $fromYear, string $schoolYear, int $adminUserId, ?string $course = null): int
{
    $toYear = nextYearLevel($fromYear);

    if ($toYear === null) {
        return 0;
    }

    $filters = ['year_level' => $fromYear];

    if ($course) {
        $filters['course'] = $course;
    }

    $students = fetchStudentsForValidation($conn, $filters);
    $count = 0;

    foreach ($students as $student) {
        if (applyStudentValidation(
            $conn,
            (int) $student['user_id'],
            $schoolYear,
            $toYear,
            (string) ($student['enrollment_status'] ?? 'regular'),
            $adminUserId,
            'bulk_promote'
        )) {
            $count++;
        }
    }

    if ($count > 0) {
        logAudit(
            $conn,
            'student_validation_apply',
            'users',
            null,
            "Bulk promoted {$count} student(s) from {$fromYear} to {$toYear} for {$schoolYear}" . ($course ? " ({$course})" : ''),
            $adminUserId
        );
    }

    return $count;
}

function graduateFourthYearStudents(PDO $conn, string $schoolYear, int $adminUserId, ?string $course = null): int
{
    $filters = [
        'year_level' => '4th Year',
        'role_id' => 2,
    ];

    if ($course) {
        $filters['course'] = $course;
    }

    $students = fetchStudentsForValidation($conn, $filters);
    $count = 0;

    foreach ($students as $student) {
        $stmt = $conn->prepare("
            UPDATE users
            SET is_active = 0,
                validated_school_year = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$schoolYear, (int) $student['user_id']]);

        $stmt = $conn->prepare("
            INSERT INTO student_academic_history
            (user_id, school_year, previous_year_level, new_year_level, previous_enrollment_status, new_enrollment_status, action_type, notes, validated_by)
            VALUES (?, ?, ?, NULL, ?, ?, 'graduate', 'Account deactivated after 4th year validation', ?)
        ");
        $stmt->execute([
            (int) $student['user_id'],
            $schoolYear,
            '4th Year',
            (string) ($student['enrollment_status'] ?? 'regular'),
            (string) ($student['enrollment_status'] ?? 'regular'),
            $adminUserId,
        ]);

        $count++;
    }

    if ($count > 0) {
        logAudit(
            $conn,
            'student_validation_apply',
            'users',
            null,
            "Graduated/deactivated {$count} 4th year student(s) for {$schoolYear}" . ($course ? " ({$course})" : ''),
            $adminUserId
        );
    }

    return $count;
}
