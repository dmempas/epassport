<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/admin_export_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        if ($action === 'activate') {
            $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            logAudit($conn, 'user_activated', 'users', $userId, 'Activated user account');
        } elseif ($action === 'deactivate') {
            $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            logAudit($conn, 'user_deactivated', 'users', $userId, 'Deactivated user account');
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $userId]);
            logAudit($conn, 'user_deleted', 'users', $userId, 'Deleted user account');
        }
    }

    header('Location: users.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

$roleFilter = (int) ($_GET['role_id'] ?? 0);
$courseFilter = trim($_GET['course'] ?? '');
$yearFilter = trim($_GET['year_level'] ?? '');
$activeFilter = trim($_GET['is_active'] ?? '');
$searchQuery = trim((string) ($_GET['q'] ?? ''));

if (strlen($searchQuery) > 100) {
    $searchQuery = substr($searchQuery, 0, 100);
}

$filters = [
    'role_id' => $roleFilter,
    'course' => $courseFilter,
    'year_level' => $yearFilter,
    'is_active' => $activeFilter,
    'q' => $searchQuery,
];

$users = fetchUsersForExport($conn, $filters);
$allUsers = fetchUsersForExport($conn, []);
$usersExportQuery = http_build_query(array_filter($filters, static function ($value) {
    return $value !== '' && $value !== 0 && $value !== null;
}));

$stats = [
    'total' => count($allUsers),
    'active' => 0,
    'inactive' => 0,
    'admin' => 0,
    'student' => 0,
    'officer' => 0,
];

foreach ($allUsers as $user) {
    if ((int) ($user['is_active'] ?? 0) === 1) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }

    $roleId = (int) ($user['role_id'] ?? 0);
    if ($roleId === 1) {
        $stats['admin']++;
    } elseif ($roleId === 2) {
        $stats['student']++;
    } elseif ($roleId === 3) {
        $stats['officer']++;
    }
}

$usersListQuery = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
$usersPostAction = 'users.php' . ($usersListQuery !== '' ? '?' . $usersListQuery : '');

renderAdminHeader('User Management', 'users');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-4">
        <div>
            <h2 class="h4 mb-1">User Management</h2>
            <p class="text-muted mb-0 small">
                View and manage admin, officer, and student accounts. Search by name, student number, email, or contact.
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="admin-meta-pill">
                <i class="bi bi-people"></i>
                <?= (int) $stats['total'] ?> user(s)
            </div>
            <a href="create_user.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus"></i> Create User</a>
        </div>
    </div>

    <div class="admin-section-title">Summary</div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">Total Users</div>
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
            <div class="metric-card h-100">
                <div class="metric-label">Admins</div>
                <div class="metric-value fs-4"><?= (int) $stats['admin'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Students</div>
                <div class="metric-value fs-4"><?= (int) $stats['student'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="metric-card h-100">
                <div class="metric-label">Officers</div>
                <div class="metric-value fs-4"><?= (int) $stats['officer'] ?></div>
            </div>
        </div>
    </div>

    <?php
    $usersFilterOnlyQuery = http_build_query(array_filter([
        'role_id' => $roleFilter ?: null,
        'course' => $courseFilter,
        'year_level' => $yearFilter,
        'is_active' => $activeFilter,
    ], static function ($value) {
        return $value !== '' && $value !== null;
    }));
    $usersSearchOnlyQuery = http_build_query(array_filter([
        'q' => $searchQuery,
    ], static function ($value) {
        return $value !== '';
    }));
    $usersClearSearchHref = 'users.php' . ($usersFilterOnlyQuery !== '' ? '?' . $usersFilterOnlyQuery : '');
    $usersResetFiltersHref = 'users.php' . ($usersSearchOnlyQuery !== '' ? '?' . $usersSearchOnlyQuery : '');
    ?>

    <div class="admin-quick-card admin-filter-card mb-4">
        <div class="admin-tools-bar">
            <form method="GET" class="admin-tools-row admin-tools-search" action="users.php">
                <input type="hidden" name="role_id" value="<?= $roleFilter > 0 ? (int) $roleFilter : '' ?>">
                <input type="hidden" name="course" value="<?= htmlspecialchars($courseFilter) ?>">
                <input type="hidden" name="year_level" value="<?= htmlspecialchars($yearFilter) ?>">
                <input type="hidden" name="is_active" value="<?= htmlspecialchars($activeFilter) ?>">
                <label class="visually-hidden" for="q">Search users</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    class="form-control form-control-sm"
                    value="<?= htmlspecialchars($searchQuery) ?>"
                    placeholder="Name, student number, email, or contact"
                    maxlength="100"
                    autocomplete="off"
                >
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="<?= htmlspecialchars($usersClearSearchHref) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
            </form>

            <form method="GET" class="admin-tools-row admin-tools-filters" action="users.php">
                <input type="hidden" name="q" value="<?= htmlspecialchars($searchQuery) ?>">
                <label class="visually-hidden" for="role_id">Role</label>
                <select id="role_id" name="role_id" class="form-select form-select-sm">
                    <option value="">All roles</option>
                    <option value="1" <?= $roleFilter === 1 ? 'selected' : '' ?>>Admin</option>
                    <option value="2" <?= $roleFilter === 2 ? 'selected' : '' ?>>Student</option>
                    <option value="3" <?= $roleFilter === 3 ? 'selected' : '' ?>>Officer</option>
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
                <label class="visually-hidden" for="year_level">Year Level</label>
                <select id="year_level" name="year_level" class="form-select form-select-sm">
                    <option value="">All year levels</option>
                    <?php foreach (STUDENT_YEAR_LEVELS as $year): ?>
                    <option value="<?= htmlspecialchars($year) ?>" <?= $yearFilter === $year ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="is_active">Account Status</label>
                <select id="is_active" name="is_active" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="active" <?= $activeFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $activeFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <div class="admin-tools-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="<?= htmlspecialchars($usersResetFiltersHref) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
                    <a href="export_users_pdf.php?<?= htmlspecialchars($usersExportQuery) ?>" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-file-earmark-pdf"></i> Export PDF
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-person-lines-fill me-1"></i> Registered Users</h3>
            <span class="badge badge-soft-gold"><?= count($users) ?> shown</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Student No</th>
                        <th>Name</th>
                        <th>Course</th>
                        <th>Year</th>
                        <th>Email</th>
                        <th>Contact</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><?= $searchQuery !== '' ? 'No users match this search.' : 'No users found.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($user['student_no']) ?></td>
                        <td><strong><?= htmlspecialchars(trim($user['firstname'] . ' ' . $user['lastname'])) ?></strong></td>
                        <td><?= htmlspecialchars($user['course'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($user['year_level'] ?: 'N/A') ?></td>
                        <td class="small"><?= htmlspecialchars($user['email']) ?></td>
                        <td class="small"><?= htmlspecialchars($user['contact_number'] ?: 'N/A') ?></td>
                        <td>
                            <span class="badge text-bg-light text-dark border">
                                <?= htmlspecialchars(userRoleLabel((int) $user['role_id'], $user['role_name'] ?? null)) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= (int) $user['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                <?= (int) $user['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a href="edit_user.php?user_id=<?= (int) $user['user_id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                                <form method="POST" class="d-inline" action="<?= htmlspecialchars($usersPostAction) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                    <?php if ((int) $user['is_active'] === 1): ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="activate">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    <?php endif; ?>
                                </form>
                                <form method="POST" class="d-inline" action="<?= htmlspecialchars($usersPostAction) ?>" data-confirm="Delete this user account?" data-confirm-title="Delete account" data-confirm-label="Delete" data-confirm-danger="1">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
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
