<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

/**
 * Inbox read-side service (BR-COM-03 FR-COM-03-004, FR-COM-03-005).
 *
 * Wraps the storage interfaces and the renderer with the per-module workflow,
 * so page code has a single entry point that:
 *
 *   - materializes @all placeholders for the current user on first read;
 *   - filters muted types out of the visible list/badge (rows stay stored for
 *     history/unmute);
 *   - renders each row through NotificationTypeRenderer;
 *   - owns read/dismiss state transitions.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InboxService
{
    /** @var \ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface */
    private $storage;

    /** @var NotificationTypeRenderer */
    private $renderer;

    /** @var \ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\NotificationPreferenceStoreInterface */
    private $prefs;

    public function __construct(
        $storage,
        NotificationTypeRenderer $renderer,
        $prefs
    ) {
        $this->storage  = $storage;
        $this->renderer = $renderer;
        $this->prefs    = $prefs;
    }

    /**
     * Active badge count for the given user (muted types excluded).
     */
    public function unreadBadge(int $uid): int
    {
        $this->storage->materializeAll($uid);
        $muted = $this->prefs->mutedTypes($uid);
        return \count($this->visibleRows($this->storage->unread($uid), $muted));
    }

    /**
     * Recent notifications for the given user (muted types filtered out, kept
     * stored for history/unmute).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForUser(int $uid, int $limit = 50, ?string $type = null): array
    {
        $this->storage->materializeAll($uid);
        $visible = $this->visibleRows($this->storage->listForUser($uid, $limit, $type), []);
        $r = [];
        foreach ($visible as $row) {
            $r[] = \array_merge(
                $this->renderer->render($row),
                ['id' => (int) $row['id'], 'read' => $row['read_at'] !== null, 'dismissed' => $row['dismissed_at'] !== null]
            );
        }
        return $r;
    }

    public function markRead(int $id, int $uid): bool
    {
        return $this->storage->markRead($id, $uid);
    }

    public function markAllRead(int $uid): int
    {
        return $this->storage->markAllRead($uid);
    }

    public function dismiss(int $id, int $uid): bool
    {
        return $this->storage->dismiss($id, $uid);
    }

    /**
     * @param array<int,mixed> $rows
     * @param array<int,string> $muted
     *
     * @return array<int,mixed>
     */
    private function visibleRows(array $rows, array $muted): array
    {
        if ($muted === []) {
            return $rows;
        }
        $muted = \array_flip($muted);
        return \array_values(\array_filter($rows, function ($row) use ($muted): bool {
            return !isset($muted[(string) $row['type']]);
        }));
    }
}