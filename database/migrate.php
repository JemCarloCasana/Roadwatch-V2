<?php
// RoadWatch one-command setup: php database/migrate.php [--seed-only|--no-seed]
// Also safe to open in browser: /Roadwatch-V2/database/migrate.php
// Creates the database if missing, runs pending migrations, then idempotent seeds.

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/migrator.php';

$cfg = roadwatch_db_config();
$isCli = PHP_SAPI === 'cli';
$args = $isCli ? ($argv ?? []) : [];
$seedOnly = in_array('--seed-only', $args, true);
$noSeed = in_array('--no-seed', $args, true);

function out(string $msg): void {
    if (PHP_SAPI === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

if (!function_exists('mysqli_connect')) {
    out('ERROR: mysqli extension is not enabled in this PHP. Enable extension=mysqli in php.ini.');
    exit(1);
}

// Step 1: connect without DB to create it if missing (fresh clone case).
$server = mysqli_connect($cfg['host'], $cfg['user'], $cfg['pass'], '', (int) $cfg['port']);
if (!$server) {
    out('ERROR: cannot reach MySQL at ' . $cfg['host'] . ':' . $cfg['port'] . ' as ' . $cfg['user'] . '. Check .env (copy .env.example to .env). Details: ' . mysqli_connect_error());
    exit(1);
}
mysqli_set_charset($server, 'utf8mb4');
$dbName = '`' . str_replace('`', '``', $cfg['name']) . '`';
if (!mysqli_query($server, "CREATE DATABASE IF NOT EXISTS {$dbName} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    out('ERROR: cannot create database ' . $cfg['name'] . ': ' . mysqli_error($server));
    exit(1);
}
mysqli_close($server);

// Step 2: connect to the DB and migrate.
$conn = mysqli_connect($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], (int) $cfg['port']);
if (!$conn) {
    out('ERROR: database created but connect failed: ' . mysqli_connect_error());
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

if ($seedOnly) {
    // Seeds-only mode (reference data refresh without new migrations).
    // Repair columns first: same guarantee as the normal path, otherwise a
    // legacy DB with a dropped coordinate column fails the sanity check below.
    require_once $projectRoot . '/includes/migrator.php';
    try {
        roadwatch_repair_columns($conn);
    } catch (Throwable $e) {
        out('FAIL: repair_columns: ' . $e->getMessage());
        exit(1);
    }
    $applied = roadwatch_applied_versions($conn);
    $result = ['migrated' => [], 'skipped' => array_keys($applied), 'seeded' => [], 'errors' => []];
    $seedFiles = glob($projectRoot . '/database/seeds/*.sql') ?: [];
    sort($seedFiles);
    foreach ($seedFiles as $file) {
        $v = basename($file, '.sql');
        $r = roadwatch_apply_sql_file($conn, $file);
        if (!$r['ok']) {
            $result['errors'][] = "seed {$v}: {$r['error']}";
            break;
        }
        $result['seeded'][] = $v;
    }
} elseif ($noSeed) {
    $result = roadwatch_migrate($conn, $projectRoot, false);
    $result['seeded'] = ['skipped (--no-seed)'];
} else {
    $result = roadwatch_migrate($conn, $projectRoot);
}

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $e) {
        out('FAIL: ' . $e);
    }
    out('Migration stopped. Fix the SQL above, then re-run: php database/migrate.php');
    exit(1);
}

out('Database: ' . $cfg['name'] . ' @ ' . $cfg['host']);
out('Migrated: ' . (empty($result['migrated']) ? '(none pending)' : implode(', ', $result['migrated'])));
out('Skipped: ' . (empty($result['skipped']) ? '(none)' : implode(', ', $result['skipped'])));
out('Seeded: ' . (empty($result['seeded']) ? '(none)' : implode(', ', $result['seeded'])));

// Step 3: quick sanity check so clones fail loudly instead of at index.php:81.
$checks = [
    'reports.latitude' => "SHOW COLUMNS FROM `reports` LIKE 'latitude'",
    'reports.longitude' => "SHOW COLUMNS FROM `reports` LIKE 'longitude'",
    'barangays.latitude' => "SHOW COLUMNS FROM `barangays` LIKE 'latitude'",
    // 003 sentinels: a half-applied reconciliation fails fast here.
    'reports.updated_at' => "SHOW COLUMNS FROM `reports` LIKE 'updated_at'",
    'reports.reviewed_at' => "SHOW COLUMNS FROM `reports` LIKE 'reviewed_at'",
    'users.updated_at' => "SHOW COLUMNS FROM `users` LIKE 'updated_at'",
    'users.must_change_password' => "SHOW COLUMNS FROM `users` LIKE 'must_change_password'",
    'report_photos.mime_type' => "SHOW COLUMNS FROM `report_photos` LIKE 'mime_type'",
    'report_photos.file_size' => "SHOW COLUMNS FROM `report_photos` LIKE 'file_size'",
    'report_photos.created_at' => "SHOW COLUMNS FROM `report_photos` LIKE 'created_at'",
    'report_photos.sort_order' => "SHOW COLUMNS FROM `report_photos` LIKE 'sort_order'",
];
foreach ($checks as $label => $q) {
    $r = mysqli_query($conn, $q);
    $ok = $r && mysqli_num_rows($r) > 0;
    out(($ok ? 'OK: ' : 'MISSING: ') . $label);
    if (!$ok) {
        exit(1);
    }
}
$counts = [];
foreach (['barangays', 'hazard_types', 'users', 'reports', 'report_photos'] as $t) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `{$t}`");
    $counts[] = "{$t}=" . ($r ? mysqli_fetch_assoc($r)['n'] : '?');
}
out('Rows: ' . implode(' ', $counts));
// Step 4: default-credential warning (seed passwords are public in this repo).
// Clears when each default account rotates (must_change_password=0) or is removed.
$warnDefaults = ['superadmin@roadwatch.local', 'admin@roadwatch.local', 'user@roadwatch.local'];
$esc = array_map(static fn($e) => "'" . mysqli_real_escape_string($conn, $e) . "'", $warnDefaults);
$hasFlag = (bool)mysqli_query($conn, "SHOW COLUMNS FROM `users` LIKE 'must_change_password'");
if ($hasFlag) {
    $r = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `users` WHERE `email` IN (" . implode(',', $esc) . ") AND `must_change_password`=1");
    $n = $r ? (int)mysqli_fetch_assoc($r)['n'] : 0;
    if ($n > 0) {
        out("WARNING: {$n} default account(s) still use repo-shipped passwords. Rotate now: change password + set must_change_password=0.");
    }
}
out('Done. Open index.php — no manual SQL import needed.');
