<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

/**
 * ModuleDependencyTrait
 *
 * Standardized dependency-availability check and warning for modules that
 * depend on another KSF FA module (e.g. ksf_FA_RelatedTo for cross-record
 * associations, ksf_FA_RBAC for security, etc.).
 *
 * Modules that extend FA's base `hooks` class can use this trait to check
 * whether a required dependency module is active, and render a consistent
 * warning message when it is not.
 *
 * Usage:
 *   class hooks_my_module extends hooks {
 *       use \ksfraser\FrontAccounting\Common\Traits\ModuleDependencyTrait;
 *
 *       function some_hook() {
 *           if (!$this->isModuleActive('ksf_FA_RelatedTo')) {
 *               $this->renderMissingDependencyWarning('ksf_FA_RelatedTo');
 *               return;
 *           }
 *           // ... normal operation
 *       }
 *   }
 *
 * @since 1.2.0
 */
trait ModuleDependencyTrait
{
    /**
     * Check whether a module is active by looking for its hooks class.
     *
     * FA instantiates each active module's hooks_<module> class when
     * session.inc loads installed extensions, so class_exists() is a
     * reliable proxy for "is this module active".
     *
     * @param string $moduleName Module package name (e.g. 'ksf_FA_RelatedTo')
     * @return bool True when the module's hooks class exists
     */
    protected function isModuleActive(string $moduleName): bool
    {
        $hooksClass = 'hooks_' . $moduleName;

        if (class_exists($hooksClass)) {
            return true;
        }

        // Also check if the module is in the installed extensions registry
        // with active => true (covers modules loaded outside hooks.php).
        global $installed_extensions;
        if (is_array($installed_extensions)) {
            foreach ($installed_extensions as $ext) {
                if (is_array($ext)
                    && isset($ext['name']) && $ext['name'] === $moduleName
                    && isset($ext['active']) && $ext['active'] === true
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether a PHP class (e.g. from a Composer package) is available.
     *
     * @param string $className Fully-qualified class name
     * @return bool True when the class exists
     */
    protected function isClassAvailable(string $className): bool
    {
        return class_exists($className);
    }

    /**
     * Render a standard warning when a required dependency module is not active.
     *
     * @param string $moduleName Name of the missing module
     * @param string|null $message Custom message; defaults to a standard warning
     * @return void
     */
    protected function renderMissingDependencyWarning(string $moduleName, ?string $message = null): void
    {
        if ($message === null) {
            $message = sprintf(
                _('%s is required for related-record features but is not active. '
                . 'Please activate it in Setup › Extensions, then refresh this page.'),
                $moduleName
            );
        }

        echo '<div class="warn_msg">' . $message . '</div>';
    }
}
