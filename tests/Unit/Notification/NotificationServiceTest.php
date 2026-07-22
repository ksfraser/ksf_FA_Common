<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Notification;

use ksfraser\FrontAccounting\Common\Notification\Contract\NotificationStorageInterface;
use ksfraser\FrontAccounting\Common\Notification\Notification;
use ksfraser\FrontAccounting\Common\Notification\NotificationService;
use PHPUnit\Framework\TestCase;

final class NotificationServiceTest extends TestCase
{
    public function testAcknowledgeDelegatesToStorage(): void
    {
        $storage = $this->createMock(NotificationStorageInterface::class);
        $storage->expects($this->once())
            ->method('acknowledge')
            ->with(5, 'token')
            ->willReturn(true);

        $service = new NotificationService($storage);
        $this->assertTrue($service->acknowledge(5, 'token'));
    }

    public function testDispatchDueMarksNotificationsDispatched(): void
    {
        $notif = new Notification('calendar', '1', 'Title');
        $notif->setId(7);

        $storage = $this->createMock(NotificationStorageInterface::class);
        $storage->method('findDue')->willReturn([$notif]);
        $storage->expects($this->once())->method('markDispatched')->with(7, $this->anything())->willReturn(true);

        $service = new NotificationService($storage);
        $count = $service->dispatchDue(function (Notification $notification): void {
            $this->assertSame('Title', $notification->getTitle());
        });

        $this->assertSame(1, $count);
    }
}
