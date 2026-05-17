<?php
/**
 * KSF FrontAccounting Module Hooks
 * 
 * STANDARD PATTERNS:
 * 
 * 1. ADDING MODULE TABS
 *    Define a class extending 'application' in hooks.php.
 *    Return new instance from install_tabs().
 *    Include add_extensions() to load other modules' install_options.
 * 
 * 2. ADDING MENU ITEMS TO EXISTING APPS
 *    Use install_options() with switch($app->id).
 *    Use add_module() + add_lapp_function() for new menu section.
 * 
 * 3. DATABASE SCHEMA
 *    DO NOT create tables in PHP code.
 *    Use sql/install.sql with @TB_PREF@ placeholders.
 *    Call $this->update_databases() in activate_extension().
 * 
 * 4. SECURITY
 *    Define SS_<MODULE> constant (section << 8).
 *    Define SA_<MODULE>VIEW and SA_<MODULE>MANAGE in install_access().
 * 
 * @package KsfFA_[ModuleName]
 * @version 2.4.3
 */

// DEFINE SECTION CONSTANT
// Section constants use (unique_number << 8) format
// Example: define('SS_ksf_FA_[Module]', 126 << 8);

class hooks_ksf_FA_[Module] extends hooks {
    var $module_name = 'ksf_FA_[Module]';
    var $version = '1.0.0';

    /**
     * Add module tab
     * 
     * Return new application class instance to add a tab.
     * Omit or return nothing to skip tab addition.
     * 
     * @param application|null $app Ignored
     * @return application New tab application instance
     */
    function install_tabs($app) {
        return new [Module]_app();
    }

    /**
     * Add menu items to existing FA applications
     * 
     * @param application $app FA application instance
     */
    function install_options($app) {
        global $path_to_root;

        switch($app->id) {
            case 'manuf':  // Manufacturing
            case 'setup':  // Setup
            case 'inventory':  // Inventory
            case 'sales':  // Sales
            case 'purchasing':  // Purchasing
            case 'fa_crm': // CRM (if installed)
            case 'fa_hrm': // HRM (if installed)
                // Add menu items here
                break;
        }
    }

    /**
     * Define security areas
     * 
     * @return array [0] => $security_areas, [1] => $security_sections
     */
    function install_access() {
        // Section base (must be unique)
        $security_sections[SS_ksf_FA_[Module]] = _("[Module Name]");
        
        // Permission levels
        $security_areas['SA_ksf_FA_[Module]VIEW'] = array(
            SS_ksf_FA_[Module] | 1, 
            _("View [Module]")
        );
        $security_areas['SA_ksf_FA_[Module]MANAGE'] = array(
            SS_ksf_FA_[Module] | 2, 
            _("Manage [Module]")
        );
        
        return array($security_areas, $security_sections);
    }

    /**
     * Activate extension
     * 
     * @param int $company Company number
     * @param bool $check_only Only check if activation possible
     * @return bool Success
     */
    function activate_extension($company, $check_only=true) {
        $this->ensure_composer_dependencies();
        
        // Apply sql/install.sql using update_databases()
        // This handles @TB_PREF@ replacement automatically
        $updates = array('install.sql' => array($this->module_name));
        return $this->update_databases($company, $updates, $check_only);
    }

    /**
     * Install composer dependencies if needed
     */
    private function ensure_composer_dependencies(): void {
        $module_dir = dirname(__FILE__);
        $autoload_path = $module_dir . '/vendor/autoload.php';
        
        if (file_exists($autoload_path)) {
            return;
        }
        
        $composer_path = $module_dir . '/composer.json';
        if (!file_exists($composer_path)) {
            return;
        }
        
        chdir($module_dir);
        $output = [];
        $return_code = 0;
        exec('composer install --no-interaction --prefer-dist 2>&1', $output, $return_code);
        if ($return_code !== 0) {
            error_log('KSF Module: composer install failed: ' . implode("\n", $output));
        }
    }
}

/**
 * [Module] Application Class
 * 
 * Extends application to create a new tab.
 * MUST be defined in hooks.php for FA's module loader.
 * 
 * @extends application
 */
class [Module]_app extends application {
    function __construct() {
        global $path_to_root;
        parent::__construct("[Module]", _($this->help_context = "&[Module]"));
        
        // Add menu items
        // $this->add_module(_("[Module]"));
        // $this->add_lapp_function(0, _("&[Module]"),
        //     $path_to_root."/modules/ksf_FA_[Module]/...", 'SA_ksf_FA_[Module]VIEW', MENU_MAIN);
        
        // REQUIRED: Load other modules' install_options hooks
        $this->add_extensions();
    }
}
