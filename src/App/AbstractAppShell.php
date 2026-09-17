<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

use ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistry;
use ksfraser\FrontAccounting\Common\Menu\FAModuleMenu;
use ksfraser\FrontAccounting\Common\Plugin\PluginRegistry;

/**
 * AbstractAppShell — templated SRP for an application shell page (HRM / CRM /
 * Project Management, ...).
 *
 * A host app page extends this class to gain:
 *   - tab registration from other modules (the "register with me" hook),
 *   - menu rendering from the merged registration set,
 *   - view resolution + access control,
 *   - dispatch to a tab controller SRP (or a legacy page script).
 *
 * Three registration sources are merged, in order:
 *   1. core/default tabs registered directly on the shell (pre-session,
 *      authoritative for the pre-session security check),
 *   2. the app's "register with me" hook
 *      (`hook_invoke_all('<appId>_register_tabs', $data)` — responders push
 *       TabRegistration instances or definition arrays into `$data['tabs']`),
 *   3. a PluginRegistry discovery directory (plugins implementing
 *      `getTabRegistration()`).
 *
 * Subclasses MUST implement getAppId() and expose a list of core tabs (banks).
 * Subclasses MAY override createController() to inject services/repos.
 *
 * PHP 7.3 compatible — no typed properties, no PHP 8+ syntax.
 *
 * @package KsfCommon\App
 * @since   1.0.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §6/§7 (host contract + app-shell role)
 * @BABOK Related: FR-HRM-001 (App shell tab registration)
 */
abstract class AbstractAppShell
{
    /** @var string App id used to derive the register-with-me hook name. */
    protected $appId;

    /** @var string Base script the shell dispatches through (e.g. 'index.php'). */
    protected $baseUrl;

    /** @var string Query parameter carrying the view key (default 'view'). */
    protected $viewParam;

    /** @var string Fallback view when no/invalid view is requested. */
    protected $defaultView;

    /** @var TabRegistration[] Keyed by tab key. */
    protected $tabs = [];

    /** @var string|null Optional PluginRegistry discovery directory. */
    protected $discoverDirectory;

    /** @var bool Whether boot() has run. */
    protected $booted = false;

    /**
     * @param string $appId        App id (derives the register hook name)
     * @param string $baseUrl      Dispatch script (default 'index.php')
     * @param string $viewParam    View query param (default 'view')
     * @param string $defaultView  Fallback view key
     *
     * @since 1.0.0
     */
    public function __construct(
        string $appId,
        string $baseUrl = 'index.php',
        string $viewParam = 'view',
        string $defaultView = ''
    ) {
        $this->appId       = $appId;
        $this->baseUrl     = $baseUrl;
        $this->viewParam   = $viewParam;
        $this->defaultView = $defaultView;
    }

    /**
     * The register-with-me hook name for this app (e.g. 'hrm_register_tabs').
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function getRegisterHook(): string
    {
        return $this->appId . '_register_tabs';
    }

    /**
     * Register a single tab directly (core tabs / manual injection).
     *
     * @param TabRegistration $tab
     * @return self
     *
     * @since 1.0.0
     */
    public function registerTab(TabRegistration $tab): self
    {
        if ($tab->getKey() !== '') {
            $this->tabs[$tab->getKey()] = $tab;
        }
        return $this;
    }

    /**
     * Register a tab from a definition array (hook responder convenience).
     *
     * @param array<string, mixed> $def
     * @return self
     *
     * @since 1.0.0
     */
    public function registerTabFromArray(array $def): self
    {
        return $this->registerTab(TabRegistration::fromArray($def));
    }

    /**
     * Set the PluginRegistry discovery directory (activated during boot()).
     *
     * @param string $directory Absolute path
     * @return self
     *
     * @since 1.0.0
     */
    public function setDiscoveryDirectory(string $directory): self
    {
        $this->discoverDirectory = $directory;
        return $this;
    }

