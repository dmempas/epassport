<?php



/**

 * Penalty resolution: an officer records pesos, community-service hours, or both

 * against a student's open penalties. Students view the balance; they do not choose.

 */



require_once __DIR__ . '/student_helpers.php';




function tableColumnMap(PDO $conn, string $table): array
{
    static $cache = [];

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';

    if ($table === '') {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cache[$table] = [];

    try {
        $stmt = $conn->query('SHOW COLUMNS FROM `' . $table . '`');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$table][strtolower((string) ($row['Field'] ?? ''))] = true;
        }
    } catch (PDOException $e) {
        $cache[$table] = [];
    }

    return $cache[$table];
}

function tableHasColumn(PDO $conn, string $table, string $column): bool
{
    $map = tableColumnMap($conn, $table);

    return isset($map[strtolower($column)]);
}

function tableHasIndex(PDO $conn, string $table, string $indexName): bool
{
    static $cache = [];

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
    $indexName = strtolower($indexName);

    if ($table === '') {
        return false;
    }

    if (!isset($cache[$table])) {
        $cache[$table] = [];

        try {
            $stmt = $conn->query('SHOW INDEX FROM `' . $table . '`');

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$table][strtolower((string) ($row['Key_name'] ?? ''))] = true;
            }
        } catch (PDOException $e) {
            $cache[$table] = [];
        }
    }

    return isset($cache[$table][$indexName]);
}

function ensurePenaltyResolutionSchema(PDO $conn): void

{

    static $done = false;



    if ($done) {

        return;

    }



    $done = true;



    $penaltyColumns = [
        'resolution_choice' => "ALTER TABLE penalties ADD COLUMN resolution_choice ENUM('pending','monetary','community_service') NOT NULL DEFAULT 'pending' AFTER monetary_status",
        'settlement_status' => "ALTER TABLE penalties ADD COLUMN settlement_status ENUM('pending','settled') NOT NULL DEFAULT 'pending' AFTER resolution_choice",
        'settled_by' => "ALTER TABLE penalties ADD COLUMN settled_by INT NULL AFTER settlement_status",
        'settled_at' => "ALTER TABLE penalties ADD COLUMN settled_at DATETIME NULL AFTER settled_by",
        'settlement_id' => "ALTER TABLE penalties ADD COLUMN settlement_id INT NULL AFTER settled_at",
        'amount_paid' => "ALTER TABLE penalties ADD COLUMN amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER settlement_id",
        'hours_rendered' => "ALTER TABLE penalties ADD COLUMN hours_rendered INT NOT NULL DEFAULT 0 AFTER amount_paid",
    ];

    foreach ($penaltyColumns as $column => $sql) {
        if (tableHasColumn($conn, 'penalties', $column)) {
            continue;
        }

        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Column may already exist.
        }
    }

    $monitoringColumns = [
        'last_ping_at' => "ALTER TABLE monitoring_sessions ADD COLUMN last_ping_at DATETIME NULL AFTER started_at",
        'missed_ping_count' => "ALTER TABLE monitoring_sessions ADD COLUMN missed_ping_count INT NOT NULL DEFAULT 0 AFTER last_ping_at",
        'consistency_count' => "ALTER TABLE monitoring_sessions ADD COLUMN consistency_count INT NOT NULL DEFAULT 0 AFTER missed_ping_count",
        'silent_warning_sent_at' => "ALTER TABLE monitoring_sessions ADD COLUMN silent_warning_sent_at DATETIME NULL AFTER warning_sent_at",
    ];

    foreach ($monitoringColumns as $column => $sql) {
        if (tableHasColumn($conn, 'monitoring_sessions', $column)) {
            continue;
        }

        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Column may already exist.
        }
    }

    $penaltyIndexes = [
        'idx_penalties_user' => 'ALTER TABLE penalties ADD KEY idx_penalties_user (user_id)',
        'idx_penalties_event_user' => 'ALTER TABLE penalties ADD KEY idx_penalties_event_user (event_id, user_id)',
    ];

    foreach ($penaltyIndexes as $indexName => $sql) {
        if (tableHasIndex($conn, 'penalties', $indexName)) {
            continue;
        }

        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            // Index may already exist.
        }
    }

    try {

        $conn->exec("

            CREATE TABLE IF NOT EXISTS penalty_settlements (

                settlement_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,

                user_id INT NOT NULL,

                school_year VARCHAR(20) DEFAULT NULL,

                resolution_choice ENUM('monetary','community_service') NOT NULL,

                total_monetary DECIMAL(10,2) NOT NULL DEFAULT 0.00,

                total_service_hours INT NOT NULL DEFAULT 0,

                penalty_count INT NOT NULL DEFAULT 0,

                settled_by INT DEFAULT NULL,

                settled_at DATETIME NOT NULL,

                notes TEXT DEFAULT NULL,

                KEY idx_penalty_settlement_user (user_id),

                KEY idx_penalty_settlement_year (school_year)

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4

        ");

    } catch (PDOException $e) {

        // Table may already exist.

    }



    try {

        $conn->exec("ALTER TABLE location_logs MODIFY location_status ENUM('inside','outside','denied','unavailable') DEFAULT NULL");

    } catch (PDOException $e) {

        // Ignore if already applied.

    }

}



function schoolYearDateRange(string $schoolYear): array

{

    if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {

        return [

            'start' => $matches[1] . '-06-01',

            'end' => $matches[2] . '-05-31',

        ];

    }



    return [

        'start' => date('Y-m-d', strtotime('-12 months')),

        'end' => date('Y-m-d'),

    ];

}



function penaltyInSchoolYearClause(string $alias = 'p'): string

{

    return "DATE({$alias}.created_at) >= ? AND DATE({$alias}.created_at) <= ?";

}



function getStudentPenaltySummary(PDO $conn, int $userId): array
{
    ensurePenaltyResolutionSchema($conn);

    $open = fetchStudentPendingPenalties($conn, $userId);
    $balance = summarizePenaltyObligation($open);

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_penalties,
            COALESCE(SUM(COALESCE(amount_paid, 0)), 0) AS paid_recorded,
            COALESCE(SUM(COALESCE(hours_rendered, 0)), 0) AS hours_recorded
        FROM penalties
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_penalties' => (int) ($row['total_penalties'] ?? 0),
        'pending_monetary' => $balance['remaining_monetary'],
        'pending_service_hours' => $balance['remaining_hours'],
        'awaiting_settlement' => count($open),
        'settled_monetary' => (float) ($row['paid_recorded'] ?? 0),
        'settled_service_hours' => (int) ($row['hours_recorded'] ?? 0),
        'remaining_fraction' => $balance['remaining_fraction'],
        'original_monetary' => $balance['original_monetary'],
        'original_hours' => $balance['original_hours'],
    ];
}



