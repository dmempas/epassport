<?php
require_once '../includes/session.php';
requireRole(2);
header('Location: attendance.php', true, 301);
exit;
