<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\MODULENAME\Utils;

$ksfModuleDepsNamespace = __NAMESPACE__;
$ksfModuleDepsClass = 'ModuleDependencies';
$ksfModuleDepsSentinel = 'KSF_FA_MODULE_DEPENDENCIES_' . md5($ksfModuleDepsNamespace);
$ksfModuleDepsWasDeclared = class_exists($ksfModuleDepsNamespace . '\\' . $ksfModuleDepsClass, false);

if (!defined($ksfModuleDepsSentinel) && !$ksfModuleDepsWasDeclared) {
    define($ksfModuleDepsSentinel, true);

    /**
     * Checks that the FA modules MODULENAME depends on are activated for a company.
     *
     * Copy this file to your module root, replace `MODULENAME` in the namespace, and
     * require it from `hooks.php`. The guard is namespace-scoped, so an unrenamed
     * copy and the ksf_FA_Common package's own `Common\Utils` copy can never
     * redeclare each other.
     *
     * @UML Note: see AGENTS_ARCH.md §11.1
     *
     * @since 1.0.0
     */
    final class ModuleDependencies
    {
        /**
         * Read a per-company extension registry.
         *
         * Prefers FA's `get_company_extensions()` when loaded. That function lives
         * in `admin/db/company_db.inc`, which `admin/inst_module.php` includes, so it
         * is always present during activation — but it is NOT in `session.inc`, so on
         * an ordinary page we fall back to including the registry file directly.
         *
         * @param int $company Company id, or -1 for the global (no-company) registry.
         * @return array<int,array<string,mixed>> Registry rows, or [] when unreadable.
         * @since 1.0.0
         */
        public static function registry(int $company = -1): array
        {
            if (function_exists('get_company_extensions')) {
                $rows = get_company_extensions($company);
                return is_array($rows) ? $rows : [];
            }

            $pathToRoot = $GLOBALS['path_to_root'] ?? '';
            if ($pathToRoot === '') {
                return [];
            }
            $file = $pathToRoot
                . ($company === -1 ? '' : '/company/' . $company)
                . '/installed_extensions.php';
            if (!is_file($file)) {
                return [];
            }

            $installed_extensions = [];
            include($file);
            return is_array($installed_extensions) ? $installed_extensions : [];
        }

        /**
         * Is one registry row an activation of $module for this company?
         *
         * @param array<string,mixed> $ext     A single `$installed_extensions` row.
         * @param string              $module  Module/package name, e.g. `ksf_FA_Calendar`.
         * @return bool True when the row names $module and is flagged active.
         * @since 1.0.0
         */
        public static function isActive(array $ext, string $module): bool
        {
            $name = $ext['package'] ?? ($ext['name'] ?? null);
            if (!is_string($name) || $name !== $module) {
                return false;
            }
            return !empty($ext['active']);
        }

        /**
         * Which of $modules are NOT active, according to $registry?
         *
         * Pure: no FA globals, no I/O. This is the unit-testable core.
         *
         * @param array<int,string> $modules      Required module names.
         * @param array<int,array<string,mixed>> $registry Rows from {@see registry()}.
         * @param bool               $requireHooks Also require `global $Hooks[$name]`,
         *                                          which proves `hooks.php` was actually
         *                                          included and not merely flagged active.
         * @return array<int,string> Missing module names, in the order requested.
         * @since 1.0.0
         */
        public static function missingFromRegistry(
            array $modules,
            array $registry,
            bool $requireHooks = false
        ): array {
            $hooks = $GLOBALS['Hooks'] ?? [];
            $missing = [];
            foreach ($modules as $module) {
                if (!is_string($module) || $module === '') {
                    continue;
                }
                $active = false;
                foreach ($registry as $ext) {
                    if (is_array($ext) && self::isActive($ext, $module)) {
                        $active = true;
                        break;
                    }
                }
                if ($active && $requireHooks && !isset($hooks[$module])) {
                    $active = false;
                }
                if (!$active) {
                    $missing[] = $module;
                }
            }
            return $missing;
        }

        /**
         * Which of $modules are NOT active for $company?
         *
         * @param array<int,string> $modules      Required module names.
         * @param int               $company      Company id, or -1 for the global registry.
         * @param bool              $requireHooks See {@see missingFromRegistry()}.
         * @return array<int,string> Missing module names, in the order requested.
         * @since 1.0.0
         */
        public static function missing(array $modules, int $company = -1, bool $requireHooks = false): array
        {
            return self::missingFromRegistry($modules, self::registry($company), $requireHooks);
        }

        /**
         * Build the operator-facing message.
         *
         * Pure, so tests can assert the exact string. For a single dependency the
         * text reads "... we need module X to be activated first."
         *
         * @param array<int,string> $missing Missing module names.
         * @param string             $self    Name of the module being activated.
         * @return string Message body, or '' when nothing is missing.
         * @since 1.0.0
         */
        public static function message(array $missing, string $self): string
        {
            if ($missing === []) {
                return '';
            }
            $noun = count($missing) === 1 ? 'module' : 'modules';
            return sprintf(
                '%s cannot be activated: we need %s %s to be activated first.',
                $self,
                $noun,
                implode(', ', $missing)
            );
        }

        /**
         * Enforce the dependency the way FA expects: warn, and fail the activation.
         *
         * `display_warning()` is `trigger_error($msg, E_USER_WARNING)`, which
         * `admin/inst_module.php` renders on the extension page; returning `false`
         * leaves the `active` flag un-flipped and makes the page report
         * "Status change for some extensions failed." This is the same contract
         * `check_src_ext_version()` uses.
         *
         * @param array<int,string> $modules      Required module names.
         * @param int               $company      Company id, or -1 for the global registry.
         * @param string            $self         Name of the module being activated.
         * @param bool              $requireHooks See {@see missingFromRegistry()}.
         * @return bool True when every dependency is satisfied; false after warning.
         * @since 1.0.0
         */
        public static function assertActive(
            array $modules,
            int $company = -1,
            string $self = '',
            bool $requireHooks = false
        ): bool {
            $missing = self::missing($modules, $company, $requireHooks);
            if ($missing === []) {
                return true;
            }
            $message = self::message($missing, $self);
            if (function_exists('display_warning')) {
                display_warning($message);
            } else {
                error_log('KSF ModuleDependencies: ' . $message);
            }
            return false;
        }
    }
}
