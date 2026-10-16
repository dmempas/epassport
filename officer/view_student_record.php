<?php

require_once '../includes/session.php';
requireRole(3);

require_once '../config/database.php';
require_once '../includes/password_reset.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/event_helpers.php';
require_once '../includes/attendance_schema.php';
require_once '../includes/officer_layout.php';

$db = new Database();
$conn = $db->connect();
ensurePenaltyResolutionSchema($conn);
ensureAttendanceSchema($conn);

$resetMessage = '';
$resetMessageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $result = resetStudentPasswordToDefault($conn, (int) ($_POST['user_id'] ?? 0));
    $resetMessage = $result['message'];
    $resetMessageType = $result['ok'] ? 'success' : 'danger';
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: student_records.php');
    exit;
}

$student = fetchStudentById($conn, $userId);
if (!$student || (int) ($student['role_id'] ?? 0) !== 2) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1");
    $stmt->execute([$userId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$student) {
    header('Location: student_records.php');
    exit;
}

$flash = (string) ($_GET['msg'] ?? '');
$flashType = ($_GET['type'] ?? 'success') === 'danger' ? 'danger' : 'success';

$stmt = $conn->prepare("
    SELECT a.scan_type, a.status, a.qr_scanned_at, e.event_name
    FROM attendance_sessions a
    INNER JOIN events e ON a.event_id = e.event_id
    WHERE a.user_id = ?
    ORDER BY a.qr_scanned_at DESC
");
$stmt->execute([$userId]);
$attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'violated' => 0];
foreach ($attendance as $row) {
    $status = (string) $row['status'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$open = fetchStudentPendingPenalties($conn, $userId);
$balance = summarizePenaltyObligation($open);
$hasOpen = $balance['remaining_fraction'] > 0.001;

renderOfficerHeader(
    'Student Record',
    'records',
    formatStudentDisplayName($student),
    trim(($student['student_no'] ?? '') . ' · ' . ($student['course'] ?? '') . ' · ' . ($student['year_level'] ?? ''))
);
?>

<p class="mb-3"><a href="student_records.php" class="btn btn-sm btn-outline-secondary">Back to records</a></p>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if ($resetMessage !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($resetMessageType) ?>"><?= htmlspecialchars($resetMessage) ?></div>
<?php endif; ?>

<div class="officer-card mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between gap-3 align-items-center">
        <div>
            <div class="small text-muted">Remaining penalty</div>
            <strong>₱<?= number_format((float) $balance['remaining_monetary'], 2) ?></strong>
            or <?= (int) $balance['remaining_hours'] ?> hr(s)
        </div>
        <?php if ($hasOpen): ?>
            <a class="btn btn-success" href="settle_penalty.php?user_id=<?= $userId ?>">View / settle penalty</a>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="settle_penalty.php?user_id=<?= $userId ?>">View penalties</a>
        <?php endif; ?>
    </div>
</div>

<div class="officer-card mb-3">
    <div class="card-body">
        <h2 class="h6 mb-2">Reset password</h2>
        <form method="POST" id="resetPasswordForm">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= $userId ?>">
            <button type="button" class="btn btn-warning btn-sm" id="resetPasswordOpen">Reset to default password</button>
        </form>
    </div>
</div>

<div class="officer-modal" id="resetPasswordModal" hidden>
    <div class="officer-modal__backdrop" data-close-modal></div>
    <div class="officer-modal__panel" role="dialog" aria-modal="true" aria-labelledby="resetPasswordTitle">
        <h2 class="officer-modal__title" id="resetPasswordTitle">Reset password?</h2>
        <p class="officer-modal__text">
            This will set
            <strong><?= htmlspecialchars(formatStudentDisplayName($student)) ?></strong>'s
            password to their student number
            (<?= htmlspecialchars((string) ($student['student_no'] ?? '')) ?>).
            They will need to change it on next login.
        </p>
        <div class="officer-modal__actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-modal>Cancel</button>
            <button type="button" class="btn btn-warning btn-sm" id="resetPasswordConfirm">Reset password</button>
        </div>
    </div>
</div>

<div class="officer-category-bar mb-3" role="tablist">
    <button type="button" class="officer-category is-active" data-filter="all">
        <span class="officer-category__count"><?= count($attendance) ?></span>
        <span class="officer-category__label">All</span>
    </button>
    <button type="button" class="officer-category" data-filter="present">
        <span class="officer-category__count"><?= (int) $counts['present'] ?></span>
        <span class="officer-category__label">Present</span>
    </button>
    <button type="button" class="officer-category" data-filter="late">
        <span class="officer-category__count"><?= (int) $counts['late'] ?></span>
        <span class="officer-category__label">Late</span>
    </button>
    <button type="button" class="officer-category" data-filter="absent">
        <span class="officer-category__count"><?= (int) $counts['absent'] ?></span>
        <span class="officer-category__label">Absent</span>
    </button>
    <button type="button" class="officer-category" data-filter="violated">
        <span class="officer-category__count"><?= (int) $counts['violated'] ?></span>
        <span class="officer-category__label">Violated</span>
    </button>
</div>

<div class="officer-record-list" id="attendanceList">
    <?php if ($attendance === []): ?>
        <p class="text-muted">No attendance records.</p>
    <?php else: ?>
        <?php foreach ($attendance as $row): ?>
            <?php
            $status = (string) $row['status'];
            $filterKey = in_array($status, ['present', 'late', 'absent', 'violated'], true) ? $status : 'other';
            ?>
            <article class="officer-record officer-record--static" data-status="<?= htmlspecialchars($filterKey) ?>">
                <div class="officer-record__top">
                    <strong><?= htmlspecialchars((string) $row['event_name']) ?></strong>
                    <span class="badge bg-secondary"><?= htmlspecialchars(formatAttendanceStatusLabel($status)) ?></span>
                </div>
                <div class="small text-muted"><?= htmlspecialchars(formatScanTypeLabel((string) $row['scan_type'])) ?></div>
                <div class="small text-muted"><?= !empty($row['qr_scanned_at']) ? date('M d, Y g:i A', strtotime($row['qr_scanned_at'])) : '—' ?></div>
            </article>
        <?php endforeach; ?>
        <p class="text-muted d-none" id="attendanceEmptyFilter">No records in this category.</p>
    <?php endif; ?>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.officer-category-bar .officer-category');
    const cards = document.querySelectorAll('#attendanceList [data-status]');
    const empty = document.getElementById('attendanceEmptyFilter');
    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const filter = btn.getAttribute('data-filter') || 'all';
            buttons.forEach(function (other) {
                other.classList.toggle('is-active', other === btn);
            });
            let shown = 0;
            cards.forEach(function (card) {
                const match = filter === 'all' || card.getAttribute('data-status') === filter;
                card.classList.toggle('d-none', !match);
                if (match) shown++;
            });
            if (empty) empty.classList.toggle('d-none', shown > 0 || cards.length === 0);
        });
    });

    const modal = document.getElementById('resetPasswordModal');
    const form = document.getElementById('resetPasswordForm');
    const openBtn = document.getElementById('resetPasswordOpen');
    const confirmBtn = document.getElementById('resetPasswordConfirm');

    function closeModal() {
        if (modal) modal.hidden = true;
    }

    function openModal() {
        if (modal) modal.hidden = false;
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (confirmBtn && form) {
        confirmBtn.addEventListener('click', function () {
            form.submit();
        });
    }
    if (modal) {
        modal.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
    }
})();
</script>

<?php renderOfficerFooter(); ?>
