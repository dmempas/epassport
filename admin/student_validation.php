<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/student_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

ensureStudentAcademicSchema($conn);

$adminUserId = (int) ($_SESSION['user_id'] ?? 0);
$message = '';
$messageType = 'info';

$activeSchoolYear = getActiveSchoolYear($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'set_school_year') {
        $schoolYear = trim($_POST['school_year'] ?? '');
        if ($schoolYear !== '') {
            setActiveSchoolYear($conn, $schoolYear);
            $activeSchoolYear = $schoolYear;
            logAudit($conn, 'school_year_set', 'school_years', null, 'Set active school year to ' . $schoolYear);
            $message = 'Active school year set to ' . $schoolYear . '.';
            $messageType = 'success';
        }
    }

    if ($action === 'bulk_promote') {
        $fromYear = trim($_POST['from_year'] ?? '');
        $schoolYear = trim($_POST['school_year'] ?? $activeSchoolYear);
        $course = trim($_POST['course'] ?? '') ?: null;

        if ($fromYear !== '' && in_array($fromYear, STUDENT_YEAR_LEVELS, true)) {
            $count = bulkPromoteStudents($conn, $fromYear, $schoolYear, $adminUserId, $course);
            $message = "Promoted {$count} student(s) from {$fromYear} to " . (nextYearLevel($fromYear) ?? 'next level') . '.';
            $messageType = $count > 0 ? 'success' : 'warning';
        }
    }

    if ($action === 'graduate_fourth') {
        $schoolYear = trim($_POST['school_year'] ?? $activeSchoolYear);
        $course = trim($_POST['course'] ?? '') ?: null;
        $count = graduateFourthYearStudents($conn, $schoolYear, $adminUserId, $course);
        $message = "Graduated/deactivated {$count} 4th year student(s).";
        $messageType = $count > 0 ? 'success' : 'warning';
    }

    if ($action === 'validate_student') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newYear = trim($_POST['new_year_level'] ?? '');
        $enrollmentStatus = trim($_POST['enrollment_status'] ?? 'regular');
        $schoolYear = trim($_POST['school_year'] ?? $activeSchoolYear);

        if ($userId > 0 && in_array($newYear, STUDENT_YEAR_LEVELS, true)) {
            applyStudentValidation($conn, $userId, $schoolYear, $newYear, $enrollmentStatus, $adminUserId, 'individual');
            logAudit(
                $conn,
                'student_validation_individual',
                'users',
                $userId,
                "Validated student to {$newYear} ({$enrollmentStatus}) for {$schoolYear}",
                $adminUserId
            );
            $message = 'Student record validated successfully.';
            $messageType = 'success';
        }
    }
}

