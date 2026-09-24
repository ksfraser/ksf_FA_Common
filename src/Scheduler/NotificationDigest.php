<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\NotificationPreferenceStoreInterface;

/**
 * BR-COM-03 digest job resolver (BR-COM-04 FR-COM-04-008).
 *
 * Recurring, per-user summary job. For each digest-enabled recipient in
 * `params.user_ids` it materializes any unconsumed @all rows, groups unread
 * inbox rows by notification type (muted types excluded) and hands the
 * resulting summary to an injected sink callable. Users without the digest
 * flag — and digest users with nothing unread — are skipped. The body is a
 * read-only computation over the append-only inbox, so the job is idempotent
 * by construction and safe to run at-least-once.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class NotificationDigest
{
    public const RESOLVER_KEY = 'brcom03.notification.digest';

    private InboxStorageInterface $inbox;
    private NotificationPreferenceStoreInterface $prefs;

    /** @var callable|null fn(int $uid, array $summary): void */
    private $sink;

    /**
     * @param InboxStorageInterface               $inbox
     * @param NotificationPreferenceStoreInterface $prefs
     * @param callable|null                       $sink fn(int $uid, array $summary): void
     */
    public function __construct(
        InboxStorageInterface $inbox,
        NotificationPreferenceStoreInterface $prefs,
        ?callable $sink = null
    ) {
        $this->inbox = $inbox;
        $this->prefs = $prefs;
        $this->sink  = $sink;
    }

    /**
     * Build and deliver one digest summary per digest-enabled user.
     *
     * @param array $params {user_ids: int[]}
     *
     * @return array<int,mixed> per-user summaries keyed by uid (also handy in tests)
     */
    public function __invoke(array $params): array
    {
        $summaries = [];
        foreach ((array) ($params['user_ids'] ?? []) as $uid) {
            $uid = (int) $uid;
            if (!$this->prefs->isDigest($uid)) {
                continue;
            }

            $this->inbox->materializeAll($uid);
            $unread = $this->inbox->unread($uid);
            if ($unread === []) {
                continue;
            }

            $summary = $this->summarize($uid, $unread);
            $summaries[$uid] = $summary;

            if ($this->sink !== null) {
                ($this->sink)($uid, $summary);
            }
        }

        return $summaries;
    }

    /**
     * Group unread rows by type, excluding muted types.
     *
     * @param int   $uid
     * @param array $rows unread inbox rows (each with a `type` key)
     *
     * @return array{total:int, by_type:array<string,int>}
     */
    private function summarize(int $uid, array $rows): array
    {
        $muted = \array_flip($this->prefs->mutedTypes($uid));

        $byType = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if (isset($muted[$type])) {
                continue;
            }
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total'   => \array_sum($byType),
            'by_type' => $byType,
        ];
    }
}