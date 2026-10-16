<?php

/**
 * Audit trail for admin, officer, and student actions.
 */
function ensureAuditSchema(PDO $conn): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                audit_id INT NOT NULL AUTO_INCREMENT,
                user_id INT DEFAULT NULL,
                action VARCHAR(255) DEFAULT NULL,
                details TEXT DEFAULT NULL,
                table_name VARCHAR(100) DEFAULT NULL,
                record_id INT DEFAULT NULL,
                ip_address VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (audit_id),
                KEY idx_audit_created (created_at),
                KEY idx_audit_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Table may already exist with different structure.
    }

    try {
        $conn->exec("ALTER TABLE audit_logs ADD COLUMN details TEXT NULL AFTER action");
    } catch (PDOException $e) {
        // Column may already exist.
    }
}

function logAudit(
    PDO $conn,
    string $action,
    ?string $tableName = null,
    ?int $recordId = null,
    ?string $details = null,
    ?int $userId = null
): void {
    ensureAuditSchema($conn);

    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (user_id, action, details, table_name, record_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId ?: null,
            $action,
            $details,
            $tableName,
            $recordId,
            $ip !== '' ? $ip : null,
        ]);
    } catch (Throwable $e) {
        // Never block the original action if audit write fails.
    }
}

function fetchAuditLogs(PDO $conn, ?string $fromDate = null, ?string $toDate = null, int $limit = 500, array $filters = []): array
{
    ensureAuditSchema($conn);

    $sql = "
        SELECT
            al.*,
            u.firstname,
            u.lastname,
            u.student_no,
            u.role_id,
            u.year_level,
            u.course
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($fromDate) {
        $sql .= " AND DATE(al.created_at) >= ?";
        $params[] = $fromDate;
    }

    if ($toDate) {
        $sql .= " AND DATE(al.created_at) <= ?";
        $params[] = $toDate;
    }

    if (!empty($filters['action'])) {
        $sql .= " AND al.action = ?";
        $params[] = $filters['action'];
    }

    if (!empty($filters['action_group'])) {
        $match = auditActionSqlForGroup((string) $filters['action_group']);

        if ($match['sql'] !== '') {
            $sql .= ' AND ' . $match['sql'];
            $params = array_merge($params, $match['params']);
        }
    }

    if (!empty($filters['user_id'])) {
        $sql .= " AND al.user_id = ?";
        $params[] = (int) $filters['user_id'];
    }

    if (!empty($filters['role_id'])) {
        $sql .= " AND u.role_id = ?";
        $params[] = (int) $filters['role_id'];
    }

    if (!empty($filters['year_level'])) {
        $sql .= " AND u.year_level = ?";
        $params[] = $filters['year_level'];
    }

    if (!empty($filters['course'])) {
        $sql .= " AND u.course = ?";
        $params[] = $filters['course'];
    }

    if (!empty($filters['table_name'])) {
        $sql .= " AND al.table_name = ?";
        $params[] = $filters['table_name'];
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT " . max(1, min(5000, $limit));

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function auditRoleLabel(?int $roleId): string
{
    $map = [1 => 'Admin', 2 => 'Student', 3 => 'Officer'];

    return $map[$roleId] ?? 'System';
}

function formatAuditActionLabel(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}

function auditActionGroups(): array
{
    return [
        '' => 'All actions',
        'auth' => 'Login, logout & passwords',
        'users' => 'User accounts',
        'events' => 'Events, scan windows & geofence',
        'attendance' => 'Attendance & scanning',
        'penalty' => 'Penalties & settlements',
        'monitoring' => 'Geofence monitoring',
        'export' => 'Report exports',
        'validation' => 'Student validation',
    ];
}

function auditActionMatchersForGroup(string $group): array
{
    $map = [
        'auth' => ['login_success', 'login_failed', 'logout', 'password_changed', 'password_reset'],
        'users' => ['user_created', 'user_updated', 'user_activated', 'user_deactivated', 'user_deleted'],
        'events' => [
            'event_created', 'event_updated', 'event_activated', 'event_deactivated', 'event_deleted',
            'scan_windows_saved', 'scan_window_updated', 'extend_scan_window',
            'penalty_rules_saved', 'geofence_saved',
        ],
        'attendance' => [
            'officer_scan', 'officer_rescan', 'officer_scan_duplicate', 'officer_scan_failed',
            'attendance_marked_present', 'attendance_marked_late', 'attendance_marked_absent',
            'evaluate_attendance',
        ],
        'penalty' => ['penalty_settlement', 'monitoring_penalize', 'generate_penalties'],
        'monitoring' => [
            'student_left_geofence',
            'student_no_signal_warning',
            'student_no_signal_penalized',
            'monitoring_void_warning',
            'monitoring_void_penalty',
            'monitoring_penalize',
            'location_access_denied',
            'location_jump_flagged',
            'location_jump_reviewed',
            'gps_consent_given',
        ],
        'export' => [
            'export_attendance_pdf', 'export_attendance_csv', 'export_penalties_pdf',
            'export_audit_pdf', 'export_users_pdf', 'export_events_pdf',
        ],
        'validation' => ['student_validation_apply', 'student_validation_individual', 'school_year_set'],
    ];

    return $map[$group] ?? [];
}

function auditActionsForGroup(string $group): array
{
    return auditActionMatchersForGroup($group);
}

/**
 * @return array{sql: string, params: list<string>}
 */
function auditActionSqlForGroup(string $group): array
{
    $actions = auditActionMatchersForGroup($group);

    if ($actions === []) {
        return ['sql' => '', 'params' => []];
    }

    $clauses = [];
    $params = [];

    foreach ($actions as $action) {
        $clauses[] = '(al.action = ? OR al.action LIKE ?)';
        $params[] = $action;
        $params[] = $action . '%';
    }

    return [
        'sql' => '(' . implode(' OR ', $clauses) . ')',
        'params' => $params,
    ];
}