$courseFilter = trim($_GET['course'] ?? '');
$yearFilter = trim($_GET['year_level'] ?? '');
$statusFilter = trim($_GET['enrollment_status'] ?? '');
$roleFilter = (int) ($_GET['role_id'] ?? 0);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$needsOnly = isset($_GET['needs_validation']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

if (strlen($searchQuery) > 100) {
    $searchQuery = substr($searchQuery, 0, 100);
}

$filters = [];

if ($courseFilter !== '') {
    $filters['course'] = $courseFilter;
}

if ($yearFilter !== '') {
    $filters['year_level'] = $yearFilter;
}

if ($statusFilter !== '') {
    $filters['enrollment_status'] = $statusFilter;
}

if (in_array($roleFilter, academicValidationRoleIds(), true)) {
    $filters['role_id'] = $roleFilter;
}

if ($needsOnly) {
    $filters['needs_validation'] = $activeSchoolYear;
}

if ($searchQuery !== '') {
    $filters['q'] = $searchQuery;
}

$breakdown = countValidationBreakdown($conn, $filters, $activeSchoolYear);
$needsCount = $breakdown['needs'];
$validatedCount = $breakdown['validated'];
$totalRows = $breakdown['total'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$students = fetchStudentsForValidation($conn, $filters, $perPage, $offset);

$listQuery = array_filter([
    'course' => $courseFilter !== '' ? $courseFilter : null,
    'year_level' => $yearFilter !== '' ? $yearFilter : null,
    'enrollment_status' => $statusFilter !== '' ? $statusFilter : null,
    'role_id' => in_array($roleFilter, academicValidationRoleIds(), true) ? $roleFilter : null,
    'q' => $searchQuery !== '' ? $searchQuery : null,
    'needs_validation' => $needsOnly ? '1' : null,
], static function ($value) {
    return $value !== null && $value !== '';
});
$listQueryString = http_build_query($listQuery);
$formAction = 'student_validation.php' . ($listQueryString !== '' ? '?' . $listQueryString : '');
$clearSearchQuery = $listQuery;
unset($clearSearchQuery['q']);
$clearSearchHref = 'student_validation.php' . ($clearSearchQuery !== [] ? '?' . http_build_query($clearSearchQuery) : '');

renderAdminHeader('Student Validation', 'student_validation');
?>

<section class="admin-panel">

    <?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="admin-quick-card mb-3">
        <div class="validation-step-label">1. School year</div>
        <form method="POST" class="admin-tools-row admin-tools-search">
            <input type="hidden" name="action" value="set_school_year">
            <label class="visually-hidden" for="school_year">School year</label>
            <input type="text" id="school_year" name="school_year" class="form-control form-control-sm" value="<?= htmlspecialchars($activeSchoolYear) ?>" placeholder="2025-2026" required>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </form>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-6">
            <div class="metric-card metric-success h-100">
                <div class="metric-label">Validated for <?= htmlspecialchars($activeSchoolYear) ?></div>
                <div class="metric-value"><?= (int) $validatedCount ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="metric-card metric-warning h-100">
                <div class="metric-label">Not yet validated</div>
                <div class="metric-value"><?= (int) $needsCount ?></div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-filter-card mb-3">
        <div class="validation-step-label">2. Find students and officers</div>
        <form method="GET" class="admin-tools-row admin-tools-search mb-2">
            <label class="visually-hidden" for="q">Search</label>
            <input
                type="search"
                id="q"
                name="q"
                class="form-control form-control-sm"
                value="<?= htmlspecialchars($searchQuery) ?>"
                placeholder="Name or student number"
                maxlength="100"
                autocomplete="off"
            >
            <input type="hidden" name="course" value="<?= htmlspecialchars($courseFilter) ?>">
            <input type="hidden" name="year_level" value="<?= htmlspecialchars($yearFilter) ?>">
            <input type="hidden" name="enrollment_status" value="<?= htmlspecialchars($statusFilter) ?>">
            <input type="hidden" name="role_id" value="<?= $roleFilter > 0 ? (int) $roleFilter : '' ?>">
            <?php if ($needsOnly): ?>
            <input type="hidden" name="needs_validation" value="1">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <a href="<?= htmlspecialchars($clearSearchHref) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
        </form>
        <form method="GET" class="admin-tools-row admin-tools-filters-4">
            <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
            <label class="visually-hidden" for="role_id">Role</label>
            <select id="role_id" name="role_id" class="form-select form-select-sm">
                <option value="">Students and officers</option>
                <option value="2" <?= $roleFilter === 2 ? 'selected' : '' ?>>Students</option>
                <option value="3" <?= $roleFilter === 3 ? 'selected' : '' ?>>Officers</option>
            </select>
            <label class="visually-hidden" for="course">Course</label>
            <select id="course" name="course" class="form-select form-select-sm">
                <option value="">All courses</option>
                <?php foreach (STUDENT_COURSES as $course): ?>
                <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>><?= htmlspecialchars($course) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="year_level">Year</label>
            <select id="year_level" name="year_level" class="form-select form-select-sm">
                <option value="">All years</option>
                <?php foreach (STUDENT_YEAR_LEVELS as $year): ?>
                <option value="<?= htmlspecialchars($year) ?>" <?= $yearFilter === $year ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="enrollment_status">Enrollment</label>
            <select id="enrollment_status" name="enrollment_status" class="form-select form-select-sm">
                <option value="">Regular or irregular</option>
                <?php foreach (ENROLLMENT_STATUSES as $status): ?>
                <option value="<?= htmlspecialchars($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="admin-tools-check">
                <input class="form-check-input me-2" type="checkbox" name="needs_validation" value="1" <?= $needsOnly ? 'checked' : '' ?>>
                Not yet validated
            </label>
            <div class="admin-tools-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">Show</button>
                <a href="student_validation.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <div class="admin-quick-card mb-3">
        <div class="validation-step-label">Year-end only</div>
        <form method="POST" class="admin-tools-row admin-tools-filters-3 mb-2" data-confirm="Move all matching students up one year?" data-confirm-title="Promote students" data-confirm-label="Promote">
            <input type="hidden" name="action" value="bulk_promote">
            <input type="hidden" name="school_year" value="<?= htmlspecialchars($activeSchoolYear) ?>">
            <label class="visually-hidden" for="from_year">Promote from</label>
            <select id="from_year" name="from_year" class="form-select form-select-sm" required>
                <option value="">Move this year up</option>
                <?php foreach (['1st Year', '2nd Year', '3rd Year'] as $year): ?>
                <option value="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?> → <?= htmlspecialchars(nextYearLevel($year) ?? '') ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="promote_course">Course</label>
            <select id="promote_course" name="course" class="form-select form-select-sm">
                <option value="">All courses</option>
                <?php foreach (STUDENT_COURSES as $course): ?>
                <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-success btn-sm">Promote all</button>
        </form>
        <form method="POST" class="admin-tools-row admin-tools-search" data-confirm="Deactivate matching 4th year student accounts? Officer accounts stay active so they can still scan." data-confirm-title="Graduate 4th year" data-confirm-label="Graduate" data-confirm-danger="1">
            <input type="hidden" name="action" value="graduate_fourth">
            <input type="hidden" name="school_year" value="<?= htmlspecialchars($activeSchoolYear) ?>">
            <label class="visually-hidden" for="graduate_course">Course</label>
            <select id="graduate_course" name="course" class="form-select form-select-sm">
                <option value="">All courses</option>
                <?php foreach (STUDENT_COURSES as $course): ?>
                <option value="<?= htmlspecialchars($course) ?>"><?= htmlspecialchars($course) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline-danger btn-sm">Graduate 4th year</button>
        </form>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="validation-step-label mb-0">3. Confirm year and enrollment, then validate</div>
            <span class="small text-muted">
                Showing <?= $totalRows === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $totalRows) ?>
                of <?= (int) $totalRows ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Role</th>
                        <th>Course</th>
                        <th>Current year</th>
                        <th>Status</th>
                        <th>Set for <?= htmlspecialchars($activeSchoolYear) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No students or officers match the filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                <?php
                    $isValidated = ($student['validated_school_year'] ?? '') === $activeSchoolYear;
                    $currentYear = (string) ($student['year_level'] ?? '');
                    $currentEnrollment = (string) ($student['enrollment_status'] ?? 'regular');
                    $roleId = (int) ($student['role_id'] ?? 2);
                ?>
                <tr class="<?= $isValidated ? '' : 'table-warning' ?>">
                    <td>
                        <strong><?= htmlspecialchars(trim($student['firstname'] . ' ' . $student['lastname'])) ?></strong>
                        <div class="student-sub"><?= htmlspecialchars((string) $student['student_no']) ?></div>
                    </td>
                    <td>
                        <span class="badge text-bg-light text-dark border"><?= $roleId === 3 ? 'Officer' : 'Student' ?></span>
                    </td>
                    <td><?= htmlspecialchars((string) $student['course']) ?></td>
                    <td><?= htmlspecialchars($currentYear) ?></td>
                    <td>
                        <?php if ((int) $student['is_active'] !== 1): ?>
                        <span class="badge text-bg-secondary">Inactive</span>
                        <?php elseif ($isValidated): ?>
                        <span class="badge bg-success">Validated</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Not yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $student['is_active'] !== 1): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                        <form method="POST" class="validation-inline-form" action="<?= htmlspecialchars($formAction) ?>">
                            <input type="hidden" name="action" value="validate_student">
                            <input type="hidden" name="user_id" value="<?= (int) $student['user_id'] ?>">
                            <input type="hidden" name="school_year" value="<?= htmlspecialchars($activeSchoolYear) ?>">
                            <label class="visually-hidden" for="year_<?= (int) $student['user_id'] ?>">Year</label>
                            <select id="year_<?= (int) $student['user_id'] ?>" name="new_year_level" class="form-select form-select-sm">
                                <?php foreach (STUDENT_YEAR_LEVELS as $year): ?>
                                <option value="<?= htmlspecialchars($year) ?>" <?= $year === $currentYear ? 'selected' : '' ?>><?= htmlspecialchars($year) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="visually-hidden" for="enroll_<?= (int) $student['user_id'] ?>">Enrollment</label>
                            <select id="enroll_<?= (int) $student['user_id'] ?>" name="enrollment_status" class="form-select form-select-sm">
                                <?php foreach (ENROLLMENT_STATUSES as $status): ?>
                                <option value="<?= htmlspecialchars($status) ?>" <?= $currentEnrollment === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm <?= $isValidated ? 'btn-outline-secondary' : 'btn-primary' ?>">
                                <?= $isValidated ? 'Update' : 'Validate' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Validation pages">
            <a class="btn btn-outline-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars(studentValidationPageUrl($listQuery, max(1, $page - 1))) ?>">Previous</a>
            <span class="small text-muted">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <a class="btn btn-outline-secondary btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= htmlspecialchars(studentValidationPageUrl($listQuery, min($totalPages, $page + 1))) ?>">Next</a>
        </nav>
        <?php endif; ?>
    </div>

</section>

<?php renderAdminFooter(); ?>
