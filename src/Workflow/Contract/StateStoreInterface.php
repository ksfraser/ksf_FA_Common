<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow\Contract;

/**
 * Process state/history storage seam (BR-COM-02 FR-COM-02-001/004).
 *
 * Transport-agnostic: the FA runtime implementation uses native db_* calls
 * (FaStateStore), tests/CLI may use an in-memory store.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface StateStoreInterface
{
    /**
     * Load the persisted current_state row for a record.
     *
     * @param string $recordType
     * @param string $recordId
     *
     * @return array|null ['record_type','record_id','state','definition_version',...]
     *                    or null when the record has no process state yet.
     */
    public function loadState(string $recordType, string $recordId): ?array;

    /**
     * Persist (upsert) the current state row for a record.
     *
     * @param array $row ['record_type','record_id','state','definition_version',...]
     */
    public function saveState(array $row): void;

    /**
     * Append an immutable history row. History is never updated/deleted.
     *
     * @param array $row ['record_type','record_id','from','to','actor','reason','at','failed']
     */
    public function appendHistory(array $row): void;

    /**
     * Read the append-only history for a record, oldest first.
     *
     * @return array[]
     */
    public function listHistory(string $recordType, string $recordId): array;

    /**
     * Run $fn inside a single storage transaction. Return value passes through.
     *
     * @param callable $fn fn(): mixed
     *
     * @return mixed
     */
    public function transaction(callable $fn);
}