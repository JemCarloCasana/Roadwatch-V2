<?php
// RoadWatch central config. Loads .env (project root) with XAMPP-friendly defaults.
// No composer dependency: minimal KEY=VALUE parser.

function roadwatch_project_root(): string {
    return dirname(__DIR__);
}

function roadwatch_load_env_once(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $envFile = roadwatch_project_root() . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // Allow optional `export KEY=VALUE`
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Strip matching single/double quotes
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key === '' || array_key_exists($key, $_ENV)) {
            continue;
        }
        $_ENV[$key] = $value;
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}

function roadwatch_env(string $key, string $default = ''): string {
    roadwatch_load_env_once();
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    $fromServer = getenv($key);
    if ($fromServer !== false) {
        return (string) $fromServer;
    }
    return $default;
}

function roadwatch_db_config(): array {
    return [
        'host' => roadwatch_env('DB_HOST', 'localhost'),
        'port' => (int) roadwatch_env('DB_PORT', '3306'),
        'user' => roadwatch_env('DB_USER', 'root'),
        'pass' => roadwatch_env('DB_PASS', ''),
        'name' => roadwatch_env('DB_NAME', 'roadwatch_db'),
    ];
}
