<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

use InvalidArgumentException;

/**
 * AbstractFrontController — templated SRP for an application's single front
 * controller (the `<module>.php` at the module root).
 *
 * Every module was hand-rolling the same preamble: resolve the tab, resolve the
 * action, set $page_security, include session.inc, render the menu, dispatch to
 * the tab's controller. This class extracts that preamble so a module's
 * controller reduces to a declaration:
 *
 *     $fc = new HrmFrontController(new HrmAppShell(), 'SA_ksf_FA_HRMVIEW', '../..');
 *     $page_security = $fc->getSecurityArea();
 *     include_once($path_to_root . "/includes/session.inc");
 *     add_access_extensions();
 *     $fc->run();
 *
 * Canonical URL shape:
 *
 *     hrm.php?tab=<tab>                  read
 *     hrm.php?tab=<tab>&page=3          tab + pager position
 *     hrm.php?tab=<tab>&action=save     mutation
 *
 * `tab` and `page` are deliberately separate parameters. `tab` is the tab
 * selector; `page` is the pager position and is NEVER consulted as a tab
 * selector. `page` is also left untouched in $_GET so that
 * MasterSummaryTable::pagerUrl() — which copies each name in its
 * `preserve_params` list verbatim out of $_GET — emits `?page=3&tab=leave` on
 * pager links.
 *
 * ## Security is fail-closed and cannot be skipped
 *
 *   - the constructor rejects an empty fallback security area;
 *   - run() is `final`, so a subclass cannot replace the dispatch sequence;
 *   - enforceSecurity() is `final protected`, so a subclass cannot weaken it;
 *   - enforcement happens ONCE, before the action path is chosen, so neither
 *     the read path nor the action path can route around it;
 *   - an action request re-checks the same area the read path would, which is
 *     what the hand-rolled controllers each had to remember to do;
 *   - if the runtime offers no way to check access (FA's check_page_security()
 *     absent) the request is refused rather than rendered.
 *
 * ## Deliberately left to subclasses
 *
 *   - page title and <head> JavaScript (getPageTitle() / getPageJs());
 *   - pre-handling an action verb before the tab controller sees it
 *     (dispatchAction()) — safe to override, because enforcement has already
 *     happened by then;
 *   - the unavailable-action message (renderUnavailable()).
 *
 * Everything else — tab resolution, the register hook, the security area,
 * pager passthrough, action resolution, menu rendering, dispatch — is fixed.
 *
 * PHP 7.3 compatible — no typed properties, no PHP 8+ syntax.
 *
 * @package KsfCommon\App
 * @since   1.1.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §11 (host page = thin router)
 * @BABOK Related: FR-HRM-001 (app shell front controller)
 */
abstract class AbstractFrontController
{
    /** @var AbstractAppShell The application shell whose tabs are served. */
    protected $appShell;

    /** @var string Path to the FA root ('' = do not load FA includes). */
    protected $pathToRoot;

    /** @var string Security area applied when a tab carries none of its own. */
    protected $fallbackSecurity;

    /** @var array<string, mixed> Normalised constructor options. */
    protected $options = [];

    /** @var string Query parameter carrying the tab selector (default 'tab'). */
    protected $tabParam = 'tab';

    /** @var string Deprecated tab-selector alias (default 'view', '' = disabled). */
    protected $aliasParam = 'view';

    /** @var string Query parameter carrying the action verb (default 'action'). */
    protected $actionParam = 'action';

    /** @var string Query parameter carrying the pager position (default 'page'). */
    protected $pageParam = 'page';

    /** @var bool Whether to write the resolved tab back into $_GET. */
    protected $normaliseGet = true;

    /** @var bool Whether to include FA's includes/ui.inc before dispatch. */
    protected $loadUi = true;

    /** @var callable|null Access checker override (DI seam for tests/CLI). */
    protected $securityChecker;

