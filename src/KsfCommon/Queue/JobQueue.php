<?php

declare(strict_types=1);

namespace KsfCommon\Queue;

/**
 * Job Queue — background task processing for the ksf_FA ecosystem.
 *
 * Modules enqueue jobs via createJob() (non-blocking). A cron script
 * processes pending jobs by dispatching a 'job_<type>' hook for each.
 *
 * Job types are handled by any module that implements a matching
 * hook method in its hooks class (e.g. 'job_send_welcome_email').
 *
 * @package KsfCommon\Queue
 * @since 1.0.0
 *
 * @startuml
 *   skinparam packageStyle rectangle
 *   rectangle "FA hook_invoke_first" as hooks
 *   rectangle "JobQueue" as queue
 *   rectangle "Cron" as cron
 *   rectangle "Handler Modules" as handlers
 *
 *   queue --> hooks : createJob() inserts
 *   cron --> queue : processJobs() fetches pending
 *   cron --> hooks : hook_invoke_first('job_<type>', payload)
 *   hooks --> handlers : method 'job_<type>' on hooks class
 *   handlers --> queue : returns success/failure
 *   queue --> cron : updates status
 * @enduml
 */
class JobQueue
{
    private const TABLE = 'fa_job_queue';

    /**
     * Enqueue a background job.
     *
     * @param string      $type         Job type identifier (e.g. 'send_email', 'assign_sales_rep')
     * @param array       $payload      Job data (serialized as JSON in DB)
     * @param int         $priority     Higher = more urgent (default 0)
     * @param string|null $scheduledAt  ISO datetime for delayed execution (null = immediate)
     * @return int                      Job ID
     */
    public static function createJob(string $type, array $payload = [], int $priority = 0, ?string $scheduledAt = null): int
    {
        $pref = defined('TB_PREF') ? TB_PREF : '';
        $table = $pref . self::TABLE;

        $sql = "INSERT INTO {$table}
            (job_type, payload, priority, scheduled_at)
            VALUES (
                " . self::dbEscape($type) . ",
                " . self::dbEscape(json_encode($payload)) . ",
                " . (int) $priority . ",
                " . ($scheduledAt ? self::dbEscape($scheduledAt) : 'NULL') . "
            )";

        self::dbQuery($sql, 'Could not enqueue job');
        return (int) self::dbInsertId();
    }

    /**
     * Process the next batch of pending jobs.
     *
     * Called by cron (e.g. every minute). For each pending job:
     * 1. Mark as 'processing'
     * 2. Dispatch hook_invoke_first('job_<type>', payload)
     * 3. Mark 'completed' on success, 'failed' on exception
     *
     * @param int $limit  Max jobs per run
     * @return array      ['processed' => int, 'failed' => int, 'errors' => array]
     */
    public static function processJobs(int $limit = 10): array
    {
        $pref = defined('TB_PREF') ? TB_PREF : '';
        $table = $pref . self::TABLE;

        $results = ['processed' => 0, 'failed' => 0, 'errors' => []];

        // Fetch pending jobs
        $sql = "SELECT * FROM {$table}
            WHERE status = 'pending'
            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ORDER BY priority DESC, created_at ASC
            LIMIT " . (int) $limit;

        $result = self::dbQuery($sql, 'Could not fetch pending jobs');
        $jobs = [];
        while ($row = self::dbFetch($result)) {
            $jobs[] = $row;
        }

        foreach ($jobs as $job) {
            // Mark as processing
            $updateSql = "UPDATE {$table} SET status = 'processing',
                attempts = attempts + 1
                WHERE id = " . (int) $job['id'] . " AND status = 'pending'";
            self::dbQuery($updateSql, 'Could not lock job');

            try {
                $payload = isset($job['payload']) ? json_decode($job['payload'], true) ?? [] : [];

                // Dispatch job_<type> hook — any module can handle it
                if (function_exists('hook_invoke_first')) {
                    $hookResult = hook_invoke_first('job_' . $job['job_type'], $payload);
                }

                // Mark completed
                $doneSql = "UPDATE {$table} SET status = 'completed',
                    processed_at = NOW(),
                    error_message = NULL
                    WHERE id = " . (int) $job['id'] . " AND status = 'processing'";
                self::dbQuery($doneSql, 'Could not complete job');
                $results['processed']++;

            } catch (\Throwable $e) {
                $maxAttempts = (int) ($job['max_attempts'] ?? 3);
                $attempts = (int) ($job['attempts'] ?? 0);
                $newStatus = $attempts >= $maxAttempts ? 'failed' : 'pending';

                $failSql = "UPDATE {$table} SET status = " . self::dbEscape($newStatus) . ",
                    error_message = " . self::dbEscape($e->getMessage()) . ",
                    processed_at = " . ($newStatus === 'failed' ? 'NOW()' : 'NULL') . "
                    WHERE id = " . (int) $job['id'] . " AND status = 'processing'";
                self::dbQuery($failSql, 'Could not fail job');

                $results['failed']++;
                $results['errors'][] = [
                    'job_id'  => (int) $job['id'],
                    'type'    => $job['job_type'],
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get queue statistics.
     */
    public static function getStats(): array
    {
        $pref = defined('TB_PREF') ? TB_PREF : '';
        $table = $pref . self::TABLE;

        $sql = "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status";
        $result = self::dbQuery($sql, 'Could not get queue stats');

        $stats = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        while ($row = self::dbFetch($result)) {
            $stats[$row['status']] = (int) $row['cnt'];
        }
        return $stats;
    }

    // ---- DB helpers (same pattern as FA's db_* functions) ----

    private static function dbQuery(string $sql, string $msg)
    {
        if (function_exists('db_query')) {
            return db_query($sql, $msg);
        }
        throw new \RuntimeException('FA db_query not available');
    }

    private static function dbFetch($result)
    {
        if (function_exists('db_fetch')) {
            return db_fetch($result);
        }
        return false;
    }

    private static function dbInsertId(): int
    {
        if (function_exists('db_insert_id')) {
            return (int) db_insert_id();
        }
        return 0;
    }

    private static function dbEscape(string $value): string
    {
        if (function_exists('db_escape')) {
            return db_escape($value);
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
