<?php
/**
 * FAModuleMenu
 *
 * Single-responsibility class for defining and rendering a FrontAccounting
 * module's navigation menu.  Serves two consumers from one item list:
 *
 *  1. FA sidebar (hooks.php / application class) via registerWithApp().
 *  2. In-page HTML sub-menu (cal.php / any page script) via render().
 *
 * Extracted from ksf_FA_Calendar to ksf_FA_Common as shared platform
 * infrastructure.  All KSF FA modules should use this class for
 * consistent menu registration and rendering.
 *
 * Usage:
 * <code>
 *   $menu = new FAModuleMenu('cal.php', 'view', $currentView);
 *   $menu->addItem('calendar',       _('Calendar'),       MENU_MAIN)
 *        ->addItem('my_events',      _('My Events'),      MENU_ENTRY)
 *        ->addItem('my_invitations', _('My Invitations'), MENU_ENTRY)
 *        ->addItem('settings',       _('Settings'),       MENU_ENTRY);
 *
 *   // In hooks.php (application class constructor):
 *   $menu->registerWithApp($this, 'SA_ksf_FA_CalendarVIEW');
 *
 *   // In cal.php page renderer:
 *   echo $menu->render();
 * </code>
 *
 * @package KsfCommon\Menu
 * @since   1.0.0 (ksf_FA_Calendar), 2.1.0 (extracted to ksf_FA_Common)
 */

declare(strict_types=1);

namespace KsfCommon\Menu;

/**
 * FA module navigation menu — item list, in-page HTML renderer,
 * and FA application sidebar registrar.
 *
 * @since 1.0.0
 */
class FAModuleMenu
{
    /**
     * Base script URL (relative, e.g. "cal.php" or
     * "modules/ksf_FA_Calendar/cal.php").
     *
     * @var string
     */
    private $baseUrl;

    /**
     * GET parameter name that carries the active view key, e.g. "view".
     *
     * @var string
     */
    private $viewParam;

    /**
     * The key of the currently active item (matched against item 'key').
     *
     * @var string
     */
    private $activeKey;

    /**
     * Ordered list of menu items.
     *
     * Each element is an associative array with keys:
     *   'key'      => string   — unique identifier, used as the ?view= value
     *   'label'    => string   — translated display text
     *   'fa_type'  => int|null — FA MENU_* constant (MENU_MAIN, MENU_ENTRY, …)
     *                            Null items are rendered in the HTML sub-menu
     *                            but not registered in the FA sidebar.
     *
     * @var array<int, array{key: string, label: string, fa_type: int|null}>
     */
    private $items = [];

    /**
     * @param string $baseUrl   Base script path (relative URL).
     * @param string $viewParam GET parameter that selects the active view.
     * @param string $activeKey Key of the currently active item.
     *
     * @since 1.0.0
     */
    public function __construct(string $baseUrl, string $viewParam = 'view', string $activeKey = '')
    {
        $this->baseUrl   = $baseUrl;
        $this->viewParam = $viewParam;
        $this->activeKey = $activeKey;
    }

    /**
     * Add a menu item.
     *
     * @param string   $key    Unique key / ?view= value.
     * @param string   $label  Translated display label.
     * @param int|null $faType FA MENU_* constant for sidebar registration,
     *                         or null to suppress FA sidebar registration.
     * @return self Fluent interface.
     *
     * @since 1.0.0
     */
    public function addItem(string $key, string $label, $faType = null): self
    {
        $this->items[] = [
            'key'     => $key,
            'label'   => $label,
            'fa_type' => $faType,
        ];
        return $this;
    }

    /**
     * Append multiple menu items at once.
     *
     * @param array<int, array{key: string, label: string, fa_type: int|null, priority?: int}> $items
     * @return self Fluent interface.
     *
     * @since 2.1.0
     */
    public function addItems(array $items): self
    {
        foreach ($items as $item) {
            $this->addItem($item['key'], $item['label'], $item['fa_type'] ?? null);
        }
        return $this;
    }

    /**
     * Append items from the ExtensionRegistry, sorted by priority.
     *
     * @param array<int, array{key: string, label: string, fa_type: int|null, priority?: int}> $registeredItems
     * @return self Fluent interface.
     *
     * @since 2.1.0
     */
    public function addRegisteredItems(array $registeredItems): self
    {
        usort($registeredItems, function ($a, $b) {
            return ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50);
        });

        foreach ($registeredItems as $item) {
            $this->addItem($item['key'], $item['label'], $item['fa_type'] ?? null);
        }
        return $this;
    }

    /**
     * Change the active key after construction.
     *
     * @param string $key Key of the active item.
     * @return self Fluent interface.
     *
     * @since 1.0.0
     */
    public function setActive(string $key): self
    {
        $this->activeKey = $key;
        return $this;
    }

    /**
     * Render the in-page HTML sub-menu.
     *
     * Outputs pipe-separated links with the active item bolded.
     * Safe to call from procedural page scripts.
     *
     * @return string HTML string (not escaped — labels must already be translated).
     *
     * @since 1.0.0
     */
    public function render(): string
    {
        if (empty($this->items)) {
            return '';
        }

        $parts = [];
        foreach ($this->items as $item) {
            $url    = $this->baseUrl . '?' . $this->viewParam . '=' . rawurlencode($item['key']);
            $label  = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $isActive = ($item['key'] === $this->activeKey);

            if ($isActive) {
                $parts[] = '<strong><a href="' . $url . '">' . $label . '</a></strong>';
            } else {
                $parts[] = '<a href="' . $url . '">' . $label . '</a>';
            }
        }

        return '<div class="ksf-module-menu" style="margin-bottom:12px;">'
            . implode('&nbsp;&nbsp;|&nbsp;&nbsp;', $parts)
            . '</div>' . "\n";
    }

    /**
     * Register all items that carry a fa_type into a FA application object.
     *
     * Must be called from within an FA application class constructor after
     * add_module() has been called.
     *
     * @param object $app           FA application instance (has add_lapp_function()).
     * @param string $securityArea  FA security area identifier string.
     * @param int    $moduleIndex   Module index for add_lapp_function() (default 0).
     * @return void
     *
     * @since 1.0.0
     */
    public function registerWithApp($app, string $securityArea, int $moduleIndex = 0): void
    {
        foreach ($this->items as $item) {
            if ($item['fa_type'] === null) {
                continue;
            }

            $url = $this->baseUrl . '?' . $this->viewParam . '=' . rawurlencode($item['key']);

            $app->add_lapp_function(
                $moduleIndex,
                $item['label'],
                $url,
                $securityArea,
                $item['fa_type']
            );
        }
    }

    /**
     * Return the raw items array (useful for testing or custom rendering).
     *
     * @return array<int, array{key: string, label: string, fa_type: int|null}>
     *
     * @since 1.0.0
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
