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
        $message = "Please fill in all required user details.";
        $messageType = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please provide a valid email address.";
        $messageType = "danger";
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $contact_number)) {
        $message = "Contact number format is invalid.";
        $messageType = "danger";
    } else {
        // DEFAULT PASSWORD = student_no
        $default_password = password_hash($student_no, PASSWORD_DEFAULT);

        try {
            $stmt = $conn->prepare("
                INSERT INTO users
                (role_id, student_no, firstname, middlename, lastname, course, year_level, enrollment_status, email, contact_number, password, is_active)
                VALUES
                (:role_id, :student_no, :firstname, :middlename, :lastname, :course, :year_level, :enrollment_status, :email, :contact_number, :password, :is_active)
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
                ':password' => $default_password,
                ':is_active' => $is_active
            ]);

            logAudit(
                $conn,
                'user_created',
                'users',
                (int) $conn->lastInsertId(),
                "Created user {$firstname} {$lastname} ({$student_no})"
            );

            $message = "User created successfully.";
            $messageType = "success";

        } catch (PDOException $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}

renderAdminHeader('Create User', 'create_user');
?>

<section class="admin-panel">
    <h2 class="h5 mb-3">Add New User</h2>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Student Number</label>
            <input type="text" name="student_no" class="form-control" placeholder="2024-0001" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" placeholder="name@example.com" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Contact Number</label>
            <input type="text" name="contact_number" class="form-control" placeholder="+63 912 345 6789" required>
        </div>

        <div class="col-md-4">
            <label class="form-label">First Name</label>
            <input type="text" name="firstname" class="form-control" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Middle Name</label>
            <input type="text" name="middlename" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">Last Name</label>
            <input type="text" name="lastname" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Course</label>
            <select name="course" class="form-select" required>
                <option value="">Select Course</option>
                <option value="BSIT">BSIT</option>
                <option value="BSMB">BSMB</option>
                <option value="BTLEd- HE">BTLEd- HE</option>
                <option value="BTLEd-IA">BTLEd-IA</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Year Level</label>
            <select name="year_level" class="form-select" required>
                <option value="">Select Year Level</option>
                <?php foreach (STUDENT_YEAR_LEVELS as $yearOption): ?>
                <option value="<?= htmlspecialchars($yearOption) ?>"><?= htmlspecialchars($yearOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Enrollment Status</label>
            <select name="enrollment_status" class="form-select">
                <?php foreach (ENROLLMENT_STATUSES as $statusOption): ?>
                <option value="<?= htmlspecialchars($statusOption) ?>"><?= htmlspecialchars(ucfirst($statusOption)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role_id" class="form-select" required>
                <option value="">Select role</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars(ucfirst($role['role_name'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="is_active" name="is_active" checked>
                <label class="form-check-label" for="is_active">
                    Activate account after creation
                </label>
            </div>
        </div>

        <div class="col-12">
            <button class="btn btn-success">Create User</button>
            <a href="users.php" class="btn btn-outline-secondary ms-2">Back to Users</a>
        </div>
    </form>
</section>

<?php renderAdminFooter(); ?>