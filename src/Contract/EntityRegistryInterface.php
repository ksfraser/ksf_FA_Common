<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Contract;

/**
 * Entity registry contract - source of truth for entity type definitions.
 *
 * Each entity type defines: table, pk, label, module, picker, multiple, title,
 * options_hook (optional). Modules can extend via NOTES_ENTITY_TYPES hook.
 *
 * @since 1.0.0
 */
interface EntityRegistryInterface
{
    /**
     * Get the full entity type registry.
     *
     * @return array<string, array> entity_type => definition
     */
    public function all(): array;

    /**
     * Get one entity type definition.
     *
     * @param string $entity_type Registry key
     * @return array|null Definition or null when unknown
     */
    public function get(string $entity_type): ?array;

    /**
     * Get entity types that can be offered in pickers (picker => true).
     *
     * @return array<string, array>
     */
    public function pickable(): array;

    /**
     * Human labels for registered types (type => title).
     *
     * @return array<string, string>
     */
    public function typeLabels(): array;

    /**
     * Link roles valid for one entity type.
     *
     * @param string $entity_type Registry key
     * @return array<string, string> role => label
     */
    public function linkRolesFor(string $entity_type): array;

    /**
     * Default link roles when type has no declared roles.
     *
     * @return array<string, string>
     */
    public function defaultLinkRoles(): array;

    /**
     * Note type categories (note_type => label).
     *
     * @return array<string, string>
     */
    public function noteTypes(): array;
}