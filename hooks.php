<?php
/**
 * ksf_FA_Common Module Hooks for FrontAccounting
 *
 * MUST BE ACTIVATED FIRST — all other ksf_FA_<Module> modules depend on
 * the platform contracts defined here (contact types, schema installer,
 * composer installer, base hooks).
 *
 * Shared platform-common library providing:
 *   - ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry           (persisted in DB)
 *   - ksfraser\FrontAccounting\Common\ContactType\ContactType                    (value object)
 *   - ksfraser\FrontAccounting\Common\ContactType\Contract\ContactTypeProviderInterface
 *   - ksfraser\FrontAccounting\Common\Utils\SchemaInstaller
 *   - ksfraser\FrontAccounting\Common\Utils\ComposerInstaller
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

// NOTE: This module NEVER loads its own vendor/autoload.php. The module dir is
// the single canonical source for the shared ksf-fa-common namespaces; class
// loading goes through src/autoload.php (registered in __construct below and
// first-line in standalone pages). Vendored ksf-fa-common copies must stay
// inert — never add a PSR-4 for ksfraser\FrontAccounting\Common\ pointing at a
// vendored copy, or you reintroduce "Cannot redeclare class" fatals.
// See doc/ProjectDocuments/LOADING_ARCHITECTURE.md.

class hooks_ksf_FA_Common extends hooks {
    var $module_name = 'ksf_FA_Common';

    function __construct() {
        // Register the module dir as the canonical (single) autoload source for
        // the shared ksf-fa-common namespaces before any sibling module loads a
        // class from its own vendored copy. See src/autoload.php.
        if (is_file(dirname(__FILE__) . '/src/autoload.php')) {
            require_once dirname(__FILE__) . '/src/autoload.php';
        }
    }

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
        if (class_exists('\\ksfraser\FrontAccounting\Common\\ContactType\\ContactTypeRegistry')) {
            \ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry::unregisterModule('ksf_FA_Common');
        }
        return true;
    }

    function install_access() {
        $security_sections[SS_ksf_FA_Common] = _("Common");
        $security_areas['SA_ksf_FA_CommonVIEW'] = array(SS_ksf_FA_Common | 1, _("View "));
        $security_areas['SA_ksf_FA_CommonMANAGE'] = array(SS_ksf_FA_Common | 2, _("Manage "));
        return array($security_areas, $security_sections);
    }

    // -----------------------------------------------------------------------
    // Item Event API (inter-module)
    //
    // Entry points for hook_invoke('ksf_FA_Common', '<method>', $data) used by
    // modules that write stock items programmatically, and for the shared
    // item change watcher. Methods follow FA's hook dispatch contract:
    //   method(&$data, $opts=null)
    // -----------------------------------------------------------------------

    /**
     * Broadcast an item_created event.
     *
     * @param array $data Requires 'stock_id'; optional 'context' array and
     *                    'trigger' string
     * @return mixed
     */
    public function publishItemCreated($data, $opts=null) {
        $stockId = isset($data['stock_id']) ? (string) $data['stock_id'] : '';
        if ($stockId === '') {
            return null;
        }
        $this->itemEventPublisher()->publishCreated(
            $stockId,
            isset($data['context']) && is_array($data['context']) ? $data['context'] : array(),
            isset($data['trigger']) ? (string) $data['trigger'] : 'module'
        );
        return true;
    }

    /**
     * Broadcast an item_updated event.
     *
     * @param array $data Requires 'stock_id'; optional 'context' array and
     *                    'trigger' string
     * @return mixed
     */
    public function publishItemUpdated($data, $opts=null) {
        $stockId = isset($data['stock_id']) ? (string) $data['stock_id'] : '';
        if ($stockId === '') {
            return null;
        }
        $this->itemEventPublisher()->publishUpdated(
            $stockId,
            isset($data['context']) && is_array($data['context']) ? $data['context'] : array(),
            isset($data['trigger']) ? (string) $data['trigger'] : 'module'
        );
        return true;
    }

    /**
     * Broadcast create-or-update for an item whose lifecycle is unknown.
     * Sets $data['event'] to the event that was actually broadcast.
     *
     * @param array $data Requires 'stock_id'; optional 'context' array and
     *                    'trigger' string
     * @return mixed
     */
    public function publishItemChanged($data, $opts=null) {
        $stockId = isset($data['stock_id']) ? (string) $data['stock_id'] : '';
        if ($stockId === '') {
            return null;
        }
        $event = $this->itemEventPublisher()->publishChanged(
            $stockId,
            isset($data['context']) && is_array($data['context']) ? $data['context'] : array(),
            isset($data['trigger']) ? (string) $data['trigger'] : 'module'
        );
        $data['event'] = $event;
        return true;
    }

    /**
     * Run one item change scan and broadcast any creates/updates found.
     * Sets $data['events'] to the detected event list.
     *
     * @param array $data Optional 'trigger' string
     * @return mixed
     */
    public function scanItemChanges($data, $opts=null) {
        $watcher = new \ksfraser\FrontAccounting\Common\ItemEvents\ItemChangeWatcher(
            new \ksfraser\FrontAccounting\Common\ItemEvents\FASnapshotSource(),
            new \ksfraser\FrontAccounting\Common\ItemEvents\DbItemChangeStateStore(),
            $this->itemEventPublisher()
        );
        $data['events'] = $watcher->scan(
            isset($data['trigger']) ? (string) $data['trigger'] : 'watcher'
        );
        return true;
    }

    /**
     * Respond to a known-item query. Sets $data['known'] to whether the item
     * already has sync state tracked by this module.
     *
     * @param array $data Requires 'stock_id'
     * @return mixed
     */
    public function isItemKnown($data, $opts=null) {
        $stockId = isset($data['stock_id']) ? (string) $data['stock_id'] : '';
        $data['known'] = $stockId !== ''
            && (new \ksfraser\FrontAccounting\Common\ItemEvents\DbItemChangeStateStore())->has($stockId);
        return true;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Shared item event publisher bound to FA's hook_invoke_all().
     */
    private function itemEventPublisher() {
        return new \ksfraser\FrontAccounting\Common\ItemEvents\ItemEventPublisher();
    }

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
        if (!class_exists('\\ksfraser\FrontAccounting\Common\\ContactType\\ContactTypeRegistry')) {
            return;
        }
        \ksfraser\FrontAccounting\Common\ContactType\ContactTypeRegistry::registerTypes([
            new \ksfraser\FrontAccounting\Common\ContactType\ContactType(
                'fa_user', 'FA User', 'ksf_FA_Common',
                'FrontAccounting RBAC user account'
            ),
            new \ksfraser\FrontAccounting\Common\ContactType\ContactType(
                'crm_contact', 'CRM Contact', 'ksf_FA_Common',
                'Customer or lead managed by the CRM module'
            ),
            new \ksfraser\FrontAccounting\Common\ContactType\ContactType(
                'resource', 'Resource', 'ksf_FA_Common',
                'Shared resource (room, equipment, vehicle)'
            ),
            new \ksfraser\FrontAccounting\Common\ContactType\ContactType(
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
