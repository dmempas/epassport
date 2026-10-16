<?php

/**
 * Start realtime/server.js if it is not already listening.
 * Called from web requests so XAMPP/hosting does not depend on a manual start.bat.
 */
function ensureEpassportWebsocketServer(): void
{
    static $attempted = false;

    if ($attempted) {
        return;
    }

    $attempted = true;

    if (!function_exists('epassport_realtime_config')) {
        require_once __DIR__ . '/realtime_config.php';
    }

    $config = epassport_realtime_config();

    if (empty($config['enabled']) || empty($config['autostart_websocket'])) {
        return;
    }

    $port = (int) ($config['ws_port'] ?? 8081);

    if ($port <= 0) {
        return;
    }

    if (epassport_websocket_port_open($port)) {
        return;
    }

    $stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epassport_ws_autostart.json';
    $now = time();
    $state = epassport_websocket_autostart_state($stateFile);

    if (($now - (int) ($state['last_attempt'] ?? 0)) < 20) {
        return;
    }

    epassport_websocket_autostart_state_write($stateFile, ['last_attempt' => $now]);
    epassport_websocket_spawn($port);
}

function epassport_websocket_port_open(int $port): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

    if (!is_resource($fp)) {
        return false;
    }

    fclose($fp);

    return true;
}

function epassport_websocket_autostart_state(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

function epassport_websocket_autostart_state_write(string $file, array $state): void
{
    @file_put_contents($file, json_encode($state), LOCK_EX);
}

function epassport_find_node_binary(): ?string
{
    $env = getenv('EPASSPORT_NODE');

    if (is_string($env) && $env !== '' && is_file($env)) {
        return $env;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $lines = [];
        @exec('where node 2>NUL', $lines);

        if (!empty($lines[0]) && is_file($lines[0])) {
            return $lines[0];
        }

        foreach ([
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    $which = trim((string) @shell_exec('command -v node 2>/dev/null'));

    if ($which !== '' && is_file($which)) {
        return $which;
    }

    return null;
}

function epassport_websocket_spawn(int $port): void
{
    $realtimeDir = realpath(__DIR__ . '/../realtime');

    if ($realtimeDir === false) {
        return;
    }

    $serverJs = $realtimeDir . DIRECTORY_SEPARATOR . 'server.js';
    $modules = $realtimeDir . DIRECTORY_SEPARATOR . 'node_modules';

    if (!is_file($serverJs) || !is_dir($modules)) {
        return;
    }

    $node = epassport_find_node_binary();

    if ($node === null) {
        return;
    }

    if (!function_exists('proc_open') && !function_exists('popen')) {
        return;
    }

    $env = [
        'EPASSPORT_WS_PORT' => (string) $port,
        'EPASSPORT_WS_HOST' => '0.0.0.0',
    ];

    $log = $realtimeDir . DIRECTORY_SEPARATOR . 'websocket.log';

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'cmd.exe /c start /B "" '
            . escapeshellarg($node) . ' '
            . escapeshellarg($serverJs);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $proc = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $realtimeDir,
            $env + $_ENV
        );

        if (is_resource($proc)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            @proc_close($proc);
        }

        return;
    }

    $cmd = 'nohup ' . escapeshellarg($node) . ' ' . escapeshellarg($serverJs)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    @exec('cd ' . escapeshellarg($realtimeDir) . ' && ' . $cmd);
}
