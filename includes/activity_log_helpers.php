<?php

require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/audit_helpers.php';
require_once __DIR__ . '/penalty_helpers.php';

function studentAuditActivityActions(): array
{
    return [
        'login_success',
        'logout',
        'password_changed',
        'gps_consent_given',
        'location_access_denied',
        'student_left_geofence',
        'penalty_settlement',
        'attendance_marked_present',
        'attendance_marked_late',
        'attendance_marked_absent',
    ];
}

function studentActivityItemFromAudit(array $row): array
{
    $action = (string) ($row['action'] ?? '');
    $details = trim((string) ($row['details'] ?? ''));
    $map = [
        'login_success' => ['label' => 'Successful login', 'tone' => 'success', 'icon' => 'box-arrow-in-right'],
        'logout' => ['label' => 'Logout', 'tone' => 'primary', 'icon' => 'box-arrow-right'],
        'password_changed' => ['label' => 'Password changed', 'tone' => 'primary', 'icon' => 'key'],
        'gps_consent_given' => ['label' => 'GPS location consent', 'tone' => 'success', 'icon' => 'geo-alt'],
        'location_access_denied' => ['label' => 'Denied location access', 'tone' => 'warning', 'icon' => 'geo-alt'],
        'student_left_geofence' => ['label' => 'Left event area', 'tone' => 'warning', 'icon' => 'geo'],
        'penalty_settlement' => ['label' => 'Penalty settlement successful', 'tone' => 'success', 'icon' => 'check-circle'],
        'attendance_marked_present' => ['label' => 'Attendance: present', 'tone' => 'success', 'icon' => 'check2-circle'],
        'attendance_marked_late' => ['label' => 'Attendance: late', 'tone' => 'warning', 'icon' => 'clock'],
        'attendance_marked_absent' => ['label' => 'Attendance: absent', 'tone' => 'warning', 'icon' => 'x-circle'],
    ];
    $meta = $map[$action] ?? [
        'label' => formatAuditActionLabel($action),
        'tone' => 'primary',
        'icon' => 'activity',
    ];

    return [
        'type' => $action,
        'label' => $meta['label'],
        'description' => $details !== '' ? $details : $meta['label'],
        'occurred_at' => (string) ($row['created_at'] ?? ''),
        'icon' => $meta['icon'],
        'tone' => $meta['tone'],
    ];
}

function studentActivityBadgeClass(string $tone): string
{
    return match ($tone) {
        'success' => 'activity-type-badge activity-type-badge--success',
        'warning' => 'activity-type-badge activity-type-badge--warning',
        default => 'activity-type-badge activity-type-badge--primary',
    };
}

function officerActivityItemFromAudit(array $row): array
{
    $action = (string) ($row['action'] ?? '');
    $details = trim((string) ($row['details'] ?? ''));
    $map = [
        'login_success' => ['label' => 'Signed in', 'tone' => 'success'],
        'login_failed' => ['label' => 'Failed sign-in', 'tone' => 'warning'],
        'logout' => ['label' => 'Signed out', 'tone' => 'primary'],
        'password_changed' => ['label' => 'Changed own password', 'tone' => 'primary'],
        'password_reset' => ['label' => 'Reset student password', 'tone' => 'warning'],
        'officer_scan' => ['label' => 'Scanned QR', 'tone' => 'success'],
        'officer_rescan' => ['label' => 'Re-scanned QR', 'tone' => 'primary'],
        'officer_scan_duplicate' => ['label' => 'QR already scanned', 'tone' => 'warning'],
        'officer_scan_failed' => ['label' => 'Scan failed', 'tone' => 'warning'],
        'penalty_settlement' => ['label' => 'Settled penalty', 'tone' => 'success'],
    ];
    $meta = $map[$action] ?? [
        'label' => formatAuditActionLabel($action),
        'tone' => 'primary',
    ];
    $description = $details !== '' ? $details : $meta['label'];
    $ip = trim((string) ($row['ip_address'] ?? ''));

    if ($ip !== '') {
        $description .= ($description !== '' ? ' · ' : '') . 'IP ' . $ip;
    }

    return [
        'type' => $action,
        'label' => $meta['label'],
        'description' => $description,
        'occurred_at' => (string) ($row['created_at'] ?? ''),
        'tone' => $meta['tone'],
    ];
}

