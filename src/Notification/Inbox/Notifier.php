<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;
use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\RecipientResolverInterface;

/**
 * Inbox publisher (BR-COM-03 FR-COM-03-001).
 *
 * Least-effort publish API — one call stores one row per resolved recipient
 * (`users`/`roles`/`dto`/`all` routing, FR-COM-03-002). The subscriber engine
 * owns persistence/routing/presentation, not the publisher.
 *
 * Semantics:
 *   - append-only: a repeated notify() is a repeated row set;
 *   - unknown type is still stored (renderer degrades, notipublisher);
 *   - unresolvable recipients => zero rows, never throws;
 *   - @all => one uid-0 placeholder row, materialized per user on first read.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class Notifier
{
    /** @var InboxStorageInterface */
    private $storage;

    /** @var RecipientResolverInterface */
    private $resolver;

    public function __construct(
        InboxStorageInterface $storage,
        RecipientResolverInterface $resolver
    ) {
        $this->storage  = $storage;
        $this->resolver = $resolver;
    }

    /**
     * Publish a notification to resolved recipients.
     *
     * @param array  $target  routing target (see RecipientResolverInterface)
     * @param string $type    notification type (registered or not — stored anyway)
     * @param array  $payload JSON-safe body fields
     * @param string|null $ref deep-link ref (e.g. 'hr:leave_request:42')
     * @param array  $context optional ['dto' => array] for dto field routing
     *
     * @return int number of rows stored (0 for unresolvable recipients)
     */
    public function notify(
        array $target,
        string $type = 'system',
        array $payload = [],
        ?string $ref = null,
        array $context = []
    ): int {
        $recipients = $this->resolver->resolve($target, $context);
        if ($recipients === []) {
            return 0;
        }

        $count = 0;
        $at = \date('Y-m-d H:i:s');
        foreach ($recipients as $uid) {
            try {
                $this->storage->insert([
                    'recipient_uid' => (int) $uid,
                    'type'          => $type,
                    'payload'       => $payload,
                    'ref'           => $ref,
                    'created_at'    => $at,
                ]);
                $count++;
            } catch (\Throwable $e) {
                // silence is safe: one bad row never breaks a step chain
            }
        }
        return $count;
    }
}