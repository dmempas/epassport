<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/admin_dashboard_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$summary = getAdminDashboardSummary($conn);
$adminName = trim((string) ($_SESSION['name'] ?? 'Administrator'));

$activeEvent = $summary['active_event'];
$attendance = $summary['today_attendance'];
$penalties = $summary['penalty_stats']['active_year'] ?? [];
$pastUnsettled = $summary['penalty_stats']['past_unsettled'] ?? [];
$attendanceTotal = max(1, (int) $attendance['total']);

renderAdminHeader('Dashboard', 'dashboard');
?>

<section class="dashboard-welcome mb-4">
    <div class="dashboard-welcome-copy">
        <p class="dashboard-welcome-kicker mb-1">Welcome back<?= $adminName !== '' ? ', ' . htmlspecialchars($adminName) : '' ?></p>
        <h2 class="h4 mb-1">Campus operations at a glance</h2>
        <p class="dashboard-welcome-meta mb-0">
            <?= htmlspecialchars(appNow('l, F j, Y', $conn)) ?> · <?= htmlspecialchars(appNow('g:i A', $conn)) ?> (<?= htmlspecialchars(appTimezoneLabel()) ?>)
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <div class="admin-meta-pill">
            <i class="bi bi-calendar3"></i>
            SY <?= htmlspecialchars($summary['active_school_year']) ?>
        </div>
        <?php if ($activeEvent): ?>
            <div class="admin-meta-pill">
                <i class="bi bi-broadcast"></i>
                <?= htmlspecialchars($activeEvent['event_name']) ?>
            </div>
        <?php else: ?>
            <div class="admin-meta-pill dashboard-pill-warning">
                <i class="bi bi-exclamation-circle"></i>
                No active event
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
$attentionItems = [];

if (!$activeEvent) {
    $attentionItems[] = [
        'tone' => 'warning',
        'icon' => 'bi-calendar-x',
        'title' => 'No event is active',
        'text' => 'Officers cannot scan and live monitoring is paused until an event is fully set up and activated.',
        'link' => 'events.php',
        'link_label' => 'Manage events',
    ];
}

if (!empty($summary['incomplete_events'])) {
    $count = count($summary['incomplete_events']);
    $attentionItems[] = [
        'tone' => 'info',
        'icon' => 'bi-tools',
        'title' => $count . ' event' . ($count === 1 ? '' : 's') . ' need setup',
        'text' => 'Finish geofence, scan windows, and penalty rules before activation.',
        'link' => 'event_setup.php?event_id=' . (int) $summary['incomplete_events'][0]['event']['event_id'] . '&step=' . (int) $summary['incomplete_events'][0]['suggested_step'],
        'link_label' => 'Continue setup',
    ];
}

if ($summary['validation_needs_count'] > 0) {
    $attentionItems[] = [
        'tone' => 'warning',
        'icon' => 'bi-mortarboard',
        'title' => $summary['validation_needs_count'] . ' student' . ($summary['validation_needs_count'] === 1 ? '' : 's') . ' need validation',
        'text' => 'Annual validation is pending for the current school year.',
        'link' => 'student_validation.php?needs_validation=1',
        'link_label' => 'Review students',
    ];
}

if ((int) ($pastUnsettled['penalty_count'] ?? 0) > 0) {
    $attentionItems[] = [
        'tone' => 'danger',
        'icon' => 'bi-exclamation-triangle',
        'title' => (int) $pastUnsettled['penalty_count'] . ' past-year penalties unsettled',
        'text' => 'Students still have pending penalties from previous school years.',
        'link' => 'penalty_management.php',
        'link_label' => 'Open penalties',
    ];
}

if ($summary['live_alert_count'] > 0): ?>
    <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-4">
        <div>
            <strong><i class="bi bi-broadcast me-1"></i><?= (int) $summary['live_alert_count'] ?> live monitoring alert<?= $summary['live_alert_count'] === 1 ? '' : 's' ?></strong>
            <span class="small ms-1">Students are outside the geofence or awaiting admin review.</span>
        </div>
        <a href="live_tracking.php" class="btn btn-danger btn-sm">Open live tracking</a>
    </div>
<?php endif; ?>

