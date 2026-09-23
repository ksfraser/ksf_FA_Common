<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox\Contract;

/**
 * Recipient resolution contract (BR-COM-03 FR-COM-03-002).
 *
 * A routing target is an array of one or more named recipient selectors;
 * the resolver expands them into concrete FA user ids at publish time:
 *
 *   ['users'  => [3, 7]]                     explicit FA user ids
 *   ['roles'  => ['SA_LEAVE_APPROVE', ...]]  every user holding a security area
 *   ['dto'    => ['type' => 'leave_request',  'id' => 42, 'field' => 'owner']]
 *                                            field value via get_dto schema
 *   ['all'    => true]                       @all broadcast (uid 0 placeholder)
 *
 * Unknown selectors / unresolvable values yield zero ids — never throw
 * (silence is safe, BR-COM-03 req 6).
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface RecipientResolverInterface
{
    /**
     * Resolve a routing target to concrete FA user ids.
     *
     * @param array $target  selector array as documented above
     * @param array $context optional ['dto' => array] payload for dto routing
     *
     * @return int[] FA user ids (0 = @all placeholder)
     */
    public function resolve(array $target, array $context = []): array;
}