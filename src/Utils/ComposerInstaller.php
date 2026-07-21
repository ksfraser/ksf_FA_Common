<?php
/**
 * KSF FA Module Composer Installer
 * 
 * Utility class for handling composer dependency installation
 * across all KSF FrontAccounting modules.
 *
 * @package KsfCommon
 */

namespace KsfCommon\Utils;

class ComposerInstaller
{
    private $moduleDir;
    private $errors = [];
    
    public function __construct(string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir ?? dirname(__FILE__, 4);
    }
    
    public function ensureDependencies(): bool
    {
        $autoloadPath = $this->moduleDir . '/vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            return true;
        }
        
        $composerPath = $this->moduleDir . '/composer.json';
        if (!file_exists($composerPath)) {
            return true; // No composer.json, nothing to install
        }
        
        return $this->runComposerInstall();
    }
    
    private function runComposerInstall(): bool
    {
        $cwd = getcwd();
        chdir($this->moduleDir);
        
        $output = [];
        $returnCode = 0;
        
        exec('composer install --no-interaction --prefer-dist 2>&1', $output, $returnCode);
        
        chdir($cwd);
        
        if ($returnCode !== 0) {
            $this->errors = $output;
            error_log('KSF Module: composer install failed: ' . implode("\n", $output));
            return false;
        }
        
        return true;
    }
    
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    public static function ensure(string $moduleDir = null): bool
    {
        $installer = new self($moduleDir);
        return $installer->ensureDependencies();
    }
}
