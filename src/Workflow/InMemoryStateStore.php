<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\Contract\StateStoreInterface;

/**
 * In-memory state/history store (unit tests, CLI, non-FA embedding).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InMemoryStateStore implements StateStoreInterface
{
    /** @var array<string,array<string,array>> recordType -> record_id -> state row */
    private $states = [];

    /** @var array<string,array<int,array>> recordType -> record_id -> history rows */
    private $history = [];

    public function loadState(string $recordType, string $recordId): ?array
    {
        return $this->states[$recordType][$recordId] ?? null;
    }

    public function saveState(array $row): void
    {
        $this->states[(string) $row['record_type']][(string) $row['record_id']] = $row;
    }

    public function appendHistory(array $row): void
    {
        $key = (string) $row['record_type'] . ':' . (string) $row['record_id'];
        $this->history[$key][] = $row;
    }

    public function listHistory(string $recordType, string $recordId): array
    {
        return $this->history[$recordType . ':' . $recordId] ?? [];
    }

    /**
     * @param callable $fn fn(): mixed
     *
     * @return mixed
     */
    public function transaction(callable $fn)
    {
        $stateSnapshot  = $this->states;
        $historySnapshot = $this->history;
        try {
            return $fn();
        } catch (\Throwable $e) {
            $this->states  = $stateSnapshot;
            $this->history = $historySnapshot;
            throw $e;
        }
    }

    public function reset(): void
    {
        $this->states  = [];
        $this->history = [];
    }
}