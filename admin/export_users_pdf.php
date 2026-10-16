<?php

require_once '../includes/session.php';
requireRole(1);

require_once '../config/database.php';
require_once '../includes/student_helpers.php';
require_once '../includes/audit_helpers.php';
require_once '../includes/admin_export_helpers.php';

$db = new Database();
$conn = $db->connect();

$roleId = (int) ($_GET['role_id'] ?? 0);
$course = trim($_GET['course'] ?? '');
$yearLevel = trim($_GET['year_level'] ?? '');
$activeFilter = trim($_GET['is_active'] ?? '');
$searchQuery = trim((string) ($_GET['q'] ?? ''));

if (strlen($searchQuery) > 100) {
    $searchQuery = substr($searchQuery, 0, 100);
}

$filters = [
    'role_id' => $roleId,
    'course' => $course,
    'year_level' => $yearLevel,
    'is_active' => $activeFilter,
    'q' => $searchQuery,
];

$meta = [];

if ($searchQuery !== '') {
    $meta['Search'] = $searchQuery;
}

if ($roleId > 0) {
    $meta['Role Filter'] = userRoleLabel($roleId);
}

if ($course !== '') {
    $meta['Course'] = $course;
}

if ($yearLevel !== '') {
    $meta['Year Level'] = $yearLevel;
}

if ($activeFilter !== '') {
    $meta['Status Filter'] = ucfirst($activeFilter);
}

try {
    logAudit($conn, 'export_users_pdf', 'users', null, 'Users PDF export');

    exportUsersPdf($conn, $filters, $meta);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8', true, 400);
    echo 'Report error: ' . $e->getMessage();
}
