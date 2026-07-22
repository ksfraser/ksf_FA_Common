<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ExtensionRegistry;

/**
 * Generic singleton extension registry.
 *
 * Collects registrations from multiple modules and provides lookups by
 * category and key.  Two registration paths are supported:
 *
 *  1. Direct registration via traits (e.g. CalendarRegistrationTrait)
 *     — modules call register() during activate_extension() or init.
 *
 *  2. Hook-based registration via boot()
 *     — fires hook_invoke_all() for each registered hook name, allowing
 *       modules to push their definitions into the registry.
 *
 * The registry is a per-request singleton.  Registrations are not persisted
 * to the database — modules must re-register on each request (typically
 * during module init or activate_extension).
 *
 * @package KsfCommon\ExtensionRegistry
 * @since   2.1.0
 */
class ExtensionRegistry implements ExtensionRegistryInterface
{
    /** @var self|null Singleton instance */
    private static $instance = null;

    /** @var array<string, array<string, array>> category => key => definition */
    private $registrations = [];

    /** @var bool Whether boot() has been called */
    private $booted = false;

    /** @var string[] Hook names that have been booted */
    private $bootedHooks = [];

    /**
     * Return the singleton instance.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fire FA hooks to collect registrations from all loaded modules.
     *
     * Each hook name corresponds to a registration category.  The hook
     * handler receives &$data (by reference) and adds its registrations
     * to the array.  Hook names are typically derived from the module
     * prefix, e.g.:
     *
     *   hook_invoke_all('calendar_register_source_types', $data)
     *   hook_invoke_all('calendar_register_menu_items', $data)
     *
     * Boot is idempotent — calling it multiple times is safe.
     *
     * @param string[] $hookNames  FA hook names to fire
     * @return void
     *
     * @since 2.1.0
     */
    public function boot(array $hookNames): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($hookNames as $hookName) {
            if (in_array($hookName, $this->bootedHooks, true)) {
                continue;
            }

            $category = $hookName;
            if (!isset($this->registrations[$category])) {
                $this->registrations[$category] = [];
            }

            if (function_exists('hook_invoke_all')) {
                hook_invoke_all($hookName, $this->registrations[$category]);
            }

            $this->bootedHooks[] = $hookName;
        }

        $this->booted = true;
    }

    /**
     * {@inheritdoc}
     */
    public function register(string $category, string $key, array $definition): void
    {
        if (!isset($this->registrations[$category])) {
            $this->registrations[$category] = [];
        }

        $this->registrations[$category][$key] = $definition;
    }

    /**
     * {@inheritdoc}
     */
    public function getRegistered(string $category): array
    {
        return $this->registrations[$category] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $category, string $key): ?array
    {
        return $this->registrations[$category][$key] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function isValid(string $category, string $key): bool
    {
        return isset($this->registrations[$category][$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function getCategories(): array
    {
        return array_keys($this->registrations);
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        $this->registrations = [];
        $this->booted = false;
        $this->bootedHooks = [];
    }

    /**
     * Destroy the singleton (useful for testing).
     *
     * @return void
     */
    public static function destroyInstance(): void
    {
        self::$instance = null;
    }
}
