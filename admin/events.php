<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/admin_export_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

expirePastEvents($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $eventId = (int) ($_POST['event_id'] ?? 0);

    if ($eventId > 0) {
        if ($action === 'activate') {
            $eventRow = fetchEventById($conn, $eventId);

            if ($eventRow && isEventExpired($eventRow)) {
                $_SESSION['flash_message'] = 'Cannot activate: this event has already ended.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $result = activateEvent($conn, $eventId);
                if ($result['ok']) {
                    logAudit($conn, 'event_activated', 'events', $eventId, 'Activated event');
                    $_SESSION['flash_message'] = 'Event activated successfully.';
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = implode(' ', $result['errors']);
                    $_SESSION['flash_type'] = 'danger';
                }
            }
        } elseif ($action === 'deactivate') {
            $stmt = $conn->prepare("UPDATE events SET is_active = 0 WHERE event_id = :id");
            $stmt->execute([':id' => $eventId]);
            logAudit($conn, 'event_deactivated', 'events', $eventId, 'Deactivated event');
            $_SESSION['flash_message'] = 'Event deactivated.';
            $_SESSION['flash_type'] = 'warning';
        } elseif ($action === 'delete') {
            $eventRow = fetchEventById($conn, $eventId);

            if (!$eventRow) {
                $_SESSION['flash_message'] = 'Event not found.';
                $_SESSION['flash_type'] = 'danger';
            } else {
                $canDelete = canDeleteEvent($conn, $eventRow);

                if (!$canDelete['ok']) {
                    $_SESSION['flash_message'] = $canDelete['error'];
                    $_SESSION['flash_type'] = 'danger';
                } else {
                    $stmt = $conn->prepare("DELETE FROM events WHERE event_id = :id");
                    $stmt->execute([':id' => $eventId]);
                    logAudit($conn, 'event_deleted', 'events', $eventId, 'Deleted event');
                    $_SESSION['flash_message'] = 'Event deleted.';
                    $_SESSION['flash_type'] = 'info';
                }
            }
        }
    }

    header('Location: events.php');
    exit;
}

$flash = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$statusFilter = trim($_GET['status'] ?? '');
$scheduleFilter = trim($_GET['schedule_type'] ?? '');
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'status' => $statusFilter,
    'schedule_type' => $scheduleFilter,
    'q' => $searchQuery,
];

$events = fetchEventsForExport($conn, $filters);
$allEvents = fetchEventsForExport($conn, []);
$eventsExportQuery = http_build_query(array_filter($filters, static fn($value) => $value !== ''));

$stats = [
    'total' => count($allEvents),
    'active' => 0,
    'inactive' => 0,
    'expired' => 0,
    'setup_complete' => 0,
];

foreach ($allEvents as $event) {
    $label = eventStatusLabel($event);
    if ($label === 'Active') {
        $stats['active']++;
    } elseif ($label === 'Expired') {
        $stats['expired']++;
    } else {
        $stats['inactive']++;
    }

    $readiness = getEventReadiness($conn, (int) $event['event_id']);
    if (!empty($readiness['ok'])) {
        $stats['setup_complete']++;
    }
}

renderAdminHeader('Event Management', 'events');
?>

