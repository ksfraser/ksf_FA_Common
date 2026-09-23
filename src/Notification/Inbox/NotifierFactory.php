<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;

/**
 * Notifier factory (BR-COM-03).
 *
 * Builds the inbox stack for the runtime context:
 *
 *   FA runtime  -> FaInboxStore + FaNotificationPreferenceStore + role/DTO
 *                  backed RecipientResolver (native db_* only).
 *   standalone  -> InMemory* stores + injected resolver (unit tests, CLI,
 *                  any non-FA embedding).
 *
 * Slim by design: the InboxService stays ignorant of the underlying store, so
 * modules only interact with the interfaces.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class NotifierFactory
{
    /**
     * @return Notifier
     */
    public function create(
        ?InboxStorageInterface $storage = null,
        ?RecipientResolverInterface $resolver = null,
        bool $forceInMemory = false
    ): Notifier {
        $storage  = $storage  ?: ($forceInMemory ? $this->inMemoryStorage() : $this->faStorage());
        $resolver = $resolver ?: $this->defaultResolver();
        return new Notifier($storage, $resolver);
    }

    public function inMemoryStorage(): InboxStorageInterface
    {
        return new InMemoryInboxStore();
    }

    public function faStorage(): InboxStorageInterface
    {
        return new FaInboxStore();
    }

    public function defaultResolver(): RecipientResolverInterface
    {
        return new RecipientResolver();
    }
}