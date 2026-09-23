<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;

/**
 * In-memory inbox store for unit tests / CLI / standalone embedding.
 *
 * Mirrors FaInboxStore semantics exactly, including @all materialization with
 * a per-user watermark (stored in-memory here).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InMemoryInboxStore implements InboxStorageInterface
{
    /** @var array<int,array> rowId => row */
    private $rows = [];

    /** @var int */
    private $nextId = 1;

    /** @var array<int,int> recipientUid => last materialized @all id */
    private $watermarks = [];

    public function insert(array $row): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id'           => $id,
            'recipient_uid' => (int) ($row['recipient_uid'] ?? 0),
            'type'         => (string) ($row['type'] ?? ''),
            'payload'      => (array) ($row['payload'] ?? []),
            'ref'          => isset($row['ref']) ? (string) $row['ref'] : null,
            'created_at'   => (string) ($row['created_at'] ?? \date('Y-m-d H:i:s')),
            'read_at'      => null,
            'dismissed_at' => null,
        ];
        return $id;
    }

    public function materializeAll(int $recipientUid): int
    {
        if ($recipientUid <= 0) {
            return 0;
        }
        $watermark = $this->watermarks[$recipientUid] ?? 0;
        $count = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['recipient_uid'] !== 0 || $id <= $watermark) {
                continue;
            }
            $this->insert([
                'recipient_uid' => $recipientUid,
                'type'          => $row['type'],
                'payload'       => $row['payload'],
                'ref'           => $row['ref'],
                'created_at'    => $row['created_at'],
            ]);
            $this->watermarks[$recipientUid] = $id;
            $count++;
        }
        return $count;
    }

    public function unread(int $recipientUid): array
    {
        return \array_values(\array_filter($this->rows, function ($row) use ($recipientUid) {
            return $row['recipient_uid'] === (int) $recipientUid
                && $row['read_at'] === null
                && $row['dismissed_at'] === null;
        }));
    }

    public function unreadCount(int $recipientUid): int
    {
        return \count($this->unread($recipientUid));
    }

    public function listForUser(int $recipientUid, int $limit = 50, ?string $type = null): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            if ($row['recipient_uid'] !== (int) $recipientUid) {
                continue;
            }
            if ($type !== null && $row['type'] !== $type) {
                continue;
            }
            $rows[] = $row;
        }
        \usort($rows, function ($a, $b) {
            $cmp = \strcmp((string) $b['created_at'], (string) $a['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return (int) $b['id'] <=> (int) $a['id'];
        });
        return \array_slice($rows, 0, \max(1, $limit));
    }

    public function markRead(int $id, int $recipientUid): bool
    {
        return $this->touch($id, $recipientUid, 'read_at');
    }

    public function markAllRead(int $recipientUid): int
    {
        $count = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['recipient_uid'] === (int) $recipientUid
                && $row['read_at'] === null
                && $row['dismissed_at'] === null) {
                $this->rows[$id]['read_at'] = \date('Y-m-d H:i:s');
                $count++;
            }
        }
        return $count;
    }

    public function dismiss(int $id, int $recipientUid): bool
    {
        return $this->touch($id, $recipientUid, 'dismissed_at');
    }

    /**
     * Reset all rows + watermarks (test isolation).
     */
    public function reset(): void
    {
        $this->rows = [];
        $this->nextId = 1;
        $this->watermarks = [];
    }

    private function touch(int $id, int $recipientUid, string $field): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['recipient_uid'] !== (int) $recipientUid) {
            return false;
        }
        $this->rows[$id][$field] = \date('Y-m-d H:i:s');
        return true;
    }
}