<?php
require_once '../includes/session.php';
requireRole(1);
require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/audit_helpers.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();
ensureStudentAcademicSchema($conn);

$user_id = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($user_id <= 0) {
    header("Location: users.php");
    exit;
}

$message = "";
$messageType = "info";
$roles = $conn->query("SELECT id, role_name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_no = trim($_POST['student_no']);
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $lastname = trim($_POST['lastname']);
    $course = trim($_POST['course']);
    $year_level = trim($_POST['year_level']);
    $enrollment_status = trim($_POST['enrollment_status'] ?? 'regular');
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $role_id = (int) ($_POST['role_id'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($student_no === '' || $firstname === '' || $lastname === '' || $course === '' || $year_level === '' || $email === '' || $contact_number === '' || $role_id <= 0) {
        $message = "Please fill in all required fields.";
        $messageType = "danger";
    } else {
        try {
            $stmt = $conn->prepare("
                UPDATE users
                SET role_id = :role_id,
                    student_no = :student_no,
                    firstname = :firstname,
                    middlename = :middlename,
                    lastname = :lastname,
                    course = :course,
                    year_level = :year_level,
                    enrollment_status = :enrollment_status,
                    email = :email,
                    contact_number = :contact_number,
                    is_active = :is_active
                WHERE user_id = :user_id
            ");
            $stmt->execute([
                ':role_id' => $role_id,
                ':student_no' => $student_no,
                ':firstname' => $firstname,
                ':middlename' => $middlename,
                ':lastname' => $lastname,
                ':course' => $course,
                ':year_level' => $year_level,
                ':enrollment_status' => in_array($enrollment_status, ENROLLMENT_STATUSES, true) ? $enrollment_status : 'regular',
                ':email' => $email,
                ':contact_number' => $contact_number,
                ':is_active' => $is_active,
                ':user_id' => $user_id
            ]);
            logAudit(
                $conn,
                'user_updated',
                'users',
                $user_id,
                "Updated user {$firstname} {$lastname} ({$student_no})"
            );
            $message = "User updated successfully.";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = :user_id");
$stmt->execute([':user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header("Location: users.php");
    exit;
}

renderAdminHeader('Edit User', 'users');
?>

<section class="admin-panel">
    <h2 class="h5 mb-3">Edit User Account</h2>
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3">
        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
        <div class="col-md-6">
            <label class="form-label">Student Number</label>
            <input type="text" name="student_no" class="form-control" value="<?= htmlspecialchars($user['student_no']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Contact Number</label>
            <input type="text" name="contact_number" class="form-control" value="<?= htmlspecialchars($user['contact_number']) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">First Name</label>
            <input type="text" name="firstname" class="form-control" value="<?= htmlspecialchars($user['firstname']) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Middle Name</label>
            <input type="text" name="middlename" class="form-control" value="<?= htmlspecialchars($user['middlename']) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="lastname" class="form-control" value="<?= htmlspecialchars($user['lastname']) ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Course</label>
            <select name="course" class="form-select" required>
                <option value="">Select Course</option>
                <option value="BSIT" <?= ($user['course'] ?? '') === 'BSIT' ? 'selected' : '' ?>>BSIT</option>
                <option value="BSMB" <?= ($user['course'] ?? '') === 'BSMB' ? 'selected' : '' ?>>BSMB</option>
                <option value="BTLEd- HE" <?= ($user['course'] ?? '') === 'BTLEd- HE' ? 'selected' : '' ?>>BTLEd- HE</option>
                <option value="BTLEd-IA" <?= ($user['course'] ?? '') === 'BTLEd-IA' ? 'selected' : '' ?>>BTLEd-IA</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Year Level</label>
            <select name="year_level" class="form-select" required>
                <option value="">Select Year Level</option>
                <?php foreach (STUDENT_YEAR_LEVELS as $yearOption): ?>
                <option value="<?= htmlspecialchars($yearOption) ?>" <?= ($user['year_level'] ?? '') === $yearOption ? 'selected' : '' ?>><?= htmlspecialchars($yearOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Enrollment Status</label>
            <select name="enrollment_status" class="form-select">
                <?php foreach (ENROLLMENT_STATUSES as $statusOption): ?>
                <option value="<?= htmlspecialchars($statusOption) ?>" <?= ($user['enrollment_status'] ?? 'regular') === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($statusOption)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role_id" class="form-select" required>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id'] ?>" <?= (int) $role['id'] === (int) $user['role_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(ucfirst($role['role_name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" <?= (int) $user['is_active'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Active account</label>
            </div>
        </div>
        <div class="col-12">
            <button class="btn btn-success">Save Changes</button>
            <a href="users.php" class="btn btn-outline-secondary ms-2">Back to Users</a>
        </div>
    </form>
</section>

<?php renderAdminFooter(); ?>
