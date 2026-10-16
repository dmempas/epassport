<?php

/**
 * Mobile browsers block Geolocation on plain HTTP (except localhost).
 * Redirect LAN/phone traffic to HTTPS so GPS can work.
 */
function epassport_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function epassport_is_localhost_request(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

    return (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
}

function epassport_https_url_for_current_request(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    return 'https://' . $host . $uri;
}

function epassport_secure_access_help_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);

    return 'https://' . $host . epassport_url('student/secure_access.php');
}

function epassport_require_https_for_gps(): void
{
    if (epassport_is_https_request() || epassport_is_localhost_request()) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    // Show instructions before HTTPS (self-signed cert requires Advanced -> Proceed on phone)
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, 'secure_access.php') === false) {
        header('Location: ' . epassport_url('student/secure_access.php'), true, 302);
        exit;
    }
}
