<?php
/**
 * KSF FA Module Schema Installer
 * 
 * Utility class for handling database schema installation
 * across all KSF FrontAccounting modules.
 *
 * @package KsfCommon\Utils
 */

namespace ksfraser\FrontAccounting\Common\Utils;

class SchemaInstaller
{
    private $tables = [];
    
    public function addTable(string $tableName, string $createSql): self
    {
        $this->tables[$tableName] = $createSql;
        return $this;
    }
    
    public function addTables(array $tables): self
    {
        foreach ($tables as $tableName => $createSql) {
            $this->addTable($tableName, $createSql);
        }
        return $this;
    }
    
    public function install(): bool
    {
        $allSuccess = true;
        
        foreach ($this->tables as $tableName => $sql) {
            if (!$this->createTable($tableName, $sql)) {
                $allSuccess = false;
            }
        }
        
        return $allSuccess;
    }
    
    private function createTable(string $tableName, string $sql): bool
    {
        // Check if table already exists
        $checkSql = "SHOW TABLES LIKE " . db_escape($tableName);
        $result = db_query($checkSql, 'Failed checking table existence');
        
        if (db_num_rows($result) > 0) {
            return true; // Table exists, skip
        }
        
        // Create table
        $createSql = sprintf($sql, TB_PREF);
        db_query($createSql, "Could not create table: $tableName");
        
        return true;
    }
    
    public static function create(string $tableName, string $createSql): self
    {
        return (new self())->addTable($tableName, $createSql);
    }
}
