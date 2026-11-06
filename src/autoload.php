<?php
declare(strict_types=1);

/**
 * ksf_FA_Common canonical autoloader.
 *
 * Loads ksfraser\FrontAccounting\Common\* classes when Composer's PSR-4
 * autoloader is unavailable or does not carry the mapping (e.g. ksf_FA_Common
 * used as a standalone FA module without a vendor/autoload.php).
 *
 * Safe to load alongside Composer's autoloader: classes already declared
 * (by Composer or another autoloader) are skipped via class_exists/interface_exists
 * guards, so there is no redeclaration fatal.
 *
 * Consuming modules that cannot rely on Composer autoloading should require
 * this file at the top of their hooks.php:
 *
 *     require_once __DIR__ . '/../../ksf_FA_Common/src/autoload.php';
 *
 * @since 1.0.11
 */

namespace {
    if (isset($GLOBALS['KSF_FA_COMMON_AUTOLOAD_REGISTERED'])) {
        return;
    }
    $GLOBALS['KSF_FA_COMMON_AUTOLOAD_REGISTERED'] = true;

    $srcDir = __DIR__;
    $prefix = 'ksfraser\\FrontAccounting\\Common\\';

    spl_autoload_register(static function ($class) use ($srcDir, $prefix): void {
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relativePath = substr($class, strlen($prefix));
        $file = $srcDir . '/' . str_replace('\\', '/', $relativePath) . '.php';

        if (!is_file($file)) {
            return;
        }

        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }

        require_once $file;
    });
}
