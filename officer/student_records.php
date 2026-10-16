<?php

require_once '../includes/session.php';
requireRole(3);

require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/search_helpers.php';
require_once '../includes/penalty_helpers.php';
require_once '../includes/event_helpers.php';
require_once '../includes/officer_layout.php';

$db = new Database();
$conn = $db->connect();
ensurePenaltyResolutionSchema($conn);
ensureStudentAcademicSchema($conn);

$eventFilter = (int) ($_GET['event_id'] ?? 0);
$course = trim((string) ($_GET['course'] ?? ''));
$year = trim((string) ($_GET['year_level'] ?? ''));
$statusFilter = normalizePenaltyStatusFilter((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$events = $conn->query("SELECT event_id, event_name FROM events ORDER BY event_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$students = fetchOfficerStudentRecords($conn, [
    'event_id' => $eventFilter,
    'course' => $course,
    'year_level' => $year,
    'status' => $statusFilter,
    'q' => $search,
]);

renderOfficerHeader(
    'Student Records',
    'records',
    'Student Records',
    'Search a student, review remaining penalties, and open a record to settle.'
);
?>

<div class="officer-card officer-toolbar mb-3">
    <form method="GET" class="officer-toolbar-form" action="student_records.php">
        <label class="visually-hidden" for="q">Name or student number</label>
        <input type="search" id="q" name="q" class="form-control officer-toolbar-search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or student number">

        <label class="visually-hidden" for="event_id">Event</label>
        <select id="event_id" name="event_id" class="form-select" title="Event">
            <option value="0">All events</option>
            <?php foreach ($events as $ev): ?>
                <option value="<?= (int) $ev['event_id'] ?>" <?= $eventFilter === (int) $ev['event_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($ev['event_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="course">Course</label>
        <select id="course" name="course" class="form-select" title="Course">
            <option value="">All courses</option>
            <?php foreach (STUDENT_COURSES as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $course === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="year_level">Year</label>
        <select id="year_level" name="year_level" class="form-select" title="Year">
            <option value="">All years</option>
            <?php foreach (STUDENT_YEAR_LEVELS as $y): ?>
                <option value="<?= htmlspecialchars($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="status">Status</label>
        <select id="status" name="status" class="form-select" title="Status">
            <option value="">All statuses</option>
            <option value="unsettled" <?= $statusFilter === 'unsettled' ? 'selected' : '' ?>>Unsettled</option>
            <option value="settled" <?= $statusFilter === 'settled' ? 'selected' : '' ?>>Settled</option>
        </select>

        <div class="officer-toolbar-actions">
            <button type="submit" class="btn officer-btn-primary">Apply</button>
            <a href="student_records.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="officer-card">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h2 class="h6 mb-0">Students</h2>
        <span class="badge text-bg-light text-dark border"><?= count($students) ?> shown</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle officer-compact-table mb-0">
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
                <?php if ($students === []): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars((string) $student['student_no']) ?></td>
                            <td>
                                <a href="view_student_record.php?id=<?= (int) $student['user_id'] ?>">
                                    <strong><?= htmlspecialchars((string) $student['student_name']) ?></strong>
                                </a>
                            </td>
                            <td class="small"><?= htmlspecialchars((string) $student['course'] ?: '—') ?></td>
                            <td class="small"><?= htmlspecialchars((string) $student['year_level'] ?: '—') ?></td>
                            <td>₱<?= number_format((float) $student['remaining_monetary'], 2) ?></td>
                            <td><?= (int) $student['remaining_hours'] ?> hr(s)</td>
                            <td>
                                <?php if ($student['status'] === 'Unsettled'): ?>
                                    <a class="badge bg-warning text-dark text-decoration-none" href="settle_penalty.php?user_id=<?= (int) $student['user_id'] ?>">Unsettled</a>
                                <?php elseif ($student['status'] === 'Settled'): ?>
                                    <a class="badge bg-success text-decoration-none" href="settle_penalty.php?user_id=<?= (int) $student['user_id'] ?>">Settled</a>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Clear</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php renderOfficerFooter(); ?>
