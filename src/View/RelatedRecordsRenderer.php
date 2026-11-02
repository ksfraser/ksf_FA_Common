<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\View;

use ksfraser\FrontAccounting\Common\Repository\EntityRegistry;
use ksfraser\RelatedTo\UI\RelatedRecordsRendererInterface;
use ksfraser\RelatedTo\DTO\RelatedRecordDto;

/**
 * Notes-specific related-records renderer.
 *
 * Renders related records grouped by rhs type under collapsible (twisty)
 * section headers, with resolved labels and clickable links to open records
 * in new windows/tabs.
 *
 * @since 1.0.0
 */
class RelatedRecordsRenderer implements RelatedRecordsRendererInterface
{
    /**
     * @var \ksfraser\FrontAccounting\Common\Repository\EntityRegistry
     */
    private $registry;

    /**
     * @param \ksfraser\FrontAccounting\Common\Repository\EntityRegistry $registry Entity registry for label resolution
     * @since 1.0.0
     */
    public function __construct(\ksfraser\FrontAccounting\Common\Repository\EntityRegistry $registry = null)
    {
        $this->registry = $registry ?? new \ksfraser\FrontAccounting\Common\Repository\EntityRegistry();
    }

    /**
     * {@inheritdoc}
     * @since 1.0.0
     */
    public function renderRelatedRecords(
        $lhsType,
        $lhsId,
        array $related,
        array $options = array()
    ) {
        $cssClass = isset($options['css_class']) ? (string) $options['css_class'] : 'related-records';
        $headerClass = isset($options['header_class']) ? (string) $options['header_class'] : 'related-header';
        $tableClass = isset($options['table_class']) ? (string) $options['table_class'] : 'related-table';

        // Group related records by rhs type
        $groups = array();
        foreach ($related as $record) {
            $type = $record->rhsType;
            if (!isset($groups[$type])) {
                $groups[$type] = array();
            }
            $groups[$type][] = $record;
        }

        if (empty($groups)) {
            return '<p class="' . $this->esc('no-related') . '">' . _('No related records.') . '</p>';
        }

        $html = '<div class="' . $this->esc($cssClass) . '">';

        // Sort groups by type label for stable display
        ksort($groups);

        foreach ($groups as $type => $records) {
            $label = isset($options['type_labels'][$type])
                ? (string) $options['type_labels'][$type]
                : $this->typeLabel($type);

            $count = count($records);
            $toggleId = 'rel-toggle-' . $this->esc($type) . '-' . $this->esc((string) $lhsId);

            $html .= '<div class="' . $this->esc($headerClass) . '">';
            $html .= '<a href="#" onclick="return toggleRelated(\'' . $this->escJs($toggleId) . '\');">';
            $html .= '<span class="rel-arrow">▼</span> ' . $this->esc($label);
            $html .= ' <span class="rel-count">(' . $count . ')</span>';
            $html .= '</a>';
            $html .= '</div>';

            $html .= '<div id="' . $this->esc($toggleId) . '" class="rel-content" style="display:block;">';
            $html .= '<table class="' . $this->esc($tableClass) . '">';
            $html .= '<thead><tr>';
            $html .= '<th>' . _('Record') . '</th>';
            $html .= '<th>' . _('Role') . '</th>';
            $html .= '<th></th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            foreach ($records as $record) {
                $label = $this->recordLabel($record->rhsType, $record->rhsId);
                $url = $this->recordUrl($record->rhsType, $record->rhsId);
                $role = $record->linkRole !== null ? $record->linkRole : _('reference');

                $html .= '<tr>';
                $html .= '<td>';
                if ($url !== '#') {
                    $html .= '<a href="' . $this->esc($url) . '" target="_blank" rel="noopener noreferrer">' . $this->esc($label) . '</a>';
                } else {
                    $html .= $this->esc($label);
                }
                $html .= '</td>';
                $html .= '<td>' . $this->esc($role) . '</td>';
                $html .= '<td><a href="notes.php?action=unlink&unlink_type=' . $this->esc($record->rhsType) . '&unlink_id=' . $this->esc($record->rhsId) . '&unlink_role=' . $this->esc($role) . '&edit_id=' . $this->esc((string) $lhsId) . '" onclick="return confirm(\'' . _('Remove this association?') . '\');">' . _('Remove') . '</a></td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';
        }

        $html .= '</div>';

        $html .= '<script>'
            . 'function toggleRelated(id) {'
            . ' var el = document.getElementById(id);'
            . ' if (!el) return false;'
            . ' var arrow = el.previousElementSibling.querySelector(".rel-arrow");'
            . ' if (el.style.display === "none") { el.style.display = "block"; if (arrow) arrow.textContent = "▼"; }'
            . ' else { el.style.display = "none"; if (arrow) arrow.textContent = "▶"; }'
            . ' return false;'
            . '}'
            . '</script>';

        return $html;
    }

