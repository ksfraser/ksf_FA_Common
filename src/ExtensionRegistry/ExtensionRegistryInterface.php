<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ExtensionRegistry;

/**
 * Contract for module extension registries.
 *
 * A generic key-value registry that collects registrations from multiple
 * modules via direct registration (traits) or FA hooks.  Serves as the
 * single source of truth for extensible type definitions, menu items,
 * labels, and other module-contributed metadata.
 *
 * @package KsfCommon\ExtensionRegistry
 * @since   2.1.0
 */
interface ExtensionRegistryInterface
{
    /**
     * Register a definition under a category and key.
     *
     * @param string $category  Registration category (e.g. 'source_types', 'menu_items')
     * @param string $key       Unique key within the category (e.g. 'campaign', 'my_campaigns')
     * @param array  $definition  Key-value pairs defining the registration
     * @return void
     */
    public function register(string $category, string $key, array $definition): void;

    /**
     * Return all registrations for a given category.
     *
     * @param string $category
     * @return array<string, array>  Keyed by registration key
     */
    public function getRegistered(string $category): array;

    /**
     * Return a single registration by category and key.
     *
     * @param string $category
     * @param string $key
     * @return array|null  Registration definition, or null if not found
     */
    public function get(string $category, string $key): ?array;

    /**
     * Check whether a key is registered under a given category.
     *
     * @param string $category
     * @param string $key
     * @return bool
     */
    public function isValid(string $category, string $key): bool;

    /**
     * Return the list of all registered category names.
     *
     * @return string[]
     */
    public function getCategories(): array;

    /**
     * Clear all registrations and reset boot state.
     *
     * @return void
     */
    public function reset(): void;
}