    /** @var string Tab key resolved by the last run()/resolveTabKey() call. */
    protected $resolvedTab = '';

    /** @var bool Whether the last resolution fell back to the default view. */
    protected $resolvedFromFallback = false;

    /**
     * @param AbstractAppShell $appShell         Application shell to serve
     * @param string            $fallbackSecurity Security area used when a tab
     *                                           declares none. REQUIRED and must
     *                                           be non-empty: it is what makes
     *                                           enforcement fail closed for
     *                                           third-party tabs that register
     *                                           without an area.
     * @param string            $pathToRoot       Path to the FA root, used to
     *                                           include includes/ui.inc. May be
     *                                           '' when the host page has
     *                                           already loaded it.
     * @param array<string, mixed> $options       {
     *     tab_param?: string,        default 'tab'
     *     alias_param?: string,      default 'view', '' disables the alias
     *     action_param?: string,     default 'action'
     *     page_param?: string,       default 'page'
     *     normalise_get?: bool,      default true
     *     load_ui_includes?: bool,   default true
     *     security_checker?: callable (callable(string): void),
     *                              default FA's check_page_security()
     * }
     *
     * @throws FrontControllerSecurityException When $fallbackSecurity is empty
     *
     * @since 1.1.0
     */
    public function __construct(
        AbstractAppShell $appShell,
        string $fallbackSecurity,
        string $pathToRoot = '',
        array $options = []
    ) {
        if (trim($fallbackSecurity) === '') {
            throw new FrontControllerSecurityException(
                'AbstractFrontController requires a non-empty fallback security area; '
                . 'without one a tab registering no area of its own would be served unchecked.'
            );
        }

        $this->appShell         = $appShell;
        $this->fallbackSecurity = $fallbackSecurity;
        $this->pathToRoot       = $pathToRoot;
        $this->options          = $options;

        $this->tabParam     = (string) ($options['tab_param'] ?? 'tab');
        $this->aliasParam   = (string) ($options['alias_param'] ?? 'view');
        $this->actionParam  = (string) ($options['action_param'] ?? 'action');
        $this->pageParam    = (string) ($options['page_param'] ?? 'page');
        $this->normaliseGet = (bool) ($options['normalise_get'] ?? true);
        $this->loadUi       = (bool) ($options['load_ui_includes'] ?? true);

        $checker = $options['security_checker'] ?? null;
        if ($checker !== null && !is_callable($checker)) {
            throw new InvalidArgumentException(
                'AbstractFrontController option security_checker must be callable.'
            );
        }
        $this->securityChecker = $checker;
    }

    // ─── Public accessors ───────────────────────────────────────────────

    /**
     * @return AbstractAppShell
     *
     * @since 1.1.0
     */
    public function getAppShell(): AbstractAppShell
    {
        return $this->appShell;
    }

    /**
     * The tab key resolved by the most recent resolution.
     *
     * @return string
     *
     * @since 1.1.0
     */
    public function getResolvedTab(): string
    {
        return $this->resolvedTab;
    }

    /**
     * Security area for the request, to be assigned to $page_security and the
     * FA session included with it.
     *
     * Must be called BEFORE session.inc, so it can only see the tabs the shell
     * registered at construction time — the register hook fires on boot(), which
     * needs a session. A third-party tab therefore falls back to the area given
     * to the constructor, and is re-checked against its own area by
     * enforceSecurity() once boot() has run.
     *
     * @return string
     *
     * @since 1.1.0
     */
    public function getSecurityArea(): string
    {
        $area = $this->appShell->getSecurity($this->resolveTabKey(), $this->fallbackSecurity);

        return $area !== '' ? $area : $this->fallbackSecurity;
    }

    // ─── Request resolution ─────────────────────────────────────────────

