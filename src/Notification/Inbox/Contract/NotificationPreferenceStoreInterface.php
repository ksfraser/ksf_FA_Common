<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox\Contract;

/**
 * Per-user notification preferences (BR-COM-03 FR-COM-03-005, v1 minimal).
 *
 * v1 supports mute-by-type + a digest flag; everything defaults to all-on.
 * Rows are still stored for muted types (append-only); muting only affects
 * rendering (the inbox hides muted types).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface NotificationPreferenceStoreInterface
{
    /**
     * @return string[] notification types muted for this user ([] = nothing muted)
     */
    public function mutedTypes(int $recipientUid): array;

    public function setMutedTypes(int $recipientUid, array $types): void;

    public function isDigest(int $recipientUid): bool;

    public function setDigest(int $recipientUid, bool $digest): void;
}