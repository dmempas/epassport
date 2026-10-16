<?php

if (defined('EPASSPORT_BOOTSTRAP')) {
    return;
}

define('EPASSPORT_BOOTSTRAP', true);

require_once __DIR__ . '/env.php';

function appConfig(): array
{
    static $cfg = null;

    if (!is_array($cfg)) {
        $loaded = require __DIR__ . '/../config/app.php';
        $cfg = is_array($loaded) ? $loaded : [];
    }

    return $cfg;
}

$appConfig = appConfig();
$tzName = $appConfig['timezone'] ?? 'Asia/Manila';

date_default_timezone_set($tzName);

function appTimezoneObject(): DateTimeZone
{
    return new DateTimeZone(date_default_timezone_get());
}

function appTimezoneLabel(): string
{
    return appConfig()['timezone_label'] ?? 'Philippine Time (PHT)';
}

/**
 * Align MySQL session TZ and PHP so NOW() and appNow() match the server wall clock.
 * XAMPP on Windows reports @@system_time_zone as Etc/GMT±N; SET time_zone = '+08:00' then
 * double-shifts NOW() away from the clock used for scan windows.
 */
function configureAppClock(PDO $conn): void
{
    static $configured = false;

    if ($configured) {
        return;
    }

    $configured = true;

    $config = appConfig();
    $appTz = $config['timezone'] ?? 'Asia/Manila';
    $mysqlTz = $config['mysql_time_zone'] ?? 'auto';

    if ($mysqlTz === 'auto') {
        try {
            $sysTz = (string) $conn->query('SELECT @@system_time_zone')->fetchColumn();

            if (stripos(PHP_OS_FAMILY, 'Windows') !== false && preg_match('/GMT/i', $sysTz)) {
                $mysqlTz = 'SYSTEM';
            } else {
                $mysqlTz = $config['timezone_offset'] ?? '+08:00';
            }
        } catch (Throwable $e) {
            $mysqlTz = $config['timezone_offset'] ?? '+08:00';
        }
    }

    try {
        if ($mysqlTz === 'SYSTEM') {
            $conn->exec("SET time_zone = 'SYSTEM'");
            $sysTz = (string) $conn->query('SELECT @@system_time_zone')->fetchColumn();

            if ($sysTz !== '' && @timezone_open($sysTz) !== false) {
                date_default_timezone_set($sysTz);

                return;
            }
        } else {
            $conn->exec('SET time_zone = ' . $conn->quote($mysqlTz));
            date_default_timezone_set($appTz);

            return;
        }
    } catch (Throwable $e) {
        // Fall through to app timezone from config.
    }

    date_default_timezone_set($appTz);
}

/**
 * Current moment — prefers MySQL NOW() (same clock as scan window TIME columns).
 */
function appDateTime(?PDO $conn = null): DateTime
{
    if ($conn instanceof PDO) {
        try {
            $row = $conn->query('SELECT NOW() AS dt')->fetch(PDO::FETCH_ASSOC);

            if (!empty($row['dt'])) {
                return new DateTime($row['dt']);
            }
        } catch (Throwable $e) {
            // Fall through to PHP
        }
    }

    return new DateTime('now');
}

function appNow(string $format = 'Y-m-d H:i:s', ?PDO $conn = null): string
{
    return appDateTime($conn)->format($format);
}

function appTime(?PDO $conn = null): string
{
    return appNow('H:i:s', $conn);
}

/**
 * Clock time for people reading the screen: 14:00:00 → 2:00 PM.
 * Stored scan times stay 24-hour. HTML time inputs still need HH:MM values.
 */
function formatAppTime12(?string $time): string
{
    $time = trim((string) $time);

    if ($time === '') {
        return '';
    }

    if (strlen($time) === 5) {
        $time .= ':00';
    }

    $parsed = DateTime::createFromFormat('H:i:s', substr($time, 0, 8));

    if (!$parsed) {
        return substr(trim((string) $time), 0, 5);
    }

    return $parsed->format('g:i A');
}

function formatAppTimeRange(?string $start, ?string $end, string $separator = '–'): string
{
    return formatAppTime12($start) . $separator . formatAppTime12($end);
}

function epassport_render_dialog_script(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;
    $src = epassport_url('assets/js/epassport-dialog.js');
    echo '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';
}

function appDate(?PDO $conn = null): string
{
    return appNow('Y-m-d', $conn);
}

/**
 * Web URL prefix for this install (e.g. /E-PASSPORT). Empty when hosted at domain root.
 */
function epassport_base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $cfg = appConfig();
    $base = isset($cfg['base_path']) ? rtrim((string) $cfg['base_path'], '/') : '';

    return $base;
}

function epassport_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = epassport_base_path();

    if ($base === '') {
        return '/' . $path;
    }

    return $base . '/' . $path;
}

function epassport_vendor_asset(string $path): string
{
    return epassport_url('assets/vendor/' . ltrim($path, '/'));
}

function epassport_filter_ymd(string $value, string $fallback): string
{
    $value = trim($value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        return $value;
    }

    return $fallback;
}

function epassport_allow_list(string $value, array $allowed): string
{
    return in_array($value, $allowed, true) ? $value : '';
}
