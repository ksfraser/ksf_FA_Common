<?php
/**
 * KSF FrontAccounting Module Hooks
 * 
 * STANDARD PATTERNS:
 * 
 * 1. ADDING MODULE TABS (new top-level menu item)
 *    - Define a class extending 'application' in hooks.php
 *    - Use set_ext_domain('modules/ksf_FA_<Module>') before adding
 *    - Use $app->add_application(new module_app())
 *    - Class constructor uses function __construct() with parent::__construct()
 * 
 * 2. ADDING MENU ITEMS TO EXISTING APPS
 *    - Use install_options() with switch($app->id)
 *    - Use add_module() + add_lapp_function() for new menu section
 * 
 * 3. DATABASE SCHEMA
 *    - DO NOT create tables in PHP code
 *    - Use sql/install.sql with @TB_PREF@ placeholders
 *    - Call $this->update_databases() in activate_extension()
 * 
 * 4. SECURITY
 *    - Define SS_<MODULE> constant (section << 8)
 *    - Define SA_<MODULE>VIEW and SA_<MODULE>MANAGE in install_access()
 * 
 * 5. CONTACT TYPES (if the module provides contact types used across the platform)
 *    - Contact types are a PLATFORM concept (not Calendar-specific).
 *    - Add ksfraser/ksf-fa-common to composer.json (path: ../ksf_FA_Common)
 *    - Register types in activate_extension() and clean up in deactivate_extension():
 *        function activate_extension($company, $check_only=true) {
 *            // ... existing setup ...
 *            \ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry::registerTypes([
 *                new \ksfraser\FrontAccounting\Common\ContactType\ContactType(
 *                    'my_type', 'My Label', 'ksf_FA_<Module>', 'Description'
 *                ),
 *            ]);
 *            return true;
 *        }
 *        function deactivate_extension($company, $check_only=true) {
 *            \ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry::unregisterModule('ksf_FA_<Module>');
 *            return true;
 *        }
 * 
 * @package KsfFA_<Module>
 * @version 2.4.3
 */

define('SS_ksf_FA_<Module>', <section_num> << 8);

class hooks_ksf_FA_<Module> extends hooks {
    var $module_name = 'ksf_FA_<Module>';
    var $version = '1.0.0';

    /**
     * Add module tab (optional - omit if not adding a new tab)
     */
    function install_tabs($app) {
        set_ext_domain('modules/ksf_FA_<Module>');
        $app->add_application(new module_app());
        set_ext_domain();
    }

    /**
     * Add menu items to existing FA applications
     */
    function install_options($app) {
        global $path_to_root;

        switch($app->id) {
            case 'manuf':
            case 'setup':
                // Add menu items here
                break;
        }
    }

    /**
     * Define security areas
     */
    function install_access() {
        $security_sections[SS_ksf_FA_<Module>] = _("<Module Name>");
        $security_areas['SA_ksf_FA_<Module>VIEW'] = array(
            SS_ksf_FA_<Module> | 1, 
            _("View <Module>")
        );
        $security_areas['SA_ksf_FA_<Module>MANAGE'] = array(
            SS_ksf_FA_<Module> | 2, 
            _("Manage <Module>")
        );
        return array($security_areas, $security_sections);
    }

    /**
     * Activate extension
     */
    function activate_extension($company, $check_only=true) {
        $this->ensure_composer_dependencies();
        
        if (file_exists(dirname(__FILE__) . '/sql/install.sql')) {
            $updates = array('install.sql' => array($this->module_name));
            return $this->update_databases($company, $updates, $check_only);
        }
        
        return true;
    }

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
 * <Module> Application Class (omit if not adding a tab)
 * 
 * @extends application
 */
class module_app extends application {
    function __construct() {
        global $path_to_root;
        parent::__construct("<Module>", _($this->help_context = "&<Module>"));
        
        $this->add_module(_("<Module>"));
        $this->add_lapp_function(0, _("&<Module>"),
            "modules/ksf_FA_<Module>/index.php", 'SA_ksf_FA_<Module>VIEW', MENU_MAIN);
        
        $this->add_extensions();
    }
}
