<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/activity_log_helpers.php';
require_once '../includes/student_layout.php';

$db = new Database();
$conn = $db->connect();
ensurePenaltyResolutionSchema($conn);

$settlementId = (int) ($_GET['id'] ?? 0);
$userId = (int) $_SESSION['user_id'];
$detail = $settlementId > 0 ? fetchStudentPenaltySettlementDetail($conn, $settlementId, $userId) : null;

if (!$detail) {
    header('Location: penalties.php');
    exit;
}

$paid = (float) ($detail['monetary_paid'] ?? $detail['total_monetary'] ?? 0);
$hours = (int) ($detail['hours_rendered'] ?? $detail['total_service_hours'] ?? 0);

renderStudentHeader('Settlement details', 'penalties', 'Settlement details');
?>

<p class="mb-3"><a href="penalties.php" class="btn btn-sm btn-outline-secondary">Back to penalties</a></p>

<div class="student-record">
    <h3 class="student-record__title"><?= htmlspecialchars(penaltyResolutionLabel((string) ($detail['resolution_choice'] ?? ''))) ?></h3>
    <ul class="info-list mt-3">
        <li>
            <span class="info-label">When</span>
            <span class="info-value"><?= !empty($detail['settled_at']) ? date('M d, Y g:i A', strtotime($detail['settled_at'])) : '—' ?></span>
        </li>
        <li>
            <span class="info-label">Amount paid</span>
            <span class="info-value">₱<?= number_format($paid, 2) ?></span>
        </li>
        <li>
            <span class="info-label">Hours rendered</span>
            <span class="info-value"><?= $hours ?> hr(s)</span>
        </li>
        <li>
            <span class="info-label">Method</span>
            <span class="info-value"><?= htmlspecialchars(penaltyResolutionLabel((string) ($detail['resolution_choice'] ?? ''))) ?></span>
        </li>
        <li>
            <span class="info-label">Settled by</span>
            <span class="info-value"><?= htmlspecialchars(trim((string) ($detail['settled_by_name'] ?? '')) ?: 'Officer') ?></span>
        </li>
        <li>
            <span class="info-label">Penalties included</span>
            <span class="info-value"><?= (int) ($detail['penalty_count'] ?? 0) ?></span>
        </li>
        <?php if (!empty($detail['notes'])): ?>
        <li>
            <span class="info-label">Notes</span>
            <span class="info-value"><?= htmlspecialchars((string) $detail['notes']) ?></span>
        </li>
        <?php endif; ?>
    </ul>
</div>

<?php if (!empty($detail['lines'])): ?>
    <h2 class="student-section-title mt-4"><i class="bi bi-list-check"></i> Applied to</h2>
    <div class="student-record-list">
        <?php foreach ($detail['lines'] as $line): ?>
            <article class="student-record">
                <h3 class="student-record__title"><?= htmlspecialchars((string) $line['event_name']) ?></h3>
                <p class="student-record__meta"><?= htmlspecialchars((string) $line['reason']) ?></p>
                <p class="student-record__amount">
                    Applied ₱<?= number_format((float) $line['monetary_applied'], 2) ?>
                    + <?= (int) $line['hours_applied'] ?> hr(s)
                </p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php renderStudentFooter(); ?>
