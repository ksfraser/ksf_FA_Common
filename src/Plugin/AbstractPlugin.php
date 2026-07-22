<?php

namespace ksfraser\FrontAccounting\Common\Plugin;

/**
 * Base class for plugins with sensible defaults.
 *
 * Override getName() and isActive() at minimum. All other methods
 * are no-ops by default — override what you need.
 */
abstract class AbstractPlugin implements PluginInterface
{
    /** {@inheritDoc} */
    public function isActive(): bool
    {
        return true;
    }
}
