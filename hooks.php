<?php
/**
 * ksf_FA_Common Module Hooks for FrontAccounting
 *
 * The shared ksf-fa-common library is now distributed as the Composer/Packagist
 * package `ksfraser/ksf-fa-common` and vendored by every ksf module.  Nothing
 * here registers a loader or seeds any data any more.
 *
 * Contact types are no longer platform-owned: each type (fa_user, crm_contact,
 * lead, opportunity, invitee, employee, team, job_applicant, resource, ...) is
 * registered by its natural owning module during activate_extension() and
 * removed during deactivate_extension().
 *
 * The module directory is kept as a no-op install shell so the module can stay
 * installed/activated in FrontAccounting without side effects — the
 * install/activate/deactivate hooks exist only to satisfy "is it there" checks.
 */

define('SS_ksf_FA_Common', 100 << 8);

class hooks_ksf_FA_Common extends hooks {
    var $module_name = 'ksf_FA_Common';

    function install_extension($check_only=true) {
        return true;
    }

    function activate_extension($company, $check_only=true) {
        return true;
    }

    function deactivate_extension($company, $check_only=true) {
        return true;
    }

    function install_tabs($app) {
        // Override in modules that add apps
    }

    function install_options($app) {
        // Override in modules that add menu items
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
    // Entry points for hook_invoke('ksf_FA_Common', '<method>', $data). The
    // implementation classes ship in the ksf-fa-common package and are loaded
    // from each module's own vendored vendor/autoload.php. Methods follow FA's
    // hook dispatch contract:  method(&$data, $opts=null)
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
}