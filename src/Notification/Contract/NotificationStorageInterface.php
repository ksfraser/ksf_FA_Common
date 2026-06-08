<?php

declare(strict_types=1);

namespace KsfCommon\Notification\Contract;

use DateTimeInterface;
use KsfCommon\Notification\Notification;

interface NotificationStorageInterface
{
    public function save(Notification $notification): Notification;

    /**
     * @return Notification[]
     */
    public function findDue(DateTimeInterface $now): array;

    public function findById(int $id): ?Notification;

    public function acknowledge(int $id, string $ackToken, ?DateTimeInterface $acknowledgedAt = null): bool;

    public function markDispatched(int $id, ?DateTimeInterface $dispatchedAt = null): bool;
}