    /**
     * Resolve the requested tab key.
     *
     * `$this->tabParam` is the selector. `$this->aliasParam` is a deprecated
     * alias kept for bookmarks minted before the app had a front controller;
     * it is consulted only when the canonical parameter is absent. The pager
     * parameter is deliberately NEVER consulted: treating it as a tab selector
     * is the collision this design removes, and it would make a future tab
     * with a numeric key unreachable.
     *
     * An unrecognised value falls back to the shell's default view rather than
     * erroring, so a stale bookmark lands on a working tab.
     *
     * @param array<string, mixed>|null $get Query array to read (default $_GET)
     * @return string Resolved tab key
     *
     * @since 1.1.0
     */
    public function resolveTabKey(?array $get = null): string
    {
        $get = $get ?? $_GET;

        $candidates = [];
        if (isset($get[$this->tabParam]) && $get[$this->tabParam] !== '') {
            $candidates[] = (string) $get[$this->tabParam];
        }
        if ($this->aliasParam !== ''
            && isset($get[$this->aliasParam])
            && $get[$this->aliasParam] !== ''
        ) {
            $candidates[] = (string) $get[$this->aliasParam];
        }

        foreach ($candidates as $candidate) {
            if ($this->appShell->getTab($candidate) !== null) {
                $this->resolvedTab           = $candidate;
                $this->resolvedFromFallback = false;
                return $candidate;
            }
        }

        $this->resolvedTab           = $this->appShell->getDefaultView();
        $this->resolvedFromFallback = true;

        return $this->resolvedTab;
    }

    /**
     * Resolve the current pager position.
     *
     * Pagination and nothing else. Exposed for the host page and for tab
     * controllers that need the position without re-reading $_GET; the front
     * controller itself never uses it to choose a tab.
     *
     * @param array<string, mixed>|null $get Query array to read (default $_GET)
     * @return int 1-based page, never below 1
     *
     * @since 1.1.0
     */
    public function resolvePagePosition(?array $get = null): int
    {
        $get = $get ?? $_GET;

        if (!isset($get[$this->pageParam]) || !is_numeric($get[$this->pageParam])) {
            return 1;
        }

        return max(1, (int) $get[$this->pageParam]);
    }

    /**
     * Resolve the requested action verb.
     *
     * Read from $_REQUEST so a POST mutation and a GET pre-load read the same.
     * '' means "no action" — render the tab. Whether a verb requires POST, and
     * whether CSRF applies, is the tab controller's contract, not the front
     * controller's.
     *
     * @param array<string, mixed>|null $request Request array (default $_REQUEST)
     * @return string Action verb ('' when absent)
     *
     * @since 1.1.0
     */
    public function resolveAction(?array $request = null): string
    {
        $request = $request ?? $_REQUEST;

        if (!isset($request[$this->actionParam]) || is_array($request[$this->actionParam])) {
            return '';
        }

        return (string) $request[$this->actionParam];
    }

    /**
     * Write the resolved tab back into $_GET under the canonical parameter.
     *
     * MasterSummaryTable::pagerUrl() copies each name in its `preserve_params`
     * list verbatim out of $_GET, so normalising here is what makes pager links
     * read `?page=3&tab=leave`. Arriving on the deprecated alias is normalised
     * onto the canonical parameter the same way.
     *
     * The pager parameter is left untouched: it must survive verbatim into
     * pager links and form actions.
     *
     * @param string|null $tabKey Resolved tab key (default: last resolved)
     * @return void
     *
     * @since 1.1.0
     */
    public function normaliseRequest(?string $tabKey = null): void
    {
        if (!$this->normaliseGet) {
            return;
        }

        $tabKey = $tabKey ?? $this->resolvedTab;
        if ($tabKey === '') {
            return;
        }

        $_GET[$this->tabParam] = $tabKey;
        if ($this->aliasParam !== '') {
            unset($_GET[$this->aliasParam]);
        }
    }

    // ─── Entry point ────────────────────────────────────────────────────

