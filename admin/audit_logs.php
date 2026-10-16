<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/student_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

$fromDate = epassport_filter_ymd(trim($_GET['from'] ?? ''), date('Y-m-01'));
$toDate = epassport_filter_ymd(trim($_GET['to'] ?? ''), date('Y-m-d'));
$actionGroup = epassport_allow_list(trim($_GET['action_group'] ?? ''), array_keys(auditActionGroups()));
$roleId = (int) ($_GET['role_id'] ?? 0);
$course = epassport_allow_list(trim($_GET['course'] ?? ''), STUDENT_COURSES);
$yearLevel = epassport_allow_list(trim($_GET['year_level'] ?? ''), STUDENT_YEAR_LEVELS);

$filters = [];

if ($actionGroup !== '') {
    $filters['action_group'] = $actionGroup;
}

if ($roleId > 0) {
    $filters['role_id'] = $roleId;
}

if ($course !== '') {
    $filters['course'] = $course;
}

if ($yearLevel !== '') {
    $filters['year_level'] = $yearLevel;
}

$logs = fetchAuditLogs($conn, $fromDate, $toDate, 500, $filters);

$stats = [
    'total' => count($logs),
    'admin' => 0,
    'student' => 0,
    'officer' => 0,
    'system' => 0,
];

foreach ($logs as $log) {
    $logRoleId = isset($log['role_id']) ? (int) $log['role_id'] : 0;
    if ($logRoleId === 1) {
        $stats['admin']++;
    } elseif ($logRoleId === 2) {
        $stats['student']++;
    } elseif ($logRoleId === 3) {
        $stats['officer']++;
    } else {
        $stats['system']++;
    }
}

$exportQuery = http_build_query([
    'type' => 'audit',
    'from' => $fromDate,
    'to' => $toDate,
    'action_group' => $actionGroup,
    'role_id' => $roleId,
    'course' => $course,
    'year_level' => $yearLevel,
]);

$actionGroupLabel = auditActionGroups()[$actionGroup] ?? 'All actions';

renderAdminHeader('Audit Logs', 'audit_logs');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-4">
        <div>
            <h2 class="h4 mb-1">Audit Logs</h2>
            <p class="text-muted mb-0 small">
                Traceability record of actions across admin, officer, and student roles.
            </p>
        </div>
        <div class="admin-meta-pill">
            <i class="bi bi-calendar-range"></i>
            <?= htmlspecialchars($fromDate) ?> – <?= htmlspecialchars($toDate) ?>
        </div>
    </div>

    <div class="admin-section-title">Period Summary</div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">Total Entries</div>
                <div class="metric-value fs-4"><?= (int) $stats['total'] ?></div>
                <div class="metric-hint">Showing up to 500 records</div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Admin Actions</div>
                <div class="metric-value fs-4"><?= (int) $stats['admin'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Officer Actions</div>
                <div class="metric-value fs-4"><?= (int) $stats['officer'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Student Actions</div>
                <div class="metric-value fs-4"><?= (int) $stats['student'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">System / Other</div>
                <div class="metric-value fs-4"><?= (int) $stats['system'] ?></div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-filter-card mb-4">
        <h3 class="h6 mb-3"><i class="bi bi-funnel me-1"></i> Filter Logs</h3>
        <form method="GET">
            <div class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="from">From</label>
                    <input type="date" id="from" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate) ?>">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="to">To</label>
                    <input type="date" id="to" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate) ?>">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="action_group">Action Group</label>
                    <select id="action_group" name="action_group" class="form-select form-select-sm">
                        <?php foreach (auditActionGroups() as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>" <?= $actionGroup === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="role_id">Role</label>
                    <select id="role_id" name="role_id" class="form-select form-select-sm">
                        <option value="">All roles</option>
                        <option value="1" <?= $roleId === 1 ? 'selected' : '' ?>>Admin</option>
                        <option value="2" <?= $roleId === 2 ? 'selected' : '' ?>>Student</option>
                        <option value="3" <?= $roleId === 3 ? 'selected' : '' ?>>Officer</option>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="course">Course</label>
                    <select id="course" name="course" class="form-select form-select-sm">
                        <option value="">All courses</option>
                        <?php foreach (STUDENT_COURSES as $courseOption): ?>
                        <option value="<?= htmlspecialchars($courseOption) ?>" <?= $course === $courseOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars($courseOption) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label class="form-label" for="year_level">Year Level</label>
                    <select id="year_level" name="year_level" class="form-select form-select-sm">
                        <option value="">All year levels</option>
                        <?php foreach (STUDENT_YEAR_LEVELS as $yearOption): ?>
                        <option value="<?= htmlspecialchars($yearOption) ?>" <?= $yearLevel === $yearOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars($yearOption) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3 pt-2 border-top">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Apply Filters</button>
                <a href="audit_logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                <a href="export_report.php?<?= htmlspecialchars($exportQuery) ?>" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                </a>
            </div>
            <p class="small text-muted mb-0 mt-2">Apply filters to update the table. Export downloads the logs currently shown.</p>
        </form>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-journal-text me-1"></i> Activity Log</h3>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($actionGroup !== ''): ?>
                <span class="badge badge-soft-gold"><?= htmlspecialchars($actionGroupLabel) ?></span>
                <?php endif; ?>
                <span class="badge badge-soft-gold"><?= (int) $stats['total'] ?> entr<?= $stats['total'] === 1 ? 'y' : 'ies' ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Course / Year</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No audit entries for this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="small text-nowrap"><?= htmlspecialchars($log['created_at']) ?></td>
                        <td>
                            <?php if ($log['firstname']): ?>
                                <strong><?= htmlspecialchars(trim($log['firstname'] . ' ' . $log['lastname'])) ?></strong>
                                <?php if (!empty($log['student_no'])): ?>
                                <div class="student-sub"><?= htmlspecialchars($log['student_no']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-light text-dark border">
                                <?= htmlspecialchars(auditRoleLabel(isset($log['role_id']) ? (int) $log['role_id'] : null)) ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars($log['course'] ?? '—') ?>
                            <?php if (!empty($log['year_level'])): ?>
                            <div class="cell-detail"><?= htmlspecialchars($log['year_level']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(formatAuditActionLabel((string) $log['action'])) ?></td>
                        <td class="small"><?= htmlspecialchars($log['details'] ?? '') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>

<?php renderAdminFooter(); ?>
