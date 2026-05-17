<?php
/**
 * ksf_FA_Common Module Hooks for FrontAccounting
 */

define('SS_ksf_FA_Common', 100 << 8);

class hooks_ksf_FA_Common extends hooks {
    var $module_name = 'ksf_FA_Common';

    function install_extension($check_only=true) {
        return true;
    }

    function install_tabs($app) {
        // Override in modules that add apps
    }

    function install_options($app) {
        // Override in modules that add menu items
    }

    function activate_extension($company, $check_only=true) {
        $this->ensure_composer_dependencies();
        return true;
    }

    function install_access() {
        $security_sections[SS_ksf_FA_Common] = _("");
        $security_areas['SA_ksf_FA_CommonVIEW'] = array(SS_ksf_FA_Common | 1, _("View "));
        $security_areas['SA_ksf_FA_CommonMANAGE'] = array(SS_ksf_FA_Common | 2, _("Manage "));
        return array($security_areas, $security_sections);
    }
    
    private function ensure_composer_dependencies() {
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
        $output = array();
        $return_code = 0;
        exec('composer install --no-interaction --prefer-dist 2>&1', $output, $return_code);
        if ($return_code !== 0) {
            error_log('KSF Module: composer install failed: ' . implode("\n", $output));
        }
    }
}
