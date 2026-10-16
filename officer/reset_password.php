<?php
require_once '../includes/session.php';
requireRole(3);

header('Location: student_records.php', true, 301);
exit;
