<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/https_helper.php';
require_once __DIR__ . '/gps_consent.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => epassport_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function epassport_login_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#/(admin|student|officer|ajax)/#', $script)) {
        return '../login.php';
    }

    return 'login.php';
}

function redirectByRole(int $roleId): void
{
    switch ($roleId) {
        case 1:
            header('Location: admin/dashboard.php');
            break;
        case 2:
            header('Location: student/gps_consent.php');
            break;
        case 3:
            header('Location: officer/dashboard.php');
            break;
        default:
            header('Location: ' . epassport_login_url());
            break;
    }

    exit;
}

function dashboardUrlForRole(int $roleId): string
{
    return match ($roleId) {
        1 => 'admin/dashboard.php',
        2 => 'student/dashboard.php',
        3 => 'officer/dashboard.php',
        default => epassport_login_url(),
    };
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . epassport_login_url());
        exit;
    }
}

function requireRole($roleId, bool $requireStudentGpsConsent = true) {
    if (!isLoggedIn() || $_SESSION['role_id'] != $roleId) {
        header('Location: ' . epassport_login_url());
        exit;
    }

    if ((int) $roleId === 2 && $requireStudentGpsConsent) {
        epassport_require_student_gps_consent();
    }
}