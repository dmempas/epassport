<?php

require_once '../includes/session.php';
requireRole(3);

require_once '../config/database.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/activity_log_helpers.php';
require_once '../includes/officer_layout.php';

$db = new Database();
$conn = $db->connect();
ensureAuditSchema($conn);

$officerId = (int) $_SESSION['user_id'];
$group = trim((string) ($_GET['group'] ?? ''));
$allowedGroups = ['', 'auth', 'attendance', 'penalty'];

if (!in_array($group, $allowedGroups, true)) {
    $group = '';
}

$filters = ['user_id' => $officerId];

if ($group !== '') {
    $filters['action_group'] = $group;
}

$rows = fetchAuditLogs($conn, null, null, 400, $filters);
$items = array_map('officerActivityItemFromAudit', $rows);

$tabs = [
    '' => 'All',
    'auth' => 'Sign-in',
    'attendance' => 'Scans',
    'penalty' => 'Penalties',
];

renderOfficerHeader(
    'Audit Log',
    'log',
    'Audit log',
    'Actions recorded for your officer account: sign-in, QR scans, penalty settlements, and password resets.'
);
?>

<nav class="officer-category-bar mb-3" aria-label="Filter audit log">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="audit_log.php<?= $key !== '' ? ('?group=' . urlencode($key)) : '' ?>"
           class="officer-category<?= $group === $key ? ' is-active' : '' ?>">
            <span class="officer-category__label"><?= htmlspecialchars($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($items === []): ?>
    <div class="officer-card">
        <div class="card-body">
            <p class="mb-0 text-muted">No actions recorded yet for this account.</p>
        </div>
    </div>
<?php else: ?>
    <div class="officer-record-list">
        <?php foreach ($items as $item): ?>
            <article class="officer-record officer-record--static">
                <div class="officer-record__top">
                    <span class="<?= studentActivityBadgeClass((string) $item['tone']) ?>">
                        <?= htmlspecialchars((string) $item['label']) ?>
                    </span>
                </div>
                <p class="officer-record__meta"><?= htmlspecialchars((string) $item['description']) ?></p>
                <p class="officer-record__when">
                    <?= $item['occurred_at'] !== '' ? date('M d, Y g:i A', strtotime((string) $item['occurred_at'])) : '—' ?>
                </p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<p class="mt-3 mb-0 small text-muted">
    To change your own password, open
    <a href="../change_password.php">Change password</a>.
    Student password resets stay on each student record.
</p>

<?php renderOfficerFooter(); ?>
