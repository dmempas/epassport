<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/event_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/attendance_schema.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

ensureAttendanceSchema($conn);

$selectedUserId = (int) ($_GET['user_id'] ?? 0);
if ($selectedUserId > 0) {
    header('Location: student_attendance.php?user_id=' . $selectedUserId);
    exit;
}

$eventFilter = trim($_GET['event_id'] ?? '');
$courseFilter = epassport_allow_list(trim($_GET['course'] ?? ''), STUDENT_COURSES);
$yearFilter = epassport_allow_list(trim($_GET['year_level'] ?? ''), STUDENT_YEAR_LEVELS);
$statusFilter = epassport_allow_list(trim($_GET['status'] ?? ''), ['present', 'late', 'absent', 'pending', 'violated']);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
if (strlen($searchQuery) > 100) {
    $searchQuery = substr($searchQuery, 0, 100);
}

$stmt = $conn->prepare("
    SELECT event_id, event_name
    FROM events
    ORDER BY start_date DESC
");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'event_id' => (int) $eventFilter,
    'course' => $courseFilter,
    'year_level' => $yearFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
];

$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalStudents = countAttendanceStudentsList($conn, $filters);
$totalPages = max(1, (int) ceil($totalStudents / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$students = $totalStudents > 0
    ? fetchAttendanceStudentsList($conn, $filters, $perPage, $offset)
    : [];

$listQuery = array_filter([
    'event_id' => $eventFilter,
    'course' => $courseFilter,
    'year_level' => $yearFilter,
    'status' => $statusFilter,
    'q' => $searchQuery,
], static fn($value) => $value !== '');

$viewFilters = $listQuery;
$attendancePdfQuery = http_build_query(array_merge(['type' => 'attendance'], $viewFilters));
$attendanceCsvQuery = http_build_query(array_merge(['type' => 'attendance_summary'], $viewFilters));

$statsStmt = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(a.status = 'present') AS present,
        SUM(a.status = 'late') AS late,
        SUM(a.status = 'absent') AS absent,
        SUM(a.status = 'violated') AS violated,
        SUM(a.status = 'pending') AS pending,
        SUM(EXISTS (
            SELECT 1
            FROM penalties p
            WHERE p.user_id = a.user_id
              AND p.event_id = a.event_id
        )) AS with_penalty
    FROM attendance_sessions a
    INNER JOIN users u ON a.user_id = u.user_id
    INNER JOIN events e ON a.event_id = e.event_id
");
$statsRow = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : [];

$stats = [
    'total' => (int) ($statsRow['total'] ?? 0),
    'present' => (int) ($statsRow['present'] ?? 0),
    'late' => (int) ($statsRow['late'] ?? 0),
    'absent' => (int) ($statsRow['absent'] ?? 0),
    'violated' => (int) ($statsRow['violated'] ?? 0),
    'pending' => (int) ($statsRow['pending'] ?? 0),
    'with_penalty' => (int) ($statsRow['with_penalty'] ?? 0),
];

$selectedEventName = 'All Events';
if ($eventFilter !== '') {
    foreach ($events as $event) {
        if ((string) $event['event_id'] === $eventFilter) {
            $selectedEventName = $event['event_name'];
            break;
        }
    }
}

renderAdminHeader('Attendance Management', 'attendance_management');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-3">
        <div class="small text-muted">
            <?php if ($eventFilter !== ''): ?>
                <?= htmlspecialchars($selectedEventName) ?>
            <?php endif; ?>
        </div>
        <div class="admin-page-actions">
            <button type="button" id="runAttendanceEvaluation" class="btn btn-outline-primary btn-sm">
                Evaluate pending
            </button>
            <button type="button" id="runPenaltyGeneration" class="btn btn-success btn-sm">
                Generate penalties
            </button>
            <a href="export_report.php?<?= htmlspecialchars($attendancePdfQuery) ?>" class="btn btn-outline-danger btn-sm">Export to PDF</a>
            <a href="export_report.php?<?= htmlspecialchars($attendanceCsvQuery) ?>" class="btn btn-outline-success btn-sm">Export to CSV</a>
        </div>
    </div>

    <div id="workflowFeedback" class="mb-3"></div>

    <div class="admin-section-title">Summary</div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">Total</div>
                <div class="metric-value fs-4"><?= (int) $stats['total'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card metric-success h-100">
                <div class="metric-label">Present</div>
                <div class="metric-value fs-4"><?= (int) $stats['present'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card metric-warning h-100">
                <div class="metric-label">Late</div>
                <div class="metric-value fs-4"><?= (int) $stats['late'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card metric-danger h-100">
                <div class="metric-label">Absent</div>
                <div class="metric-value fs-4"><?= (int) $stats['absent'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card metric-warning h-100">
                <div class="metric-label">Violated</div>
                <div class="metric-value fs-4"><?= (int) $stats['violated'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="metric-card h-100">
                <div class="metric-label">Penalty</div>
                <div class="metric-value fs-4"><?= (int) $stats['with_penalty'] ?></div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-filter-card mb-3">
        <div class="admin-tools-bar">
            <form method="GET" class="admin-tools-row admin-tools-search" action="attendance_management.php">
                <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventFilter) ?>">
                <input type="hidden" name="course" value="<?= htmlspecialchars($courseFilter) ?>">
                <input type="hidden" name="year_level" value="<?= htmlspecialchars($yearFilter) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <label class="visually-hidden" for="q">Name or student number</label>
                <input type="search" id="q" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Name or student number">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="attendance_management.php" class="btn btn-outline-secondary btn-sm">Clear</a>
            </form>

            <form method="GET" class="admin-tools-row admin-tools-filters" action="attendance_management.php">
                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                <label class="visually-hidden" for="event_id">Event</label>
                <select id="event_id" name="event_id" class="form-select form-select-sm">
                    <option value="">All events</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= (int) $event['event_id'] ?>" <?= $eventFilter === (string) $event['event_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($event['event_name']) ?>
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
                    <option value="">All years</option>
                    <?php foreach (STUDENT_YEAR_LEVELS as $year): ?>
                    <option value="<?= htmlspecialchars($year) ?>" <?= $yearFilter === $year ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="status">Status</label>
                <select id="status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="present" <?= $statusFilter === 'present' ? 'selected' : '' ?>>Present</option>
                    <option value="late" <?= $statusFilter === 'late' ? 'selected' : '' ?>>Late</option>
                    <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>Absent</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="violated" <?= $statusFilter === 'violated' ? 'selected' : '' ?>>Violated</option>
                </select>
                <div class="admin-tools-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="attendance_management.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-clipboard-check me-1"></i> Students with Attendance</h3>
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
                        <th>Stamps</th>
                        <th>Latest</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($students) > 0): ?>
                    <?php foreach ($students as $student): ?>
                        <?php
                        $badge = match ($student['last_status']) {
                            'present' => 'success',
                            'late', 'violated' => 'warning',
                            'absent' => 'danger',
                            default => 'secondary',
                        };
                        $lastAt = (string) ($student['last_scanned_at'] ?? '');
                        ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($student['student_no']) ?></td>
                            <td>
                                <a href="student_attendance.php?user_id=<?= (int) $student['user_id'] ?>">
                                    <strong><?= htmlspecialchars($student['student_name']) ?></strong>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($student['course'] !== '' ? $student['course'] : '—') ?></td>
                            <td><?= htmlspecialchars($student['year_level'] !== '' ? $student['year_level'] : '—') ?></td>
                            <td><?= (int) $student['stamp_count'] ?></td>
                            <td>
                                <?php if ($student['last_status'] !== ''): ?>
                                    <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars(formatAttendanceStatusLabel($student['last_status'])) ?></span>
                                    <div class="small text-muted"><?= $lastAt !== '' ? date('M d, Y h:i A', strtotime($lastAt)) : '' ?></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No students with attendance found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Attendance pages">
            <a class="btn btn-outline-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars(attendanceManagementPageUrl($listQuery, max(1, $page - 1))) ?>">Previous</a>
            <span class="small text-muted">Page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= (int) $perPage ?> per page)</span>
            <a class="btn btn-outline-secondary btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= htmlspecialchars(attendanceManagementPageUrl($listQuery, min($totalPages, $page + 1))) ?>">Next</a>
        </nav>
        <?php endif; ?>
    </div>

</section>

<script>
    async function runWorkflow(url, button, successMessageBuilder) {
        const feedback = document.getElementById('workflowFeedback');
        const originalText = button.textContent;

        button.disabled = true;
        button.textContent = 'Processing...';

        try {
            const response = await fetch(url, { method: 'POST' });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Request failed.');
            }

            feedback.innerHTML =
                `<div class="alert alert-success">${successMessageBuilder(data)}</div>`;

            setTimeout(() => {
                window.location.reload();
            }, 1200);
        } catch (error) {
            feedback.innerHTML =
                `<div class="alert alert-danger">Workflow failed: ${error.message}</div>`;
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    document.getElementById('runAttendanceEvaluation').addEventListener('click', function () {
        runWorkflow(
            '../ajax/evaluate_attendance.php',
            this,
            (data) => {
                const parts = [`Updated pending records: ${data.updated_records ?? 0}.`];

                if ((data.unscanned_marked_absent ?? 0) > 0) {
                    parts.push(`Unscanned students marked absent: ${data.unscanned_marked_absent}.`);
                }

                return `Attendance evaluation complete. ${parts.join(' ')}`;
            }
        );
    });

    document.getElementById('runPenaltyGeneration').addEventListener('click', function () {
        runWorkflow(
            '../ajax/generate_penalties.php',
            this,
            (data) => `Penalty generation complete. New penalties: ${data.generated_penalties ?? 0}.`
        );
    });
</script>

<?php renderAdminFooter(); ?>