    /**
     * Resolve, secure and dispatch the request. Call AFTER session.inc.
     *
     * Sequence (final — a subclass may not reorder or omit any step):
     *   1. load FA's includes/ui.inc, which supplies text_row(), amount_row(),
     *      date_row(), array_selector_row() and submit_row() to the tab
     *      controllers;
     *   2. resolve the tab from the tabs known pre-boot;
     *   3. boot() the shell — fires hook_invoke_all('<appId>_register_tabs') so
     *      third-party tabs merge in — and re-resolve if the pre-boot answer
     *      was the default view, so a tab contributed by another module is
     *      picked up;
     *   4. normalise $_GET so emitted links carry the canonical parameter;
     *   5. open the page and render the tab menu;
     *   6. ENFORCE SECURITY — once, for both the read and the action path;
     *   7. dispatch the action verb if there is one, otherwise the tab;
     *   8. close the page.
     *
     * @return void
     *
     * @throws FrontControllerSecurityException When no tab resolves, or access
     *                                          cannot be checked
     * @throws FrontControllerRoutingException When the shell has no tabs
     *
     * @since 1.1.0
     */
    final public function run(): void
    {
        $this->loadUiIncludes();

        $tabKey = $this->resolveTabKey();
        $fellBack = $this->resolvedFromFallback;

        // Fire the register-with-me hook. boot() needs a session, so this is
        // why a third-party tab cannot influence getSecurityArea() above — it
        // does not exist yet. step 6 is what closes that gap.
        $this->appShell->boot();

        // A tab contributed through the hook did not exist at step 2, so a
        // request for it fell back to the default view. Re-resolve now that it
        // is registered. (The `getTab() === null` arm covers a requested key
        // that no longer resolves at all.)
        if ($fellBack || $this->appShell->getTab($tabKey) === null) {
            $tabKey = $this->resolveTabKey();
        }

        $tab = $this->resolveTab($tabKey);
        $this->resolvedTab = $tab->getKey();

        $this->normaliseRequest($tab->getKey());

        $this->openPage($tab);
        echo $this->appShell->renderMenu($tab->getKey());

        // Single enforcement point, ahead of both dispatch paths.
        $this->enforceSecurity($tab);

        $action = $this->resolveAction();
        if ($action !== '') {
            $this->dispatchAction($tab, $action);
        } else {
            $this->appShell->dispatch($tab->getKey());
        }

        $this->closePage();
    }

    /**
     * Resolve a tab key to its registration, following the shell's fallback
     * chain (requested key, then default view, then first registered tab).
     *
     * @param string $tabKey Requested tab key
     * @return TabRegistration
     *
     * @throws FrontControllerRoutingException When the shell has no tabs at
     *                                          all — rendering an empty shell
     *                                          would be a silent fail-open
     *
     * @since 1.1.0
     */
    protected function resolveTab(string $tabKey): TabRegistration
    {
        $tab = $this->appShell->resolveView($tabKey);
        if ($tab->getKey() === '') {
            throw new FrontControllerRoutingException(
                'No tab could be resolved for the request; the application shell has no tabs registered.'
            );
        }

        return $tab;
    }

    /**
     * Enforce access to the resolved tab. Runs once, before either dispatch
     * path, so an action request cannot route around the check the read path
     * would have made.
     *
     * The tab's own area wins; otherwise the constructor's fallback applies.
     * Both are guaranteed non-empty, so there is no "no area, no check" path.
     * If the runtime provides no checker the request is refused rather than
     * rendered.
     *
     * @param TabRegistration $tab Resolved tab
     * @return void
     *
     * @throws FrontControllerSecurityException When no checker is available
     *
     * @since 1.1.0
     */
    final protected function enforceSecurity(TabRegistration $tab): void
    {
        $area = $tab->getSecurity() !== '' ? $tab->getSecurity() : $this->fallbackSecurity;

        $checker = $this->securityChecker;
        if ($checker === null) {
            if (!function_exists('check_page_security')) {
                throw new FrontControllerSecurityException(
                    'No access checker is available, so the tab cannot be served safely. '
                    . 'AbstractFrontController must run inside an FA session (check_page_security), '
                    . 'or be constructed with a security_checker option.'
                );
            }
            $checker = function (string $securityArea): void {
                check_page_security($securityArea);
            };
        }

        $checker($area);
    }

