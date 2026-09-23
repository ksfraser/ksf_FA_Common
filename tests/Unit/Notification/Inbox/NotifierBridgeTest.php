<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Notifier;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotifierBridge;
use ksfraser\FrontAccounting\Common\Notification\Inbox\RecipientResolver;
use ksfraser\FrontAccounting\Common\Workflow\CalcRegistry;
use ksfraser\FrontAccounting\Common\Workflow\StepEngine;
use ksfraser\FrontAccounting\Common\Workflow\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: BR-COM-03, BR-COM-01
 */
final class NotifierBridgeTest extends TestCase
{
    public function testCallVerbNotifiesAndSetsResult(): void
    {
        $store = new InMemoryInboxStore();
        $notifier = new Notifier($store, new RecipientResolver(function (int $id): bool {
            return true;
        }));
        $bridge = new NotifierBridge($notifier);

        $calc = new CalcRegistry();
        NotifierBridge::registerOn($calc, $notifier);

        $registry = WorkflowRegistry::getInstance();
        $registry->clear();
        $registry->register('leave_request', 'after_save', [
            ['do' => [
                ['call', 'common.notifier', 'notify', [
                    'target'  => ['users' => [3]],
                    'type'    => 'hr.leave.submitted',
                    'payload' => ['person' => 'dto.person'],
                    'ref'     => 'dto.id',
                ]],
            ]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $result = $engine->run('leave_request', 'after_save', ['id' => 9, 'person' => 'Ana']);
        $this->assertFalse($result['stopped']);
        $this->assertSame(1, $store->unreadCount(3));
        $this->assertSame('Ana', $store->unread(3)[0]['payload']['person'] ?? null);
        $this->assertSame('9', $store->unread(3)[0]['ref'] ?? null);
    }

    public function testCallVerbUnknownResolverSetsResultErrorFaultTolerant(): void
    {
        $store = new InMemoryInboxStore();
        $notifier = new Notifier($store, new RecipientResolver(function (int $id): bool {
            return true;
        }));

        $calc = new CalcRegistry();
        $registry = WorkflowRegistry::getInstance();
        $registry->clear();
        $registry->register('leave_request', 'after_save', [
            ['do' => [
                ['call', 'nope.notifier', 'notify', ['target' => ['users' => [1]]]],
            ]],
        ]);

        $engine = new StepEngine($registry, $calc);
        $result = $engine->run('leave_request', 'after_save', ['id' => 1]);
        $this->assertFalse($result['stopped']);
        $this->assertSame(0, $store->unreadCount(1));
    }
}