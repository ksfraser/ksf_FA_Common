<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\App\TabRegistration;
use ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistry;

/**
 * TabRegistrationTrait — convenience trait for modules that register their
 * tabs into an application shell (HRM / CRM / Project Management, ...).
 *
 * Include this trait in a module's hooks class, then respond to the app's
 * "register with me" hook by implementing a method named after the hook:
 *
 *   class hooks_ksf_FA_Teams extends hooks {
 *       use TabRegistrationTrait;
 *
 *       public function hrm_register_tabs(&$data, $opts = null)
 *       {
 *           $this->registerTab('teams', _('Teams'), 'SA_HRM_TEAM',
 *               \ksfraser\FrontAccounting\Teams\TabController\TeamsTabController::class, 40);
 *       }
 *   }
 *
 * The app shell (AbstractAppShell::boot) fires hook_invoke_all('hrm_register_tabs')
 * and collects $data['tabs']. This trait also writes the same registrations
 * into the generic ExtensionRegistry under the app-scoped category.
 *
 * PHP 7.3 compatible — no typed properties, no PHP 8+ syntax.
 *
 * @package KsfCommon\Traits
 * @since   1.0.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §6/§7 (register-with-me hook protocol)
 * @BABOK Related: FR-HRM-001 (App shell tab registration)
 */
trait TabRegistrationTrait
{
    /**
     * Register a tab with an application shell.
     *
     * The method name your hooks class exposes must match the app's
     * register-with-me hook (e.g. `hrm_register_tabs`), and must append the
     * registered TabRegistration to `$data['tabs']`:
     *
     *   public function hrm_register_tabs(&$data, $opts = null)
     *   {
     *       $this->registerTab('teams', _('Teams'), 'SA_HRM_TEAM', TeamsTabController::class, 40);
     *   }
     *
     * The returned TabRegistration is also written to the ExtensionRegistry
     * under `{appId}_tabs`, so hosts can query registrations without firing
     * hooks again.
     *
     * @param string      $appId           App id (e.g. 'hrm', 'crm', 'pm')
     * @param string      $key             Unique tab key (matches ?view=)
     * @param string      $label           Display label
     * @param string      $security        FA security area ('' = app default)
     * @param string|null $controllerClass FQCN of the tab controller SRP
     * @param int         $priority        Menu priority (lower = higher, default 50)
     * @param int         $order           Within-priority order (default 0)
     * @param string|null $pageFile        Optional legacy page script path
     * @return TabRegistration
     *
     * @since 1.0.0
     */
    protected function registerTabWithApp(
        string $appId,
        string $key,
        string $label,
        string $security = '',
        ?string $controllerClass = null,
        int $priority = 50,
        int $order = 0,
        ?string $pageFile = null
    ): TabRegistration {
        $tab = new TabRegistration($key, $label, $security, $controllerClass, $priority, $order, $pageFile);
        ExtensionRegistry::instance()->register($appId . '_tabs', $key, $tab->toArray());
        return $tab;
    }

    /**
     * Respond to an app shell's register-with-me hook by appending a tab.
     *
     * Shorthand the concrete hook method should delegate to — it appends the
     * TabRegistration to the passed `$data['tabs']` collection (by reference).
     *
     * @param array<string, mixed> $data Hook payload (by reference, has 'tabs')
     * @param string               $appId        App id (e.g. 'hrm')
     * @param string               $key          Tab key
     * @param string               $label        Display label
     * @param string               $security     FA security area
     * @param string|null          $controllerClass FQCN of the tab controller
     * @param int                  $priority     Menu priority
     * @param int                  $order        Within-priority order
     * @param string|null          $pageFile     Legacy page script path (optional)
     * @return void
     *
     * @since 1.0.0
     */
    protected function respondToAppRegister(
        array &$data,
        string $appId,
        string $key,
        string $label,
        string $security = '',
        ?string $controllerClass = null,
        int $priority = 50,
        int $order = 0,
        ?string $pageFile = null
    ): void {
        if (!isset($data['tabs']) || !is_array($data['tabs'])) {
            $data['tabs'] = [];
        }
        $data['tabs'][] = $this->registerTabWithApp(
            $appId,
            $key,
            $label,
            $security,
            $controllerClass,
            $priority,
            $order,
            $pageFile
        );
    }
}