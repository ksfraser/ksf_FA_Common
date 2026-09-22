<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * Ordered step engine — the executer of BR-COM-01 step tables.
 *
 * For a (recordType, event) the engine runs its ordered steps:
 *   1. criteria pass? IF -> run THEN resolver -> apply DO verbs.
 *   2. criteria fail & ELSE present? apply ELSE (may 'end').
 * A `create`/`broadcast` re-enters the engine (chaining). Guard rails:
 *   - max depth (WorkflowRegistry::getMaxDepth, default 5) before refusals,
 *   - visited-set keyed (recordType,id-ish) to catch feedback loops,
 *   - fault tolerance: a step that throws is recorded and the chain continues
 *     (never aborts the caller's own save/event).
 *   - audit log: every fired step appends a row.
 *
 * Transport-agnostic: the engine calls the injected CalcRegistry + a
 * Dispatcher callback for broadcast — FA bindings are wired by the adapter
 * (hook_invoke_all), nothing here touches db_*.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class StepEngine
{
    public const GUARD_DEPTH    = 'depth';
    public const GUARD_VISITED  = 'visited';

    /** @var WorkflowRegistry */
    private $registry;

    /** @var CalcRegistry */
    private $calc;

    /** @var callable|null broadcast dispatcher fn(string $event, array $payload): void */
    private $broadcaster;

    /** @var callable|null create factory fn(string $type, array $copyFrom): array */
    private $creator;

    /** @var callable|null audit logger fn(array $row): void */
    private $auditor;

    /** @var array<int,string> visited keys for the current top-level run */
    private $visited = [];

    /** @var array<string, mixed> audit rows accumulated */
    private $auditLog = [];

    /** @var array<int,array> DTOs produced by `create` verbs (post-chain) */
    private $created = [];

    /**
     * @param WorkflowRegistry $registry
     * @param CalcRegistry     $calc
     */
    public function __construct(WorkflowRegistry $registry, CalcRegistry $calc)
    {
        $this->registry = $registry;
        $this->calc     = $calc;
    }

    /**
     * Set the broadcast dispatcher (event -> hook_invoke_all in FA).
     */
    public function setBroadcaster(?callable $broadcaster): void
    {
        $this->broadcaster = $broadcaster;
    }

    /**
     * Set the DTO creation factory for the `create` verb (module-provided).
     */
    public function setCreator(?callable $creator): void
    {
        $this->creator = $creator;
    }

    /**
     * Set an audit logger (append-only row sink).
     */
    public function setAuditor(?callable $auditor): void
    {
        $this->auditor = $auditor;
    }

    /**
     * Run the ordered steps for (recordType, event) on a DTO.
     *
     * @param string $recordType
     * @param string $event
     * @param array  $dto        DTO array (mutated by `set` verbs)
     * @param int    $depth      internal chain depth
     *
     * @return array<string,mixed> ['dto'=>array, 'changed'=>bool, 'stopped'=>bool]
     */
    public function run(string $recordType, string $event, array $dto, int $depth = 0): array
    {
        $steps  = $this->registry->getSteps($recordType, $event);
        $result = ['dto' => $dto, 'changed' => false, 'stopped' => false];

        if ($depth > $this->registry->getMaxDepth()) {
            $this->log(\array_merge($this->baseRow($recordType, $event, $depth),
                ['guard' => self::GUARD_DEPTH, 'step' => null, 'detail' => 'max depth exceeded']));
            $result['stopped'] = true;
            return $result;
        }

        $visitedKey = $this->visitKey($recordType, $dto);
        if ($visitedKey !== null && \in_array($visitedKey, $this->visited, true)) {
            $this->log(\array_merge($this->baseRow($recordType, $event, $depth),
                ['guard' => self::GUARD_VISITED, 'step' => null,
                'detail' => "already visited {$visitedKey}"]));
            $result['stopped'] = true;
            return $result;
        }
        if ($visitedKey !== null) {
            $this->visited[] = $visitedKey;
        }

        foreach ($steps as $index => $step) {
            if ($result['stopped']) {
                break;
            }

            $context = ['dto' => $result['dto'], 'result' => null,
                        'record_type' => $recordType, 'event' => $event,
                        'depth' => $depth, 'step_index' => $index];

            $criteria = $step['criteria'] ?? [];
            $matched  = CriteriaEvaluator::all($criteria, $result['dto'], $context);

            if ($matched) {
                $outcome = $this->executeThen($step, $result['dto'], $context);
                if ($outcome['stopped']) {
                    $result['stopped'] = true;
                }
                $result['dto']     = $outcome['dto'];
                $result['changed'] = $result['changed'] || $outcome['changed'];
                $this->log(\array_merge($this->baseRow($recordType, $event, $depth),
                    ['guard' => null, 'step' => $index, 'detail' => 'matched']));
            } elseif (isset($step['else'])) {
                $outcome = $this->executeDoList((array) $step['else'],
                    $result['dto'], $context);
                if ($outcome['stopped']) {
                    $result['stopped'] = true;
                }
                $result['dto']     = $outcome['dto'];
                $result['changed'] = $result['changed'] || $outcome['changed'];
                $this->log(\array_merge($this->baseRow($recordType, $event, $depth),
                    ['guard' => null, 'step' => $index, 'detail' => 'else']));
            }
        }

        return $result;
    }

    /**
     * Run a step's THEN: resolver call, then DO list.
     */
    private function executeThen(array $step, array $dto, array $context): array
    {
        $then = $step['then'] ?? $step['resolver'] ?? null;
        $do   = $step['do'] ?? [];

        if (\is_string($then)) {
            $resolverKey = $then;
            $method      = null;
        } elseif (\is_array($then)) {
            $resolverKey = $then['resolver'] ?? null;
            $method      = $then['method'] ?? null;
        } else {
            $resolverKey = null;
            $method      = null;
        }

        if ($resolverKey !== null) {
            try {
                $context['result'] = $this->calc->call($resolverKey, $dto, $context, $method);
            } catch (\Throwable $e) {
                $context['result'] = ['error' => $e->getMessage()];
                $this->log(\array_merge($this->baseRow($context['record_type'], $context['event'],
                        $context['depth']),
                    ['guard' => 'resolver', 'step' => $context['step_index'],
                    'detail' => "resolver {$resolverKey} threw: " . $e->getMessage()]));
            }
        }

        $outcome = $this->executeDoList($do, $dto, $context);

        // Post-then chaining: `create` fired after_save on a new DTO already;
        // `broadcast` may have re-entered. Nothing more needed here.
        return $outcome;
    }

    /**
     * Apply a do-verb list in order (`set`/`create`/`call`/`broadcast`/`end`).
     */
    private function executeDoList(array $do, array $dto, array $context): array
    {
        $outcome = ['dto' => $dto, 'changed' => false, 'stopped' => false];

        foreach ($do as $verbRow) {
            if (!\is_array($verbRow) || \count($verbRow) === 0) {
                continue;
            }

            $verb = (string) $verbRow[0];
            switch ($verb) {
                case 'set':
                    if (\count($verbRow) >= 4) {
                        [$_, $field, $op, $value] = $verbRow;
                        if ($op === '=') {
                            $value = $this->resolveValue($value, $context);
                            DtoValue::set($outcome['dto'], (string) $field, $value);
                            $outcome['changed'] = true;
                        }
                    }
                    break;

                case 'create':
                    if (\count($verbRow) >= 2 && $this->creator !== null) {
                        $type   = (string) $verbRow[1];
                        $copy   = isset($verbRow[2]['copy']) ? (array) $verbRow[2]['copy'] : [];
                        $fields = [];
                        foreach ($copy as $srcField) {
                            $fields[$srcField] = DtoValue::get($outcome['dto'], $srcField);
                        }
                        $created = ($this->creator)($type, $fields);
                        // chain: fire after_save on the new record's type
                        $chain = $this->run($type, 'after_save', $created, $context['depth'] + 1);
                        $this->created[] = $chain['dto'];
                        $outcome['changed'] = true;
                        if ($chain['stopped']) {
                            $outcome['stopped'] = true;
                        }
                    }
                    break;

                case 'call':
                    if (\count($verbRow) >= 2) {
                        $target = $verbRow[1];
                        $method = $verbRow[2] ?? null;
                        $resolverKey = \is_array($target) ? ($target['resolver'] ?? null)
                            : $target;
                        if ($resolverKey !== null) {
                            try {
                                $this->calc->call($resolverKey, $outcome['dto'], $context, $method);
                            } catch (\Throwable $e) {
                                $this->log(\array_merge($this->baseRow($context['record_type'],
                                        $context['event'], $context['depth']),
                        ['guard' => 'call', 'step' => $context['step_index'],
                        'detail' => "call {$resolverKey} threw: " . $e->getMessage()]));
                            }
                        }
                    }
                    break;

                case 'broadcast':
                    if (\count($verbRow) >= 2 && $this->broadcaster !== null) {
                        $event   = (string) $verbRow[1];
                        $payload = $verbRow[2] ?? ['dto' => $outcome['dto']];
                        if (\is_array($payload)) {
                            $payload = $this->resolvePayload($payload, $context);
                        }
                        ($this->broadcaster)($event, $payload);
                        $outcome['changed'] = true;
                    }
                    break;

                case 'end':
                    $outcome['stopped'] = true;
                    break 2;
            }
        }

        return $outcome;
    }

    /**
     * Resolve a scalar value: literal or context reference ('result.'/'dto.').
     *
     * @param mixed $value
     * @param array $context
     *
     * @return mixed
     */
    private function resolveValue($value, array $context)
    {
        if (\is_string($value) && \strpos($value, 'result.') === 0) {
            return isset($context['result']) ? DtoValue::get($context['result'], \substr($value, 7)) : null;
        }
        if (\is_string($value) && \strpos($value, 'dto.') === 0) {
            return DtoValue::get($context['dto'], \substr($value, 4));
        }
        return $value;
    }

    /**
     * Recursively resolve any 'result.'/*'dto.' references in a payload array.
     */
    private function resolvePayload(array $payload, array $context): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (\is_array($v)) {
                $out[$k] = $this->resolvePayload($v, $context);
            } elseif (\is_string($v) && \strpos($v, 'result.') === 0) {
                $out[$k] = isset($context['result'])
                    ? DtoValue::get($context['result'], \substr($v, 7)) : null;
            } elseif (\is_string($v) && \strpos($v, 'dto.') === 0) {
                $out[$k] = DtoValue::get($context['dto'], \substr($v, 4));
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function visitKey(string $recordType, array $dto): ?string
    {
        $id = DtoValue::get($dto, 'id');
        if ($id === null) {
            $id = DtoValue::get($dto, 'request_id');
        }
        if ($id === null) {
            $id = DtoValue::get($dto, 'person_id');
        }
        return $id === null ? null : \sprintf('%s:%s', $recordType, (string) $id);
    }

    private function baseRow(string $recordType, string $event, int $depth): array
    {
        return [
            'record_type' => $recordType,
            'event'       => $event,
            'depth'       => $depth,
            'at'          => \date('Y-m-d H:i:s'),
        ];
    }

    private function log(array $row): void
    {
        $this->auditLog[] = $row;
        if ($this->auditor !== null) {
            ($this->auditor)($row);
        }
    }

    /**
     * @return array<string,mixed> Audit rows from this engine instance.
     */
    public function getAuditLog(): array
    {
        return $this->auditLog;
    }

    public function clearAuditLog(): void
    {
        $this->auditLog = [];
        $this->visited  = [];
        $this->created  = [];
    }

    /**
     * @return array<int,array> DTOs produced by `create` verbs this run.
     */
    public function getCreated(): array
    {
        return $this->created;
    }
}