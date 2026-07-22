<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

use ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistry;

/**
 * Convenience trait for modules that want to register calendar-specific
 * extensions (source types, menu items, type labels) with the Calendar module.
 *
 * Include this trait in your hooks class or module bootstrap to get
 * registerCalendar*() methods.  These methods write directly to the
 * generic ExtensionRegistry singleton, which the Calendar module reads
 * at render time.
 *
 * Usage in a module's hooks class:
 *
 *   class hooks_ksf_FA_Marketing extends hooks {
 *       use \ksfraser\FrontAccounting\Common\Traits\CalendarRegistrationTrait;
 *
 *       function activate_extension($company, $check_only = true) {
 *           $this->registerCalendarSourceType('campaign', 'Campaign', [
 *               'statuses' => ['planned', 'active', 'completed'],
 *               'color'    => '#4CAF50',
 *           ]);
 *           $this->registerCalendarMenuItem('my_campaigns', 'Campaigns', MENU_ENTRY, 60);
 *           $this->registerCalendarTypeLabel('campaign', 'Campaign', ['online_url']);
 *           return true;
 *       }
 *   }
 *
 * @package KsfCommon\Traits
 * @since   2.1.0
 */
trait CalendarRegistrationTrait
{
    /**
     * Register a custom calendar source type.
     *
     * The Calendar module will accept entries with this source_type,
     * display it in the type dropdown, and filter views accordingly.
     *
     * @param string $type     Machine name (e.g. 'campaign', 'project_task')
     * @param string $label    Human-readable label (e.g. 'Campaign')
     * @param array  $options  Optional: 'statuses' (string[]), 'color' (string),
     *                         'fields' (string[] — form fields to show)
     * @return void
     *
     * @since 2.1.0
     */
    protected function registerCalendarSourceType(string $type, string $label, array $options = []): void
    {
        $definition = array_merge(['label' => $label], $options);
        ExtensionRegistry::instance()->register('calendar_source_types', $type, $definition);
    }

    /**
     * Register a menu item for the Calendar module's sidebar/sub-menu.
     *
     * @param string   $key       Unique key / ?view= value
     * @param string   $label     Translated display label
     * @param int|null $faType    FA MENU_* constant for sidebar registration,
     *                            or null for sub-menu only
     * @param int      $priority  Sort order (lower = higher priority, default 50)
     * @return void
     *
     * @since 2.1.0
     */
    protected function registerCalendarMenuItem(string $key, string $label, $faType = null, int $priority = 50): void
    {
        ExtensionRegistry::instance()->register('calendar_menu_items', $key, [
            'key'      => $key,
            'label'    => $label,
            'fa_type'  => $faType,
            'priority' => $priority,
        ]);
    }

    /**
     * Register a type label and optional form field visibility for the
     * Calendar modal's entry type dropdown.
     *
     * @param string   $type    Source type value (must match a registered source type)
     * @param string   $label   Display label for the modal dropdown
     * @param string[] $fields  Optional form fields to show for this type
     *                          (e.g. ['online_url'] for meetings)
     * @return void
     *
     * @since 2.1.0
     */
    protected function registerCalendarTypeLabel(string $type, string $label, array $fields = []): void
    {
        ExtensionRegistry::instance()->register('calendar_type_labels', $type, [
            'type'   => $type,
            'label'  => $label,
            'fields' => $fields,
        ]);
    }

    /**
     * Register a status option for a specific calendar source type.
     *
     * @param string $type   Source type this status applies to
     * @param string $status Status machine name (e.g. 'campaign_planned')
     * @param string $label  Human-readable label
     * @return void
     *
     * @since 2.1.0
     */
    protected function registerCalendarStatus(string $type, string $status, string $label): void
    {
        ExtensionRegistry::instance()->register('calendar_statuses', $status, [
            'status' => $status,
            'label'  => $label,
            'type'   => $type,
        ]);
    }
}