    /**
     * Fire the "register with me" hook and merge registrations.
     *
     * Idempotent. Call after session.inc (hooks loaded) and after registering
     * core tabs. Hook responders append TabRegistration objects or definition
     * arrays to `$data['tabs']`.
     *
     * @return self
     *
     * @since 1.0.0
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $data = ['tabs' => []];
        if (function_exists('hook_invoke_all')) {
            hook_invoke_all($this->getRegisterHook(), $data);
        }

        foreach ($data['tabs'] as $def) {
            $this->registerTab($def instanceof TabRegistration ? $def : TabRegistration::fromArray($def));
        }

        if ($this->discoverDirectory !== null && function_exists('class_exists')) {
            $registry = new PluginRegistry();
            $registry->discover($this->discoverDirectory);
            foreach ($registry->getActive() as $plugin) {
                if (is_object($plugin) && method_exists($plugin, 'getTabRegistration')) {
                    $tab = $plugin->getTabRegistration();
                    if ($tab instanceof TabRegistration) {
                        $this->registerTab($tab);
                    }
                }
            }
        }

        $this->booted = true;
        return $this;
    }

    /**
     * All registered tabs, sorted by priority then order.
     *
     * @return TabRegistration[]
     *
     * @since 1.0.0
     */
    public function getTabs(): array
    {
        $tabs = array_values($this->tabs);
        usort($tabs, function (TabRegistration $a, TabRegistration $b) {
            $cmp = $a->getPriority() <=> $b->getPriority();
            return $cmp === 0 ? ($a->getOrder() <=> $b->getOrder()) : $cmp;
        });
        return $tabs;
    }

    /**
     * Look up a tab by key.
     *
     * @param string $key
     * @return TabRegistration|null
     *
     * @since 1.0.0
     */
    public function getTab(string $key): ?TabRegistration
    {
        return $this->tabs[$key] ?? null;
    }

    /**
     * Resolve a requested view to a tab (falls back to default view, then the
     * first tab).
     *
     * @param string $view
     * @return TabRegistration
     *
     * @since 1.0.0
     */
    public function resolveView(string $view): TabRegistration
    {
        if ($view !== '') {
            $tab = $this->getTab($view);
            if ($tab !== null) {
                return $tab;
            }
        }

        $fallback = $this->getTab($this->defaultView)
            ?? $this->getTab('')
            ?? null;
        if ($fallback !== null) {
            return $fallback;
        }

        $tabs = $this->getTabs();
        return $tabs[0] ?? new TabRegistration('', '');
    }

    /**
     * Security area for a view, resolved synchronously from tabs known at
     * construction time. Used by the host page to set $page_security BEFORE
     * session.inc.
     *
     * @param string $view
     * @param string $defaultSecurity Fallback when the tab carries no security
     * @return string
     *
     * @since 1.0.0
     */
    public function getSecurity(string $view, string $defaultSecurity = ''): string
    {
        $tab = $this->getTab($view);
        $security = $tab !== null ? $tab->getSecurity() : '';
        return $security !== '' ? $security : $defaultSecurity;
    }

    /**
     * Render the app sub-menu from the merged tab set.
     *
     * @param string $currentView  Active view key
     * @return string HTML
     *
     * @since 1.0.0
     */
    public function renderMenu(string $currentView): string
    {
        $menu = new FAModuleMenu($this->baseUrl, $this->viewParam, $currentView);
        foreach ($this->getTabs() as $tab) {
            $label = $tab->getLabel();
            if (function_exists('_')) {
                $label = _($label);
            }
            $menu->addItem($tab->getKey(), $label, $tab->getFaType());
        }
        return $menu->render();
    }

    /**
     * Dispatch a resolved view: enforce access, then run the tab controller SRP
     * or include the legacy page script.
     *
     * @param string $view Requested view key
     * @return void
     *
     * @since 1.0.0
     */
    public function dispatch(string $view): void
    {
        $tab = $this->resolveView($view);

        $security = $tab->getSecurity();
        if ($security !== '' && function_exists('check_page_security')) {
            check_page_security($security);
        }

        if ($tab->hasController()) {
            $controller = $this->createController($tab);
            if ($controller !== null && method_exists($controller, 'run')) {
                $controller->run();
                return;
            }
        }

        $pageFile = $tab->getPageFile();
        if ($pageFile !== null && is_file($pageFile)) {
            include $pageFile;
        }
    }

    /**
     * Instantiate the tab controller SRP.
     *
     * Override to inject services/repositories (DI). Base implementation just
     * news up the class.
     *
     * @param TabRegistration $tab
     * @return object|null Controller instance
     *
     * @since 1.0.0
     */
    protected function createController(TabRegistration $tab)
    {
        $class = $tab->getControllerClass();
        if ($class === null || !class_exists($class)) {
            return null;
        }
        return new $class();
    }
}