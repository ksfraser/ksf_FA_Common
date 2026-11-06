<?php
/**
 * EntityUrlResolverHookContract — hook names and payload keys for the shared
 * entity URL resolver.
 *
 * A platform that is NOT FrontAccounting (WordPress, Nextcloud, a standalone
 * CLI) answers the hook instead of implementing the FA adapter, so consumers
 * keep calling one API:
 *
 *   $data = [
 *       'operation' => 'resolve_url',
 *       'arguments' => ['entity_type' => 'customer', 'entity_id' => '123'],
 *       'result'    => null,
 *       'handled'   => false,
 *   ];
 *   hook_invoke_all(EntityUrlResolverHookContract::HOOK_RESOLVE_URL, $data);
 *
 * A listener that does not understand the operation leaves 'handled' false;
 * the resolver then falls back to its own implementation.  A listener's
 * absence is a normal state, never an error.
 *
 * @package ksfraser\FrontAccounting\Common\Contract
 * @since   2.4.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Contract;

/**
 * Hook names and payload keys for entity URL resolution.
 *
 * @package ksfraser\FrontAccounting\Common\Contract
 * @since   2.4.0
 */
final class EntityUrlResolverHookContract
{
    /** The single hook a non-FA platform answers. */
    public const HOOK_RESOLVE_URL = 'ksf_entity_url_resolve';

    /** Payload key: the lookup being asked for (always 'resolve_url'). */
    public const KEY_OPERATION = 'operation';

    /** Payload key: the lookup's arguments, as ['entity_type' => ..., 'entity_id' => ...]. */
    public const KEY_ARGUMENTS = 'arguments';

    /** Payload key: the answer (a URL string). A listener sets this AND 'handled'. */
    public const KEY_RESULT = 'result';

    /** Payload key: set to true by a listener that answered the question. */
    public const KEY_HANDLED = 'handled';

    /** Operation: resolve entity URL. Arguments: entity_type, entity_id. */
    public const OP_RESOLVE_URL = 'resolve_url';

    /**
     * Not instantiable.
     */
    private function __construct()
    {
    }
}
