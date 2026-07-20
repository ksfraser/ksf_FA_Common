<?php

namespace KsfCommon\Plugin;

/**
 * Auto-discovery registry for plugins.
 *
 * Scans directories for PHP files containing classes that implement
 * PluginInterface. Supports both file-level scanning and manual
 * registration.
 *
 * Usage:
 *   $registry = new PluginRegistry();
 *   $registry->discover('/path/to/plugins');
 *   $registry->register(new MyPlugin());
 *
 *   foreach ($registry->getActive() as $plugin) {
 *       // use $plugin
 *   }
 */
class PluginRegistry
{
    /** @var PluginInterface[] keyed by plugin name */
    private array $plugins = [];

    /**
     * Scan a directory for PHP files and register any PluginInterface
     * implementations found.
     *
     * @param string $directory Absolute path to scan
     */
    public function discover(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $this->loadFile($file);
        }
    }

    /**
     * Load a single file and register any PluginInterface class found.
     */
    public function loadFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $before = get_declared_classes();
        require_once $file;
        $after = get_declared_classes();

        $newClasses = array_diff($after, $before);
        foreach ($newClasses as $className) {
            $rc = new \ReflectionClass($className);
            if ($rc->isInterface()
                || $rc->isAbstract()
                || !$rc->implementsInterface(PluginInterface::class)) {
                continue;
            }

            /** @var PluginInterface $instance */
            $instance = $rc->newInstance();
            $this->register($instance);
        }
    }

    /**
     * Manually register a plugin instance.
     */
    public function register(PluginInterface $plugin): void
    {
        $this->plugins[$plugin->getName()] = $plugin;
    }

    /**
     * Get all registered plugins.
     *
     * @return PluginInterface[]
     */
    public function getAll(): array
    {
        return $this->plugins;
    }

    /**
     * Get only active plugins.
     *
     * @return PluginInterface[]
     */
    public function getActive(): array
    {
        return array_filter(
            $this->plugins,
            fn(PluginInterface $p) => $p->isActive()
        );
    }

    /**
     * Get a single plugin by name.
     */
    public function get(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Check whether a plugin is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Remove all registered plugins.
     */
    public function clear(): void
    {
        $this->plugins = [];
    }
}
