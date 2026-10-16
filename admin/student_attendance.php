<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/search_helpers.php';
require_once '../includes/student_helpers.php';
require_once '../includes/event_helpers.php';
require_once '../includes/attendance_schema.php';
require_once 'layout.php';

$db = new Database();
$conn = $db->connect();

ensureAttendanceSchema($conn);

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: attendance_management.php');
    exit;
}

$student = fetchStudentById($conn, $userId);
if (!$student) {
    $student = [
        'user_id' => $userId,
        'student_no' => '',
        'firstname' => 'Student',
        'middlename' => '',
        'lastname' => '#' . $userId,
        'course' => '',
        'year_level' => '',
    ];
}

$records = fetchStudentAttendanceRecords($conn, $userId);
$counts = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'violated' => 0,
    'pending' => 0,
];

foreach ($records as $row) {
    $status = (string) ($row['status'] ?? '');
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

renderAdminHeader('Student Attendance Details', 'attendance_management');
?>

<section class="admin-panel">

    <div class="admin-page-header mb-3">
        <div>
            <h2 class="h4 mb-1"><?= htmlspecialchars(formatStudentDisplayName($student)) ?></h2>
            <p class="small text-muted mb-0">
                <?= htmlspecialchars((string) $student['student_no']) ?>
                · <?= htmlspecialchars((string) ($student['course'] ?? '')) ?>
                · <?= htmlspecialchars((string) ($student['year_level'] ?? '')) ?>
            </p>
        </div>
        <div class="admin-page-actions">
            <a href="student_penalty.php?user_id=<?= (int) $userId ?>" class="btn btn-outline-success btn-sm">Penalties</a>
            <a href="attendance_management.php" class="btn btn-outline-secondary btn-sm">Back to Attendance</a>
        </div>
    </div>

    <div class="admin-section-title">Summary</div>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md">
            <div class="metric-card metric-muted h-100">
                <div class="metric-label">Stamps</div>
                <div class="metric-value fs-4"><?= count($records) ?></div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="metric-card metric-success h-100">
                <div class="metric-label">Present</div>
                <div class="metric-value fs-4"><?= (int) $counts['present'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="metric-card metric-warning h-100">
                <div class="metric-label">Late</div>
                <div class="metric-value fs-4"><?= (int) $counts['late'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="metric-card metric-danger h-100">
                <div class="metric-label">Absent</div>
                <div class="metric-value fs-4"><?= (int) $counts['absent'] ?></div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="metric-card metric-warning h-100">
                <div class="metric-label">Violated</div>
                <div class="metric-value fs-4"><?= (int) $counts['violated'] ?></div>
            </div>
        </div>
    </div>

    <div class="admin-quick-card admin-records-card">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h3 class="h6 mb-0"><i class="bi bi-clipboard-check me-1"></i> Attendance records</h3>
            <span class="badge badge-soft-gold"><?= count($records) ?> stamp<?= count($records) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Scan</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                        <th>Penalty</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($records !== []): ?>
                    <?php foreach ($records as $row): ?>
                        <?php
                        $badge = match ($row['status']) {
                            'present' => 'success',
                            'late', 'violated' => 'warning',
                            'absent' => 'danger',
                            default => 'secondary',
                        };
                        $scannedAt = (string) ($row['qr_scanned_at'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $row['event_name']) ?></strong>
                                <?php if (!empty($row['start_date']) || !empty($row['end_date'])): ?>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($row['start_date'] ?? '')) ?> → <?= htmlspecialchars((string) ($row['end_date'] ?? '')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars(formatScanTypeLabel((string) $row['scan_type'])) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars(formatAttendanceStatusLabel((string) $row['status'])) ?></span>
                            </td>
                            <td class="text-nowrap small">
                                <?= $scannedAt !== '' ? date('M d, Y h:i A', strtotime($scannedAt)) : '—' ?>
                            </td>
                            <td>
                                <?php if (!empty($row['penalty_id'])): ?>
                                    <span class="badge bg-warning text-dark">Yes</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No attendance records.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>

<?php renderAdminFooter(); ?>
