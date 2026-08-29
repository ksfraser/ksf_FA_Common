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
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = $ksfCommonSrcDir . '/' . $subdir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
        return;
    }
});