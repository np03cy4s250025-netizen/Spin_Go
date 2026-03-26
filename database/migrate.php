<?php
/**
 * database/migrate.php — SpinGo migration runner
 *
 * Usage (CLI only):   php database/migrate.php
 *
 * – Reads all .sql files from database/migrations/ in numeric order.
 * – Skips already-applied migrations recorded in schema_versions.
 * – Records each successful migration.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Migration runner must be executed via CLI only.\n");
}

require_once __DIR__ . '/../backend/config/db.php';

// Ensure tracking table exists (bootstraps itself on first run)
$conn->exec("
    CREATE TABLE IF NOT EXISTS `schema_versions` (
        `id`         INT(11)      NOT NULL AUTO_INCREMENT,
        `version`    VARCHAR(100) NOT NULL,
        `applied_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_version` (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Load applied versions
$appliedStmt = $conn->query("SELECT version FROM schema_versions");
$applied     = $appliedStmt->fetchAll(PDO::FETCH_COLUMN);

// Discover migration files
$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);   // numeric order guaranteed by filename prefix

if (empty($files)) {
    echo "No migration files found in {$migrationsDir}.\n";
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $version = pathinfo($file, PATHINFO_FILENAME);

    if (in_array($version, $applied, true)) {
        echo "[SKIP]    {$version}\n";
        continue;
    }

    $sql = file_get_contents($file);

    try {
        $conn->exec($sql);

        $record = $conn->prepare("INSERT INTO schema_versions (version) VALUES (?)");
        $record->execute([$version]);

        echo "[APPLIED] {$version}\n";
        $ran++;
    } catch (PDOException $e) {
        $errorCode = isset($e->errorInfo[1]) ? $e->errorInfo[1] : 0;
        // 1050: Table already exists, 1060: Duplicate column name, 1061: Duplicate key name, 1062: Duplicate entry, 1091: Can't drop column/key
        if (in_array($errorCode, [1050, 1060, 1061, 1062, 1091])) {
            echo "[WARN]    {$version} encountered error {$errorCode}: " . $e->getMessage() . "\n";
            echo "[INFO]    Registering {$version} as applied and continuing.\n";
            try {
                $record = $conn->prepare("INSERT IGNORE INTO schema_versions (version) VALUES (?)");
                $record->execute([$version]);
                $ran++;
            } catch (PDOException $ex) {
                echo "[ERROR]   Failed to record version {$version} after warning: " . $ex->getMessage() . "\n";
                exit(1);
            }
        } else {
            echo "[ERROR]   {$version}: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

echo $ran > 0
    ? "\nDone — {$ran} migration(s) applied.\n"
    : "\nAll migrations already up to date.\n";
