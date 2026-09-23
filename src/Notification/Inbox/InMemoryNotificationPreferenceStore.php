<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\NotificationPreferenceStoreInterface;

/**
 * In-memory preference store for unit tests / CLI / standalone embedding.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class InMemoryNotificationPreferenceStore implements NotificationPreferenceStoreInterface
{
    /** @var array<int,array> recipientUid => ['muted'=>string[], 'digest'=>bool] */
    private $prefs = [];

    public function mutedTypes(int $recipientUid): array
    {
        return isset($this->prefs[$recipientUid])
            ? $this->prefs[$recipientUid]['muted']
            : [];
    }

    public function setMutedTypes(int $recipientUid, array $types): void
    {
        $this->prefs[$recipientUid]['muted'] = \array_values(\array_map('strval', $types));
        $this->prefs[$recipientUid]['digest'] = isset($this->prefs[$recipientUid]['digest'])
            ? $this->prefs[$recipientUid]['digest'] : false;
    }

    public function isDigest(int $recipientUid): bool
    {
        return isset($this->prefs[$recipientUid]) ? (bool) $this->prefs[$recipientUid]['digest'] : false;
    }

    public function setDigest(int $recipientUid, bool $digest): void
    {
        $this->prefs[$recipientUid]['digest'] = $digest;
        $this->prefs[$recipientUid]['muted'] = isset($this->prefs[$recipientUid]['muted'])
            ? $this->prefs[$recipientUid]['muted'] : [];
    }

    public function reset(): void
    {
        $this->prefs = [];
    }
}