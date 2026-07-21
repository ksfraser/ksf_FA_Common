<?php
/**
 * KSF FA Module Schema Migration Runner
 *
 * Scans a module's sql/ directory for upgrade_*.sql files and applies any
 * that haven't been recorded in the ksf_schema_migrations tracking table.
 *
 * All KSF modules inherit this — no hooks.php changes needed per migration.
 * Trigger from each module's admin page (e.g. cal.php?view=migrations).
 *
 * @package KsfCommon\Utils
 */

namespace KsfCommon\Utils;

class SchemaMigrationRunner
{
    private const TRACKING_TABLE = 'ksf_schema_migrations';

    private $moduleDir;
    private $moduleName = 'unknown';
    private $prefix;

    public function __construct(string $moduleDir)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
        $this->prefix = defined('TB_PREF') ? TB_PREF : '0_';
    }

    public function setModuleName(string $name): void
    {
        $this->moduleName = $name;
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    /**
     * Ensure the tracking table exists.
     */
    public function ensureTrackingTable(): bool
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->prefix}" . self::TRACKING_TABLE . "` (
            `module`     VARCHAR(100) NOT NULL,
            `filename`   VARCHAR(200) NOT NULL,
            `checksum`   VARCHAR(64)  NOT NULL DEFAULT '',
            `applied_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`module`, `filename`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $result = db_query($sql, 'Could not create migration tracking table');
        return (bool) $result;
    }

    /**
     * Find all upgrade_*.sql files in the sql/ directory, sorted by version.
     *
     * @return array{filename: string, path: string, checksum: string}[]
     */
    public function findMigrationFiles(): array
    {
        $sqlDir = $this->moduleDir . '/sql';
        if (!is_dir($sqlDir)) {
            return [];
        }

        $files = glob($sqlDir . '/upgrade_*.sql');
        if ($files === false || empty($files)) {
            return [];
        }

        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $migrations[] = [
                'filename' => $filename,
                'path'     => $path,
                'checksum' => md5_file($path) ?: '',
            ];
        }

        return $migrations;
    }

    /**
     * Get already-applied migrations from the tracking table.
     *
     * @return array<string, array{checksum: string, applied_at: string}>  filename => info
     */
    public function getAppliedMigrations(): array
    {
        $sql = "SELECT filename, checksum, applied_at FROM `{$this->prefix}" . self::TRACKING_TABLE . "`
                WHERE module = '" . db_escape($this->moduleName) . "'";

        $result = db_query($sql, 'Could not query migration tracking table');
        if (!$result) {
            return [];
        }

        $applied = [];
        while ($row = db_fetch_assoc($result)) {
            $applied[$row['filename']] = [
                'checksum'   => (string) ($row['checksum'] ?? ''),
                'applied_at' => (string) ($row['applied_at'] ?? ''),
            ];
        }

        return $applied;
    }

    /**
     * Run all pending (unapplied or checksum-changed) migrations.
     *
     * @return array{applied: int, skipped: int, errors: array}
     */
    public function runPending(): array
    {
        $this->ensureTrackingTable();

        $migrations = $this->findMigrationFiles();
        $applied    = $this->getAppliedMigrations();

        $result = [
            'applied' => 0,
            'skipped' => 0,
            'errors'  => [],
        ];

        foreach ($migrations as $mig) {
            $filename = $mig['filename'];

            if (isset($applied[$filename])) {
                if ($applied[$filename]['checksum'] === $mig['checksum']) {
                    $result['skipped']++;
                    continue;
                }
            }

            $sql = file_get_contents($mig['path']);
            if ($sql === false || trim($sql) === '') {
                $result['errors'][] = "Empty or unreadable: {$filename}";
                continue;
            }

            $statements = $this->splitSqlStatements($sql);
            $stmtOk = true;

            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }
                $qResult = db_query($stmt, "Migration {$filename} failed");
                if (!$qResult) {
                    $stmtOk = false;
                    $result['errors'][] = "Statement failed in {$filename}: " . substr($stmt, 0, 120);
                    break;
                }
            }

            if ($stmtOk) {
                $this->recordMigration($filename, $mig['checksum']);
                $result['applied']++;
            }
        }

        return $result;
    }

    private function recordMigration(string $filename, string $checksum): void
    {
        $sql = "INSERT INTO `{$this->prefix}" . self::TRACKING_TABLE . "`
                (module, filename, checksum, applied_at)
                VALUES (
                    '" . db_escape($this->moduleName) . "',
                    '" . db_escape($filename) . "',
                    '" . db_escape($checksum) . "',
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    checksum   = VALUES(checksum),
                    applied_at = NOW()";

        db_query($sql, 'Could not record migration');
    }

    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $parts = explode(';', $sql);
        return array_map('trim', $parts);
    }
}
