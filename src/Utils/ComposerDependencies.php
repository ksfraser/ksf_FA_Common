<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

$ksfCommonComposerDepsNamespace = __NAMESPACE__;
$ksfCommonComposerDepsClass = 'ComposerDependencies';
$ksfCommonComposerDepsSentinel = 'KSF_FA_COMPOSER_DEPENDENCIES_' . md5($ksfCommonComposerDepsNamespace);
$ksfCommonComposerDepsWasDeclared = class_exists($ksfCommonComposerDepsNamespace . '\\' . $ksfCommonComposerDepsClass, false);

if (!defined($ksfCommonComposerDepsSentinel) && !$ksfCommonComposerDepsWasDeclared) {
    define($ksfCommonComposerDepsSentinel, true);

    if ($ksfCommonComposerDepsNamespace === 'ksfraser\FrontAccounting\Common\Utils' && !defined('KSF_FA_COMMON_COMPOSER_DEPENDENCIES_DECLARED')) {
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
