<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;
use ksfraser\FrontAccounting\Common\Workflow\ProcessDefinition;
use ksfraser\FrontAccounting\Common\Workflow\StateMachine;

/**
 * The BR-COM-02 time-wait wire (BR-COM-04 FR-COM-04-007).
 *
 * A state-machine transition edge carrying `wait` arms a one-shot scheduler
 * job keyed wf:trans:<recordType>:<instanceId>:<from>:<to> with
 * `next_run_at = now + wait`. When the deadline passes the resolver wakes the
 * state machine: it loads the state row and, if the instance is still in
 * `from`, calls StateMachine::transition(dto, to, 'system', 'deadline'); if
 * the instance has already left `from`, the job is an idempotent no-op and it
 * throws JobSkippedException so the runner logs a 'skipped' row.
 *
 * The scheduler wakes the state machine — never the reverse (BR-COM-02
 * lease-to-scheduler rule).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class WaitTransition
{
    public const RESOLVER_KEY = 'wf.transition.wait';

    private StateMachine $machine;
    private JobsAdapterInterface $adapter;

    public function __construct(StateMachine $machine, JobsAdapterInterface $adapter)
    {
        $this->machine = $machine;
        $this->adapter = $adapter;
    }

    /**
     * Arm a one-shot job for a transition edge carrying `wait`.
     *
     * @param ProcessDefinition $def
     * @param string            $recordId
     * @param array             $edge     the transition edge (from/to/wait/...)
     * @param DateTimeImmutable $now
     *
     * @return int|null job id, or null when the edge has no `wait`
     */
    public function arm(
        ProcessDefinition $def,
        string $recordId,
        array $edge,
        DateTimeImmutable $now
    ): ?int {
        $wait = isset($edge['wait']) ? (string) $edge['wait'] : '';
        if ($wait === '') {
            return null;
        }

        $from = (string) $edge['from'];
        $to   = (string) $edge['to'];
        $key  = 'wf:trans:' . $def->getRecord() . ':' . $recordId . ':' . $from . ':' . $to;

        return $this->adapter->registerJob([
            'module'      => $def->getRecord(),
            'job_key'     => $key,
            'job_type'    => 'oneshot',
            'interval_p'  => '',
            'resolver'    => self::RESOLVER_KEY,
            'params'      => [
                'record_type' => $def->getRecord(),
                'record_id'   => $recordId,
                'from'        => $from,
                'to'          => $to,
            ],
            'enabled'     => true,
            'next_run_at' => $now->modify('+' . $wait),
        ]);
    }

    /**
     * Job resolver: wake the state machine at the deadline.
     *
     * @param array $params {record_type, record_id, from, to}
     *
     * @throws JobSkippedException when the instance already left `from`
     */
    public function __invoke(array $params): void
    {
        $recordType = (string) ($params['record_type'] ?? '');
        $recordId   = (string) ($params['record_id'] ?? '');
        $from       = (string) ($params['from'] ?? '');
        $to         = (string) ($params['to'] ?? '');

        $current = $this->machine->currentState($recordType, $recordId);
        if ($current !== $from) {
            throw new JobSkippedException(
                "instance '{$recordType}:{$recordId}' no longer in '{$from}' (now '{$current}')"
            );
        }

        $this->machine->transition($recordType, $recordId, $to, [], 'system', 'deadline');
    }
}