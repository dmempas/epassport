<?php

/**
 * Resolved realtime settings for PHP notify (localhost) and browser WebSocket (LAN/public host).
 */
function epassport_realtime_config(): array
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $path = __DIR__ . '/../config/realtime.php';
    $config = is_file($path) ? require $path : [];

    $defaults = [
        'enabled' => true,
        'ws_port' => 8081,
        'ws_public_host' => '',
        'notify_url' => 'http://127.0.0.1:8081/notify',
        'location_grace_seconds' => 300,
    ];

    $config = array_merge($defaults, $config);

    if (!empty($config['ws_url_client'])) {
        $resolved = $config;
        return $resolved;
    }

    $port = (int) $config['ws_port'];

    if (!empty($config['ws_public_host'])) {
        $host = $config['ws_public_host'];
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $host = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
    } else {
        $host = '127.0.0.1';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    $wsPath = trim((string) ($config['ws_path'] ?? ''), '/');

    if ($isHttps && $wsPath !== '') {
        $base = epassport_base_path();
        $pathPrefix = $base !== '' ? $base . '/' . $wsPath : '/' . $wsPath;
        $config['ws_url_client'] = 'wss://' . $host . $pathPrefix;
    } elseif (!$isHttps) {
        $config['ws_url_client'] = 'ws://' . $host . ':' . $port;
    } else {
        // HTTPS without ws_path — polling fallback (2s) until proxy is configured
        $config['ws_url_client'] = '';
    }

    $resolved = $config;

    return $resolved;
}
