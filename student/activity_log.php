<?php

require_once '../includes/session.php';
requireRole(2);

require_once '../config/database.php';
require_once '../includes/activity_log_helpers.php';
require_once '../includes/student_layout.php';

$db = new Database();
$conn = $db->connect();

$userId = (int) $_SESSION['user_id'];
$activity = getStudentActivityTimeline($conn, $userId, null, 200);

renderStudentHeader('Activity Log', 'activity', 'Activity Log');
?>

<div class="student-block">
    <h2 class="student-section-title"><i class="bi bi-clock-history"></i> Your activity</h2>
    <?php if ($activity === []): ?>
        <p class="student-empty">No activity recorded yet.</p>
    <?php else: ?>
        <div class="student-record-list">
            <?php foreach ($activity as $item): ?>
                <article class="student-record">
                    <div class="student-record__top">
                        <span class="<?= studentActivityBadgeClass((string) $item['tone']) ?>">
                            <?= htmlspecialchars($item['label']) ?>
                        </span>
                    </div>
                    <p class="student-record__meta"><?= htmlspecialchars($item['description']) ?></p>
                    <p class="student-record__when">
                        <?= $item['occurred_at'] ? date('M d, Y g:i A', strtotime($item['occurred_at'])) : '—' ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php renderStudentFooter(); ?>
