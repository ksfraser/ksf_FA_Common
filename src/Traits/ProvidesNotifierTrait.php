<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\NotificationPreferenceStoreInterface;
use ksfraser\FrontAccounting\Common\Notification\Inbox\FaInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\FaNotificationPreferenceStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InboxService;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryNotificationPreferenceStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotificationTypeRegistry;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotificationTypeRenderer;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Notifier;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotifierFactory;
use ksfraser\FrontAccounting\Common\Notification\Inbox\NotifierBridge;
use ksfraser\FrontAccounting\Common\Notification\Inbox\RecipientResolver;

/**
 * Notification glue for owning modules (BR-COM-03).
 *
 * Provides:
 *   - notificationTypeRegistry() / notificationTypeRenderer()  (schema-driven)
 *   - notifierFactory() / notifier()  (FA runtime vs in-memory)
 *   - inboxService()  (badge/list/read/dismiss with mute filtering)
 *   - notifierBridge() wired for the CalcRegistry 'common.notifier' key
 *   - registerNotificationType($type, $definition)  (module types on hooks)
 *
 * Nothing here touches FA unless a module explicitly calls the FA-backed
 * accessors; unit tests use the in-memory variants. PHP 7.3 floor.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
trait ProvidesNotifierTrait
{
    /** @var NotificationTypeRegistry|null */
    private $notificationTypeRegistry;

    /** @var NotificationTypeRenderer|null */
    private $notificationTypeRenderer;

    /** @var Notifier|null */
    private $notifier;

    /** @var InboxService|null */
    private $inboxService;

    /** @var \ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\RecipientResolverInterface|null */
    private $recipientResolver;

    /** @var InboxStorageInterface|null */
    private $inboxStorage;

    /** @var NotificationPreferenceStoreInterface|null */
    private $preferenceStore;

    /** @var NotifierFactory|null */
    private $notifierFactory;

    protected function notificationTypeRegistry(): NotificationTypeRegistry
    {
        if ($this->notificationTypeRegistry === null) {
            $this->notificationTypeRegistry = new NotificationTypeRegistry();
        }
        return $this->notificationTypeRegistry;
    }

    protected function notificationTypeRenderer(): NotificationTypeRenderer
    {
        if ($this->notificationTypeRenderer === null) {
            $this->notificationTypeRenderer = new NotificationTypeRenderer(
                $this->notificationTypeRegistry()
            );
        }
        return $this->notificationTypeRenderer;
    }

    protected function registerNotificationType(string $type, array $definition): void
    {
        $this->notificationTypeRegistry()->register($type, $definition);
    }

    protected function notificationPreferenceStore(): NotificationPreferenceStoreInterface
    {
        if ($this->preferenceStore === null) {
            $this->preferenceStore = function_exists('db_query') && \defined('TB_PREF')
                ? new FaNotificationPreferenceStore()
                : new InMemoryNotificationPreferenceStore();
        }
        return $this->preferenceStore;
    }

    protected function inboxStorage(): InboxStorageInterface
    {
        if ($this->inboxStorage === null) {
            $this->inboxStorage = function_exists('db_query') && \defined('TB_PREF')
                ? new FaInboxStore()
                : new InMemoryInboxStore();
        }
        return $this->inboxStorage;
    }

    protected function notifierFactory(): NotifierFactory
    {
        if ($this->notifierFactory === null) {
            $this->notifierFactory = new NotifierFactory();
        }
        return $this->notifierFactory;
    }

    protected function notifier(): Notifier
    {
        if ($this->notifier === null) {
            $this->notifier = $this->notifierFactory()->create(
                $this->inboxStorage(),
                $this->recipientResolver()
            );
        }
        return $this->notifier;
    }

    protected function recipientResolver(): RecipientResolver
    {
        if ($this->recipientResolver === null) {
            $this->recipientResolver = new RecipientResolver();
        }
        return $this->recipientResolver;
    }

    protected function inboxService(): InboxService
    {
        if ($this->inboxService === null) {
            $this->inboxService = new InboxService(
                $this->inboxStorage(),
                $this->notificationTypeRenderer(),
                $this->notificationPreferenceStore()
            );
        }
        return $this->inboxService;
    }

    /**
     * Wire the bridge into a CalcRegistry for `['call','common.notifier',...]`.
     */
    protected function notifierBridge(): NotifierBridge
    {
        return new NotifierBridge($this->notifier());
    }
}