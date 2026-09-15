<?php

declare(strict_types=1);

/**
 * Composer bootstrap guard — per-module copy template.
 *
 * COPY THIS FILE to <module_root>/ComposerDependencies.php and REPLACE the
 * `MODULENAME` token in the namespace below with your module's name, e.g.
 * for ksf_FA_HRM:
 *
 *     namespace ksfraser\FrontAccounting\HRM\Utils;
 *
 * Then call it at the VERY TOP of your hooks.php, before any other requires:
 *
 *     require_once __DIR__ . '/ComposerDependencies.php';
 *     \ksfraser\FrontAccounting\HRM\Utils\ComposerDependencies::ensure(__DIR__);
 *
 * The guard is namespace-scoped and intentional:
 *  - Each module that DOES rename MODULENAME gets its own class in its own
 *    namespace — copies can never collide, and the shared global constant is
 *    NOT set (so a properly-renamed module never suppresses a sibling).
 *  - If you FORGET to replace MODULENAME, every unrenamed copy collapses onto
 *    the same placeholder namespace `ksfraser\FrontAccounting\MODULENAME\Utils`;
 *    the `class_exists(__NAMESPACE__...)` + namespace-derived sentinel guard
 *    then lets only the FIRST unrenamed copy to load declare the class — no
 *    redeclaration fatal, no clobbering. The remainder short-circuit and their
 *    hooks.php calls still resolve (the class takes $moduleDir per call).
 *  - The legacy constant KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED is only
 *    defined when the copy lands on the Common\Utils namespace (i.e. an
 *    unrenamed template that was never meant to ship outside the package).
 *
 * @since 1.0.11
 */

namespace ksfraser\FrontAccounting\MODULENAME\Utils;

$ksfModuleComposerDepsNamespace = __NAMESPACE__;
$ksfModuleComposerDepsClass = 'ComposerDependencies';
$ksfModuleComposerDepsSentinel = 'KSF_FA_COMPOSER_DEPENDENCIES_' . md5($ksfModuleComposerDepsNamespace);
$ksfModuleComposerDepsWasDeclared = class_exists($ksfModuleComposerDepsNamespace . '\\' . $ksfModuleComposerDepsClass, false);

if (!defined($ksfModuleComposerDepsSentinel) && !$ksfModuleComposerDepsWasDeclared) {
    define($ksfModuleComposerDepsSentinel, true);

    if ($ksfModuleComposerDepsNamespace === 'ksfraser\FrontAccounting\Common\Utils' && !defined('KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED')) {
        define('KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED', true);
    }

    final class ComposerDependencies
    {
        public static function ensure(string $moduleDir): bool
        {
            $autoloadPath = $moduleDir . '/vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                return true;
            }

            $composerPath = $moduleDir . '/composer.json';
            if (!file_exists($composerPath)) {
                return false;
            }

            chdir($moduleDir);
            $output = [];
            $returnCode = 0;
            exec('composer install --no-interaction --prefer-dist 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                error_log('KSF Module: composer install failed: ' . implode("\n", $output));
            }

            return file_exists($autoloadPath);
        }
    }
}