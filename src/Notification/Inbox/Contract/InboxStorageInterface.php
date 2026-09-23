<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox\Contract;

/**
 * Append-only per-user inbox storage (BR-COM-03 FR-COM-03-001/004).
 *
 * Rows are INSERT-only; the owner row is updated in place only via
 * readAt/dismissAt (+ materialization of `@all` broadcasts). Deletes never
 * happen — dismissal is a soft flag, mirroring BR-007/BR-COM-02 append-only
 * discipline.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface InboxStorageInterface
{
    /**
     * Insert one inbox row.
     *
     * @param array $row ['recipient_uid'=>int, 'type'=>string, 'payload'=>array,
     *                   'ref'=>string|null, 'created_at'=>string (Y-m-d H:i:s)]
     *
     * @return int new row id
     */
    public function insert(array $row): int;

    /**
     * Copy any unconsumed `@all` (recipient_uid = 0) rows into per-user rows.
     * Idempotent across requests via a durable per-user watermark table;
     * called from the read path so the 0-placeholder is materialized lazily
     * on first poll (FR-COM-03-002).
     *
     * @param int $recipientUid FA user id
     *
     * @return int number of rows materialized
     */
    public function materializeAll(int $recipientUid): int;

    /**
     * @param int $recipientUid
     *
     * @return array<int,array> rows without read/dismiss timestamps, newest first
     */
    public function unread(int $recipientUid): array;

    public function unreadCount(int $recipientUid): int;

    /**
     * @param int      $recipientUid
     * @param int      $limit
     * @param string|null $type  filter by notification type (null = all)
     *
     * @return array<int,array> rows newest first
     */
    public function listForUser(int $recipientUid, int $limit = 50, ?string $type = null): array;

    public function markRead(int $id, int $recipientUid): bool;

    public function markAllRead(int $recipientUid): int;

    public function dismiss(int $id, int $recipientUid): bool;
}