<?php
/**
 * ksf_FA_Common Module Hooks for FrontAccounting
 *
 * MUST BE ACTIVATED FIRST — all other ksf_FA_<Module> modules depend on
 * the platform contracts defined here (contact types, schema installer,
 * composer installer, base hooks).
 *
 * Shared platform-common library providing:
 *   - KsfCommon\ContactType\ContactTypeRegistry           (persisted in DB)
 *   - KsfCommon\ContactType\ContactType                    (value object)
 *   - KsfCommon\ContactType\Contract\ContactTypeProviderInterface
 *   - KsfCommon\Utils\SchemaInstaller
 *   - KsfCommon\Utils\ComposerInstaller
 *
 * Activation order requirement:
 *   1. ksf_FA_Common     (this module — creates ksf_contact_types table)
 *   2. ksf_RBAC          (registers the fa_user type)
 *   3. ksf_HRM           (registers employee, team types)
 *   4. ksf_FA_Assets     (registers resource refinements)
 *   5. ksf_ProjectMgmt   (registers project-contact types)
 *   6. ksf_CRM           (registers crm_contact refinements)
 *   7. All other modules
 */

define('SS_ksf_FA_Common', 100 << 8);

// Load Composer autoloader so all KsfCommon classes are available.
$autoload_path = dirname(__FILE__) . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

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
        $this->install_schema();
        $this->register_default_types();
        return true;
    }

    function deactivate_extension($company, $check_only=true) {
        // Clean up default types on deactivation.
        if (class_exists('\\KsfCommon\\ContactType\\ContactTypeRegistry')) {
            \KsfCommon\ContactType\ContactTypeRegistry::unregisterModule('ksf_FA_Common');
        }
        return true;
    }

    function install_access() {
        $security_sections[SS_ksf_FA_Common] = _("");
        $security_areas['SA_ksf_FA_CommonVIEW'] = array(SS_ksf_FA_Common | 1, _("View "));
        $security_areas['SA_ksf_FA_CommonMANAGE'] = array(SS_ksf_FA_Common | 2, _("Manage "));
        return array($security_sections, $security_areas);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Create the ksf_cal_contact_types table if it does not exist.
     */
    private function install_schema() {
        $sql_file = dirname(__FILE__) . '/sql/install.sql';
        if (!file_exists($sql_file)) {
            return;
        }

        $sql = file_get_contents($sql_file);
        if ($sql === false || $sql === '') {
            return;
        }

        $prefix = defined('TB_PREF') ? TB_PREF : '';
        $sql = str_replace('@TB_PREF@', $prefix, $sql);

        // Split by semicolons and execute each statement.
        $statements = explode(';', $sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                db_query($stmt, 'Could not execute ksf_FA_Common schema statement');
            }
        }
    }

    /**
     * Seed the contact types table with the four built-in types.
     * Idempotent — INSERT IGNORE means re-activation does not duplicate.
     */
    private function register_default_types() {
        if (!class_exists('\\KsfCommon\\ContactType\\ContactTypeRegistry')) {
            return;
        }
        \KsfCommon\ContactType\ContactTypeRegistry::registerTypes([
            new \KsfCommon\ContactType\ContactType(
                'fa_user', 'FA User', 'ksf_FA_Common',
                'FrontAccounting RBAC user account'
            ),
            new \KsfCommon\ContactType\ContactType(
                'crm_contact', 'CRM Contact', 'ksf_FA_Common',
                'Customer or lead managed by the CRM module'
            ),
            new \KsfCommon\ContactType\ContactType(
                'resource', 'Resource', 'ksf_FA_Common',
                'Shared resource (room, equipment, vehicle)'
            ),
            new \KsfCommon\ContactType\ContactType(
                'ad_hoc', 'Ad-hoc', 'ksf_FA_Common',
                'External invitee without a system record'
            ),
        ]);
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