    /**
     * Human-readable label for an entity type.
     *
     * @param string $type
     * @return string
     * @since 1.0.0
     */
    private function typeLabel($type)
    {
        $labels = array(
            'customer' => _('Customers'),
            'contact' => _('Contacts'),
            'meeting' => _('Meetings'),
            'opportunity' => _('Opportunities'),
            'project' => _('Projects'),
            'task' => _('Tasks'),
            'calendar' => _('Calendar Entries'),
            'attachment' => _('Attachments'),
            'contract' => _('Contracts'),
            'note' => _('Notes'),
        );

        return isset($labels[$type]) ? $labels[$type] : ucfirst((string) $type);
    }

    /**
     * Label for a related record — resolves actual name from the entity's table.
     *
     * @param string $type Entity type key (e.g. 'customer', 'contact')
     * @param string|int $id Primary key
     * @return string Resolved label, or the ID if not found
     * @since 1.0.0
     */
    private function recordLabel($type, $id)
    {
        $def = $this->registry->get($type);

        if ($def === null) {
            return (string) $id;
        }

        // Use FA's db_query to look up the label
        global $db;
        if (!isset($db) || !$db instanceof \mysqli) {
            return (string) $id;
        }

        $table = TB_PREF . $def['table'];
        $pk = $def['pk'];
        $labelCol = $def['label'];

        $sql = "SELECT `$labelCol` AS label FROM `$table` WHERE `$pk` = " . $db->real_escape_string((string) $id) . " LIMIT 1";
        $result = db_query($sql);

        if ($result !== false && db_num_rows($result) > 0) {
            $row = db_fetch_assoc($result);
            if ($row !== false && !empty($row['label'])) {
                return (string) $row['label'];
            }
        }

        return (string) $id;
    }

    /**
     * Get the URL to open a related record in a new window/tab.
     *
     * @param string $type Entity type key
     * @param string|int $id Primary key
     * @return string URL
     * @since 1.0.0
     */
    private function recordUrl($type, $id)
    {
        $urls = array(
            'customer' => "sales/customer_inquiries.php?customer_id={$id}",
            'contact' => "crm/crm_contacts.php?contact_id={$id}",
            'meeting' => "crm/meeting_view.php?id={$id}",
            'opportunity' => "crm/opportunity_view.php?id={$id}",
            'project' => "project/project_view.php?project_id={$id}",
            'task' => "project/task_view.php?task_id={$id}",
            'calendar' => "calendar/cal.php?id={$id}",
            'attachment' => "admin/attachments.php?vw={$id}",
            'invoice' => "sales/sales_inquiry.php?trans_no={$id}",
        );

        return isset($urls[$type]) ? $urls[$type] : "#";
    }

    /**
     * @param string $value
     * @return string
     * @since 1.0.0
     */
    private function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string $value
     * @return string
     * @since 1.0.0
     */
    private function escJs($value)
    {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value);
    }
}