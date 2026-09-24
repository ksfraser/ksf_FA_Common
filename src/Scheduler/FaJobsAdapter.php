<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;

/**
 * FA runtime jobs adapter — native db_* calls only (no PDO, no raw mysqli).
 *
 * Implements the BR-COM-04 jobs registry + append-only run log on FA's tables
 * (0_ksf_wf_jobs / 0_ksf_wf_run_log, translated to TB_PREF at runtime). The
 * per-job lock maps to MySQL GET_LOCK()/RELEASE_LOCK() exactly as the BR
 * mandates, so two CLIs can never run the same job concurrently.
 *
 * Timestamps come from the injected clock — never NOW(). The clock is passed
 * per call: completeRun/logRun carry explicit DateTimeImmutable values.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class FaJobsAdapter implements JobsAdapterInterface
{
    private const TABLE_JOBS = 'ksf_wf_jobs';
    private const TABLE_RUNS = 'ksf_wf_run_log';

    public function listDue(DateTimeImmutable $now): array
    {
        $sql = 'SELECT *
                 FROM ' . $this->table(self::TABLE_JOBS) . '
                WHERE enabled=1 AND next_run_at IS NOT NULL AND next_run_at <= '
            . db_escape($this->stamp($now)) . '
                ORDER BY next_run_at ASC';
        $result = db_query($sql, 'Failed to list due jobs');
        $rows   = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getJob(int $jobId): ?array
    {
        $sql = 'SELECT * FROM ' . $this->table(self::TABLE_JOBS)
            . ' WHERE job_id=' . db_escape((string) $jobId);
        $result = db_query($sql, 'Failed to read job');
        if ($result && ($row = db_fetch_assoc($result))) {
            return $row;
        }
        return null;
    }

    public function registerJob(array $job): int
    {
        $sql = 'INSERT INTO ' . $this->table(self::TABLE_JOBS)
            . ' (module, job_key, job_type, interval_p, resolver, params, enabled, created_at, next_run_at)
             VALUES ('
            . db_escape((string) ($job['module'] ?? '')) . ', '
            . db_escape((string) ($job['job_key'] ?? '')) . ', '
            . db_escape((string) ($job['job_type'] ?? 'recurring')) . ', '
            . db_escape((string) ($job['interval_p'] ?? '')) . ', '
            . db_escape((string) ($job['resolver'] ?? '')) . ', '
            . db_escape($this->json($job['params'] ?? null)) . ', '
            . db_escape((string) ((int) ($job['enabled'] ?? 1))) . ', '
            . db_escape($this->stamp(new DateTimeImmutable('now'))) . ', '
            . db_escape($this->stamp($job['next_run_at'] ?? null)) . ')';
        db_query($sql, 'Failed to register job');
        return function_exists('db_insert_id') ? (int) db_insert_id() : 0;
    }

    public function acquireLock(string $lockKey): bool
    {
        $sql = 'SELECT GET_LOCK(' . db_escape($lockKey) . ', 0) AS acquired';
        $result = db_query($sql, 'Failed to acquire job lock');
        if ($result && ($row = db_fetch_assoc($result))) {
            return (int) ($row['acquired'] ?? 0) === 1;
        }
        return false;
    }

    public function releaseLock(string $lockKey): void
    {
        db_query(
            'SELECT RELEASE_LOCK(' . db_escape($lockKey) . ')',
            'Failed to release job lock'
        );
    }

    public function logRun(int $jobId, array $row): void
    {
        $sql = 'INSERT INTO ' . $this->table(self::TABLE_RUNS)
            . ' (job_id, started_at, finished_at, status, detail, source)
             VALUES ('
            . db_escape((string) $jobId) . ', '
            . db_escape($this->stamp($row['started_at'] ?? new DateTimeImmutable('now'))) . ', '
            . db_escape($this->stamp($row['finished_at'] ?? null)) . ', '
            . db_escape((string) ($row['status'] ?? '')) . ', '
            . db_escape($this->json($row['detail'] ?? null)) . ', '
            . db_escape((string) ($row['source'] ?? 'cli')) . ')';
        db_query($sql, 'Failed to log scheduler run');
    }

    public function completeRun(int $jobId, DateTimeImmutable $now, ?DateTimeImmutable $nextRunAt, ?string $lastError, bool $enabled): void
    {
        $failCountSql = $lastError !== null
            ? 'fail_count = fail_count + 1'
            : 'fail_count = 0';
        $sql = 'UPDATE ' . $this->table(self::TABLE_JOBS) . ' SET '
            . $failCountSql . ', '
            . 'last_error=' . db_escape($lastError) . ', '
            . 'last_run_at=' . db_escape($this->stamp($now)) . ', '
            . 'next_run_at=' . db_escape($this->stamp($nextRunAt)) . ', '
            . 'enabled=' . db_escape((string) (int) $enabled)
            . ' WHERE job_id=' . db_escape((string) $jobId);
        db_query($sql, 'Failed to complete scheduler run');
    }

    private function table(string $name): string
    {
        return \defined('TB_PREF') ? TB_PREF . $name : '0_' . $name;
    }

    private function stamp(?DateTimeImmutable $dt): ?string
    {
        return $dt === null ? null : $dt->format('Y-m-d H:i:s');
    }

    private function json($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}