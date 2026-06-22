<?php

declare(strict_types=1);

namespace KsfCommon\Utils;

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
