<?php

namespace ksfraser\FrontAccounting\Common\Plugin;

/**
 * Generic contract for a plugin.
 *
 * Implement this interface to create a plugin that can be discovered
 * and managed by the PluginRegistry. This is intentionally minimal —
 * domain-specific contracts (e.g. ProductAttributeTabInterface) extend
 * this with additional methods.
 */
interface PluginInterface
{
    /**
     * Unique identifier for this plugin.
     */
    public function getName(): string;

    /**
     * Whether this plugin is currently active.
     * Inactive plugins are still registered but skipped during execution.
     */
    public function isActive(): bool;
}
