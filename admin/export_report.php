<?php
require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/attendance_schema.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/table_pdf.php';
require_once '../includes/student_helpers.php';
require_once '../includes/penalty_helpers.php';

$db = new Database();
$conn = $db->connect();

ensureAttendanceSchema($conn);

$type = trim($_GET['type'] ?? '');
$eventId = (int) ($_GET['event_id'] ?? 0);
$fromDate = epassport_filter_ymd(trim($_GET['from'] ?? ''), date('Y-m-01'));
$toDate = epassport_filter_ymd(trim($_GET['to'] ?? ''), date('Y-m-d'));
$course = epassport_allow_list(trim($_GET['course'] ?? ''), STUDENT_COURSES);
$yearLevel = epassport_allow_list(trim($_GET['year_level'] ?? ''), STUDENT_YEAR_LEVELS);
$status = epassport_allow_list(trim($_GET['status'] ?? ''), ['present', 'late', 'absent', 'pending', 'violated']);
$settlementStatus = epassport_allow_list(trim($_GET['settlement_status'] ?? ''), ['pending', 'partial', 'settled']);
$actionGroup = epassport_allow_list(trim($_GET['action_group'] ?? ''), array_keys(auditActionGroups()));
$roleId = (int) ($_GET['role_id'] ?? 0);

function buildFilterMeta(array $filters): array
{
    $meta = [];

    foreach ($filters as $label => $value) {
        if ($value !== '' && $value !== null && $value !== 0) {
            $meta[$label] = $value;
        }
    }

    return $meta;
}

function exportCsvDownload(string $filename, array $columns, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, array_values($columns));

    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = $row[$key] ?? '';
        }
        fputcsv($out, $line);
    }

    fclose($out);
}

