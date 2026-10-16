<?php

require_once '../includes/session.php';
requireRole(3);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/activity_log_helpers.php';
require_once '../includes/officer_layout.php';

$db = new Database();
$conn = $db->connect();

$activeEvent = getActiveEvent($conn);

if ($activeEvent) {
    $_SESSION['officer_selected_event_id'] = (int) $activeEvent['event_id'];
}

$selectedEventId = (int) ($_SESSION['officer_selected_event_id'] ?? 0);
$selectedEvent = $activeEvent && $selectedEventId === (int) $activeEvent['event_id']
    ? $activeEvent
    : null;

$scanAvailability = $selectedEvent
    ? getScanAvailability($conn, $selectedEvent)
    : ['allowed' => false, 'reason' => 'No active event is configured for scanning.'];
$lateAvailability = $selectedEvent
    ? getLateScanAvailability($conn, $selectedEvent)
    : ['allowed' => false, 'reason' => '', 'scan_rule' => null];

$scanCount = 0;
$uniqueStudents = 0;
$officerScanLog = [];

if ($selectedEvent) {
    $statsStmt = $conn->prepare("
        SELECT COUNT(*) AS total_scans, COUNT(DISTINCT user_id) AS unique_students
        FROM attendance_sessions
        WHERE event_id = :event_id
    ");
    $statsStmt->execute([':event_id' => (int) $selectedEvent['event_id']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $scanCount = (int) ($stats['total_scans'] ?? 0);
    $uniqueStudents = (int) ($stats['unique_students'] ?? 0);
    $officerScanLog = getOfficerScanLog(
        $conn,
        (int) $_SESSION['user_id'],
        (int) $selectedEvent['event_id'],
        50
    );
}

$scheduleType = $selectedEvent
    ? ($selectedEvent['schedule_type'] ?? 'full_day')
    : 'full_day';
$scheduleLabel = $selectedEvent
    ? (eventScheduleTypes()[$scheduleType]['label'] ?? 'Whole day')
    : '';
$expectedStamps = $selectedEvent
    ? getExpectedStampCountForEvent($selectedEvent)
    : 0;

renderOfficerHeader('Dashboard', 'dashboard');
?>

<div class="officer-grid">
    <section class="officer-card officer-card--full">
        <div class="officer-card-title">
            <i class="bi bi-calendar-event"></i>
            <h2 class="h5 mb-0">Active Event</h2>
        </div>

        <?php if (!$activeEvent): ?>
            <div class="officer-empty-state">
                <i class="bi bi-calendar-x d-block mb-2"></i>
                <p class="fw-semibold mb-1">Scanning is disabled</p>
                <p class="text-muted small mb-0">
                    No event is active. An administrator must complete event setup
                    (details, geofence, scan windows, penalty rules) and activate an event first.
                </p>
            </div>
        <?php else: ?>
            <?php $windows = getEventScanRulesForDate($conn, (int) $activeEvent['event_id'], appDate($conn)); ?>
            <div class="row g-3 align-items-start">
                <div class="col-lg-8">
                    <p class="mb-1 fw-semibold fs-5"><?= htmlspecialchars($activeEvent['event_name']) ?></p>
                    <p class="officer-event-meta mb-2">
                        <?= htmlspecialchars($activeEvent['start_date']) ?> → <?= htmlspecialchars($activeEvent['end_date']) ?>
                        · <?= htmlspecialchars((string) ($activeEvent['duration_days'] ?? '')) ?> day(s)
                        · <?= (int) $expectedStamps ?> stamps expected
                    </p>
                    <p class="small mb-0">
                        <strong>Today's scan windows:</strong>
                        <?php if (empty($windows)): ?>
                            <span class="text-danger">None configured</span>
                        <?php else: ?>
                            <?php foreach ($windows as $w): ?>
                                <span class="badge badge-soft me-1 mb-1">
                                    <?= htmlspecialchars(formatScanTypeLabel($w['scan_type'])) ?>
                                    <?= htmlspecialchars(formatAppTimeRange($w['start_time'], $w['end_time'])) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-lg-4">
                    <p class="small text-muted mb-2">
                        Current time: <strong><?= appNow('g:i A', $conn) ?></strong>
                        (<?= htmlspecialchars(appTimezoneLabel()) ?>)
                    </p>
                    <?php
                    $nextScan = getNextScanWindow($conn, $activeEvent);
                    $nextScanText = formatNextScanDisplay($nextScan, $conn);
                    ?>
                    <?php if ($nextScanText): ?>
                        <div class="alert alert-info py-2 small mb-2 officer-next-scan">
                            <i class="bi bi-clock me-1"></i><?= htmlspecialchars($nextScanText) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($scanAvailability['allowed']): ?>
                        <div class="alert alert-success py-2 small mb-0 officer-scan-status">
                            <strong><i class="bi bi-check-circle me-1"></i>Scanner open</strong><br>
                            Current stamp: <?= htmlspecialchars(formatScanTypeLabel($scanAvailability['scan_rule']['scan_type'])) ?>
                        </div>
                    <?php elseif (!empty($lateAvailability['allowed'])): ?>
                        <div class="alert alert-warning py-2 small mb-0 officer-scan-status">
                            <strong><i class="bi bi-clock-history me-1"></i>Window closed — late scan available</strong><br>
                            <?= htmlspecialchars(formatScanTypeLabel($lateAvailability['scan_rule']['scan_type'] ?? '')) ?>
                            will be marked Late.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 small mb-0 officer-scan-status">
                            <strong><i class="bi bi-pause-circle me-1"></i>Scanner closed</strong><br>
                            <?= htmlspecialchars($scanAvailability['reason']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="officer-cta-bar">
                <p class="small text-muted mb-0">
                    Use the scanner during an open window. After login closes, Scan Late Students stays available until <?= (int) lateScanCutoffMinutes() ?> minutes before logout. Anyone still unscanned is then marked absent automatically.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($scanAvailability['allowed']): ?>
                        <a href="scanner.php" class="btn officer-btn-primary">
                            <i class="bi bi-qr-code-scan me-1"></i>Open Scanner
                        </a>
                    <?php elseif (!empty($lateAvailability['allowed'])): ?>
                        <a href="scanner.php?late=1" class="btn btn-warning">
                            <i class="bi bi-clock-history me-1"></i>Scan Late Students
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn officer-btn-primary" disabled>
                            <i class="bi bi-qr-code-scan me-1"></i>Open Scanner
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($selectedEvent): ?>
        <section class="officer-card officer-card--half">
            <div class="metric-card">
                <p class="metric-label"><i class="bi bi-upc-scan me-1"></i>Total Scans</p>
                <p class="metric-value"><?= $scanCount ?></p>
            </div>
        </section>
        <section class="officer-card officer-card--half">
            <div class="metric-card">
                <p class="metric-label"><i class="bi bi-person-check me-1"></i>Unique Students</p>
                <p class="metric-value"><?= $uniqueStudents ?></p>
            </div>
        </section>

        <section class="officer-card officer-card--full">
            <div class="officer-card-title">
                <i class="bi bi-clock-history"></i>
                <h2 class="h5 mb-0">My Scan Log</h2>
            </div>
            <p class="small text-muted mb-3">
                Students you scanned during <strong><?= htmlspecialchars($selectedEvent['event_name']) ?></strong>.
            </p>
            <?php if (empty($officerScanLog)): ?>
                <div class="alert alert-secondary mb-0">No scans recorded yet for this event.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 officer-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Student</th>
                                <th>Stamp</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($officerScanLog as $scan): ?>
                                <tr>
                                    <td class="small text-nowrap">
                                        <?= date('M d, g:i A', strtotime($scan['scanned_at'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($scan['student_name']) ?></strong><br>
                                        <span class="small text-muted"><?= htmlspecialchars($scan['student_no']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $scan['is_logout'] ? 'bg-success' : 'bg-primary' ?>">
                                            <?= htmlspecialchars($scan['scan_label']) ?>
                                        </span>
                                        <?php if ($scan['is_rescan']): ?>
                                            <span class="badge bg-secondary">Rescan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= htmlspecialchars(ucfirst($scan['status'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php renderOfficerFooter(); ?>