    /**
     * Route an action verb to the resolved tab's controller.
     *
     * The controller decides whether it handles the verb; an unhandled verb
     * falls through to a normal render so a mistyped URL still shows the tab
     * rather than a blank body. Security has already been enforced by run()
     * before this is reached, so overriding this cannot widen access.
     *
     * @param TabRegistration $tab    Resolved tab
     * @param string          $action Action verb
     * @return void
     *
     * @since 1.1.0
     */
    protected function dispatchAction(TabRegistration $tab, string $action): void
    {
        $class = $tab->getControllerClass();
        if (!$tab->hasController() || $class === null || !class_exists($class)) {
            $this->renderUnavailable();
            return;
        }

        $controller = new $class(null, ['view' => $tab->getKey()]);
        if (!method_exists($controller, 'runAction')) {
            $this->renderUnavailable();
            return;
        }

        if ($controller->runAction($action) === true) {
            return;
        }

        // Verb not handled — render the tab normally.
        $this->appShell->dispatch($tab->getKey());
    }

    // ─── Overridable seams ──────────────────────────────────────────────

    /**
     * Include FA's includes/ui.inc, which the tab controllers depend on for
     * their form helpers. No-op when disabled or when the file is absent.
     *
     * @return void
     *
     * @since 1.1.0
     */
    protected function loadUiIncludes(): void
    {
        if (!$this->loadUi || $this->pathToRoot === '') {
            return;
        }

        $file = $this->pathToRoot . '/includes/ui.inc';
        if (!is_file($file)) {
            return;
        }

                // ui.inc reads $path_to_root as a plain variable in ITS including scope.
        // Included from a method, that variable does not exist, so every include
        // INSIDE ui.inc resolved against the filesystem root and failed silently -
        // leaving array_selector_row(), label_cell() and friends undefined until a
        // tab died mid-render. Seed the local before including.
        $path_to_root = $this->pathToRoot;

        include_once $file;
    }

    /**
     * Open the FA page wrapper.
     *
     * @param TabRegistration $tab Resolved tab
     * @return void
     *
     * @since 1.1.0
     */
    protected function openPage(TabRegistration $tab): void
    {
        if (!function_exists('page')) {
            return;
        }

        page($this->getPageTitle(), false, false, '', $this->getPageJs($tab));
    }

    /**
     * Close the FA page wrapper.
     *
     * @return void
     *
     * @since 1.1.0
     */
    protected function closePage(): void
    {
        if (function_exists('end_page')) {
            end_page();
        }
    }

    /**
     * Browser/FA page title.
     *
     * @return string
     *
     * @since 1.1.0
     */
    protected function getPageTitle(): string
    {
        return '';
    }

    /**
     * Extra <head> JavaScript the resolved tab needs.
     *
     * @param TabRegistration $tab Resolved tab
     * @return string
     *
     * @since 1.1.0
     */
    protected function getPageJs(TabRegistration $tab): string
    {
        return '';
    }

    /**
     * Message shown when an action verb has nowhere to go (tab is not
     * controller-backed, or the controller has no runAction()).
     *
     * @return void
     *
     * @since 1.1.0
     */
    protected function renderUnavailable(): void
    {
        $message = $this->localise('This action is not available for the selected tab.');
        echo '<p>' . $message . '</p>';
    }

    /**
     * @param string $message
     * @return string Translated message, or original when FA is absent.
     *
     * @since 1.1.0
     */
    protected function localise(string $message): string
    {
        return function_exists('_') ? _($message) : $message;
    }
}