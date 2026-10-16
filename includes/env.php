<?php

/**
 * Load project-root .env into putenv / $_ENV without overwriting real environment variables.
 */
function epassport_load_env(?string $file = null): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = $file ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $eq = strpos($line, '=');

        if ($eq === false || $eq === 0) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $existing = '';
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
            $existing = trim((string) $_ENV[$key]);
        } elseif (getenv($key) !== false) {
            $existing = trim((string) getenv($key));
        }

        // Empty Apache/Windows env vars must not block values from .env.
        if ($existing !== '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function epassport_env(string $key, string $default = ''): string
{
    epassport_load_env();

    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
        $fromEnvBag = trim((string) $_ENV[$key]);
        if ($fromEnvBag !== '') {
            return (string) $_ENV[$key];
        }
    }

    $fromEnv = getenv($key);

    if ($fromEnv !== false && trim($fromEnv) !== '') {
        return (string) $fromEnv;
    }

    return $default;
}

epassport_load_env();
