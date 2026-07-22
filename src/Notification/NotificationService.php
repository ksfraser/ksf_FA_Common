<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification;

use DateTimeImmutable;
use ksfraser\FrontAccounting\Common\Notification\Contract\NotificationStorageInterface;

final class NotificationService
{
    private $repository;

    public function __construct(?NotificationStorageInterface $repository = null)
    {
        $this->repository = $repository ?: new NotificationRepository();
    }

    public function enqueue(Notification $notification): Notification
    {
        return $this->repository->save($notification);
    }

    /**
     * @return Notification[]
     */
    public function due(): array
    {
        return $this->repository->findDue(new DateTimeImmutable('now'));
    }

    public function dispatchDue(callable $dispatcher): int
    {
        $count = 0;
        foreach ($this->due() as $notification) {
            $dispatcher($notification);
            $this->repository->markDispatched((int) $notification->getId());
            $count++;
        }
        return $count;
    }

    public function acknowledge(int $id, string $ackToken): bool
    {
        return $this->repository->acknowledge($id, $ackToken);
    }

    public function getById(int $id): ?Notification
    {
        return $this->repository->findById($id);
    }
}
