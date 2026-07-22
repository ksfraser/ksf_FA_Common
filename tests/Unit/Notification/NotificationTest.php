<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Notification;

use ksfraser\FrontAccounting\Common\Notification\Notification;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testFromArrayAndToArrayRoundTrip(): void
    {
        $n = new Notification('calendar', 'entry-1', 'Reminder');
        $n->setRecipientUserId('5')
          ->setNotificationType('reminder')
          ->setChannel(Notification::CHANNEL_EMAIL)
          ->setBody('Body')
          ->setPayload(['a' => 1]);

        $restored = Notification::fromArray($n->toArray());

        $this->assertSame('calendar', $restored->getSourceModule());
        $this->assertSame('entry-1', $restored->getSourceRef());
        $this->assertSame('Reminder', $restored->getTitle());
        $this->assertSame('5', $restored->getRecipientUserId());
        $this->assertSame(['a' => 1], $restored->getPayload());
    }
}