<?php if (!empty($attentionItems)): ?>
<section class="admin-panel mb-4">
    <div class="admin-section-title">Needs attention</div>
    <div class="row g-3">
        <?php foreach ($attentionItems as $item): ?>
        <div class="col-lg-6">
            <div class="dashboard-attention-card dashboard-attention-card--<?= htmlspecialchars($item['tone']) ?>">
                <div class="dashboard-attention-icon"><i class="bi <?= htmlspecialchars($item['icon']) ?>"></i></div>
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars($item['title']) ?></div>
                    <p class="small text-muted mb-2"><?= htmlspecialchars($item['text']) ?></p>
                    <a href="<?= htmlspecialchars($item['link']) ?>" class="small fw-semibold"><?= htmlspecialchars($item['link_label']) ?> <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="admin-panel mb-4">
    <div class="admin-section-title">Overview</div>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <a href="users.php" class="metric-card metric-card-link">
                <div class="metric-label">Active Users</div>
                <div class="metric-value"><?= (int) $summary['active_user_count'] ?></div>
                <div class="metric-hint"><?= (int) $summary['user_count'] ?> total · <?= (int) $summary['role_counts'][2] ?> students</div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="events.php" class="metric-card metric-card-link">
                <div class="metric-label">Active Events</div>
                <div class="metric-value"><?= (int) $summary['active_event_count'] ?></div>
                <div class="metric-hint"><?= (int) $summary['event_count'] ?> total configured</div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="attendance_management.php" class="metric-card metric-card-link metric-success">
                <div class="metric-label">Today's Scans</div>
                <div class="metric-value"><?= (int) $attendance['total'] ?></div>
                <div class="metric-hint">
                    <?= (int) $attendance['unique_students'] ?> students<?= $activeEvent ? ' · active event' : ' · all events' ?>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="penalty_management.php" class="metric-card metric-card-link<?= (int) ($penalties['pending_count'] ?? 0) > 0 ? ' metric-warning' : '' ?>">
                <div class="metric-label">Pending Penalties</div>
                <div class="metric-value"><?= (int) ($penalties['pending_count'] ?? 0) ?></div>
                <div class="metric-hint"><?= (int) ($penalties['pending_students'] ?? 0) ?> students this SY</div>
            </a>
        </div>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <section class="admin-panel h-100">
            <?php if ($activeEvent): ?>
                <?php
                $scheduleType = $activeEvent['schedule_type'] ?? 'full_day';
                $scheduleLabel = eventScheduleTypes()[$scheduleType]['label'] ?? 'Whole day';
                $scanAvailability = $summary['scan_availability'];
                ?>
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <div class="admin-section-title mb-1">Active event</div>
                        <h3 class="h5 mb-1"><?= htmlspecialchars($activeEvent['event_name']) ?></h3>
                        <p class="small text-muted mb-0">
                            <?= htmlspecialchars($activeEvent['start_date']) ?> → <?= htmlspecialchars($activeEvent['end_date']) ?>
                            · <?= htmlspecialchars($scheduleLabel) ?>
                        </p>
                    </div>
                    <?php if ($scanAvailability && $scanAvailability['allowed']): ?>
                        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Scanner open</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><i class="bi bi-pause-circle me-1"></i>Scanner closed</span>
                    <?php endif; ?>
                </div>

                <?php
                $nextScanText = formatNextScanDisplay($summary['next_scan'] ?? null, $conn);
                ?>
                <?php if ($nextScanText): ?>
                    <div class="dashboard-next-scan mb-3">
                        <i class="bi bi-clock"></i>
                        <span><?= htmlspecialchars($nextScanText) ?></span>
                        <span class="dashboard-next-scan__tz"><?= htmlspecialchars(appTimezoneLabel()) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($scanAvailability && !$scanAvailability['allowed']): ?>
                    <p class="small text-muted mb-3"><?= htmlspecialchars($scanAvailability['reason']) ?></p>
                <?php elseif ($scanAvailability && !empty($scanAvailability['scan_rule'])): ?>
                    <p class="small mb-3">
                        Current stamp:
                        <strong><?= htmlspecialchars(formatScanTypeLabel($scanAvailability['scan_rule']['scan_type'])) ?></strong>
                    </p>
                <?php endif; ?>

                <div class="admin-section-title">Today's attendance mix</div>
                <?php
                $statusBars = [
                    ['key' => 'present', 'label' => 'Present', 'class' => 'bg-success'],
                    ['key' => 'late', 'label' => 'Late', 'class' => 'bg-warning text-dark'],
                    ['key' => 'absent', 'label' => 'Absent', 'class' => 'bg-secondary'],
                    ['key' => 'violated', 'label' => 'Violated', 'class' => 'bg-danger'],
                    ['key' => 'pending', 'label' => 'Pending', 'class' => 'bg-primary'],
                ];
                ?>
                <div class="dashboard-status-bars mb-3">
                    <?php foreach ($statusBars as $bar): ?>
                        <?php $count = (int) ($attendance[$bar['key']] ?? 0); ?>
                        <div class="dashboard-status-row">
                            <div class="dashboard-status-label"><?= htmlspecialchars($bar['label']) ?></div>
                            <div class="dashboard-status-track">
                                <div class="dashboard-status-fill <?= htmlspecialchars($bar['class']) ?>" style="width: <?= round(($count / $attendanceTotal) * 100, 1) ?>%;"></div>
                            </div>
                            <div class="dashboard-status-count"><?= $count ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($summary['scan_windows'])): ?>
                    <div class="admin-section-title">Scan windows</div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($summary['scan_windows'] as $window): ?>
                            <span class="badge badge-soft-gold">
                                <?= htmlspecialchars(formatScanTypeLabel($window['scan_type'])) ?>
                                <?= htmlspecialchars(formatAppTimeRange($window['start_time'], $window['end_time'])) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="admin-section-title">Active event</div>
                <div class="dashboard-empty-state">
                    <i class="bi bi-calendar-event"></i>
                    <p class="fw-semibold mb-1">No active event right now</p>
                    <p class="small text-muted mb-3">Create or activate an event to enable scanning, attendance tracking, and live geofence monitoring.</p>
                    <a href="event_setup.php?step=1" class="btn btn-success btn-sm me-2">Create event</a>
                    <a href="events.php" class="btn btn-outline-primary btn-sm">View all events</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="admin-panel h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="admin-section-title mb-0">Recent activity</div>
                <a href="audit_logs.php" class="small fw-semibold">View all</a>
            </div>

            <?php if (empty($summary['recent_audit'])): ?>
                <p class="text-muted small mb-0">No audit entries recorded yet.</p>
            <?php else: ?>
                <div class="dashboard-activity-list">
                    <?php foreach ($summary['recent_audit'] as $entry): ?>
                        <div class="dashboard-activity-item">
                            <div class="dashboard-activity-icon">
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div class="dashboard-activity-body">
                                <div class="dashboard-activity-title"><?= htmlspecialchars(formatAuditActionLabel((string) ($entry['action'] ?? ''))) ?></div>
                                <div class="dashboard-activity-meta">
                                    <?= htmlspecialchars(formatAuditActor($entry)) ?>
                                    · <?= htmlspecialchars(date('M j, g:i A', strtotime((string) ($entry['created_at'] ?? 'now')))) ?>
                                </div>
                                <?php if (!empty($entry['details'])): ?>
                                    <div class="dashboard-activity-detail"><?= htmlspecialchars((string) $entry['details']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="admin-panel">
    <div class="admin-section-title">Quick actions</div>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <a href="create_user.php" class="dashboard-action-card">
                <i class="bi bi-person-plus"></i>
                <span>Create user</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="event_setup.php?step=1" class="dashboard-action-card">
                <i class="bi bi-calendar-plus"></i>
                <span>New event</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="live_tracking.php" class="dashboard-action-card">
                <i class="bi bi-broadcast"></i>
                <span>Live tracking</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="reports.php" class="dashboard-action-card">
                <i class="bi bi-file-earmark-bar-graph"></i>
                <span>Reports</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="attendance_management.php" class="dashboard-action-card">
                <i class="bi bi-clipboard-check"></i>
                <span>Attendance</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="penalty_management.php" class="dashboard-action-card">
                <i class="bi bi-exclamation-triangle"></i>
                <span>Penalties</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="geofence_map.php" class="dashboard-action-card">
                <i class="bi bi-geo-alt"></i>
                <span>Geofence map</span>
            </a>
        </div>
        <div class="col-md-6 col-xl-3">
            <a href="student_validation.php" class="dashboard-action-card">
                <i class="bi bi-mortarboard"></i>
                <span>Validation</span>
            </a>
        </div>
    </div>
</section>

<?php renderAdminFooter(); ?>
