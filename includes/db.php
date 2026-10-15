<?php
// RoadWatch DB bootstrap. Reads .env via includes/config.php (falls back to XAMPP defaults),
// creates the database on fresh clones, and auto-applies pending migrations so
// index.php never fatals with "Unknown column" after git clone.
require_once __DIR__ . '/config.php';

$cfg = roadwatch_db_config();
$host = $cfg['host'];
$username = $cfg['user'];
$password = $cfg['pass'];
$database = $cfg['name'];
$port = (int) $cfg['port'];

function roadwatch_db_fatal(string $hint, string $detail = ''): void {
    $msg = 'Database connection failed. ' . $hint;
    if ($detail !== '') {
        $msg .= ' Details: ' . $detail;
    }
    $msg .= ' (config: ' . htmlspecialchars(roadwatch_env('DB_HOST', 'localhost')) . ' / '
        . htmlspecialchars(roadwatch_env('DB_NAME', 'roadwatch_db')) . ' — copy .env.example to .env to change, then run: php database/migrate.php)';
    die($msg);
}

if (!function_exists('mysqli_connect')) {
    roadwatch_db_fatal('PHP mysqli extension is disabled. Enable extension=mysqli in php.ini and restart Apache.');
}

// Fresh-clone case: database may not exist yet. Connect without DB and create it.
$bootstrap = @mysqli_connect($host, $username, $password, '', $port);
if (!$bootstrap) {
    roadwatch_db_fatal('Cannot reach MySQL. Is XAMPP MySQL running?', (string) mysqli_connect_error());
}
@mysqli_query($bootstrap, 'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
@mysqli_close($bootstrap);

$conn = @mysqli_connect($host, $username, $password, $database, $port);
if (!$conn) {
    // 1049 = unknown database (race with create above), 1045 = access denied (wrong .env creds)
    roadwatch_db_fatal('Check DB_HOST/DB_USER/DB_PASS/DB_NAME in .env.', (string) mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');

// Seamless auto-migrate: only runs work when something is pending, otherwise cheap checks.
try {
    require_once __DIR__ . '/migrator.php';
    $projectRoot = dirname(__DIR__);
    $applied = roadwatch_applied_versions($conn);
    $pending = [];
    $files = glob($projectRoot . '/database/migrations/*.sql') ?: [];
    sort($files);
    foreach ($files as $f) {
        $v = basename($f, '.sql');
        if (!isset($applied[$v])) {
            $pending[] = $v;
        }
    }
    $needsSeed = false;
    $needsRepair = false;
    if (empty($pending)) {
        // Fresh DB edge: migrations recorded but seeds never ran (empty reference tables).
        $r = @mysqli_query($conn, 'SELECT COUNT(*) AS n FROM `barangays`');
        if ($r) {
            $needsSeed = ((int) mysqli_fetch_assoc($r)['n'] === 0);
            mysqli_free_result($r);
        } else {
            $needsSeed = true; // barangays table itself missing -> full migrate
            $pending[] = 'missing-tables';
        }
        // Legacy/manual-DROP edge: 002 marked applied but columns gone (repair is cheap).
        if (empty($pending)) {
            $needsRepair = !roadwatch_column_exists($conn, 'reports', 'latitude')
                || !roadwatch_column_exists($conn, 'reports', 'longitude')
                || !roadwatch_column_exists($conn, 'barangays', 'latitude')
                || !roadwatch_column_exists($conn, 'barangays', 'longitude');
        }
    }
    if (!empty($pending) || $needsSeed || $needsRepair) {
        $result = roadwatch_migrate($conn, $projectRoot);
        if (!empty($result['errors'])) {
            roadwatch_db_fatal('Auto-migration failed. Run manually for details: php database/migrate.php — ' . implode(' | ', $result['errors']));
        }
    }
} catch (Throwable $e) {
    roadwatch_db_fatal('Auto-migration crashed. Run manually: php database/migrate.php — ' . $e->getMessage());
}
