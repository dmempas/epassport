<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/event_helpers.php';
require_once '../includes/attendance_schema.php';
require_once '../includes/student_layout.php';

$db = new Database();
$conn = $db->connect();
ensureAttendanceSchema($conn);

$userId = (int) $_SESSION['user_id'];

$sql = "
    SELECT
        a.scan_type,
        a.status,
        a.qr_scanned_at,
        COALESCE(a.is_late_scan, 0) AS is_late_scan,
        e.event_name
    FROM attendance_sessions a
    INNER JOIN events e ON a.event_id = e.event_id
    WHERE a.user_id = ?
    ORDER BY a.qr_scanned_at DESC
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $conn->prepare("
        SELECT a.scan_type, a.status, a.qr_scanned_at, e.event_name
        FROM attendance_sessions a
        INNER JOIN events e ON a.event_id = e.event_id
        WHERE a.user_id = ?
        ORDER BY a.qr_scanned_at DESC
    ");
    $stmt->execute([$userId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($records as &$row) {
        $row['is_late_scan'] = 0;
    }
    unset($row);
}

$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'violated' => 0];
foreach ($records as $record) {
    $status = (string) $record['status'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

renderStudentHeader('Attendance', 'attendance', 'Attendance');
?>

<div class="student-category-bar" role="tablist" aria-label="Attendance status">
    <button type="button" class="student-category is-active" data-filter="all" role="tab" aria-selected="true">
        <span class="student-category__count"><?= count($records) ?></span>
        <span class="student-category__label">All</span>
    </button>
    <button type="button" class="student-category" data-filter="present" role="tab" aria-selected="false">
        <span class="student-category__count"><?= (int) $counts['present'] ?></span>
        <span class="student-category__label">Present</span>
    </button>
    <button type="button" class="student-category" data-filter="late" role="tab" aria-selected="false">
        <span class="student-category__count"><?= (int) $counts['late'] ?></span>
        <span class="student-category__label">Late</span>
    </button>
    <button type="button" class="student-category" data-filter="absent" role="tab" aria-selected="false">
        <span class="student-category__count"><?= (int) $counts['absent'] ?></span>
        <span class="student-category__label">Absent</span>
    </button>
    <button type="button" class="student-category" data-filter="violated" role="tab" aria-selected="false">
        <span class="student-category__count"><?= (int) $counts['violated'] ?></span>
        <span class="student-category__label">Violated</span>
    </button>
</div>

<div class="student-record-list" id="attendanceList">
    <?php if ($records === []): ?>
        <p class="student-empty">No attendance records yet.</p>
    <?php else: ?>
        <?php foreach ($records as $record): ?>
            <?php
            $status = (string) $record['status'];
            $filterKey = in_array($status, ['present', 'late', 'absent', 'violated'], true) ? $status : 'other';
            $label = formatAttendanceStatusLabel($status);
            $pill = match ($status) {
                'present' => 'present',
                'late' => 'late',
                'absent' => 'absent',
                'violated' => 'violated',
                default => 'pending',
            };
            ?>
            <article class="student-record" data-status="<?= htmlspecialchars($filterKey) ?>">
                <div class="student-record__top">
                    <h3 class="student-record__title"><?= htmlspecialchars((string) $record['event_name']) ?></h3>
                    <span class="status-pill status-pill--<?= $pill ?>"><?= htmlspecialchars($label) ?></span>
                </div>
                <p class="student-record__meta"><?= htmlspecialchars(formatScanTypeLabel((string) $record['scan_type'])) ?></p>
                <p class="student-record__when">
                    <?= !empty($record['qr_scanned_at']) ? date('M d, Y g:i A', strtotime($record['qr_scanned_at'])) : '—' ?>
                </p>
            </article>
        <?php endforeach; ?>
        <p class="student-empty student-empty--filter d-none" id="attendanceEmptyFilter">No records in this category.</p>
    <?php endif; ?>
</div>

<script>
(function () {
    const buttons = document.querySelectorAll('.student-category');
    const cards = document.querySelectorAll('#attendanceList .student-record');
    const empty = document.getElementById('attendanceEmptyFilter');

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const filter = btn.getAttribute('data-filter') || 'all';
            buttons.forEach(function (other) {
                other.classList.toggle('is-active', other === btn);
                other.setAttribute('aria-selected', other === btn ? 'true' : 'false');
            });
            let shown = 0;
            cards.forEach(function (card) {
                const match = filter === 'all' || card.getAttribute('data-status') === filter;
                card.classList.toggle('d-none', !match);
                if (match) shown++;
            });
            if (empty) empty.classList.toggle('d-none', shown > 0 || cards.length === 0);
        });
    });
})();
</script>

<?php renderStudentFooter(); ?>
