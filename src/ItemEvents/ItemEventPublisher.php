<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ItemEvents;

/**
 * Item Event Publisher
 *
 * Broadcasts FrontAccounting stock item lifecycle events to all listening
 * modules using FA's hook_invoke_all() mechanism.
 *
 * Canonical event contract:
 *   hook_invoke_all('item_created', $data)  - fired after a new stock item is written
 *   hook_invoke_all('item_updated', $data)  - fired after an existing stock item changes
 *   hook_invoke_all('ksf_crud_event', $data) - generic CRUD broadcast for broad listeners
 *
 * Payload shape (listeners should re-fetch full item data via their own DAOs):
 *   [
 *     'stock_id'  => string,                  // FA stock_id (SKU)
 *     'event'     => 'created' | 'updated',
 *     'trigger'   => 'watcher' | 'publisher' | 'page' | 'module' | 'cli',
 *     'timestamp' => 'Y-m-d H:i:s',
 *     'data'      => array,                   // optional extra context
 *   ]
 *
 * The FA hook functions are resolved at call time. In environments where
 * FrontAccounting is not loaded (e.g. unit tests) a dispatcher and/or a
 * known-item checker may be injected to observe or replace the behaviour.
 *
 * @package KsfCommon
 * @since   1.6.0
 */
class ItemEventPublisher
{
    /** @var string Event name for new stock items. */
    const EVENT_CREATED = 'created';

    /** @var string Event name for modified stock items. */
    const EVENT_UPDATED = 'updated';

    /** @var callable|null Optional dispatcher, fn(string $hook, array $payload): void. */
    private $dispatcher;

    /** @var callable|null Optional known-item checker, fn(string $stockId): bool. */
    private $knownChecker;

    /**
     * @param callable|null $dispatcher   Overrides hook_invoke_all() broadcasting
     * @param callable|null $knownChecker Overrides hook_invoke('ksf_FA_Common', 'isItemKnown')
     *
     * @since 1.6.0
     */
    public function __construct(?callable $dispatcher = null, ?callable $knownChecker = null)
    {
        $this->dispatcher = $dispatcher;
        $this->knownChecker = $knownChecker;
    }

    /**
     * Publish an "item created" event.
     *
     * @param string $stockId FA stock_id of the new item
     * @param array  $context Optional extra context (e.g. changed fields)
     * @param string $trigger Origin of the event
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function publishCreated(string $stockId, array $context = [], string $trigger = 'publisher'): void
    {
        $this->publish(self::EVENT_CREATED, $stockId, $context, $trigger);
    }

    /**
     * Publish an "item updated" event.
     *
     * @param string $stockId FA stock_id of the modified item
     * @param array  $context Optional extra context (e.g. changed fields)
     * @param string $trigger Origin of the event
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function publishUpdated(string $stockId, array $context = [], string $trigger = 'publisher'): void
    {
        $this->publish(self::EVENT_UPDATED, $stockId, $context, $trigger);
    }

    /**
     * Publish a create-or-update event for an item whose lifecycle is unknown.
     *
     * Asks the ksf_FA_Common hooks class whether the item already has sync
     * state; unknown items emit "created", known items emit "updated". When
     * the state cannot be determined (Common not installed) the event falls
     * back to "created" because the caller is reporting a freshly written item.
     *
     * @param string $stockId FA stock_id
     * @param array  $context Optional extra context
     * @param string $trigger Origin of the event
     *
     * @return string The event that was actually published ('created'|'updated')
     *
     * @since 1.6.0
     */
    public function publishChanged(string $stockId, array $context = [], string $trigger = 'publisher'): string
    {
        if ($this->isItemKnown($stockId)) {
            $this->publishUpdated($stockId, $context, $trigger);
            return self::EVENT_UPDATED;
        }

        $this->publishCreated($stockId, $context, $trigger);
        return self::EVENT_CREATED;
    }

    /**
     * Publish an item lifecycle event.
     *
     * @param string $event   'created' or 'updated'
     * @param string $stockId FA stock_id
     * @param array  $context Optional extra context
     * @param string $trigger Origin of the event
     *
     * @return void
     *
     * @since 1.6.0
     */
    public function publish(string $event, string $stockId, array $context = [], string $trigger = 'publisher'): void
    {
        $hook = $event === self::EVENT_CREATED ? 'item_created' : 'item_updated';

        $payload = [
            'stock_id'  => $stockId,
            'event'     => $event,
            'trigger'   => $trigger,
            'timestamp' => date('Y-m-d H:i:s'),
            'data'      => $context,
        ];

        $this->dispatch($hook, $payload);
    }

    /**
     * Dispatch an event payload to all listening modules.
     *
     * @param string $hook    Event hook name
     * @param array  $payload Event payload
     *
     * @return void
     *
     * @since 1.6.0
     */
    private function dispatch(string $hook, array $payload): void
    {
        if ($this->dispatcher !== null) {
            call_user_func($this->dispatcher, $hook, $payload);
            return;
        }

        if (!function_exists('hook_invoke_all')) {
            return;
        }

        hook_invoke_all($hook, $payload);
        hook_invoke_all('ksf_crud_event', $payload);
    }

    /**
     * Determine whether an item already has sync state in ksf_FA_Common.
     *
     * @param string $stockId FA stock_id
     *
     * @return bool True when the item is known to the platform
     *
     * @since 1.6.0
     */
    private function isItemKnown(string $stockId): bool
    {
        if ($this->knownChecker !== null) {
            return (bool) call_user_func($this->knownChecker, $stockId);
        }

        if (!function_exists('hook_invoke')) {
            return false;
        }

        $data = ['stock_id' => $stockId];
        hook_invoke('ksf_FA_Common', 'isItemKnown', $data);
        return !empty($data['known']);
    }
}
