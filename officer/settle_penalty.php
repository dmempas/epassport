<?php

require_once '../includes/session.php';
requireRole(3);

require_once '../config/database.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/officer_layout.php';

$db = new Database();
$conn = $db->connect();
ensurePenaltyResolutionSchema($conn);

$userId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: student_records.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $choice = trim((string) ($_POST['resolution_choice'] ?? ''));
    $result = ['ok' => false, 'message' => 'Choose a settlement option.'];

    if ($action === 'settle_mix') {
        $result = applyStudentPenaltySettlement(
            $conn,
            $userId,
            (int) $_SESSION['user_id'],
            (float) ($_POST['amount_paid'] ?? 0),
            (int) ($_POST['hours_rendered'] ?? 0),
            trim((string) ($_POST['notes'] ?? ''))
        );
    } elseif ($action === 'settle_batch' && $choice !== '') {
        $result = settleStudentPenaltiesBatch($conn, $userId, $choice, (int) $_SESSION['user_id']);
    }

    $type = $result['ok'] ? 'success' : 'danger';
    header(
        'Location: settle_penalty.php?user_id=' . $userId
        . '&msg=' . urlencode($result['message'])
        . '&type=' . $type
    );
    exit;
}

$student = fetchStudentById($conn, $userId);
if (!$student) {
    header('Location: student_records.php');
    exit;
}

$open = fetchStudentPendingPenalties($conn, $userId);
$balance = summarizePenaltyObligation($open);
$ledger = fetchStudentPenaltyLedger($conn, $userId);
$flash = (string) ($_GET['msg'] ?? '');
$flashType = ($_GET['type'] ?? 'success') === 'danger' ? 'danger' : 'success';
$hasOpen = ($balance['remaining_fraction'] ?? 0) > 0.001;

renderOfficerHeader(
    'Student Penalty Details',
    'records',
    formatStudentDisplayName($student),
    trim(($student['student_no'] ?? '') . ' · ' . ($student['course'] ?? '') . ' · ' . ($student['year_level'] ?? ''))
);
?>

<p class="mb-3">
    <a href="student_records.php" class="btn btn-sm btn-outline-secondary">Back to records</a>
    <a href="view_student_record.php?id=<?= $userId ?>" class="btn btn-sm btn-outline-primary">Student record</a>
</p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="officer-card mb-3">
    <div class="card-body">
        <?php if ($hasOpen): ?>
            <h3 class="h6 mb-2">Record settlement</h3>
            <form method="POST" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="action" value="settle_mix">
                <div class="col-md-4">
                    <label class="form-label small">Pay ₱</label>
                    <input type="number" name="amount_paid" class="form-control" min="0" step="0.01" value="0" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Hours</label>
                    <input type="number" name="hours_rendered" class="form-control" min="0" step="1" value="0" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100">Record mix / partial</button>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <form method="POST" data-confirm="Settle remaining ₱<?= number_format((float) $balance['remaining_monetary'], 2) ?> in cash?" data-confirm-title="Record payment" data-confirm-label="Settle">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="action" value="settle_batch">
                    <input type="hidden" name="resolution_choice" value="monetary">
                    <button type="submit" class="btn btn-outline-danger">Pay remaining ₱<?= number_format((float) $balance['remaining_monetary'], 2) ?></button>
                </form>
                <form method="POST" data-confirm="Settle remaining <?= (int) $balance['remaining_hours'] ?> hour(s)?" data-confirm-title="Record community service" data-confirm-label="Settle">
                    <input type="hidden" name="user_id" value="<?= $userId ?>">
                    <input type="hidden" name="action" value="settle_batch">
                    <input type="hidden" name="resolution_choice" value="community_service">
                    <button type="submit" class="btn btn-outline-primary">Render remaining <?= (int) $balance['remaining_hours'] ?> hr</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <h2 class="h6 mb-0">Remaining balance</h2>
            <div>
                <strong>₱<?= number_format((float) $balance['remaining_monetary'], 2) ?></strong>
                or <strong><?= (int) $balance['remaining_hours'] ?> hr(s)</strong>
                ·
                <?php if ($hasOpen): ?>
                    <span class="badge bg-warning text-dark">Unsettled</span>
                <?php else: ?>
                    <span class="badge bg-success">Settled</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive mb-3">
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
</div>

<?php renderOfficerFooter(); ?>
