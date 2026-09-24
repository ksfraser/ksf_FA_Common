<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;

/**
 * Web-tickle fallback (BR-COM-04 FR-COM-04-006).
 *
 * Non-cron hosts (UAT/integration boxes without crontab) call tickle() from
 * an FA page. It takes a process-wide mutex ('ksf-wf:lock') so concurrent
 * page hits cannot double-run the batch, then consumes at most $cap due jobs
 * with run rows tagged source 'web'.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class WebTickle
{
    public const MUTEX_KEY = 'ksf-wf:lock';

    private JobRunner $runner;
    private JobsAdapterInterface $adapter;
    private int $cap;

    public function __construct(JobRunner $runner, JobsAdapterInterface $adapter, int $cap = 5)
    {
        $this->runner  = $runner;
        $this->adapter = $adapter;
        $this->cap     = \max(1, $cap);
    }

    /**
     * Run up to $cap due jobs under the web mutex.
     *
     * @return array<int,array<string,mixed>> runs performed (empty when the
     *         mutex is held by a concurrent request)
     */
    public function tickle(): array
    {
        if (!$this->adapter->acquireLock(self::MUTEX_KEY)) {
            return [];
        }

        try {
            return $this->runner->consume($this->cap, 'web');
        } finally {
            $this->adapter->releaseLock(self::MUTEX_KEY);
        }
    }
}