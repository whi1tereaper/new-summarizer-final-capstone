<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once __DIR__ . '/../app/src/Support/config.php';
require_once __DIR__ . '/../app/src/Database.php';

$options = getopt('', ['status', 'baseline', 'dry-run', 'allow-destructive', 'help']);
$statusOnly = isset($options['status']);
$dryRun = isset($options['dry-run']);
$allowDestructive = isset($options['allow-destructive']);

if (isset($options['help'])) {
    echo "AI Summarizer Migration Runner\n";
    echo "Usage:\n";
    echo "  php tools/migrate.php          Apply pending migrations\n";
    echo "  php tools/migrate.php --status Show current migration status\n";
    echo "  php tools/migrate.php --dry-run  Show the plan without executing SQL\n";
    echo "  php tools/migrate.php --allow-destructive  Explicitly permit destructive SQL\n";
    exit(0);
}

try {
    $database = \App\Src\Database::getInstance();
    if ($database->getDriver() !== 'mysql') {
        throw new RuntimeException('This migration runner is MySQL-only. Use database/schema.postgres.sql and the PostgreSQL importer for PostgreSQL.');
    }
    $db = $database->getConnection();

    $migrationsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    if (!is_dir($migrationsDir)) {
        fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
        exit(1);
    }

    $allFiles = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
    if ($allFiles === false) {
        $allFiles = [];
    }
    sort($allFiles, SORT_NATURAL);

    $appliedMap = [];
    $ledgerExists = true;
    try {
        $appliedStmt = $db->query("SELECT migration FROM schema_migrations");
        $appliedMap = array_fill_keys($appliedStmt->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (PDOException) {
        $ledgerExists = false;
    }

    // --status and --dry-run are read-only with respect to the migration
    // ledger. A normal apply creates the ledger only when it is needed.
    if (!$ledgerExists && !$statusOnly && !$dryRun) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $appliedStmt = $db->query("SELECT migration FROM schema_migrations");
        $appliedMap = array_fill_keys($appliedStmt->fetchAll(PDO::FETCH_COLUMN), true);
    }

    // Auto-baseline detection for existing databases that were migrated before schema_migrations ledger existed
    if (empty($appliedMap)) {
        $tables = array_map('strtolower', $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
        $tablesMap = array_fill_keys($tables, true);

        $recordBaseline = function (string $migrationName) use ($db, &$appliedMap, $statusOnly, $dryRun): void {
            if (!$statusOnly && !$dryRun) {
                $stmt = $db->prepare("INSERT IGNORE INTO schema_migrations (migration, applied_at) VALUES (?, NOW())");
                $stmt->execute([$migrationName]);
            }
            $appliedMap[$migrationName] = true;
        };

        if (isset($tablesMap['summary_artifacts'])) {
            $recordBaseline('001_normalize_summaries.sql');
        }
        if (isset($tablesMap['admin_security_challenges'])) {
            $recordBaseline('002_admin_security_challenges.sql');
        }
        if (isset($tablesMap['admin_audit_logs'])) {
            $recordBaseline('003_admin_audit_logs.sql');
        }
        if (isset($tablesMap['summaries'])) {
            $summaryCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM summaries")->fetchAll(PDO::FETCH_COLUMN));
            $summaryColsMap = array_fill_keys($summaryCols, true);
            if (isset($summaryColsMap['summary_length'])) {
                $recordBaseline('004_summary_analytics.sql');
            }
            if (isset($summaryColsMap['nutshell_text'])) {
                $recordBaseline('005_nutshell_analytics.sql');
            }
        }
        if (isset($tablesMap['nutshell_generations'])) {
            $recordBaseline('006_nutshell_generations.sql');
            $recordBaseline('007_history_contracts.sql');
        }
        if (isset($tablesMap['users'])) {
            $userCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN));
            if (in_array('terms_accepted', $userCols, true)) {
                $recordBaseline('008_terms_acceptance.sql');
            }
        }
        if (isset($tablesMap['landing_page_sessions'])) {
            $lpsCols = array_map('strtolower', $db->query("SHOW COLUMNS FROM landing_page_sessions")->fetchAll(PDO::FETCH_COLUMN));
            if (in_array('inp_ms', $lpsCols, true)) {
                $recordBaseline('009_landing_inp.sql');
            }
        }
        if (isset($tablesMap['user_tokens'])) {
            $recordBaseline('010_user_tokens.sql');
        }
    }

    $allPending = [];
    foreach ($allFiles as $filePath) {
        $name = basename($filePath);
        if (isset($appliedMap[$name])) {
            continue;
        }

        $sql = file_get_contents($filePath);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException("Migration file is empty or unreadable: {$name}");
        }

        $destructiveReason = null;
        foreach ([
            '/\\bDROP\\s+(?:COLUMN|TABLE|DATABASE|INDEX)\\b/i' => 'DROP operation',
            '/\\bTRUNCATE(?:\\s+TABLE)?\\b/i' => 'TRUNCATE operation',
            '/\\bDELETE\\s+FROM\\b/i' => 'DELETE operation',
        ] as $pattern => $reason) {
            if (preg_match($pattern, $sql) === 1) {
                $destructiveReason = $reason;
                break;
            }
        }

        $allPending[] = [
            'path' => $filePath,
            'name' => $name,
            'sql' => $sql,
            'destructive_reason' => $destructiveReason,
        ];
    }

    if ($statusOnly) {
        echo "====================================================\n";
        echo "DATABASE MIGRATION STATUS\n";
        echo "====================================================\n";
        foreach ($allFiles as $filePath) {
            $name = basename($filePath);
            $isApplied = isset($appliedMap[$name]);
            $pending = null;
            foreach ($allPending as $candidate) {
                if ($candidate['name'] === $name) {
                    $pending = $candidate;
                    break;
                }
            }
            $statusText = $isApplied
                ? "[APPLIED]"
                : (($pending['destructive_reason'] ?? null) !== null && !$allowDestructive
                    ? "[BLOCKED: destructive SQL]"
                    : "[PENDING]");
            echo sprintf(" %-10s %s\n", $statusText, $name);
        }
        echo "====================================================\n";
        exit(0);
    }

    if ($dryRun) {
        echo "Migration plan (dry run; no SQL executed):\n";
        foreach ($allPending as $migration) {
            $warning = $migration['destructive_reason'] !== null ? " [destructive: {$migration['destructive_reason']}]" : '';
            echo "  PENDING: {$migration['name']}{$warning}\n";
        }
        if ($allPending === []) {
            echo "  No pending migrations. Database is up to date.\n";
        }
        exit(0);
    }

    $blocked = array_values(array_filter($allPending, static fn (array $migration): bool => $migration['destructive_reason'] !== null));
    if (!$allowDestructive && $blocked !== []) {
        fwrite(STDERR, "Migration plan blocked: destructive SQL is present. No migration was executed.\n");
        foreach ($blocked as $migration) {
            fwrite(STDERR, "  BLOCKED: {$migration['name']} ({$migration['destructive_reason']})\n");
        }
        fwrite(STDERR, "Review the database backup and run again with --allow-destructive only after explicit approval.\n");
        exit(2);
    }

    $appliedCount = 0;
    foreach ($allPending as $migration) {
        $name = $migration['name'];
        $sql = $migration['sql'];

        echo "Applying {$name}...\n";

        try {
            $db->exec($sql);
            $insStmt = $db->prepare("INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())");
            $insStmt->execute([$name]);
            $appliedMap[$name] = true;
            $appliedCount++;
            echo "  PASS: Successfully applied {$name}\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, "  FAIL: Migration {$name} failed: " . $e->getMessage() . "\n");
            fwrite(STDERR, "Execution stopped. Database state requires manual inspection.\n");
            exit(1);
        }
    }

    if ($appliedCount === 0) {
        echo "No pending migrations. Database is up to date.\n";
    } else {
        echo "Successfully applied {$appliedCount} migration(s).\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration runner error: " . $e->getMessage() . "\n");
    exit(1);
}
