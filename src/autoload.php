<?php

declare(strict_types=1);

if (defined('KSF_FA_COMMON_LOADER_REGISTERED')) {
    return;
}
define('KSF_FA_COMMON_LOADER_REGISTERED', true);

$ksfCommonSrcDir = __DIR__;

spl_autoload_register(static function (string $class) use ($ksfCommonSrcDir): void {
    $prefixes = [
        'ksfraser\\FrontAccounting\\Common\\' => '',
        'Ksfraser\\Frontaccounting\\HTML\\' => 'HTML/',
    ];
    foreach ($prefixes as $prefix => $subdir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $path = $ksfCommonSrcDir . '/' . $subdir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }

    // Legacy KsfCommon\* namespace: remap to the canonical
    // ksfraser\FrontAccounting\Common\* classes. Keeps sibling-module code
    // (e.g. fa-product-attributes-core's TabRegistry) working without relying
    // on a module's vendored compat.php, which may not be loaded.
    if (strncmp($class, 'KsfCommon\\', 10) === 0) {
        $target = 'ksfraser\\FrontAccounting\\Common\\' . substr($class, 10);
        if (class_exists($target)) {
            class_alias($target, $class);
        }
        return;
    }
}, true, true);

// Register the backward-compatibility aliases eagerly so legacy KsfCommon\*
// references resolve even if no module loaded its vendored compat.php.
if (is_file(__DIR__ . '/compat.php')) {
    require_once __DIR__ . '/compat.php';
}