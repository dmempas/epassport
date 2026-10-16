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

$userId = (int) $_SESSION['user_id'];
$penaltySummary = getStudentPenaltySummary($conn, $userId);
$byEvent = fetchStudentPenaltiesGroupedByEvent($conn, $userId);
$settlements = fetchStudentPenaltySettlements($conn, $userId);
$remainingMoney = (float) $penaltySummary['pending_monetary'];
$remainingHours = (int) $penaltySummary['pending_service_hours'];
$hasBalance = $remainingMoney > 0.009 || $remainingHours > 0;

renderStudentHeader('Penalties', 'penalties', 'Penalties');
?>

<div class="student-balance-card<?= $hasBalance ? ' student-balance-card--due' : ' student-balance-card--clear' ?>">
    <div class="student-balance-card__main">
        <span class="student-balance-card__label">Total remaining</span>
        <strong>₱<?= number_format($remainingMoney, 2) ?></strong>
    </div>
    <div class="student-balance-card__side">
        <span class="student-balance-card__label">Or hours</span>
        <strong><?= $remainingHours ?> hr</strong>
    </div>
</div>

<?php if ($hasBalance): ?>
    <div class="student-balance-alert" role="status">
        <i class="bi bi-exclamation-circle-fill"></i>
        <div>
            <strong>Balance still open.</strong>
            Settle ₱<?= number_format($remainingMoney, 2) ?> cash, <?= $remainingHours ?> hour(s) of service, or a mix with an officer.
        </div>
    </div>
<?php else: ?>
    <div class="student-balance-alert student-balance-alert--ok" role="status">
        <i class="bi bi-check-circle-fill"></i>
        <div><strong>No remaining balance.</strong> You are cleared unless a new penalty is added.</div>
    </div>
<?php endif; ?>

<div class="student-category-bar" role="tablist" aria-label="Penalty views">
    <button type="button" class="student-category is-active" data-panel="events" role="tab" aria-selected="true">
        <span class="student-category__count"><?= count($byEvent) ?></span>
        <span class="student-category__label">By event</span>
    </button>
    <button type="button" class="student-category" data-panel="history" role="tab" aria-selected="false">
        <span class="student-category__count"><?= count($settlements) ?></span>
        <span class="student-category__label">History</span>
    </button>
</div>

<div id="penaltyPanelEvents">
    <?php if ($byEvent === []): ?>
        <p class="student-empty">You have no penalty records.</p>
    <?php else: ?>
        <div class="student-record-list">
            <?php foreach ($byEvent as $group): ?>
                <article class="student-record">
                    <div class="student-record__top">
                        <h3 class="student-record__title"><?= htmlspecialchars($group['event_name']) ?></h3>
                        <?php if ($group['status'] === 'Settled'): ?>
                            <span class="status-pill status-pill--present">Settled</span>
                        <?php else: ?>
                            <span class="status-pill status-pill--late">Unsettled</span>
                        <?php endif; ?>
                    </div>
                    <p class="student-record__meta">
                        <?= htmlspecialchars($group['start_date']) ?>
                        <?php if ($group['end_date'] !== '' && $group['end_date'] !== $group['start_date']): ?>
                            → <?= htmlspecialchars($group['end_date']) ?>
                        <?php endif; ?>
                    </p>
                    <p class="student-record__amount">₱<?= number_format((float) $group['summary']['original_monetary'], 2) ?></p>
                    <p class="student-record__when">
                        Remaining ₱<?= number_format((float) $group['summary']['remaining_monetary'], 2) ?>
                        or <?= (int) $group['summary']['remaining_hours'] ?> hr(s)
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="penaltyPanelHistory" class="d-none">
    <?php if ($settlements === []): ?>
        <p class="student-empty">No settlements have been recorded yet.</p>
    <?php else: ?>
        <div class="student-record-list">
            <?php foreach ($settlements as $row): ?>
                <a class="student-record student-record--link" href="settlement_detail.php?id=<?= (int) $row['settlement_id'] ?>">
                    <div class="student-record__top">
                        <h3 class="student-record__title"><?= htmlspecialchars(penaltyResolutionLabel((string) ($row['resolution_choice'] ?? ''))) ?></h3>
                    </div>
                    <p class="student-record__amount">
                        ₱<?= number_format((float) ($row['monetary_paid'] ?? $row['total_monetary'] ?? 0), 2) ?>
                        + <?= (int) ($row['hours_rendered'] ?? $row['total_service_hours'] ?? 0) ?> hr(s)
                    </p>
                    <p class="student-record__when">
                        <?= !empty($row['settled_at']) ? date('M d, Y g:i A', strtotime($row['settled_at'])) : '—' ?>
                    </p>
                    <p class="student-record__meta">Tap for full details</p>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.student-category');
    const panels = {
        events: document.getElementById('penaltyPanelEvents'),
        history: document.getElementById('penaltyPanelHistory')
    };

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const panel = btn.getAttribute('data-panel') || 'events';
            buttons.forEach(function (other) {
                other.classList.toggle('is-active', other === btn);
                other.setAttribute('aria-selected', other === btn ? 'true' : 'false');
            });
            Object.keys(panels).forEach(function (key) {
                if (panels[key]) panels[key].classList.toggle('d-none', key !== panel);
            });
        });
    });
})();
</script>

<?php renderStudentFooter(); ?>
