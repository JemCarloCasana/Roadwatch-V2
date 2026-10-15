<?php
// RoadWatch file-based migrator. No dependencies.
// Migrations: database/migrations/*.sql (tracked in schema_migrations).
// Seeds: database/seeds/*.sql (idempotent INSERT IGNORE, re-run every time).
require_once __DIR__ . '/config.php';

function roadwatch_split_sql(string $sql): array {
    // Strip -- line comments and # comments, then split on semicolon + newline.
    // Migration files are controlled (no stored procedures), so this is safe.
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $cleaned = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        // Remove inline -- comments only when preceded by whitespace (avoid URLs like https://)
        $cleaned[] = preg_replace('/\s--.*$/', '', $line);
    }
    $normalized = implode("\n", $cleaned);
    // Remove /* ... */ blocks
    $normalized = preg_replace('#/\*.*?\*/#s', '', $normalized);
    $parts = preg_split('/;\s*\n/', $normalized);
    $statements = [];
    foreach ($parts as $part) {
        $stmt = trim($part);
        if ($stmt === '' || $stmt === ';') {
            continue;
        }
        $statements[] = $stmt;
    }
    return $statements;
}

function roadwatch_applied_versions(mysqli $conn): array {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `version` VARCHAR(50) NOT NULL PRIMARY KEY,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $applied = [];
    $res = mysqli_query($conn, "SELECT `version` FROM `schema_migrations`");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $applied[$row['version']] = true;
        }
        mysqli_free_result($res);
    }
    return $applied;
}

/** Run one SQL file statement-by-statement. Duplicate-column/key errors are treated as no-ops for idempotency. */
function roadwatch_apply_sql_file(mysqli $conn, string $file): array {
    $sql = file_get_contents($file);
    if ($sql === false) {
        return ['ok' => false, 'error' => 'Unreadable file'];
    }
    foreach (roadwatch_split_sql($sql) as $stmt) {
        try {
            $ok = mysqli_query($conn, $stmt);
        } catch (mysqli_sql_exception $e) {
            $ok = false;
            $errno = $e->getCode();
            $err = $e->getMessage();
            // 1060 duplicate column, 1061 duplicate key, 1068 multiple PK, 1091 missing key/drop twice,
            // 1022 duplicate FK constraint (re-run safety)
            if (in_array((int) $errno, [1022, 1060, 1061, 1068, 1091], true)) {
                continue;
            }
            return ['ok' => false, 'error' => "MySQL #{$errno}: {$err} :: " . substr($stmt, 0, 160)];
        }
        if (!$ok) {
            $errno = mysqli_errno($conn);
            $err = mysqli_error($conn);
            // 1060 duplicate column, 1061 duplicate key, 1068 multiple PK, 1091 missing key/drop twice,
            // 1022 duplicate FK constraint (re-run safety)
            if (in_array($errno, [1022, 1060, 1061, 1068, 1091], true)) {
                continue;
            }
            return ['ok' => false, 'error' => "MySQL #{$errno}: {$err} :: " . substr($stmt, 0, 160)];
        }
    }
    return ['ok' => true, 'error' => ''];
}

function roadwatch_column_exists(mysqli $conn, string $table, string $column): bool {
    $t = str_replace('`', '``', $table);
    $c = str_replace("'", "''", $column);
    try {
        $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    } catch (mysqli_sql_exception $e) {
        return false;
    }
    if (!$res) {
        return false;
    }
    $exists = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $exists;
}

/** Belt-and-suspenders repair: ensures coordinate columns exist even if 002 was
 * marked applied before a manual DROP COLUMN. Safe to run always (1060 ignored). */
function roadwatch_repair_columns(mysqli $conn): void {
    $fixes = [
        ['barangays', 'latitude', 'ALTER TABLE `barangays` ADD COLUMN `latitude` DECIMAL(10,7) NULL'],
        ['barangays', 'longitude', 'ALTER TABLE `barangays` ADD COLUMN `longitude` DECIMAL(10,7) NULL'],
        ['reports', 'latitude', 'ALTER TABLE `reports` ADD COLUMN `latitude` DECIMAL(10,8) NULL DEFAULT NULL AFTER `landmark`'],
        ['reports', 'longitude', 'ALTER TABLE `reports` ADD COLUMN `longitude` DECIMAL(11,8) NULL DEFAULT NULL AFTER `latitude`'],
    ];
    foreach ($fixes as [$table, $column, $ddl]) {
        if (roadwatch_column_exists($conn, $table, $column)) {
            continue;
        }
        try {
            mysqli_query($conn, $ddl);
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1060) {
                throw $e;
            }
        }
    }
}

function roadwatch_migrate(mysqli $conn, string $projectRoot, bool $runSeeds = true): array {
    $result = ['migrated' => [], 'skipped' => [], 'seeded' => [], 'errors' => []];
    $applied = roadwatch_applied_versions($conn);

    $migrationFiles = glob($projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($migrationFiles);
    foreach ($migrationFiles as $file) {
        $version = basename($file, '.sql');
        if (isset($applied[$version])) {
            $result['skipped'][] = $version;
            continue;
        }
        $out = roadwatch_apply_sql_file($conn, $file);
        if (!$out['ok']) {
            $result['errors'][] = "{$version}: {$out['error']}";
            break; // stop on first failure to preserve order
        }
        $stmt = mysqli_prepare($conn, "INSERT INTO `schema_migrations` (`version`) VALUES (?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $version);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $result['migrated'][] = $version;
    }

    if (!empty($result['errors'])) {
        return $result;
    }

    try {
        roadwatch_repair_columns($conn);
    } catch (Throwable $e) {
        $result['errors'][] = 'repair_columns: ' . $e->getMessage();
        return $result;
    }

    if (!$runSeeds) {
        return $result;
    }

    $seedFiles = glob($projectRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($seedFiles);
    foreach ($seedFiles as $file) {
        $version = basename($file, '.sql');
        $out = roadwatch_apply_sql_file($conn, $file);
        if (!$out['ok']) {
            $result['errors'][] = "seed {$version}: {$out['error']}";
            break;
        }
        $result['seeded'][] = $version;
    }
    return $result;
}
