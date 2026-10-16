<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/admin_reports_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$overview = getAdminReportsOverview($conn);

$reportPages = [
    [
        'href' => 'attendance_management.php',
        'icon' => 'clipboard-check',
        'accent' => 'attendance',
        'title' => 'Attendance',
        'count' => $overview['attendance_count'],
        'count_label' => 'records',
    ],
    [
        'href' => 'penalty_management.php',
        'icon' => 'exclamation-triangle',
        'accent' => 'penalty',
        'title' => 'Penalties',
        'count' => $overview['penalty_count'],
        'count_label' => 'students',
    ],
    [
        'href' => 'audit_logs.php',
        'icon' => 'journal-text',
        'accent' => 'audit',
        'title' => 'Audit logs',
        'count' => $overview['audit_count_month'],
        'count_label' => 'this month',
    ],
    [
        'href' => 'users.php',
        'icon' => 'people',
        'accent' => 'users',
        'title' => 'Users',
        'count' => $overview['user_count'],
        'count_label' => 'users',
    ],
    [
        'href' => 'events.php',
        'icon' => 'calendar-event',
        'accent' => 'events',
        'title' => 'Events',
        'count' => $overview['event_count'],
        'count_label' => 'events',
    ],
];

renderAdminHeader('Reports', 'reports');
?>

<section class="admin-panel">
    <div class="admin-page-header mb-3">
        <div class="small text-muted">SY <?= htmlspecialchars($overview['active_school_year']) ?></div>
        <?php if ($overview['active_event']): ?>
            <div class="small text-muted"><?= htmlspecialchars($overview['active_event']['event_name']) ?></div>
        <?php endif; ?>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-xl">
            <div class="reports-stat-card">
                <div class="reports-stat-icon reports-stat-icon--attendance"><i class="bi bi-clipboard-check"></i></div>
                <div>
                    <div class="reports-stat-value"><?= number_format($overview['attendance_count']) ?></div>
                    <div class="reports-stat-label">Attendance</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="reports-stat-card">
                <div class="reports-stat-icon reports-stat-icon--penalty"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="reports-stat-value"><?= number_format($overview['penalty_count']) ?></div>
                    <div class="reports-stat-label">Penalties</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="reports-stat-card">
                <div class="reports-stat-icon reports-stat-icon--audit"><i class="bi bi-journal-text"></i></div>
                <div>
                    <div class="reports-stat-value"><?= number_format($overview['audit_count_month']) ?></div>
                    <div class="reports-stat-label">Audit</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="reports-stat-card">
                <div class="reports-stat-icon reports-stat-icon--users"><i class="bi bi-people"></i></div>
                <div>
                    <div class="reports-stat-value"><?= number_format($overview['user_count']) ?></div>
                    <div class="reports-stat-label">Users</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl">
            <div class="reports-stat-card">
                <div class="reports-stat-icon reports-stat-icon--events"><i class="bi bi-calendar-event"></i></div>
                <div>
                    <div class="reports-stat-value"><?= number_format($overview['event_count']) ?></div>
                    <div class="reports-stat-label">Events</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($reportPages as $page): ?>
        <div class="col-md-6 col-xl-4">
            <article class="report-card h-100 mb-0">
                <div class="report-card-accent report-card-accent--<?= htmlspecialchars($page['accent']) ?>"></div>
                <div class="report-card-inner">
                    <div class="report-card-header">
                        <div class="report-card-icon report-card-icon--<?= htmlspecialchars($page['accent']) ?>">
                            <i class="bi bi-<?= htmlspecialchars($page['icon']) ?>"></i>
                        </div>
                        <h3 class="h5 mb-0"><?= htmlspecialchars($page['title']) ?></h3>
                    </div>
                    <div class="report-card-footer">
                        <div class="report-preview-count">
                            <span class="report-preview-label"><?= htmlspecialchars($page['count_label']) ?></span>
                            <strong><?= number_format($page['count']) ?></strong>
                        </div>
                        <a href="<?= htmlspecialchars($page['href']) ?>" class="btn btn-primary btn-sm">Open</a>
                    </div>
                </div>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<?php renderAdminFooter(); ?>
