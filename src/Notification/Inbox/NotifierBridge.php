<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Workflow\CalcRegistry;

/**
 * Bridges the workflow step engine to the notification subsystem.
 *
 * Register the bridge as a CalcRegistry resolver:
 *
 *   $calc->register('common.notifier', [$bridge, 'notify']);
 *
 * so a workflow call verb reaches the Notifier:
 *
 *   ['call', 'common.notifier', 'notify', [
 *       'target'  => ['roles' => ['SA_LEAVE_APPROVE']],
 *       'type'    => 'hr.leave.submitted',
 *       'payload' => ['request_id' => 'dto.id', 'person' => 'dto.person'],
 *       'ref'     => 'hr:leave:dto.id',
 *   ]]
 *
 * The StepEngine seeds `context['call_args']` from verb row 4 (with any
 * `result.*`/`dto.*` references resolved) before calling the resolver, and
 * stores the return value as `result.*` via its normal call-verb handling.
 *
 * The bridge is fully DI-able: pass the exact Notifier instance the runtime
 * uses (FA runtime: factory-created; tests: in-memory).
 */
class NotifierBridge
{
    /** @var Notifier */
    private $notifier;

    public function __construct(Notifier $notifier)
    {
        $this->notifier = $notifier;
    }

    /**
     * CalcRegistry resolver callback — invoked as `[$bridge, 'notify']`.
     *
     * @param array $dto     current DTO (latched into payload when requested)
     * @param array $context step context, incl. 'call_args' seeded by the engine
     *
     * @return array<string,mixed> resolved outcome, exposed to the chain as
     *                             `result.*` (e.g. result.notify_count)
     */
    public function notify(array $dto, array $context): array
    {
        $args   = isset($context['call_args']) && \is_array($context['call_args'])
            ? $context['call_args'] : [];
        $target = isset($args['target']) && \is_array($args['target']) ? $args['target'] : [];
        $type   = (string) ($args['type'] ?? 'system');
        $payload = isset($args['payload']) && \is_array($args['payload'])
            ? $args['payload'] : [];
        $ref    = isset($args['ref']) ? (string) $args['ref'] : null;
        $data   = isset($args['context']) && \is_array($args['context'])
            ? $args['context'] : [];

        $count = 0;
        if ($target !== []) {
            $count = $this->notifier->notify($target, $type, $payload, $ref, $data);
        }
        return ['notify_count' => $count];
    }

    /**
     * Convenience factory: registers the bridge under 'common.notifier'.
     *
     * @return CalcRegistry
     */
    public static function registerOn(CalcRegistry $calc, Notifier $notifier): CalcRegistry
    {
        $calc->register('common.notifier', [new self($notifier), 'notify']);
        return $calc;
    }
}