<style>
    .setup-progress { min-width: 140px; }
    .setup-progress .progress { height: 8px; background: #e9eeff; }
    .setup-progress .progress-bar { background: linear-gradient(90deg, #1f2f88, #3d52c4); }
    .setup-progress.complete .progress-bar { background: linear-gradient(90deg, #198754, #28a745); }
</style>

<section class="admin-panel">

    <div class="admin-page-header mb-4">
        <div>
            <h2 class="h4 mb-1">Event Management</h2>
            <p class="text-muted mb-0 small">
                Configure events with the setup wizard. Active events are locked except for renaming and extending scan windows.
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="admin-meta-pill">
                <i class="bi bi-calendar-event"></i>
                <?= (int) $stats['total'] ?> event(s)
            </div>
            <a href="geofence_map.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-geo-alt"></i> Geofence</a>
            <a href="extend_scan_windows.php" class="btn btn-outline-warning btn-sm"><i class="bi bi-clock"></i> Scan Windows</a>
            <a href="live_tracking.php" class="btn btn-outline-success btn-sm"><i class="bi bi-broadcast"></i> Live Tracking</a>
            <a href="event_setup.php?step=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Create Event</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="admin-section-title">Summary</div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">Total Events</div>
                <div class="metric-value fs-4"><?= (int) $stats['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-success h-100">
                <div class="metric-label">Active</div>
                <div class="metric-value fs-4"><?= (int) $stats['active'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Inactive</div>
                <div class="metric-value fs-4"><?= (int) $stats['inactive'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-danger h-100">
                <div class="metric-label">Expired</div>
                <div class="metric-value fs-4"><?= (int) $stats['expired'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Setup Complete</div>
                <div class="metric-value fs-4"><?= (int) $stats['setup_complete'] ?></div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-filter-card mb-4">
        <h3 class="h6 mb-3"><i class="bi bi-funnel me-1"></i> Filter Events</h3>
        <form method="GET" id="eventsFilterForm">
            <div class="row g-3">
                <div class="col-md-12 col-lg-4">
                    <label class="form-label" for="q">Event name</label>
                    <input type="search" id="q" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search event name">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="schedule_type">Schedule Type</label>
                    <select id="schedule_type" name="schedule_type" class="form-select form-select-sm">
                        <option value="">All schedules</option>
                        <?php foreach (eventScheduleTypes() as $key => $info): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $scheduleFilter === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($info['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3 pt-2 border-top">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Apply Filters</button>
                <a href="events.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                <a href="export_events_pdf.php?<?= htmlspecialchars($eventsExportQuery) ?>" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                </a>
            </div>
            <p class="small text-muted mb-0 mt-2">Apply filters to update the table. Export downloads the events currently shown.</p>
        </form>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-calendar3 me-1"></i> Events List</h3>
            <span class="badge badge-soft-gold"><?= count($events) ?> shown</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Dates</th>
                        <th>Schedule</th>
                        <th>Stamps</th>
                        <th>Setup Progress</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($events)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No events found.</td></tr>
                <?php endif; ?>
                <?php foreach ($events as $event): ?>
                <?php
                    $readiness = getEventReadiness($conn, (int) $event['event_id']);
                    $progress = getSetupProgress($readiness);
                    $scheduleType = $event['schedule_type'] ?? 'full_day';
                    $scheduleLabel = eventScheduleTypes()[$scheduleType]['label'] ?? 'Whole day';
                    $expired = isEventExpired($event);
                    $isActive = (int) $event['is_active'] === 1 && !$expired;
                    $resumeStep = getSuggestedSetupStep($readiness);
                    $canDelete = canDeleteEvent($conn, $event);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($event['event_name']) ?></strong></td>
                    <td class="small text-nowrap">
                        <?= htmlspecialchars($event['start_date']) ?><br>
                        <?= htmlspecialchars($event['end_date']) ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($scheduleLabel) ?></td>
                    <td><?= (int) $readiness['expected_stamps'] ?></td>
                    <td>
                        <div class="setup-progress <?= $progress['percent'] === 100 ? 'complete' : '' ?>">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?= $progress['completed'] ?>/<?= $progress['total'] ?> steps</span>
                                <span><?= $progress['percent'] ?>%</span>
                            </div>
                            <div class="progress" role="progressbar" aria-valuenow="<?= $progress['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar" style="width: <?= $progress['percent'] ?>%"></div>
                            </div>
                            <a href="event_setup.php?event_id=<?= (int) $event['event_id'] ?>&step=<?= $resumeStep ?>" class="btn btn-outline-primary btn-sm mt-2 w-100">
                                <?= $isActive ? 'View Setup' : ($readiness['ok'] ? 'Open Wizard' : 'Continue Setup') ?>
                            </a>
                        </div>
                    </td>
                    <td>
                        <?php if ($expired): ?>
                            <span class="badge text-bg-dark">Expired</span>
                        <?php elseif ($isActive): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            <a href="event_setup.php?event_id=<?= (int) $event['event_id'] ?>&step=<?= $resumeStep ?>" class="btn btn-sm btn-primary">
                                <?= $isActive ? 'View' : 'Setup' ?>
                            </a>
                            <?php if ($isActive): ?>
                                <form method="POST" class="d-inline" data-confirm="Deactivate this event? Officers will not be able to scan until it is activated again." data-confirm-title="Deactivate event" data-confirm-label="Deactivate">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>
                                </form>
                            <?php elseif ($readiness['ok'] && !$expired): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-success" disabled title="<?= $expired ? 'Event has ended' : 'Complete setup first' ?>">Activate</button>
                            <?php endif; ?>
                            <?php if ($canDelete['ok']): ?>
                            <form method="POST" class="d-inline" data-confirm="Delete this event and all linked data?" data-confirm-title="Delete event" data-confirm-label="Delete" data-confirm-danger="1">
                                <input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="<?= htmlspecialchars($canDelete['error']) ?>">Delete</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>

<?php renderAdminFooter(); ?>
