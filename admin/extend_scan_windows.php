<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$activeEvent = getActiveEvent($conn);
$scanRules = [];

if ($activeEvent) {
    $stmt = $conn->prepare("
        SELECT scan_id, scan_date, scan_type, start_time, end_time
        FROM event_scan_requirements
        WHERE event_id = ?
        ORDER BY scan_date ASC, FIELD(scan_type, 'AM_LOGIN','AM_LOGOUT','PM_LOGIN','PM_LOGOUT'), start_time
    ");
    $stmt->execute([(int) $activeEvent['event_id']]);
    $scanRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

renderAdminHeader('Extend Scan Windows', 'events');
?>

<section class="admin-panel">
    <h2 class="h5 mb-2">Extend Login / Logout Windows</h2>
    <p class="text-muted small mb-3">Add extra time to an active event scan window while officers are still scanning.</p>

    <?php if (!$activeEvent): ?>
        <div class="alert alert-warning">No active event. Activate an event first.</div>
    <?php else: ?>
        <p class="small mb-3">Active event: <strong><?= htmlspecialchars($activeEvent['event_name']) ?></strong></p>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Stamp</th>
                        <th>Window</th>
                        <th>Extend</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scanRules as $rule): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($rule['scan_date'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(formatScanTypeLabel($rule['scan_type'])) ?></td>
                        <td><?= htmlspecialchars(formatAppTime12($rule['start_time'])) ?> – <span id="end-<?= (int) $rule['scan_id'] ?>"><?= htmlspecialchars(formatAppTime12($rule['end_time'])) ?></span></td>
                        <td class="d-flex flex-wrap gap-1">
                            <?php foreach ([15, 30, 45, 60] as $mins): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm extend-btn" data-scan-id="<?= (int) $rule['scan_id'] ?>" data-minutes="<?= $mins ?>" data-stamp="<?= htmlspecialchars(formatScanTypeLabel($rule['scan_type'])) ?>">+<?= $mins ?>m</button>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="events.php" class="btn btn-outline-secondary btn-sm">Back to Events</a>
</section>

<script>
document.querySelectorAll('.extend-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const scanId = btn.getAttribute('data-scan-id');
        const minutes = btn.getAttribute('data-minutes');
        const stamp = btn.getAttribute('data-stamp') || 'this stamp';

        epassportConfirm('Add ' + minutes + ' minutes to the ' + stamp + ' window?', {
            title: 'Extend scan window',
            confirmLabel: 'Extend'
        }).then(function (ok) {
            if (!ok) return;

            const body = new URLSearchParams({ scan_id: scanId, minutes: minutes });
            fetch('../ajax/extend_scan_window.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                epassportAlert(data.message || (data.success ? 'Window extended.' : 'Could not extend this window.'), data.success ? 'Window extended' : 'Could not extend');
                if (data.success) {
                    const el = document.getElementById('end-' + scanId);
                    if (el) el.textContent = data.end_time_label || data.end_time;
                }
            })
            .catch(function () {
                epassportAlert('Could not extend the window. Check your connection and try again.', 'Could not extend');
            });
        });
    });
});
</script>

<?php renderAdminFooter(); ?>
