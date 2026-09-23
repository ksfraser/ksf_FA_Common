<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * ProcessDefinition — a versioned, validated state-machine definition
 * (BR-COM-02 FR-COM-02-002).
 *
 * Holds the "process author" table as data: states (nodes), transitions
 * (edges from->to with guard/pre/post/trigger), start conditions and version.
 * Definitions are authorable as arrays/JSON and validated at construction
 * (state names unique, targets exist, guards well-formed, NO self/same-state
 * edges, transitions acyclic at design time). Published definitions are
 * versioned; a running process pins its version.
 *
 * Serialization: fromArray/toArray/fromJson/toJson round-trip identically.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class ProcessDefinition
{
    /** @var string */
    private $name;

    /** @var string record type this process governs */
    private $record;

    /** @var string|null module prefix (registerWorkflowType hook prefix) */
    private $recordPrefix;

    /** @var array|null start condition ['on'=>event, 'criteria'=>[[]]] */
    private $start;

    /** @var string[] state names (unique) */
    private $states;

    /** @var string initial state */
    private $initial;

    /** @var array[] transitions: from/to/trigger/label/guard/pre/post/wait */
    private $transitions;

    /** @var bool whether append-only history is kept */
    private $history;

    /** @var int definition version (monotonic per definition_id, >= 1) */
    private $version;

    /**
     * @param string $name
     * @param string $record
     * @param string   $initial
     * @param string[] $states
     * @param array[]  $transitions
     * @param array|null  $start
     * @param string|null $recordPrefix
     * @param bool $history
     * @param int   $version
     */
    public function __construct(
        string $name,
        string $record,
        string $initial,
        array $states,
        array $transitions,
        ?array $start = null,
        ?string $recordPrefix = null,
        bool $history = true,
        int $version = 1
    ) {
        $this->name          = $name;
        $this->record        = $record;
        $this->recordPrefix  = $recordPrefix;
        $this->start         = $start;
        $this->states        = $states;
        $this->initial       = $initial;
        $this->transitions   = $transitions;
        $this->history       = $history;
        $this->version       = $version;

        $this->validate();
    }

    /**
     * Validate the definition (throws \InvalidArgumentException).
     */
    private function validate(): void
    {
        if ($this->name === '' || $this->record === '') {
            throw new \InvalidArgumentException('ProcessDefinition: name and record are required.');
        }
        if (\count($this->states) === 0) {
            throw new \InvalidArgumentException("Process '{$this->name}': at least one state required.");
        }
        $unique = \array_unique($this->states);
        if (\count($unique) !== \count($this->states)) {
            throw new \InvalidArgumentException("Process '{$this->name}': state names must be unique.");
        }
        if (\in_array($this->initial, $this->states, true) === false) {
            throw new \InvalidArgumentException("Process '{$this->name}': initial state '{$this->initial}' not in states.");
        }

        if ($this->start !== null) {
            $this->validateStart();
        }
        $this->validateTransitions();
    }

    private function validateStart(): void
    {
        $on = $this->start['on'] ?? null;
        if (!\is_string($on) || $on === '') {
            throw new \InvalidArgumentException("Process '{$this->name}': start.on required.");
        }
        if (isset($this->start['criteria'])) {
            $criteria = ($this->start['criteria'] ?? null);
            if ($criteria !== null && !\is_array($criteria)) {
                throw new \InvalidArgumentException("Process '{$this->name}': start.criteria must be an array.");
            }
        }
    }

    private function validateTransitions(): void
    {
        $seen = [];
        foreach ($this->transitions as $t) {
            if (!\is_array($t) || !isset($t['from'], $t['to'])) {
                throw new \InvalidArgumentException("Process '{$this->name}': transition needs 'from' and 'to'.");
            }
            $from = (string) $t['from'];
            $to   = (string) $t['to'];
            if (!\in_array($from, $this->states, true)) {
                throw new \InvalidArgumentException("Process '{$this->name}': transition from '{$from}' not a state.");
            }
            if (!\in_array($to, $this->states, true)) {
                throw new \InvalidArgumentException("Process '{$this->name}': transition to '{$to}' not a state.");
            }
            if ($from === $to) {
                throw new \InvalidArgumentException("Process '{$this->name}': self-loop '{$from}->{$to}' rejected.");
            }
            $key = $from . '->' . $to;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Process '{$this->name}': duplicate transition {$key}.");
            }
            $seen[$key] = true;

            $this->validateActionList($t['pre'] ?? null, 'pre');
            $this->validateActionList($t['post'] ?? null, 'post');
            $this->validateGuard($t['guard'] ?? null);
        }

        $this->assertAcyclic();
    }

    private function validateActionList($list, string $section): void
    {
        if ($list === null || $list === []) {
            return;
        }
        if (!\is_array($list)) {
            throw new \InvalidArgumentException("Process '{$this->name}': {$section} must be a list.");
        }
        foreach ($list as $row) {
            if (!\is_array($row) || \count($row) < 2) {
                throw new \InvalidArgumentException("Process '{$this->name}': {$section} rows are [verb, ...].");
            }
            $verb = (string) $row[0];
            if (!\in_array($verb, ['set', 'create', 'call', 'broadcast', 'resolver'], true)) {
                throw new \InvalidArgumentException("Process '{$this->name}': unknown {$section} verb '{$verb}'.");
            }
        }
    }

    private function validateGuard($guard): void
    {
        if ($guard === null || $guard === []) {
            return;
        }
        if (!\is_array($guard)) {
            throw new \InvalidArgumentException("Process '{$this->name}': guard must be a criteria list.");
        }
        foreach ($guard as $row) {
            if (!\is_array($row) || \count($row) < 2) {
                throw new \InvalidArgumentException("Process '{$this->name}': guard rows are [field, op, value].");
            }
            if (!CriteriaEvaluator::supportsOp((string) $row[1])) {
                throw new \InvalidArgumentException(
                    "Process '{$this->name}': unknown guard operator '" . $row[1] . "'.");
            }
        }
    }

    /**
     * Kahn's algorithm over the transition graph; a cycle means the designer
     * has a feedback edge in the state graph (FR-COM-02-002: design-time
     * acyclicity; runtime re-entry is handled by guard gates, not edges).
     */
    private function assertAcyclic(): void
    {
        $index   = \array_flip($this->states);
        $inEdge  = \array_fill(0, \count($this->states), 0);
        $adj     = \array_fill(0, \count($this->states), []);

        foreach ($this->transitions as $t) {
            $adj[$index[$t['from']]][] = $index[$t['to']];
            $inEdge[$index[$t['to']]]++;
        }

        $queue = [];
        foreach ($inEdge as $i => $deg) {
            if ($deg === 0) {
                $queue[] = $i;
            }
        }

        $visited = 0;
        while ($queue) {
            $u = \array_pop($queue);
            $visited++;
            foreach ($adj[$u] as $v) {
                if (--$inEdge[$v] === 0) {
                    $queue[] = $v;
                }
            }
        }

        if ($visited !== \count($this->states)) {
            throw new \InvalidArgumentException(
                "Process '{$this->name}': state graph contains a cycle (rejected at design time).");
        }
    }

    /**
     * Build from an array in the BR-COM-02 registry shape.
     *
     * @param array $def
     *
     * @return self
     */
    public static function fromArray(array $def): self
    {
        return new self(
            (string) ($def['name'] ?? ''),
            (string) ($def['record'] ?? ''),
            (string) ($def['initial'] ?? ''),
            (array) ($def['states'] ?? []),
            (array) ($def['transitions'] ?? []),
            isset($def['start']) ? (array) $def['start'] : null,
            isset($def['record_prefix']) ? (string) $def['record_prefix'] : null,
            (bool) ($def['history'] ?? true),
            (int) ($def['version'] ?? 1)
        );
    }

    /**
     * Serialize to the registry array shape (adds name/record for transport).
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'record'        => $this->record,
            'record_prefix' => $this->recordPrefix,
            'start'         => $this->start,
            'states'        => $this->states,
            'initial'       => $this->initial,
            'transitions'   => $this->transitions,
            'history'       => $this->history,
            'version'       => $this->version,
        ];
    }

    public static function fromJson(string $json): self
    {
        $def = \json_decode($json, true);
        if (!\is_array($def)) {
            throw new \InvalidArgumentException('ProcessDefinition: invalid JSON definition.');
        }
        return self::fromArray($def);
    }

    public function toJson(): string
    {
        return \json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getStateLabel(string $state): string
    {
        foreach ($this->transitions as $t) {
            if (isset($t['label']) && $t['from'] === $state) {
                return (string) $t['label'];
            }
        }
        return $state;
    }

    /**
     * Edges out of a state, optionally filtered by trigger type.
     *
     * @param string $state
     * @param string|null $trigger 'user'|'auto'|'time' or null (all)
     *
     * @return array[] edges
     */
    public function transitionsFrom(string $state, ?string $trigger = null): array
    {
        $out = [];
        foreach ($this->transitions as $t) {
            if ($t['from'] !== $state) {
                continue;
            }
            if ($trigger !== null && (string) ($t['trigger'] ?? 'user') !== $trigger) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Whether state -> to is a legal edge (design-time).
     */
    public function hasTransition(string $state, string $to): bool
    {
        foreach ($this->transitions as $t) {
            if ($t['from'] === $state && $t['to'] === $to) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    public function getStates(): array
    {
        return $this->states;
    }

    public function getInitial(): string
    {
        return $this->initial;
    }

    /**
     * @return array[]
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRecord(): string
    {
        return $this->record;
    }

    public function getRecordPrefix(): ?string
    {
        return $this->recordPrefix;
    }

    public function getStart(): ?array
    {
        return $this->start;
    }

    public function hasHistory(): bool
    {
        return $this->history;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}