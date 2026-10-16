<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/student_layout.php';

$db = new Database();
$conn = $db->connect();
ensureStudentAcademicSchema($conn);

$stmt = $conn->prepare("
    SELECT student_no, firstname, middlename, lastname, course, year_level,
           enrollment_status, email, contact_number
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

try {
    $stmt->execute([(int) $_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $conn->prepare("
        SELECT student_no, firstname, lastname, course, year_level, email, contact_number
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$student) {
    die('Student not found.');
}

$fullName = trim(implode(' ', array_filter([
    trim((string) ($student['firstname'] ?? '')),
    trim((string) ($student['middlename'] ?? '')),
    trim((string) ($student['lastname'] ?? '')),
])));

$flash = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

renderStudentHeader('Student Profile', 'profile', 'Student Profile');
?>

<div class="student-panel">
    <div class="card-body">
        <div class="profile-header">
            <h3><?= htmlspecialchars($fullName) ?></h3>
            <p>Student information</p>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>

        <ul class="info-list">
            <li>
                <span class="info-label">Student Number</span>
                <span class="info-value"><?= htmlspecialchars((string) ($student['student_no'] ?? '—')) ?></span>
            </li>
            <li>
                <span class="info-label">Course</span>
                <span class="info-value"><?= htmlspecialchars((string) ($student['course'] ?: 'N/A')) ?></span>
            </li>
            <li>
                <span class="info-label">Year Level</span>
                <span class="info-value"><?= htmlspecialchars((string) ($student['year_level'] ?: 'N/A')) ?></span>
            </li>
            <?php if (array_key_exists('enrollment_status', $student)): ?>
            <li>
                <span class="info-label">Enrollment</span>
                <span class="info-value"><?= htmlspecialchars(ucfirst((string) ($student['enrollment_status'] ?: 'regular'))) ?></span>
            </li>
            <?php endif; ?>
            <li>
                <span class="info-label">Email</span>
                <span class="info-value"><?= htmlspecialchars((string) ($student['email'] ?: 'N/A')) ?></span>
            </li>
            <li>
                <span class="info-label">Contact</span>
                <span class="info-value"><?= htmlspecialchars((string) ($student['contact_number'] ?: 'N/A')) ?></span>
            </li>
        </ul>

        <div class="mt-4 pt-3 border-top">
            <a href="../change_password.php" class="btn student-btn-primary">
                <i class="bi bi-key me-1"></i> Change password
            </a>
        </div>
    </div>
</div>

<?php renderStudentFooter(); ?>
