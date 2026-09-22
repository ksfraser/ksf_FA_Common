<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * Step-table registry host (BR-COM-01 FR-COM-01-001/005).
 *
 * Modules register ordered step rows for (recordType, event). Each step:
 *   [
 *     'criteria' => [ [field, op, value], ... ],       // IF (AND) on DTO
 *     'then'     => ['resolver' => '<key>',            // calculation/call
 *                    'method'   => '<method>'],        // optional method
 *     'do'       => [ [verb, ...], ... ],              // set/create/call/broadcast
 *     'else'     => [ ... same do-shape ... , 'end' ], // optional on criteria fail
 *   ]
 *
 * The engine subscribes once per module (hooks.php + registerWorkflowType) and
 * runs the ordered steps for (recordType, event). A step's create/broadcast
 * re-enters the same engine — the required chaining.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class WorkflowRegistry
{
    /** @var array<string, array[]|callable> WorkflowRegistry singleton */
    private static $instance = null;

    /** @var array<string, array>  key "<recordType>@<event>" => steps */
    private $steps = [];

    /** @var int Maximum chained step depth before a step is refused. */
    private $maxDepth = 5;

    /**
     * Singleton access (modules register from hook methods).
     */
    public static function getInstance(): WorkflowRegistry
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function setMaxDepth(int $maxDepth): void
    {
        $this->maxDepth = $maxDepth;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    /**
     * Register an ordered list of step rows for (recordType, event).
     *
     * @param string $recordType e.g. 'leave_request', 'order'
     * @param string $event      e.g. 'after_save', 'before_delete'
     * @param array  $steps
     */
    public function register(string $recordType, string $event, array $steps): void
    {
        $key = $this->key($recordType, $event);
        if (!isset($this->steps[$key])) {
            $this->steps[$key] = [];
        }
        foreach ($steps as $step) {
            $this->steps[$key][] = $step;
        }
    }

    /**
     * @return array Step rows for the given (recordType, event); [] if none.
     */
    public function getSteps(string $recordType, string $event): array
    {
        $key = $this->key($recordType, $event);
        return $this->steps[$key] ?? [];
    }

    /**
     * @return string[] All registered record types (first segment of the key).
     */
    public function getRecordTypes(): array
    {
        $types = [];
        foreach (\array_keys($this->steps) as $key) {
            $types[] = \explode('@', $key)[0];
        }
        return \array_values(\array_unique($types));
    }

    public function clear(): void
    {
        $this->steps = [];
    }

    private function key(string $recordType, string $event): string
    {
        return $recordType . '@' . $event;
    }
}