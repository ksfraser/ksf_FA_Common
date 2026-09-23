<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

/**
 * Notification type registry (BR-COM-03 FR-COM-03-003).
 *
 * Modules register per-type rendering schemas (title/body/link/icon) plus an
 * optional custom callable renderer. Rendering stays schema-driven so the
 * inbox can render any module's notifications without knowing them.
 *
 * A type definition:
 *   [
 *     'title'  => 'Leave approved',                       // title line
 *     'body'   => ['request_id' => 'Request #%s',
 *                  'person'     => 'For %s'],             // label => sprintf pattern
 *     'link'   => ['href' => 'modules/ksf_FA_HRM/pages/leave.php',
 *                  'id_param' => 'id',                    // URL query param for the id
 *                  'id_from'  => 'request_id'],           // payload key for the id value
 *     'icon'   => 'icon_leave',
 *     'renderer' => null,                                 // optional custom callable
 *   ]
 *
 * Unknown types do not throw — the renderer degrades to a raw JSON dump.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class NotificationTypeRegistry
{
    /** @var array<string,array<string,mixed>> */
    private $types = [];

    public function register(string $type, array $definition): void
    {
        $this->types[$type] = [
            'title'    => isset($definition['title']) ? (string) $definition['title'] : $type,
            'body'     => isset($definition['body']) && \is_array($definition['body'])
                ? $definition['body'] : [],
            'link'     => isset($definition['link']) && \is_array($definition['link'])
                ? $definition['link'] : [],
            'icon'     => isset($definition['icon']) ? (string) $definition['icon'] : null,
            'renderer' => isset($definition['renderer']) && \is_callable($definition['renderer'])
                ? $definition['renderer'] : null,
        ];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @return array<string,mixed>|null null when unregistered
     */
    public function get(string $type): ?array
    {
        return $this->types[$type] ?? null;
    }

    /**
     * @return string[]
     */
    public function types(): array
    {
        return \array_keys($this->types);
    }
}