function getStudentActivityTimeline(PDO $conn, int $userId, ?int $eventId = null, int $limit = 50): array
{
    ensureAuditSchema($conn);

    $limit = max(1, min(200, $limit));
    $actions = studentAuditActivityActions();
    $placeholders = implode(',', array_fill(0, count($actions), '?'));

    $sql = "
        SELECT action, details, created_at, table_name, record_id
        FROM audit_logs
        WHERE user_id = ?
        AND action IN ({$placeholders})
        ORDER BY created_at DESC
        LIMIT {$limit}
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$userId], $actions));

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = studentActivityItemFromAudit($row);
    }

    return $items;
}

function getOfficerScanLog(PDO $conn, int $officerId, ?int $eventId = null, int $limit = 100): array
{
    $params = [$officerId];
    $eventFilter = '';

    if ($eventId !== null && $eventId > 0) {
        $eventFilter = ' AND a.event_id = ?';
        $params[] = $eventId;
    }

    $stmt = $conn->prepare("
        SELECT
            a.attendance_id,
            a.scan_type,
            a.status,
            a.qr_scanned_at,
            a.is_rescan,
            e.event_name,
            s.student_no,
            s.firstname,
            s.lastname,
            s.course,
            s.year_level
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id
        INNER JOIN users s ON a.user_id = s.user_id
        WHERE a.scanned_by_officer_id = ?
        {$eventFilter}
        ORDER BY a.qr_scanned_at DESC
        LIMIT " . max(1, min(300, $limit))
    );
    $stmt->execute($params);

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scanType = (string) $row['scan_type'];
        $rows[] = [
            'attendance_id' => (int) $row['attendance_id'],
            'scan_type' => $scanType,
            'scan_label' => formatScanTypeLabel($scanType),
            'is_login' => isLoginScanType($scanType),
            'is_logout' => isLogoutScanType($scanType),
            'status' => (string) $row['status'],
            'scanned_at' => (string) ($row['qr_scanned_at'] ?? ''),
            'is_rescan' => !empty($row['is_rescan']),
            'event_name' => (string) ($row['event_name'] ?? ''),
            'student_no' => (string) ($row['student_no'] ?? ''),
            'student_name' => trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')),
            'course' => (string) ($row['course'] ?? ''),
            'year_level' => (string) ($row['year_level'] ?? ''),
        ];
    }

    return $rows;
}

function fetchStudentPenaltySettlements(PDO $conn, int $userId): array
{
    $sql = "
        SELECT
            ps.settlement_id,
            ps.resolution_choice,
            ps.total_monetary,
            ps.total_service_hours,
            COALESCE(ps.monetary_paid, ps.total_monetary) AS monetary_paid,
            COALESCE(ps.hours_rendered, ps.total_service_hours) AS hours_rendered,
            ps.penalty_count,
            ps.notes,
            ps.settled_at,
            ps.settled_by,
            TRIM(CONCAT_WS(' ', o.firstname, o.lastname)) AS settled_by_name
        FROM penalty_settlements ps
        LEFT JOIN users o ON o.user_id = ps.settled_by
        WHERE ps.user_id = ?
        ORDER BY ps.settled_at DESC
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        try {
            $stmt = $conn->prepare("
                SELECT settlement_id, resolution_choice, total_monetary, total_service_hours,
                       penalty_count, notes, settled_at, settled_by
                FROM penalty_settlements
                WHERE user_id = ?
                ORDER BY settled_at DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['monetary_paid'] = $row['total_monetary'] ?? 0;
                $row['hours_rendered'] = $row['total_service_hours'] ?? 0;
                $row['settled_by_name'] = '';
            }
            unset($row);
            return $rows;
        } catch (PDOException $e2) {
            return [];
        }
    }
}

