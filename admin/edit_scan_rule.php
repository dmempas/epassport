<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/audit_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$scan_id = (int) ($_GET['scan_id'] ?? $_POST['scan_id'] ?? 0);

if ($scan_id <= 0) {
    header("Location: events.php");
    exit;
}

$rule = fetchScanRuleById($conn, $scan_id);

if (!$rule) {
    header("Location: events.php");
    exit;
}

$event_id = (int) $rule['event_id'];
$event = fetchEventById($conn, $event_id);

if (!$event) {
    header("Location: events.php");
    exit;
}

$setupLocked = isEventSetupLocked($event);

$scheduleType = $event['schedule_type'] ?? 'full_day';
$allowedTypes = getRequiredScanTypes($scheduleType);

$message = "";
$messageType = "info";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $scan_type = $rule['scan_type'];

    if ($start_time >= $end_time) {
        $message = "End time must be later than start time.";
        $messageType = "danger";
    } elseif (isEventSetupLocked($event)) {
        $message = eventSetupLockedReason();
        $messageType = "danger";
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE event_scan_requirements
                SET start_time = :start_time,
                    end_time = :end_time,
                    is_required = 1
                WHERE scan_id = :scan_id
                AND event_id = :event_id
            ");

            $stmt->execute([
                ':start_time' => $start_time,
                ':end_time' => $end_time,
                ':scan_id' => $scan_id,
                ':event_id' => $event_id,
            ]);

            deactivateEventOnConfigChange($conn, $event_id);
            logAudit(
                $conn,
                'scan_window_updated',
                'event_scan_requirements',
                $scan_id,
                'Updated ' . $scan_type . ' window to ' . formatAppTime12($start_time) . '–' . formatAppTime12($end_time) . " for event #{$event_id}"
            );

            header("Location: event_setup.php?event_id={$event_id}&step=3");
            exit;

        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

renderAdminHeader('Edit Scan Window', 'events');
?>

<section class="admin-panel">
    <h2 class="h5 mb-3">
        Edit Scan Window — <?= htmlspecialchars(formatScanTypeLabel($rule['scan_type'])) ?>
    </h2>
    <p class="text-muted small"><?= htmlspecialchars($event['event_name']) ?></p>

    <?php if ($setupLocked): ?>
        <div class="alert alert-warning">
            <?= htmlspecialchars(eventSetupLockedReason()) ?>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3">
        <input type="hidden" name="scan_id" value="<?= $scan_id ?>">

        <div class="col-md-6">
            <label class="form-label">Stamp Type</label>
            <input
                type="text"
                class="form-control"
                value="<?= htmlspecialchars(formatScanTypeLabel($rule['scan_type'])) ?>"
                disabled
            >
            <div class="form-text">Stamp type cannot be changed. Delete and add a new window if needed.</div>
        </div>

        <div class="col-md-3">
            <label class="form-label">Start Time</label>
            <input
                type="time"
                name="start_time"
                class="form-control"
                value="<?= htmlspecialchars(substr($rule['start_time'], 0, 5)) ?>"
                <?= $setupLocked ? 'disabled' : 'required' ?>
            >
        </div>

        <div class="col-md-3">
            <label class="form-label">End Time</label>
            <input
                type="time"
                name="end_time"
                class="form-control"
                value="<?= htmlspecialchars(substr($rule['end_time'], 0, 5)) ?>"
                <?= $setupLocked ? 'disabled' : 'required' ?>
            >
        </div>

        <div class="col-12">
            <?php if ($setupLocked): ?>
            <div class="btn-group">
                <button type="button" class="btn btn-outline-warning extend-end-btn" data-minutes="15">Extend +15m</button>
                <button type="button" class="btn btn-outline-warning extend-end-btn" data-minutes="30">+30m</button>
            </div>
            <?php else: ?>
            <button class="btn btn-success">Save Changes</button>
            <?php endif; ?>
            <a href="event_setup.php?event_id=<?= $event_id ?>&step=3" class="btn btn-outline-secondary ms-2">Cancel</a>
        </div>
    </form>
</section>

<script>
document.querySelectorAll('.extend-end-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const minutes = btn.getAttribute('data-minutes');
        const body = new URLSearchParams({ scan_id: '<?= $scan_id ?>', minutes: minutes });
        fetch('../ajax/extend_scan_window.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            epassportAlert(data.message || (data.success ? 'Window extended.' : 'Could not extend this window.'), data.success ? 'Window extended' : 'Could not extend');
            if (data.success && data.end_time) {
                const endInput = document.querySelector('input[name="end_time"]');
                if (endInput) {
                    endInput.value = data.end_time;
                    endInput.dispatchEvent(new Event('input'));
                }
            }
        });
    });
});
</script>

<?php renderAdminFooter(); ?>
