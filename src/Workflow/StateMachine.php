<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\Contract\StateStoreInterface;

/**
 * StateMachine — BR-COM-02 approval engine (FR-COM-02-003).
 *
 * Walks a ProcessDefinition for a record instance:
 *   1. load (or lazily init) the persisted current_state,
 *   2. resolve the edge current -> to; refuse unknown / same-state edges,
 *   3. run the edge's `pre` verbs (CalcRegistry + DO verbs) producing `result`,
 *   4. evaluate the edge guard (criteria DSL, may read result.*),
 *   5. persist from -> to + append history INSIDE one transaction,
 *   6. run `post` verbs (may create DTOs firing their own chains, broadcast),
 *   7. rollback to `from` + append a `failed` history row when anything throws.
 *
 * Role gating uses injected access checks (FA security areas) — no new auth.
 *
 * Fault tolerance: a failing transition never throws to the caller; it returns
 * the same shape with ok=false, errors logged, so module loops continue.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class StateMachine
{
    /** @var StateStoreInterface */
    private $store;

    /** @var StepEngine */
    private $engine;

    /** @var array<string,ProcessDefinition> recordType => definition */
    private $definitions = [];

    /** @var callable|null fn(string $access): bool */
    private $accessCheck;

    public function __construct(
        StateStoreInterface $store,
        ?StepEngine $engine = null
    ) {
        $this->store  = $store;
        $this->engine = $engine ?: new StepEngine(WorkflowRegistry::getInstance(), new CalcRegistry());
    }

    public function setAccessCheck(?callable $check): void
    {
        $this->accessCheck = $check;
    }

    /**
     * Register a process definition for its record type.
     */
    public function register(ProcessDefinition $definition): void
    {
        $this->definitions[$definition->getRecord()] = $definition;
    }

    public function getDefinition(string $recordType): ?ProcessDefinition
    {
        return $this->definitions[$recordType] ?? null;
    }

    /**
     * Current persisted state for a record (lazily = definition initial).
     *
     * @return string|null null when no definition (no process) exists.
     */
    public function currentState(string $recordType, string $recordId): ?string
    {
        $def = $this->getDefinition($recordType);
        if ($def === null) {
            return null;
        }
        $row = $this->store->loadState($recordType, $recordId);
        return $row ? (string) $row['state'] : $def->getInitial();
    }

    /**
     * Allowed transitions for a record DTO: user-triggered edges out of the
     * current state whose access passes. Returns [to => label].
     *
     * @param string $recordType
     * @param string $recordId
     * @param array  $dto
     *
     * @return array<string,string>
     */
    public function allowedTransitions(string $recordType, string $recordId, array $dto = []): array
    {
        $state = $this->currentState($recordType, $recordId);
        $def   = $this->getDefinition($recordType);
        if ($state === null || $def === null) {
            return [];
        }

        $out = [];
        foreach ($def->transitionsFrom($state, 'user') as $edge) {
            $access = $edge['access'] ?? null;
            if ($access !== null && $this->accessCheck !== null
                && !($this->accessCheck)((string) $access)) {
                continue;
            }
            $out[$edge['to']] = (string) ($edge['label'] ?? $edge['to']);
        }
        return $out;
    }

    /**
     * Try a transition current -> $to for a record.
     *
     * @param string $recordType
     * @param string $recordId
     * @param string $to
     * @param array  $dto         DTO (mutated by pre/post verbs on success)
     * @param string $actor
     * @param string $reason
     * @param array  $opts        ['ignore_guard'=>bool] etc (reserved)
     *
     * @return array<string,mixed>
     *   ['ok'=>bool, 'from'=>string, 'to'=>string|null,
     *    'dto'=>array, 'error'=>string|null, 'message'=>string|null]
     */
    public function transition(
        string $recordType,
        string $recordId,
        string $to,
        array $dto = [],
        string $actor = '',
        string $reason = '',
        array $opts = []
    ): array {
        $def = $this->getDefinition($recordType);
        if ($def === null) {
            return $this->fail('no_process', null, $to, $dto,
                "no process for '{$recordType}'");
        }

        $state = $this->currentState($recordType, $recordId);
        $from  = (string) ($state ?? $def->getInitial());

        if ($from === $to) {
            return $this->fail('same_state', $from, $to, $dto, "already in '{$to}'");
        }
        if (!$def->hasTransition($from, $to)) {
            return $this->fail('illegal_transition', $from, $to, $dto,
                "no edge {$from}->{$to} in process '{$def->getName()}'");
        }

        $edge = $this->edge($def, $from, $to);
        if ($edge === null) {
            return $this->fail('illegal_transition', $from, $to, $dto,
                "no edge {$from}->{$to} in process '{$def->getName()}'");
        }

        $access = $edge['access'] ?? null;
        if ($access !== null && $this->accessCheck !== null
            && !($this->accessCheck)((string) $access)) {
            return $this->fail('access_denied', $from, $to, $dto,
                "actor lacks '{$access}' for {$from}->{$to}");
        }

        $context = ['dto' => $dto, 'result' => null,
                    'record_type' => $recordType, 'event' => 'transition',
                    'depth' => 1, 'step_index' => 0];

        // pre verbs -> may set 'result' for the guard
        $pre = $this->engine->applyVerbList($edge['pre'] ?? [], $dto, $context);
        $dto = $pre['dto'];
        $context['dto']    = $dto;
        $context['result'] = $pre['context']['result'] ?? null;

        // guard
        $guard     = $edge['guard'] ?? [];
        $populated = \array_merge($context, ['depth' => 1]);
        if (!\array_key_exists('ignore_guard', $opts) || !$opts['ignore_guard']) {
            if (!CriteriaEvaluator::all($guard, $dto, $populated)) {
                return $this->fail('guard_failed', $from, $to, $dto,
                    "guard refused {$from}->{$to}");
            }
        }

        // atomic: persist + history + post verbs inside one transaction
        try {
            $this->store->transaction(function () use ($recordType, $recordId, $from, $to, $actor, $reason, $def, $context, $dto, $edge, &$postOut) {
                $this->store->saveState([
                    'record_type'         => $recordType,
                    'record_id'           => $recordId,
                    'state'               => $to,
                    'definition_version'  => $def->getVersion(),
                ]);
                $this->store->appendHistory([
                    'record_type' => $recordType,
                    'record_id'   => $recordId,
                    'from'        => $from,
                    'to'          => $to,
                    'actor'       => $actor,
                    'reason'      => $reason,
                    'at'          => \date('Y-m-d H:i:s'),
                    'failed'      => false,
                ]);
                $postOut = $this->engine->applyVerbList($edge['post'] ?? [], $dto, $context, false);
            });
        } catch (\Throwable $e) {
            // rolled back: state back at $from; append failed history
            try {
                $this->store->appendHistory([
                    'record_type' => $recordType,
                    'record_id'   => $recordId,
                    'from'        => $from,
                    'to'          => $to,
                    'actor'       => $actor,
                    'reason'      => $reason,
                    'at'          => \date('Y-m-d H:i:s'),
                    'failed'      => true,
                ]);
            } catch (\Throwable $ignored) {
                // best effort audit row
            }
            return $this->fail('post_failed', $from, $to, $dto,
                "post actions for {$from}->{$to} threw: " . $e->getMessage());
        }

        return [
            'ok'      => true,
            'from'    => $from,
            'to'      => $to,
            'dto'     => isset($postOut) && isset($postOut['dto']) ? $postOut['dto'] : $dto,
            'error'   => null,
            'message' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(ProcessDefinition $def, string $from, string $to): ?array
    {
        foreach ($def->getTransitions() as $t) {
            if ($t['from'] === $from && $t['to'] === $to) {
                return $t;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function fail(string $error, ?string $from, ?string $to, array $dto, string $message): array
    {
        return [
            'ok'      => false,
            'from'    => $from,
            'to'      => $to,
            'dto'     => $dto,
            'error'   => $error,
            'message' => $message,
        ];
    }
}