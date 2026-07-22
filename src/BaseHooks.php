<?php
/**
 * KSF FA Module Base Hooks Class
 * 
 * Base class for all KSF FrontAccounting module hooks.
 * Provides common functionality for composer installation and schema setup.
 *
 * @package KsfCommon
 */

namespace ksfraser\FrontAccounting\Common;

class BaseHooks
{
    protected $moduleName;
    protected $securitySections = [];
    protected $securityAreas = [];
    
    public function __construct()
    {
    }
    
    protected function ensureComposerDependencies(): bool
    {
        $moduleDir = dirname(__FILE__, 3);
        $autoloadPath = $moduleDir . '/vendor/autoload.php';
        
        if (file_exists($autoloadPath)) {
            return true;
        }
        
        $composerPath = $moduleDir . '/composer.json';
        if (!file_exists($composerPath)) {
            return true;
        }
        
        $cwd = getcwd();
        chdir($moduleDir);
        
        $output = [];
        $returnCode = 0;
        exec('composer install --no-interaction --prefer-dist 2>&1', $output, $returnCode);
        
        chdir($cwd);
        
        if ($returnCode !== 0) {
            error_log('KSF Module: composer install failed: ' . implode("\n", $output));
            return false;
        }
        
        return true;
    }
    
    protected function createTableIfNotExists(string $tableName, string $createSql): bool
    {
        $checkSql = "SHOW TABLES LIKE " . db_escape($tableName);
        $result = db_query($checkSql, 'Failed checking table existence');
        
        if (db_num_rows($result) > 0) {
            return true;
        }
        
        db_query($createSql, "Could not create table: $tableName");
        return true;
    }
    
    protected function defineSecuritySection(int $section, string $name): void
    {
        $this->securitySections[$section] = $name;
    }
    
    protected function defineSecurityArea(string $name, array $definition): void
    {
        $this->securityAreas[$name] = $definition;
    }
    
    public function installAccess(): array
    {
        return array($this->securityAreas, $this->securitySections);
    }
}