try {
    if ($type === 'attendance') {
        $eventName = 'All Events';

        if ($eventId > 0) {
            $event = fetchEventById($conn, $eventId);
            if (!$event) {
                throw new Exception('Event not found.');
            }
            $eventName = $event['event_name'];
        }

        $sql = "
            SELECT
                CONCAT_WS(' ', u.firstname, u.lastname) AS student_name,
                u.course,
                u.year_level,
                e.event_name,
                a.scan_type,
                a.status,
                a.qr_scanned_at,
                a.is_rescan,
                a.is_late_scan
            FROM attendance_sessions a
            INNER JOIN users u ON a.user_id = u.user_id
            INNER JOIN events e ON a.event_id = e.event_id
            WHERE 1=1
        ";
        $params = [];

        if ($eventId > 0) {
            $sql .= " AND a.event_id = ?";
            $params[] = $eventId;
        }

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

        $sql .= " ORDER BY e.event_name ASC, u.course ASC, u.year_level ASC, a.qr_scanned_at DESC";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $sql = str_replace("a.is_late_scan", "0 AS is_late_scan", $sql);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $exportRows = [];
        foreach ($rows as $row) {
            $statusLabel = formatAttendanceStatusLabel($row['status']);
            if (!empty($row['is_late_scan']) && $row['status'] === 'late') {
                $statusLabel = 'Late (officer late scan)';
            } elseif (!empty($row['is_rescan']) && $row['status'] === 'late') {
                $statusLabel = 'Late (rescan)';
            }

            $scanDate = '';
            if (!empty($row['qr_scan_date'])) {
                $scanDate = (string) $row['qr_scan_date'];
            } elseif (!empty($row['qr_scanned_at'])) {
                $scanDate = substr((string) $row['qr_scanned_at'], 0, 10);
            }

            $exportRows[] = [
                'student_name' => $row['student_name'],
                'course' => $row['course'],
                'year_level' => $row['year_level'],
                'event_name' => $row['event_name'],
                'scan_date' => $scanDate,
                'scan_type' => formatScanTypeLabel($row['scan_type']),
                'status' => $statusLabel,
            ];
        }

        logAudit($conn, 'export_attendance_pdf', 'events', $eventId > 0 ? $eventId : null, 'Attendance PDF for ' . $eventName);

        renderTablePdf(
            'Attendance - ' . $eventName,
            $exportRows,
            [
                'student_name' => 'Name',
                'course' => 'Course',
                'year_level' => 'Year',
                'event_name' => 'Event',
                'scan_date' => 'Date',
                'scan_type' => 'Stamp',
                'status' => 'Status',
            ],
            buildFilterMeta([
                'Event' => $eventName,
                'Course' => $course,
                'Year Level' => $yearLevel,
                'Status' => $status,
            ])
        );
        exit;
    }

    if ($type === 'penalties') {
        $allEvents = ($eventId <= 0);
        $eventName = 'All Events';

        if (!$allEvents) {
            $event = fetchEventById($conn, $eventId);
            if (!$event) {
                throw new Exception('Event not found.');
            }
            $eventName = $event['event_name'];
        }

        $filters = [
            'event_id' => $eventId,
            'course' => $course,
            'year_level' => $yearLevel,
            'settlement_status' => $settlementStatus,
        ];

        $rows = fetchPenaltiesForExport($conn, $filters);

        logAudit(
            $conn,
            'export_penalties_pdf',
            'events',
            $allEvents ? null : $eventId,
            'Penalty PDF for ' . $eventName
        );

        exportPenaltiesPdf(
            $conn,
            $filters,
            'Penalties - ' . $eventName,
            buildFilterMeta([
                'Event' => $eventName,
                'Course' => $course,
                'Year Level' => $yearLevel,
                'Settlement Filter' => $settlementStatus,
            ])
        );
        exit;
    }

    if ($type === 'audit') {
        $filters = [];

        if ($actionGroup !== '') {
            $filters['action_group'] = $actionGroup;
        }

        if ($roleId > 0) {
            $filters['role_id'] = $roleId;
        }

        if ($yearLevel !== '') {
            $filters['year_level'] = $yearLevel;
        }

        if ($course !== '') {
            $filters['course'] = $course;
        }

        $logs = fetchAuditLogs($conn, $fromDate, $toDate, 3000, $filters);
        $exportRows = [];

        foreach ($logs as $log) {
            $exportRows[] = [
                'created_at' => $log['created_at'],
                'user' => trim(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?: 'System',
                'role' => auditRoleLabel(isset($log['role_id']) ? (int) $log['role_id'] : null),
                'action' => formatAuditActionLabel((string) $log['action']),
                'details' => $log['details'] ?? '',
            ];
        }

        $groupLabel = auditActionGroups()[$actionGroup] ?? 'All actions';

        logAudit($conn, 'export_audit_pdf', 'audit_logs', null, "Audit PDF {$fromDate} to {$toDate}");

        renderTablePdf(
            'Audit Logs',
            $exportRows,
            [
                'created_at' => 'When',
                'user' => 'User',
                'role' => 'Role',
                'action' => 'Action',
                'details' => 'Details',
            ],
            buildFilterMeta([
                'From' => $fromDate,
                'To' => $toDate,
                'Action Group' => $groupLabel,
                'Role' => $roleId > 0 ? auditRoleLabel($roleId) : '',
                'Course' => $course,
                'Year Level' => $yearLevel,
            ])
        );
        exit;
    }

    if ($type === 'attendance_summary') {
        $eventName = 'all-events';

        if ($eventId > 0) {
            $event = fetchEventById($conn, $eventId);
            if (!$event) {
                throw new Exception('Event not found.');
            }
            $eventName = $event['event_name'];
        }

        $sql = "
            SELECT
                e.event_name,
                a.scan_type,
                a.status,
                COUNT(*) AS total
            FROM attendance_sessions a
            INNER JOIN users u ON a.user_id = u.user_id
            INNER JOIN events e ON a.event_id = e.event_id
            WHERE 1=1
        ";
        $params = [];

        if ($eventId > 0) {
            $sql .= " AND a.event_id = ?";
            $params[] = $eventId;
        }

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

        $sql .= " GROUP BY e.event_name, a.scan_type, a.status ORDER BY e.event_name, a.scan_type, a.status";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $exportRows = [];
        foreach ($rows as $row) {
            $exportRows[] = [
                'event_name' => $row['event_name'],
                'scan_type' => formatScanTypeLabel($row['scan_type']),
                'status' => formatAttendanceStatusLabel($row['status']),
                'total' => (int) $row['total'],
            ];
        }

        logAudit(
            $conn,
            'export_attendance_csv',
            'events',
            $eventId > 0 ? $eventId : null,
            'Attendance summary CSV for ' . $eventName
        );

        exportCsvDownload(
            'attendance-summary-' . preg_replace('/[^a-z0-9_-]+/i', '-', $eventName) . '.csv',
            [
                'event_name' => 'Event',
                'scan_type' => 'Stamp Type',
                'status' => 'Status',
                'total' => 'Count',
            ],
            $exportRows
        );
        exit;
    }

    throw new Exception('Unknown report type.');
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    echo 'Report error: ' . $e->getMessage();
}