function fetchPendingPenaltyGroups(PDO $conn): array

{

    ensurePenaltyResolutionSchema($conn);



    $stmt = $conn->prepare("

        SELECT

            u.user_id,

            u.student_no,

            u.firstname,

            u.lastname,

            u.course,

            u.year_level,

            COUNT(p.penalty_id) AS penalty_count,

            COALESCE(SUM(p.monetary_amount), 0) AS total_monetary,

            COALESCE(SUM(p.community_service_hours), 0) AS total_service_hours,
            COALESCE(SUM(COALESCE(p.amount_paid, 0)), 0) AS amount_paid,
            COALESCE(SUM(COALESCE(p.hours_rendered, 0)), 0) AS hours_rendered,

            MIN(p.created_at) AS oldest_penalty,

            MAX(p.created_at) AS newest_penalty

        FROM penalties p

        INNER JOIN users u ON p.user_id = u.user_id

        WHERE p.settlement_status IN ('pending', 'partial')

        GROUP BY u.user_id, u.student_no, u.firstname, u.lastname, u.course, u.year_level

        ORDER BY u.lastname ASC, u.firstname ASC

    ");

    $stmt->execute();

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map('enrichPendingPenaltyGroup', $groups);

}



function fetchStudentPendingPenalties(PDO $conn, int $userId): array

{

    ensurePenaltyResolutionSchema($conn);



    $stmt = $conn->prepare("

        SELECT

            p.penalty_id,

            p.reason,

            p.monetary_amount,

            p.community_service_hours,
            COALESCE(p.amount_paid, 0) AS amount_paid,
            COALESCE(p.hours_rendered, 0) AS hours_rendered,
            p.settlement_status,
            p.created_at,

            e.event_name

        FROM penalties p

        INNER JOIN events e ON p.event_id = e.event_id

        WHERE p.user_id = ?

        AND p.settlement_status IN ('pending', 'partial')

        ORDER BY p.created_at ASC

    ");

    $stmt->execute([$userId]);



    return $stmt->fetchAll(PDO::FETCH_ASSOC);

}



function settleStudentPenaltiesBatch(PDO $conn, int $userId, string $choice, int $officerUserId): array
{
    if (!in_array($choice, ['monetary', 'community_service'], true)) {
        return ['ok' => false, 'message' => 'Invalid settlement type. Choose monetary payment or community service.'];
    }

    $open = fetchStudentPendingPenalties($conn, $userId);

    if ($open === []) {
        return ['ok' => false, 'message' => 'This student has no pending penalties to settle.'];
    }

    $balance = summarizePenaltyObligation($open);

    if ($choice === 'monetary') {
        return applyStudentPenaltySettlement(
            $conn,
            $userId,
            $officerUserId,
            $balance['remaining_monetary'],
            0,
            'Full remaining balance paid in cash.'
        );
    }

    return applyStudentPenaltySettlement(
        $conn,
        $userId,
        $officerUserId,
        0.0,
        $balance['remaining_hours'],
        'Full remaining balance rendered as community service.'
    );
}

/**
 * Original pesos and hours are equivalent options for the same obligation.
 * Paying part of the pesos and rendering part of the hours can complete it.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{
 *     original_monetary: float,
 *     original_hours: int,
 *     amount_paid: float,
 *     hours_rendered: int,
 *     settled_fraction: float,
 *     remaining_fraction: float,
 *     remaining_monetary: float,
 *     remaining_hours: int
 * }
 */
function summarizePenaltyObligation(array $rows): array
{
    $originalMonetary = 0.0;
    $originalHours = 0;
    $amountPaid = 0.0;
    $hoursRendered = 0;

    foreach ($rows as $row) {
        $originalMonetary += (float) ($row['monetary_amount'] ?? 0);
        $originalHours += (int) ($row['community_service_hours'] ?? 0);
        $amountPaid += (float) ($row['amount_paid'] ?? 0);
        $hoursRendered += (int) ($row['hours_rendered'] ?? 0);
    }

    $fraction = 0.0;

    if ($originalMonetary > 0.009) {
        $fraction += $amountPaid / $originalMonetary;
    }

    if ($originalHours > 0) {
        $fraction += $hoursRendered / $originalHours;
    }

    $fraction = min(1.0, max(0.0, $fraction));
    $remainingFraction = max(0.0, 1.0 - $fraction);

    return [
        'original_monetary' => $originalMonetary,
        'original_hours' => $originalHours,
        'amount_paid' => $amountPaid,
        'hours_rendered' => $hoursRendered,
        'settled_fraction' => $fraction,
        'remaining_fraction' => $remainingFraction,
        'remaining_monetary' => round($originalMonetary * $remainingFraction, 2),
        'remaining_hours' => (int) max(0, (int) round($originalHours * $remainingFraction)),
    ];
}

function enrichPendingPenaltyGroup(array $group): array
{
    $balance = summarizePenaltyObligation([
        [
            'monetary_amount' => $group['total_monetary'] ?? 0,
            'community_service_hours' => $group['total_service_hours'] ?? 0,
            'amount_paid' => $group['amount_paid'] ?? 0,
            'hours_rendered' => $group['hours_rendered'] ?? 0,
        ],
    ]);

    return array_merge($group, $balance);
}

function applyStudentPenaltySettlement(
    PDO $conn,
    int $userId,
    int $officerUserId,
    float $payAmount,
    int $renderHours,
    string $notes = ''
): array {
    ensurePenaltyResolutionSchema($conn);

    $payAmount = round(max(0, $payAmount), 2);
    $renderHours = max(0, $renderHours);

    if ($payAmount <= 0 && $renderHours <= 0) {
        return ['ok' => false, 'message' => 'Enter pesos paid, hours rendered, or both.'];
    }

    $open = fetchStudentPendingPenalties($conn, $userId);

    if ($open === []) {
        return ['ok' => false, 'message' => 'This student has no open penalties to settle.'];
    }

    $before = summarizePenaltyObligation($open);

    if ($before['remaining_fraction'] <= 0.001) {
        return ['ok' => false, 'message' => 'This student has no remaining balance.'];
    }

    $addFraction = 0.0;

    if ($before['original_monetary'] > 0.009) {
        $addFraction += $payAmount / $before['original_monetary'];
    }

    if ($before['original_hours'] > 0) {
        $addFraction += $renderHours / $before['original_hours'];
    }

    if ($addFraction <= 0) {
        return ['ok' => false, 'message' => 'That combination does not reduce the remaining balance.'];
    }

    if ($addFraction > $before['remaining_fraction'] + 0.0001) {
        $scale = $before['remaining_fraction'] / $addFraction;
        $payAmount = round($payAmount * $scale, 2);
        $renderHours = (int) round($renderHours * $scale);
    }

    $choice = 'mixed';

    if ($payAmount > 0 && $renderHours <= 0) {
        $choice = 'monetary';
    } elseif ($payAmount <= 0 && $renderHours > 0) {
        $choice = 'community_service';
    }

    $schoolYear = getActiveSchoolYear($conn);

    try {
        $conn->beginTransaction();

        $settlementId = insertPenaltySettlementRow(
            $conn,
            $userId,
            $schoolYear,
            $choice,
            $before['original_monetary'],
            $before['original_hours'],
            $payAmount,
            $renderHours,
            count($open),
            $officerUserId,
            $notes !== '' ? $notes : 'Partial or mixed settlement'
        );

        $moneyLeft = $payAmount;
        $hoursLeft = $renderHours;
        $allocStmt = $conn->prepare("
            INSERT INTO penalty_settlement_allocations
                (settlement_id, penalty_id, monetary_applied, hours_applied)
            VALUES (?, ?, ?, ?)
        ");
        $updateStmt = $conn->prepare("
            UPDATE penalties
            SET amount_paid = ?,
                hours_rendered = ?,
                monetary_status = ?,
                community_service_status = ?,
                resolution_choice = ?,
                settlement_status = ?,
                settled_by = ?,
                settled_at = CASE WHEN ? = 'settled' THEN NOW() ELSE settled_at END,
                settlement_id = ?
            WHERE penalty_id = ?
        ");

        foreach ($open as $index => $row) {
            $penaltyId = (int) $row['penalty_id'];
            $origM = (float) $row['monetary_amount'];
            $origH = (int) $row['community_service_hours'];
            $paid = (float) ($row['amount_paid'] ?? 0);
            $rendered = (int) ($row['hours_rendered'] ?? 0);
            $applyM = 0.0;
            $applyH = 0;

            if ($moneyLeft > 0.009) {
                $room = max(0.0, round($origM - $paid, 2));
                $applyM = min($moneyLeft, $room);
                $paid = round($paid + $applyM, 2);
                $moneyLeft = round($moneyLeft - $applyM, 2);
            }

            if ($hoursLeft > 0) {
                $roomH = max(0, $origH - $rendered);
                $applyH = min($hoursLeft, $roomH);
                $rendered += $applyH;
                $hoursLeft -= $applyH;
            }

            if ($applyM > 0.009 || $applyH > 0) {
                try {
                    $allocStmt->execute([$settlementId, $penaltyId, $applyM, $applyH]);
                } catch (PDOException $e) {
                    // Allocations table may be missing on an old DB; schema_solid creates it.
                }
            }

            $row['amount_paid'] = $paid;
            $row['hours_rendered'] = $rendered;
            $open[$index] = $row;
        }

        $after = summarizePenaltyObligation($open);
        $fullySettled = $after['remaining_fraction'] <= 0.001;
        $batchStatus = $fullySettled ? 'settled' : 'partial';
        $batchChoice = $choice;

        if ($after['amount_paid'] > 0.009 && $after['hours_rendered'] > 0) {
            $batchChoice = 'mixed';
        } elseif ($after['amount_paid'] > 0.009) {
            $batchChoice = 'monetary';
        } elseif ($after['hours_rendered'] > 0) {
            $batchChoice = 'community_service';
        }

        foreach ($open as $row) {
            $paid = (float) ($row['amount_paid'] ?? 0);
            $rendered = (int) ($row['hours_rendered'] ?? 0);
            $origM = (float) $row['monetary_amount'];
            $origH = (int) $row['community_service_hours'];
            $moneyStatus = $paid <= 0.009 ? 'unpaid' : ($paid + 0.009 >= $origM ? 'paid' : 'partial');
            $hoursStatus = $rendered <= 0 ? 'pending' : ($rendered >= $origH ? 'done' : 'pending');
            $rowChoice = $batchChoice;

            if ($paid > 0.009 && $rendered > 0) {
                $rowChoice = 'mixed';
            } elseif ($paid > 0.009) {
                $rowChoice = 'monetary';
            } elseif ($rendered > 0) {
                $rowChoice = 'community_service';
            }

            $rowStatus = $fullySettled ? 'settled' : (($paid > 0.009 || $rendered > 0) ? 'partial' : 'pending');

            if ($fullySettled) {
                $moneyStatus = $paid > 0.009 ? ($moneyStatus === 'unpaid' ? 'partial' : $moneyStatus) : $moneyStatus;
                $hoursStatus = $rendered > 0 ? ($rendered >= $origH ? 'done' : 'pending') : $hoursStatus;
            }

            $updateStmt->execute([
                $paid,
                $rendered,
                $moneyStatus,
                $fullySettled && $rendered > 0 ? 'done' : $hoursStatus,
                $fullySettled ? $batchChoice : $rowChoice,
                $rowStatus,
                $officerUserId,
                $rowStatus,
                $settlementId,
                (int) $row['penalty_id'],
            ]);
        }

        $conn->commit();

        try {
            require_once __DIR__ . '/audit_helpers.php';
            $studentLabel = 'student #' . $userId;
            $nameStmt = $conn->prepare("
                SELECT firstname, lastname, student_no
                FROM users
                WHERE user_id = ?
                LIMIT 1
            ");
            $nameStmt->execute([$userId]);
            $studentRow = $nameStmt->fetch(PDO::FETCH_ASSOC);

            if ($studentRow) {
                $studentLabel = trim((string) $studentRow['firstname'] . ' ' . (string) $studentRow['lastname'])
                    . ' (' . trim((string) $studentRow['student_no']) . ')';
            }

            $fullyNote = $fullySettled ? 'fully settled' : 'partial settlement';
            logAudit(
                $conn,
                'penalty_settlement',
                'penalty_settlements',
                $settlementId,
                $studentLabel . ' — ' . $fullyNote . ': ₱' . number_format($payAmount, 2)
                    . ' paid, ' . $renderHours . ' hr(s) rendered'
            );
        } catch (Throwable $e) {
            // Audit is optional.
        }

        $label = [];

        if ($payAmount > 0) {
            $label[] = '₱' . number_format($payAmount, 2) . ' paid';
        }

        if ($renderHours > 0) {
            $label[] = $renderHours . ' community service hour(s)';
        }

        $message = $fullySettled
            ? 'Penalties fully settled (' . implode(' and ', $label) . ').'
            : 'Partial settlement recorded (' . implode(' and ', $label) . '). Remaining: ₱'
                . number_format($after['remaining_monetary'], 2)
                . ' or ' . $after['remaining_hours'] . ' hour(s).';

        return [
            'ok' => true,
            'message' => $message,
            'settlement_id' => $settlementId,
            'fully_settled' => $fullySettled,
            'amount_paid' => $payAmount,
            'hours_rendered' => $renderHours,
            'remaining_monetary' => $after['remaining_monetary'],
            'remaining_hours' => $after['remaining_hours'],
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        return ['ok' => false, 'message' => 'Settlement failed: ' . $e->getMessage()];
    }
}

function insertPenaltySettlementRow(
    PDO $conn,
    int $userId,
    string $schoolYear,
    string $choice,
    float $totalMonetary,
    int $totalHours,
    float $paid,
    int $rendered,
    int $penaltyCount,
    int $officerUserId,
    string $notes
): int {
    try {
        $stmt = $conn->prepare("
            INSERT INTO penalty_settlements
                (user_id, school_year, resolution_choice, total_monetary, total_service_hours,
                 monetary_paid, hours_rendered, penalty_count, settled_by, settled_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $userId,
            $schoolYear,
            $choice,
            $totalMonetary,
            $totalHours,
            $paid,
            $rendered,
            $penaltyCount,
            $officerUserId,
            $notes,
        ]);
    } catch (PDOException $e) {
        $stmt = $conn->prepare("
            INSERT INTO penalty_settlements
                (user_id, school_year, resolution_choice, total_monetary, total_service_hours,
                 penalty_count, settled_by, settled_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $userId,
            $schoolYear,
            $choice,
            $totalMonetary,
            $totalHours,
            $penaltyCount,
            $officerUserId,
            $notes,
        ]);
    }

    return (int) $conn->lastInsertId();
}




function getAdminPenaltyStatistics(PDO $conn, ?string $schoolYear = null): array

{

    ensurePenaltyResolutionSchema($conn);

    ensureStudentAcademicSchema($conn);



    if ($schoolYear === null || $schoolYear === '') {

        $schoolYear = getActiveSchoolYear($conn);

    }



    $range = schoolYearDateRange($schoolYear);

    $yearClause = penaltyInSchoolYearClause('p');



    $stmt = $conn->prepare("

        SELECT

            COUNT(*) AS penalty_count,

            COALESCE(SUM(p.monetary_amount), 0) AS total_monetary_possible,

            COALESCE(SUM(p.community_service_hours), 0) AS total_service_hours_possible,

            COALESCE(SUM(CASE

                WHEN p.settlement_status = 'settled' AND p.resolution_choice = 'monetary'

                THEN p.monetary_amount ELSE 0 END), 0) AS monetary_paid,

            COALESCE(SUM(CASE

                WHEN p.settlement_status = 'settled' AND p.resolution_choice = 'community_service'

                THEN p.community_service_hours ELSE 0 END), 0) AS service_hours_completed,

            COALESCE(SUM(CASE WHEN p.settlement_status = 'pending' THEN p.monetary_amount ELSE 0 END), 0) AS pending_monetary,

            COALESCE(SUM(CASE WHEN p.settlement_status = 'pending' THEN p.community_service_hours ELSE 0 END), 0) AS pending_service_hours,

            SUM(CASE WHEN p.settlement_status = 'pending' THEN 1 ELSE 0 END) AS pending_count,

            COUNT(DISTINCT CASE WHEN p.settlement_status = 'pending' THEN p.user_id END) AS pending_students

        FROM penalties p

        WHERE {$yearClause}

    ");

    $stmt->execute([$range['start'], $range['end']]);

    $schoolYearStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



    $stmt = $conn->prepare("

        SELECT

            COUNT(*) AS penalty_count,

            COALESCE(SUM(p.monetary_amount), 0) AS pending_monetary,

            COALESCE(SUM(p.community_service_hours), 0) AS pending_service_hours,

            COUNT(DISTINCT p.user_id) AS pending_students

        FROM penalties p

        WHERE p.settlement_status = 'pending'

        AND (DATE(p.created_at) < ? OR DATE(p.created_at) > ?)

    ");

    $stmt->execute([$range['start'], $range['end']]);

    $pastUnsettled = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



    $stmt = $conn->query("

        SELECT

            COUNT(*) AS penalty_count,

            COALESCE(SUM(monetary_amount), 0) AS total_monetary_possible,

            COALESCE(SUM(community_service_hours), 0) AS total_service_hours_possible,

            COALESCE(SUM(CASE

                WHEN settlement_status = 'settled' AND resolution_choice = 'monetary'

                THEN monetary_amount ELSE 0 END), 0) AS monetary_paid,

            COALESCE(SUM(CASE WHEN settlement_status = 'pending' THEN monetary_amount ELSE 0 END), 0) AS pending_monetary,

            COALESCE(SUM(CASE WHEN settlement_status = 'pending' THEN community_service_hours ELSE 0 END), 0) AS pending_service_hours

        FROM penalties

    ");

    $allTime = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];



    return [

        'school_year' => $schoolYear,

        'school_year_range' => $range,

        'active_year' => [

            'penalty_count' => (int) ($schoolYearStats['penalty_count'] ?? 0),

            'total_monetary_possible' => (float) ($schoolYearStats['total_monetary_possible'] ?? 0),

            'total_service_hours_possible' => (int) ($schoolYearStats['total_service_hours_possible'] ?? 0),

            'monetary_paid' => (float) ($schoolYearStats['monetary_paid'] ?? 0),

            'service_hours_completed' => (int) ($schoolYearStats['service_hours_completed'] ?? 0),

            'pending_monetary' => (float) ($schoolYearStats['pending_monetary'] ?? 0),

            'pending_service_hours' => (int) ($schoolYearStats['pending_service_hours'] ?? 0),

            'pending_count' => (int) ($schoolYearStats['pending_count'] ?? 0),

            'pending_students' => (int) ($schoolYearStats['pending_students'] ?? 0),

        ],

        'past_unsettled' => [

            'penalty_count' => (int) ($pastUnsettled['penalty_count'] ?? 0),

            'pending_monetary' => (float) ($pastUnsettled['pending_monetary'] ?? 0),

            'pending_service_hours' => (int) ($pastUnsettled['pending_service_hours'] ?? 0),

            'pending_students' => (int) ($pastUnsettled['pending_students'] ?? 0),

        ],

        'all_time' => [

            'penalty_count' => (int) ($allTime['penalty_count'] ?? 0),

            'total_monetary_possible' => (float) ($allTime['total_monetary_possible'] ?? 0),

            'total_service_hours_possible' => (int) ($allTime['total_service_hours_possible'] ?? 0),

            'monetary_paid' => (float) ($allTime['monetary_paid'] ?? 0),

            'pending_monetary' => (float) ($allTime['pending_monetary'] ?? 0),

            'pending_service_hours' => (int) ($allTime['pending_service_hours'] ?? 0),

        ],

    ];

}



function getStudentEventAttendanceProgress(PDO $conn, int $userId, ?array $activeEvent): array

{

    if (!$activeEvent) {

        return [

            'has_active_event' => false,

            'event_name' => '',

            'completed' => 0,

            'total' => 0,

            'percent' => 0,

        ];

    }



    $eventId = (int) $activeEvent['event_id'];

    $scheduleType = $activeEvent['schedule_type'] ?? 'full_day';

    $total = getExpectedTotalScans(

        $scheduleType,

        (string) $activeEvent['start_date'],

        (string) $activeEvent['end_date']

    );



    $stmt = $conn->prepare("

        SELECT COUNT(*) AS completed

        FROM attendance_sessions

        WHERE user_id = ?

        AND event_id = ?

        AND status IN ('present', 'late')

    ");

    $stmt->execute([$userId, $eventId]);

    $completed = (int) ($stmt->fetchColumn() ?: 0);



    return [

        'has_active_event' => true,

        'event_id' => $eventId,

        'event_name' => (string) ($activeEvent['event_name'] ?? ''),

        'completed' => $completed,

        'total' => $total,

        'percent' => $total > 0 ? (int) min(100, round(($completed / $total) * 100)) : 0,

    ];

}



function penaltyResolutionLabel(string $choice): string

{

    return match ($choice) {

        'mixed' => 'Mixed (pesos + hours)',
        'monetary' => 'Monetary Payment',

        'community_service' => 'Community Service',

        default => 'Awaiting Officer Settlement',

    };

}



function penaltySettlementStatusLabel(array $penalty): string

{

    if (($penalty['settlement_status'] ?? '') === 'settled') {
        return penaltyResolutionLabel((string) ($penalty['resolution_choice'] ?? 'pending')) . ' (Settled)';
    }

    if (($penalty['settlement_status'] ?? '') === 'partial') {
        return 'Partially settled';
    }

    return 'Pending';

}



function penaltyMonetaryPaymentLabel(array $penalty): string

{

    return ($penalty['monetary_status'] ?? 'unpaid') === 'paid' ? 'Paid' : 'Unpaid';

}



function penaltyCommunityServiceLabel(array $penalty): string

{

    return ($penalty['community_service_status'] ?? 'pending') === 'done' ? 'Settled' : 'Not Settled';

}

function normalizePenaltyStatusFilter(string $status): string
{
    $status = strtolower(trim($status));

    if (in_array($status, ['unsettled', 'pending', 'partial'], true)) {
        return 'unsettled';
    }

    if ($status === 'settled') {
        return 'settled';
    }

    return '';
}

function penaltyObligationStatusLabel(array $summary): string
{
    return (($summary['remaining_fraction'] ?? 1) > 0.001) ? 'Unsettled' : 'Settled';
}

function penaltyRecordStatusLabel(array $penalty): string
{
    return (($penalty['settlement_status'] ?? '') === 'settled') ? 'Settled' : 'Unsettled';
}

function penaltyStudentsListWhere(array $filters): array
{
    require_once __DIR__ . '/search_helpers.php';

    $eventId = (int) ($filters['event_id'] ?? 0);
    $course = trim((string) ($filters['course'] ?? ''));
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $searchQuery = trim((string) ($filters['q'] ?? ''));

    $sql = '1=1';
    $params = [];

    if ($eventId > 0) {
        $sql .= ' AND p.event_id = ?';
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

    if ($searchQuery !== '') {
        $search = buildStudentSearch($searchQuery, 'u');
        $sql .= ' AND ' . $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    return ['sql' => $sql, 'params' => $params];
}

function penaltyRemainingFractionSql(bool $includePaid): string
{
    if (!$includePaid) {
        return '1';
    }

    return "GREATEST(0, 1 - LEAST(1,
        IF(SUM(p.monetary_amount) > 0.009, SUM(COALESCE(p.amount_paid, 0)) / NULLIF(SUM(p.monetary_amount), 0), 0)
        + IF(SUM(p.community_service_hours) > 0, SUM(COALESCE(p.hours_rendered, 0)) / NULLIF(SUM(p.community_service_hours), 0), 0)
    ))";
}

function penaltyStudentsStatusHaving(string $statusFilter, bool $includePaid): string
{
    $statusFilter = normalizePenaltyStatusFilter($statusFilter);
    $remaining = penaltyRemainingFractionSql($includePaid);

    if ($statusFilter === 'settled') {
        return " HAVING {$remaining} <= 0.001";
    }

    if ($statusFilter === 'unsettled') {
        return " HAVING {$remaining} > 0.001";
    }

    return '';
}

function penaltyStudentsGroupedFromSql(bool $includePaid): string
{
    $paidSelect = $includePaid
        ? "SUM(COALESCE(p.amount_paid, 0)) AS amount_paid,\n            SUM(COALESCE(p.hours_rendered, 0)) AS hours_rendered"
        : "0 AS amount_paid,\n            0 AS hours_rendered";

    return "
        SELECT
            u.user_id,
            u.student_no,
            u.firstname,
            u.lastname,
            u.course,
            u.year_level,
            SUM(p.monetary_amount) AS original_monetary,
            SUM(p.community_service_hours) AS original_hours,
            {$paidSelect}
        FROM penalties p
        INNER JOIN users u ON p.user_id = u.user_id
        WHERE %s
        GROUP BY u.user_id, u.student_no, u.firstname, u.lastname, u.course, u.year_level
        %s
    ";
}

function mapPenaltyStudentGroupRow(array $row): array
{
    $summary = summarizePenaltyObligation([
        [
            'monetary_amount' => $row['original_monetary'] ?? 0,
            'community_service_hours' => $row['original_hours'] ?? 0,
            'amount_paid' => $row['amount_paid'] ?? 0,
            'hours_rendered' => $row['hours_rendered'] ?? 0,
        ],
    ]);

    $firstname = (string) ($row['firstname'] ?? '');
    $lastname = (string) ($row['lastname'] ?? '');

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'student_no' => (string) ($row['student_no'] ?? ''),
        'firstname' => $firstname,
        'lastname' => $lastname,
        'student_name' => trim($firstname . ' ' . $lastname),
        'course' => (string) ($row['course'] ?? ''),
        'year_level' => (string) ($row['year_level'] ?? ''),
        'remaining_monetary' => $summary['remaining_monetary'],
        'remaining_hours' => $summary['remaining_hours'],
        'original_monetary' => $summary['original_monetary'],
        'original_hours' => $summary['original_hours'],
        'status' => penaltyObligationStatusLabel($summary),
    ];
}

function countPenaltyStudentsList(PDO $conn, array $filters = []): int
{
    ensurePenaltyResolutionSchema($conn);

    $where = penaltyStudentsListWhere($filters);
    $statusFilter = (string) ($filters['status'] ?? $filters['settlement_status'] ?? '');

    $countSql = "
        SELECT COUNT(*) FROM (
            SELECT p.user_id
            FROM penalties p
            INNER JOIN users u ON p.user_id = u.user_id
            WHERE {$where['sql']}
            GROUP BY p.user_id
            %s
        ) counted
    ";

    try {
        $sql = sprintf($countSql, penaltyStudentsStatusHaving($statusFilter, true));
        $stmt = $conn->prepare($sql);
        $stmt->execute($where['params']);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $sql = sprintf($countSql, penaltyStudentsStatusHaving($statusFilter, false));
        $stmt = $conn->prepare($sql);
        $stmt->execute($where['params']);
        return (int) $stmt->fetchColumn();
    }
}

/**
 * One row per student with remaining (pooled) monetary and service balances.
 *
 * @return list<array<string, mixed>>
 */
function fetchPenaltyStudentsList(PDO $conn, array $filters = [], ?int $limit = null, int $offset = 0): array
{
    ensurePenaltyResolutionSchema($conn);

    $where = penaltyStudentsListWhere($filters);
    $statusFilter = (string) ($filters['status'] ?? $filters['settlement_status'] ?? '');
    $offset = max(0, $offset);
    $limitSql = ' ORDER BY u.lastname ASC, u.firstname ASC, u.student_no ASC';

    if ($limit !== null) {
        $limitSql .= ' LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . $offset;
    }

    $run = static function (bool $includePaid) use ($conn, $where, $statusFilter, $limitSql): array {
        $sql = sprintf(
            penaltyStudentsGroupedFromSql($includePaid),
            $where['sql'],
            penaltyStudentsStatusHaving($statusFilter, $includePaid)
        ) . $limitSql;
        $stmt = $conn->prepare($sql);
        $stmt->execute($where['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        $rows = $run(true);
    } catch (PDOException $e) {
        $rows = $run(false);
    }

    $list = [];

    foreach ($rows as $row) {
        $mapped = mapPenaltyStudentGroupRow($row);
        if ($mapped['user_id'] > 0) {
            $list[] = $mapped;
        }
    }

    return $list;
}

function fetchPenaltiesForExport(PDO $conn, array $filters = []): array

{

    $eventId = (int) ($filters['event_id'] ?? 0);

    $course = trim((string) ($filters['course'] ?? ''));

    $yearLevel = trim((string) ($filters['year_level'] ?? ''));

    $settlementStatus = trim((string) ($filters['settlement_status'] ?? ''));

    $monetaryStatus = trim((string) ($filters['monetary_status'] ?? ''));

    $communityServiceStatus = trim((string) ($filters['community_service_status'] ?? ''));



    $sql = "

        SELECT

            u.user_id,

            u.student_no,

            u.firstname,

            u.lastname,

            u.course,

            u.year_level,

            e.event_id,

            e.event_name,

            p.reason,

            p.monetary_amount,

            p.community_service_hours,

            COALESCE(p.amount_paid, 0) AS amount_paid,

            COALESCE(p.hours_rendered, 0) AS hours_rendered,

            p.monetary_status,

            p.community_service_status,

            p.resolution_choice,

            p.settlement_status,

            p.settled_at,

            p.created_at

        FROM penalties p

        INNER JOIN users u ON p.user_id = u.user_id

        INNER JOIN events e ON p.event_id = e.event_id

        WHERE 1=1

    ";

    $params = [];



    if ($eventId > 0) {

        $sql .= " AND p.event_id = ?";

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



    if ($settlementStatus === 'pending') {

        $sql .= " AND p.settlement_status IN ('pending', 'partial')";

    } elseif ($settlementStatus === 'partial') {

        $sql .= " AND p.settlement_status = 'partial'";

    } elseif ($settlementStatus === 'settled') {

        $sql .= " AND p.settlement_status = 'settled'";

    } elseif ($settlementStatus === 'monetary') {

        $sql .= " AND p.settlement_status = 'settled' AND p.resolution_choice = 'monetary'";

    } elseif ($settlementStatus === 'community_service') {

        $sql .= " AND p.settlement_status = 'settled' AND p.resolution_choice = 'community_service'";

    }



    if ($monetaryStatus === 'paid') {

        $sql .= " AND p.monetary_status = 'paid'";

    } elseif ($monetaryStatus === 'unpaid') {

        $sql .= " AND p.monetary_status = 'unpaid'";

    }



    if ($communityServiceStatus === 'done') {

        $sql .= " AND p.community_service_status = 'done'";

    } elseif ($communityServiceStatus === 'pending') {

        $sql .= " AND p.community_service_status = 'pending'";

    }



    $sql .= " ORDER BY u.lastname ASC, u.firstname ASC, e.event_name ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $sql = str_replace(
            "COALESCE(p.amount_paid, 0) AS amount_paid,\n\n            COALESCE(p.hours_rendered, 0) AS hours_rendered,\n\n            ",
            '',
            $sql
        );
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['amount_paid'] = $row['amount_paid'] ?? 0;
            $row['hours_rendered'] = $row['hours_rendered'] ?? 0;
        }
        unset($row);
        return $rows;
    }

}



function aggregatePenaltyExportByStudent(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $key = $userId > 0 ? ('id:' . $userId) : ('no:' . (string) ($row['student_no'] ?? ''));

        if (!isset($grouped[$key])) {
            $name = trim(implode(' ', array_filter([
                trim((string) ($row['firstname'] ?? '')),
                trim((string) ($row['lastname'] ?? '')),
            ])));
            if ($name === '' && !empty($row['student_name'])) {
                $name = (string) $row['student_name'];
            }

            $grouped[$key] = [
                'student_name' => $name,
                'course' => (string) ($row['course'] ?? ''),
                'year_level' => (string) ($row['year_level'] ?? ''),
                'events' => [],
                'penalties' => [],
            ];
        }

        $eventName = trim((string) ($row['event_name'] ?? ''));
        if ($eventName !== '') {
            $grouped[$key]['events'][$eventName] = $eventName;
        }

        $grouped[$key]['penalties'][] = $row;
    }

    $aggregated = [];

    foreach ($grouped as $group) {
        $summary = summarizePenaltyObligation($group['penalties']);
        $eventNames = array_values($group['events']);
        $eventLabel = 'All events';

        if (count($eventNames) === 1) {
            $eventLabel = $eventNames[0];
        } elseif (count($eventNames) > 1) {
            $eventLabel = 'All events (' . count($eventNames) . ')';
        }

        $aggregated[] = [
            'student_name' => $group['student_name'],
            'course' => $group['course'],
            'year_level' => $group['year_level'],
            'event_name' => $eventLabel,
            'monetary_amount' => $summary['original_monetary'],
            'settlement_status' => $summary['remaining_fraction'] > 0.001 ? 'Unsettled' : 'Settled',
        ];
    }

    usort($aggregated, static function (array $a, array $b): int {
        return [$a['course'], $a['year_level'], $a['student_name']]
            <=> [$b['course'], $b['year_level'], $b['student_name']];
    });

    return $aggregated;
}

function buildPenaltyPdfExportRowsFromStudents(array $students): array
{
    $exportRows = [];

    foreach ($students as $row) {
        $exportRows[] = [
            'student_no' => $row['student_no'] ?? '',
            'student_name' => $row['student_name'] ?? '',
            'course' => $row['course'] ?? '',
            'year_level' => $row['year_level'] ?? '',
            'monetary_amount' => number_format((float) ($row['remaining_monetary'] ?? 0), 2),
            'community_service' => (string) (int) ($row['remaining_hours'] ?? 0),
            'settlement_status' => $row['status'] ?? 'Unsettled',
        ];
    }

    return $exportRows;
}

function penaltyPdfExportHeaders(): array
{
    return [
        'student_no' => 'Student No',
        'student_name' => 'Name',
        'course' => 'Course',
        'year_level' => 'Year',
        'monetary_amount' => 'Monetary (₱)',
        'community_service' => 'Community Service (hr)',
        'settlement_status' => 'Status',
    ];
}

function buildPenaltyPdfSummaryMetaFromStudents(array $students): array
{
    $settled = 0;
    $unsettled = 0;

    foreach ($students as $row) {
        if (($row['status'] ?? '') === 'Settled') {
            $settled++;
        } else {
            $unsettled++;
        }
    }

    return [
        'Students' => (string) count($students),
        'Settled' => (string) $settled,
        'Unsettled' => (string) $unsettled,
    ];
}

function exportPenaltiesPdf(PDO $conn, array $filters, string $title, array $extraMeta = []): void
{
    require_once __DIR__ . '/table_pdf.php';

    $students = fetchPenaltyStudentsList($conn, $filters);
    $exportRows = buildPenaltyPdfExportRowsFromStudents($students);
    $meta = array_merge(buildPenaltyPdfSummaryMetaFromStudents($students), $extraMeta);

    renderTablePdf(
        $title,
        $exportRows,
        penaltyPdfExportHeaders(),
        $meta
    );
}


