<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/student_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

ensurePenaltyResolutionSchema($conn);
ensureStudentAcademicSchema($conn);

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: penalty_management.php');
    exit;
}

$student = fetchStudentById($conn, $userId);
if (!$student) {
    $student = [
        'user_id' => $userId,
        'student_no' => '',
        'firstname' => 'Student',
        'middlename' => '',
        'lastname' => '#' . $userId,
        'course' => '',
        'year_level' => '',
    ];
}

$ledger = fetchStudentPenaltyLedger($conn, $userId);
$balance = summarizePenaltyObligation(fetchStudentPendingPenalties($conn, $userId));

renderAdminHeader('Student Penalty Details', 'penalty_management');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-3">
        <div>
            <h2 class="h4 mb-1"><?= htmlspecialchars(formatStudentDisplayName($student)) ?></h2>
            <p class="small text-muted mb-0">
                <?= htmlspecialchars((string) $student['student_no']) ?>
                · <?= htmlspecialchars((string) $student['course']) ?>
                · <?= htmlspecialchars((string) $student['year_level']) ?>
            </p>
        </div>
        <div class="admin-page-actions">
            <a href="penalty_management.php" class="btn btn-outline-secondary btn-sm">Back to Penalty Management</a>
        </div>
    </div>

    <div class="admin-quick-card mb-4">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <h3 class="h6 mb-0">Remaining balance</h3>
            <div>
                <strong>₱<?= number_format((float) $balance['remaining_monetary'], 2) ?></strong>
                or <strong><?= (int) $balance['remaining_hours'] ?> hr(s)</strong>
                ·
                <?php if (($balance['remaining_fraction'] ?? 0) > 0.001): ?>
                    <span class="badge bg-warning text-dark">Unsettled</span>
                <?php else: ?>
                    <span class="badge bg-success">Settled</span>
                <?php endif; ?>
            </div>
        </div>
        <p class="small text-muted mb-3">This page is view-only. Officers record penalty settlement.</p>

        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Reason</th>
                        <th>Monetary</th>
                        <th>Community Service</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledger as $row): ?>
                        <?php $rowBalance = summarizePenaltyObligation([$row]); ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $row['event_name']) ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars((string) $row['start_date']) ?> → <?= htmlspecialchars((string) $row['end_date']) ?></div>
                            </td>
                            <td><?= htmlspecialchars((string) $row['reason']) ?></td>
                            <td>
                                ₱<?= number_format((float) $rowBalance['remaining_monetary'], 2) ?>
                                <div class="small text-muted">of ₱<?= number_format((float) $row['monetary_amount'], 2) ?></div>
                            </td>
                            <td>
                                <?= (int) $rowBalance['remaining_hours'] ?> hr(s)
                                <div class="small text-muted">of <?= (int) $row['community_service_hours'] ?> hr(s)</div>
                            </td>
                            <td>
                                <?php if (penaltyRecordStatusLabel($row) === 'Settled'): ?>
                                    <span class="badge bg-success">Settled</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Unsettled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($ledger === []): ?>
                        <tr><td colspan="5" class="text-muted text-center py-3">No penalty records.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>

<?php renderAdminFooter(); ?>
