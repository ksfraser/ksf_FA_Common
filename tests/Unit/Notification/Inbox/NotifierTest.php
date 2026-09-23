<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\RecipientResolverInterface;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InboxService;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryNotificationPreferenceStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotificationTypeRegistry;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotificationTypeRenderer;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Notifier;
use ksfraser\FrontAccounting\Common\Notification\Inbox\RecipientResolver;
use PHPUnit\Framework\TestCase;

/**
 * @BABOK Related: BR-COM-03
 */
final class NotifierTest extends TestCase
{
    public function testNotifyExplicitUsersStoresOneRowPerRecipient(): void
    {
        $store = new InMemoryInboxStore();
        $resolver = new RecipientResolver(function (int $id): bool {
            return true;
        });
        $notifier = new Notifier($store, $resolver);

        $count = $notifier->notify(['users' => [3, 7]], 'hr.leave.approved', ['request' => 9]);

        $this->assertSame(2, $count);
        $this->assertSame(1, \count($store->listForUser(3)));
        $this->assertSame(1, \count($store->listForUser(7)));
    }

    public function testNotifyZeroRecipientsStoresNothing(): void
    {
        $store = new InMemoryInboxStore();
        $resolver = new RecipientResolver(function (int $id): bool {
            return false;
        });
        $notifier = new Notifier($store, $resolver);

        $this->assertSame(0, $notifier->notify(['users' => [999]], 'hr.leave.approved'));
        $this->assertSame(0, $store->unreadCount(999));
    }

    public function testNotifyUnresolvableTargetIsSilent(): void
    {
        $store = new InMemoryInboxStore();
        $notifier = new Notifier($store, new RecipientResolver(function (int $id): bool {
            return false;
        }));

        $this->assertSame(0, $notifier->notify(['bogus' => true], 'hr.leave.approved'));
    }

    public function testAllBroadcastMaterializesPerUserOnRead(): void
    {
        $store = new InMemoryInboxStore();
        $notifier = new Notifier($store, new RecipientResolver(function (int $id): bool {
            return true;
        }));

        $this->assertSame(1, $notifier->notify(['all' => true], 'system.maintenance', ['msg' => 'down']));
        // the @all placeholder row exists with uid 0
        $this->assertSame(1, $store->unreadCount(0));

        $this->assertSame(1, $store->materializeAll(4));
        $this->assertSame(1, $store->unreadCount(4));

        // idempotent on a second poll (durable watermark)
        $this->assertSame(0, $store->materializeAll(4));
        $this->assertSame(1, $store->unreadCount(4));
    }

    public function testMuteFilteredRowsStayStored(): void
    {
        $store = new InMemoryInboxStore();
        $prefs = new InMemoryNotificationPreferenceStore();
        $notifier = new Notifier($store, new RecipientResolver(function (int $id): bool {
            return true;
        }));

        $this->assertSame(1, $notifier->notify(['users' => [5]], 'hr.leave.approved'));
        $this->assertSame(1, $notifier->notify(['users' => [5]], 'system.maintenance'));

        $prefs->setMutedTypes(5, ['hr.leave.approved']);

        $registry = new NotificationTypeRegistry();
        $registry->register('system.maintenance', ['title' => 'Maintenance']);
        $service = new InboxService($store, new NotificationTypeRenderer($registry), $prefs);

        // badge excludes muted but the rows remain in storage
        $this->assertSame(1, $service->unreadBadge(5));
        $this->assertSame(2, $store->unreadCount(5));
    }

    public function testInboxServiceReadAndDismiss(): void
    {
        $store = new InMemoryInboxStore();
        $registry = new NotificationTypeRegistry();
        $registry->register('hr.leave.approved', [
            'title' => 'Leave approved',
            'body'  => ['request' => 'Request #%s'],
            'link'  => ['href' => 'modules/x/pages/leave.php', 'id_param' => 'id', 'id_from' => 'request_id'],
        ]);
        $service = new InboxService(
            $store,
            new NotificationTypeRenderer($registry),
            new InMemoryNotificationPreferenceStore()
        );

        $id = $store->insert([
            'recipient_uid' => 8,
            'type'          => 'hr.leave.approved',
            'payload'       => ['request_id' => 42],
        ]);
        $this->assertSame(1, $service->unreadBadge(8));

        $list = $service->listForUser(8);
        $this->assertCount(1, $list);
        $this->assertSame('Leave approved', $list[0]['title']);
        $this->assertStringContainsString('id=42', $list[0]['link']);
        $this->assertFalse($list[0]['read']);

        $this->assertTrue($service->markRead($id, 8));
        $this->assertSame(0, $service->unreadBadge(8));
        $this->assertTrue($service->dismiss($id, 8));
        $this->assertSame([], $store->unread(8));
    }

    public function testUnknownTypeRendersFallbackWithoutThrowing(): void
    {
        $store = new InMemoryInboxStore();
        $service = new InboxService(
            $store,
            new NotificationTypeRenderer(new NotificationTypeRegistry()),
            new InMemoryNotificationPreferenceStore()
        );

        $store->insert(['recipient_uid' => 2, 'type' => 'module.custom', 'payload' => ['a' => 1]]);
        $list = $service->listForUser(2);
        $this->assertCount(1, $list);
        $this->assertSame('module.custom', $list[0]['title'] ?? '');
        $this->assertSame(['a' => 1], $list[0]['raw'] ?? []);
    }

    public function testDtoFieldRoutingResolvesThroughContext(): void
    {
        $store = new InMemoryInboxStore();
        $resolver = new RecipientResolver(
            function (int $id): bool {
                return true;
            },
            null,
            function (string $type, $id): array {
                return $type === 'leave_request' ? ['owner' => 55] : [];
            }
        );
        $notifier = new Notifier($store, $resolver);

        $count = $notifier->notify(
            ['dto' => ['type' => 'leave_request', 'id' => 42, 'field' => 'owner']],
            'hr.leave.approved'
        );
        $this->assertSame(1, $count);
        $this->assertSame(1, $store->unreadCount(55));
    }

    public function testRoleResolverUsesInjectedResolver(): void
    {
        $store = new InMemoryInboxStore();
        $injected = new class implements RecipientResolverInterface {
            public function resolve(array $target, array $context = []): array
            {
                return [10, 11];
            }
        };
        $notifier = new Notifier($store, $injected);

        $this->assertSame(2, $notifier->notify(['roles' => ['SA_LEAVE_APPROVE']]));
    }
}