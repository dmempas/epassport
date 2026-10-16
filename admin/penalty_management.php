<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/student_helpers.php';
require_once '../includes/event_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

ensurePenaltyResolutionSchema($conn);
ensureStudentAcademicSchema($conn);

$selectedUserId = (int) ($_GET['user_id'] ?? 0);
if ($selectedUserId > 0) {
    header('Location: student_penalty.php?user_id=' . $selectedUserId);
    exit;
}

$stats = getAdminPenaltyStatistics($conn);
$events = $conn->query("SELECT event_id, event_name FROM events ORDER BY event_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$allTime = $stats['all_time'];

$eventFilter = (int) ($_GET['event_id'] ?? 0);
$courseFilter = trim($_GET['course'] ?? '');
$yearFilter = trim($_GET['year_level'] ?? '');
$statusFilter = normalizePenaltyStatusFilter((string) ($_GET['status'] ?? $_GET['settlement_status'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'event_id' => $eventFilter,
    'course' => $courseFilter,
    'year_level' => $yearFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
];

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalStudents = countPenaltyStudentsList($conn, $filters);
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$students = $totalStudents > 0
    ? fetchPenaltyStudentsList($conn, $filters, $perPage, $offset)
    : [];

$listQuery = array_filter([
    'event_id' => $eventFilter,
    'course' => $courseFilter,
    'year_level' => $yearFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
], static function ($value) {
    return $value !== '' && $value !== 0 && $value !== null;
});

$penaltyExportQuery = http_build_query($listQuery);

renderAdminHeader('Penalty Management', 'penalty_management');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-3">
        <div class="small text-muted">SY <?= htmlspecialchars($stats['school_year']) ?></div>
        <div class="admin-page-actions">
            <a href="export_penalties_pdf.php?<?= htmlspecialchars($penaltyExportQuery) ?>" class="btn btn-outline-danger btn-sm">Export to PDF</a>
        </div>
    </div>

    <div class="admin-section-title">Summary</div>
    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <div class="metric-card h-100">
                <div class="metric-label">Total accumulated</div>
                <div class="metric-split">
                    <div>
                        <div class="metric-split-label">Monetary</div>
                        <div class="metric-value">₱<?= number_format((float) $allTime['total_monetary_possible'], 2) ?></div>
                    </div>
                    <div>
                        <div class="metric-split-label">Community service</div>
                        <div class="metric-value"><?= (int) $allTime['total_service_hours_possible'] ?> hr</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="metric-card metric-danger h-100">
                <div class="metric-label">Balance</div>
                <div class="metric-split">
                    <div>
                        <div class="metric-split-label">Monetary</div>
                        <div class="metric-value">₱<?= number_format((float) $allTime['pending_monetary'], 2) ?></div>
                    </div>
                    <div>
                        <div class="metric-split-label">Community service</div>
                        <div class="metric-value"><?= (int) $allTime['pending_service_hours'] ?> hr</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-filter-card mb-3">
        <div class="admin-tools-bar">
            <form method="GET" class="admin-tools-row admin-tools-search" action="penalty_management.php">
                <input type="hidden" name="event_id" value="<?= (int) $eventFilter ?>">
                <input type="hidden" name="course" value="<?= htmlspecialchars($courseFilter) ?>">
                <input type="hidden" name="year_level" value="<?= htmlspecialchars($yearFilter) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <label class="visually-hidden" for="q">Name or student number</label>
                <input type="search" id="q" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Name or student number">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="penalty_management.php" class="btn btn-outline-secondary btn-sm">Clear</a>
            </form>

            <form method="GET" class="admin-tools-row admin-tools-filters" action="penalty_management.php">
                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                <label class="visually-hidden" for="event_id">Event</label>
                <select id="event_id" name="event_id" class="form-select form-select-sm">
                    <option value="0">All events</option>
                    <?php foreach ($events as $ev): ?>
                    <option value="<?= (int) $ev['event_id'] ?>" <?= $eventFilter === (int) $ev['event_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ev['event_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="course">Course</label>
                <select id="course" name="course" class="form-select form-select-sm">
                    <option value="">All courses</option>
                    <?php foreach (STUDENT_COURSES as $course): ?>
                    <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>>
                        <?= htmlspecialchars($course) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="year_level">Year</label>
                <select id="year_level" name="year_level" class="form-select form-select-sm">
                    <option value="">All year levels</option>
                    <?php foreach (STUDENT_YEAR_LEVELS as $year): ?>
                    <option value="<?= htmlspecialchars($year) ?>" <?= $yearFilter === $year ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="status">Status</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="settled" <?= $statusFilter === 'settled' ? 'selected' : '' ?>>Settled</option>
                    <option value="unsettled" <?= $statusFilter === 'unsettled' ? 'selected' : '' ?>>Unsettled</option>
                </select>
                <div class="admin-tools-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="penalty_management.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-exclamation-octagon me-1"></i> Students with Penalties</h3>
            <span class="badge badge-soft-gold"><?= (int) $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student No</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Monetary</th>
                        <th>Community Service</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($student['student_no']) ?></td>
                            <td>
                                <a href="student_penalty.php?user_id=<?= (int) $student['user_id'] ?>">
                                    <strong><?= htmlspecialchars($student['student_name']) ?></strong>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($student['course'] !== '' ? $student['course'] : '—') ?></td>
                            <td><?= htmlspecialchars($student['year_level'] !== '' ? $student['year_level'] : '—') ?></td>
                            <td>₱<?= number_format((float) $student['remaining_monetary'], 2) ?></td>
                            <td><?= (int) $student['remaining_hours'] ?> hr(s)</td>
                            <td>
                                <?php if ($student['status'] === 'Settled'): ?>
                                    <span class="badge bg-success">Settled</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Unsettled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No students with penalties found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Penalty pages">
            <a class="btn btn-outline-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars(penaltyManagementPageUrl($listQuery, max(1, $page - 1))) ?>">Previous</a>
            <span class="small text-muted">Page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= (int) $perPage ?> per page)</span>
            <a class="btn btn-outline-secondary btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= htmlspecialchars(penaltyManagementPageUrl($listQuery, min($totalPages, $page + 1))) ?>">Next</a>
        </nav>
        <?php endif; ?>
    </div>

</section>

<?php renderAdminFooter(); ?>