function fetchStudentPenaltySettlementDetail(PDO $conn, int $settlementId, ?int $forUserId = null): ?array
{
    $sql = "
        SELECT
            ps.*,
            TRIM(CONCAT_WS(' ', o.firstname, o.lastname)) AS settled_by_name,
            TRIM(CONCAT_WS(' ', s.firstname, s.middlename, s.lastname)) AS student_name,
            s.student_no,
            s.course,
            s.year_level
        FROM penalty_settlements ps
        INNER JOIN users s ON s.user_id = ps.user_id
        LEFT JOIN users o ON o.user_id = ps.settled_by
        WHERE ps.settlement_id = ?
    ";
    $params = [$settlementId];
    if ($forUserId !== null && $forUserId > 0) {
        $sql .= " AND ps.user_id = ?";
        $params[] = $forUserId;
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT ps.*, TRIM(CONCAT_WS(' ', o.firstname, o.lastname)) AS settled_by_name,
                   TRIM(CONCAT_WS(' ', s.firstname, s.lastname)) AS student_name,
                   s.student_no, s.course, s.year_level
            FROM penalty_settlements ps
            INNER JOIN users s ON s.user_id = ps.user_id
            LEFT JOIN users o ON o.user_id = ps.settled_by
            WHERE ps.settlement_id = ?
        " . ($forUserId ? " AND ps.user_id = ?" : ""));
        $stmt->execute($forUserId ? [$settlementId, $forUserId] : [$settlementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return null;
    }

    $row['monetary_paid'] = $row['monetary_paid'] ?? $row['total_monetary'] ?? 0;
    $row['hours_rendered'] = $row['hours_rendered'] ?? $row['total_service_hours'] ?? 0;
    $row['lines'] = [];

    try {
        $alloc = $conn->prepare("
            SELECT
                a.monetary_applied,
                a.hours_applied,
                p.reason,
                p.monetary_amount,
                p.community_service_hours,
                e.event_name
            FROM penalty_settlement_allocations a
            INNER JOIN penalties p ON p.penalty_id = a.penalty_id
            INNER JOIN events e ON e.event_id = p.event_id
            WHERE a.settlement_id = ?
            ORDER BY e.event_name ASC
        ");
        $alloc->execute([$settlementId]);
        $row['lines'] = $alloc->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $row['lines'] = [];
    }

    return $row;
}

function fetchStudentPenaltiesGroupedByEvent(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
            e.event_id,
            e.event_name,
            e.start_date,
            e.end_date,
            p.penalty_id,
            p.reason,
            p.monetary_amount,
            p.community_service_hours,
            COALESCE(p.amount_paid, 0) AS amount_paid,
            COALESCE(p.hours_rendered, 0) AS hours_rendered,
            p.settlement_status,
            p.resolution_choice,
            p.settled_at,
            p.created_at
        FROM penalties p
        INNER JOIN events e ON e.event_id = p.event_id
        WHERE p.user_id = ?
        ORDER BY e.start_date DESC, p.created_at DESC
    ");

    try {
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            SELECT
                e.event_id,
                e.event_name,
                e.start_date,
                e.end_date,
                p.penalty_id,
                p.reason,
                p.monetary_amount,
                p.community_service_hours,
                p.settlement_status,
                p.resolution_choice,
                p.settled_at,
                p.created_at
            FROM penalties p
            INNER JOIN events e ON e.event_id = p.event_id
            WHERE p.user_id = ?
            ORDER BY e.start_date DESC, p.created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['amount_paid'] = $row['amount_paid'] ?? 0;
            $row['hours_rendered'] = $row['hours_rendered'] ?? 0;
        }
        unset($row);
    }

    $grouped = [];

    foreach ($rows as $row) {
        $eventId = (int) $row['event_id'];
        if (!isset($grouped[$eventId])) {
            $grouped[$eventId] = [
                'event_id' => $eventId,
                'event_name' => (string) $row['event_name'],
                'start_date' => (string) ($row['start_date'] ?? ''),
                'end_date' => (string) ($row['end_date'] ?? ''),
                'penalties' => [],
            ];
        }
        $grouped[$eventId]['penalties'][] = $row;
    }

    foreach ($grouped as &$group) {
        $group['summary'] = summarizePenaltyObligation($group['penalties']);
        $group['status'] = $group['summary']['remaining_fraction'] > 0.001 ? 'Unsettled' : 'Settled';
    }
    unset($group);

    return array_values($grouped);
}
