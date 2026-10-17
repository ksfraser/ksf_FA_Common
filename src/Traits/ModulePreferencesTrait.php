<?php

declare(strict_types=1);

/**
 * Module Preferences Trait - ergonomic access to the shared module preference store.
 *
 * A module's hooks class mixes this trait in and then reads/writes preferences
 * without repeating its own name and the current user at every call site:
 *
 *   class hooks_ksf_FA_Calendar extends hooks
 *   {
 *       use ModulePreferencesTrait;
 *
 *       var $module_name = 'ksf_FA_Calendar';
 *
 *       public function getDefaultView(): string
 *       {
 *           return $this->pref('default_view', 'month');
 *       }
 *   }
 *
 * Storage is PreferenceRepository, i.e. the single cross-module
 * `0_ksf_module_prefs` table (AGENTS_ARCH.md section 12) keyed by
 * (module_name, user_id, pref_key). Nothing here touches the database until a
 * pref()/setPref() call is made — construction is side-effect free.
 *
 * @package KSF\Common\Traits
 * @since   2.2.0
 */
namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\Preference\PreferenceRepository;

trait ModulePreferencesTrait
{
    /**
     * Lazily created shared repository.
     *
     * @var PreferenceRepository|null
     * @since  2.2.0
     */
    private $modulePreferenceRepository;

    /**
     * Explicit module-name override.
     *
     * When null the trait falls back to the using class's `$module_name`
     * property, then to the literal 'unknown'.
     *
     * @var string|null
     * @since  2.2.0
     */
    protected $preferenceModuleName;

    /**
     * Explicit user-id override.
     *
     * When null the trait falls back to FA's `$_SESSION['user_id']`, then to ''.
     *
     * @var string|int|null
     * @since  2.2.0
     */
    protected $preferenceUserId;

    /**
     * Explicit table-name override passed to PreferenceRepository.
     *
     * @var string|null
     * @since  2.2.0
     */
    protected $preferenceTableName;

    /**
     * Read a preference for the bound module and user.
     *
     * Module name precedence:
     *   1. an explicit value from setPreferenceModule()/setPrefModule()
     *   2. the using class's `$module_name` property (the FA hooks convention)
     *   3. 'unknown'
     *
     * User id precedence:
     *   1. an explicit value from setPreferenceUser()/setPrefUser()
     *   2. FA's `$_SESSION['user_id']` when a session is present
     *   3. '' (company-wide setting)
     *
     * @param string $key        Preference key
     * @param mixed  $default    Value returned when the key is unset
     * @return mixed Stored value (JSON-decoded) or $default
     *
     * @example
     *   $view = $this->pref('default_view', 'month');
     *
     * @since 2.2.0
     */
    protected function pref(string $key, $default = null)
    {
        return $this->modulePreferences()->get(
            $this->preferenceModule(),
            $this->preferenceUser(),
            $key,
            $default
        );
    }

    /**
     * Write a preference for the bound module and user.
     *
     * Module and user are resolved exactly as for pref(). Values are
     * JSON-encoded, so scalars, arrays and objects are all accepted.
     *
     * @param string $key   Preference key
     * @param mixed  $value Value to store
     * @return bool True when the write was dispatched or executed
     *
     * @example
     *   $this->setPref('default_view', 'week');
     *
     * @since 2.2.0
     */
    protected function setPref(string $key, $value): bool
    {
        return $this->modulePreferences()->set(
            $this->preferenceModule(),
            $this->preferenceUser(),
            $key,
            $value
        );
    }

    /**
     * The shared repository, created on first use.
     *
     * Construction is deliberately lazy: mixing the trait in performs no DB
     * work, so a hooks class can be instantiated during FA's extension
     * bootstrap (or in a unit test with no database at all).
     *
     * @return PreferenceRepository
     *
     * @since 2.2.0
     */
    protected function modulePreferences(): PreferenceRepository
    {
        if (!$this->modulePreferenceRepository instanceof PreferenceRepository) {
            $this->modulePreferenceRepository = new PreferenceRepository(
                $this->preferenceTableName === null
                    ? PreferenceRepository::DEFAULT_TABLE
                    : $this->preferenceTableName
            );
        }

        return $this->modulePreferenceRepository;
    }

    /**
     * Inject a pre-built repository (tests, or a module caching its own).
     *
     * Pass null to drop the instance and let the trait rebuild its own.
     *
     * @param PreferenceRepository|null $repository Repository to reuse
     * @return void
     *
     * @since 2.2.0
     */
    protected function setPreferenceRepository(?PreferenceRepository $repository): void
    {
        $this->modulePreferenceRepository = $repository;
    }

    /**
     * Override the module name used as the preference key segment.
     *
     * Use this when the using class has no `$module_name` property, or when the
     * preferences should be namespaced under a name other than the class's.
     *
     * @param string|null $moduleName Module name, or null to fall back to
     *                                `$this->module_name` then 'unknown'
     * @return void
     *
     * @since 2.2.0
     */
    protected function setPreferenceModule(?string $moduleName): void
    {
        $this->preferenceModuleName = $moduleName;
    }

    /**
     * Override the user id used as the preference key segment.
     *
     * @param string|int|null $userId User id, or null to fall back to
     *                                `$_SESSION['user_id']` then ''
     * @return void
     *
     * @since 2.2.0
     */
    protected function setPreferenceUser($userId): void
    {
        $this->preferenceUserId = $userId === null ? null : (string) $userId;
    }

    /**
     * Override the table the shared repository reads and writes.
     *
     * @param string|null $tableName Table name, or null for the convention
     *                               default `0_ksf_module_prefs`
     * @return void
     *
     * @since 2.2.0
     */
    protected function setPreferenceTable(?string $tableName): void
    {
        $this->preferenceTableName = $tableName;
        $this->modulePreferenceRepository = null;
    }

    /**
     * Resolve the module name used as the preference key segment.
     *
     * @return string
     *
     * @since 2.2.0
     */
    protected function preferenceModule(): string
    {
        if ($this->preferenceModuleName !== null && $this->preferenceModuleName !== '') {
            return $this->preferenceModuleName;
        }

        if (isset($this->module_name) && is_string($this->module_name) && $this->module_name !== '') {
            return $this->module_name;
        }

        return 'unknown';
    }

    /**
     * Resolve the user id used as the preference key segment.
     *
     * @return string Empty string for a company-wide (non per-user) setting
     *
     * @since 2.2.0
     */
    protected function preferenceUser(): string
    {
        if ($this->preferenceUserId !== null && $this->preferenceUserId !== '') {
            return (string) $this->preferenceUserId;
        }

        if (isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }

        return '';
    }
}
