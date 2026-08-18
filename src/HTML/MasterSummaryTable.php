<?php
/**
 * MasterSummaryTable — reusable FA-aware master summary table component.
 *
 * Standardises the CRUD admin master table across all KSF FrontAccounting
 * modules. Renders:
 *   - an FA-styled `tablestyle` master summary table with paging,
 *   - per-row Edit + Delete submit buttons,
 *   - a centred Submit + Cancel button footer,
 *   - hidden `record_id` + `_tabs_sel` fields so a row action POST returns
 *     to the same tab/page after it completes.
 *
 * The no-hard-refresh behaviour mirrors the FA pattern documented in GitHub
 * issue #24 (FA_ProductAttributes): the page calls `set_focus(...)` and
 * `$Ajax->activate(...)` on the item/tab div, gated on the tab's own submit
 * button (see isSubmitPost() / getPostedAction() helpers). All buttons are
 * emitted with FA's `ajaxsubmit` class and `formnovalidate`.
 *
 * PHP 7.3 compatible — no typed properties, no PHP 8+ syntax.
 *
 * @package Ksfraser\Frontaccounting\HTML
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML;

class MasterSummaryTable
{
    /** @var array<int, array{key: string, label: string}> */
    private $columns;

    /** @var array<int, array<string, mixed>> */
    private $rows;

    /** @var array<string, bool> */
    private $rowActions;

    /** @var array<string, mixed> */
    private $options;

    /** @var string Form field name used to submit the record id. */
    private $recordIdField;

    /** @var string Row array key that holds the record id. */
    private $rowIdField;

    /** @var string Currently selected record id (hidden field value). */
    private $recordId;

    /** @var string FA `_tabs_sel` tab selector value. */
    private $tabSel;

    /** @var int Current page number. */
    private $page;

    /** @var int Rows per page. */
    private $perPage;

    /** @var int Total number of records. */
    private $total;

    /** @var string Form action ('' => current page). */
    private $formAction;

    /** @var string Div id re-activated via $Ajax->activate() after a row action. */
    private $notificationDiv;

    /** @var string Name of the footer Submit submit-button (gates the save). */
    private $submitButtonName;

    /** @var string Name of the footer Cancel submit-button. */
    private $cancelButtonName;

    /** @var string Optional title rendered above the table. */
    private $title;

    /** @var string Confirmation message shown before a delete row action. */
    private $deleteConfirmMessage;

    /** @var bool Whether the centred Submit + Cancel footer row is rendered. */
    private $showFooter;

    /** @var string Message rendered in an em row when the table has no rows. */
    private $emptyMessage;

    /** @var bool Whether row-action buttons use FA's ajaxsubmit class. */
    private $useAjax;

    /**
     * @param array<int, array{key: string, label: string}> $columns    List of `['key' => ..., 'label' => ...]`
     * @param array<int, array<string, mixed>>             $rows       List of assoc record arrays
     * @param array<string, bool>                          $rowActions Per-row action config (e.g. `['edit' => true, 'delete' => true]`)
     * @param array<string, mixed>                         $options    See option table below
     *
     * Options:
     *   - record_id_field        (string, default 'stock_id') hidden record id field name
     *   - row_id_field           (string, default 'id')        row array key holding the record id
     *   - record_id              (string, default '')          currently selected record id
     *   - tab_sel                (string, default '')          FA `_tabs_sel` value
     *   - page                   (int,    default 1)           current page
     *   - per_page               (int,    default 20)          rows per page
     *   - total                  (int,    default count($rows)) total records (pager)
     *   - form_action            (string, default '')          form action ('' = PHP_SELF)
     *   - notification_div       (string, default 'stock_id')  div re-activated after a row action
     *   - submit_button_name     (string, default 'submit')    footer Submit button name
     *   - cancel_button_name     (string, default 'cancel')    footer Cancel button name
     *   - title                  (string, default '')          optional table title
     *   - delete_confirm_message (string, default 'Delete this record?') delete confirm text
     *   - show_footer            (bool,   default true)         render the Submit + Cancel footer row
     *   - empty_message          (string, default '')            message shown in an em row when there are no rows
     *   - ajax                   (bool,   default true)          use FA ajaxsubmit buttons (false for standalone pages)
     *
     * @since 1.0.0
     */
    public function __construct(array $columns, array $rows, array $rowActions = [], array $options = [])
    {
        $this->columns    = $columns;
        $this->rows       = $rows;
        $this->rowActions = $rowActions;
        $this->options    = $options;

        $this->recordIdField         = (string) ($options['record_id_field'] ?? 'stock_id');
        $this->rowIdField            = (string) ($options['row_id_field'] ?? 'id');
        $this->recordId              = (string) ($options['record_id'] ?? '');
        $this->tabSel                = (string) ($options['tab_sel'] ?? '');
        $this->page                  = max(1, (int) ($options['page'] ?? 1));
        $this->perPage               = max(1, (int) ($options['per_page'] ?? 20));
        $this->total                 = (int) ($options['total'] ?? count($rows));
        $this->formAction            = (string) ($options['form_action'] ?? '');
        $this->notificationDiv       = (string) ($options['notification_div'] ?? 'stock_id');
        $this->submitButtonName      = (string) ($options['submit_button_name'] ?? 'submit');
        $this->cancelButtonName      = (string) ($options['cancel_button_name'] ?? 'cancel');
        $this->title                 = (string) ($options['title'] ?? '');
        $this->deleteConfirmMessage  = (string) ($options['delete_confirm_message'] ?? 'Delete this record?');
        $this->showFooter            = (bool) ($options['show_footer'] ?? true);
        $this->emptyMessage          = (string) ($options['empty_message'] ?? '');
        $this->useAjax               = (bool) ($options['ajax'] ?? true);
    }

    /**
     * Echo the full master summary table (title, header, rows, footer,
     * pager and hidden fields).
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function render(): void
    {
        if ($this->title !== '') {
            echo '<h3>' . htmlspecialchars($this->localise($this->title), ENT_QUOTES) . '</h3>' . "\n";
        }

        echo "<table class=\"tablestyle\">\n";

        echo "<tr class=\"tableheader\">\n";
        foreach ($this->columns as $column) {
            $label = htmlspecialchars($this->localise((string) ($column['label'] ?? '')), ENT_QUOTES);
            echo "<th>" . $label . "</th>\n";
        }
        if ($this->hasRowActions()) {
            echo '<th>' . htmlspecialchars($this->localise('Actions'), ENT_QUOTES) . "</th>\n";
        }
        echo "</tr>\n";

        $color = 0;
        foreach ($this->rows as $row) {
            echo ($color === 0) ? "<tr class=\"oddrow\">\n" : "<tr class=\"evenrow\">\n";
            $color = 1 - $color;

            foreach ($this->columns as $column) {
                $key   = (string) ($column['key'] ?? '');
                $value = isset($row[$key]) ? (string) $row[$key] : '';
                echo '<td>' . htmlspecialchars($value, ENT_QUOTES) . "</td>\n";
            }

            if ($this->hasRowActions()) {
                echo "<td>\n";
                echo $this->renderRowActions($row);
                echo "</td>\n";
            }

            echo "</tr>\n";
        }

        if (empty($this->rows) && $this->emptyMessage !== '') {
            echo '<tr><td colspan="' . $this->columnCount() . '"><em>'
                . htmlspecialchars($this->localise($this->emptyMessage), ENT_QUOTES)
                . "</em></td></tr>\n";
        }

        $this->renderFooterRow();
        $this->renderPager();

        echo "</table>\n";

        echo '<input type="hidden" name="' . htmlspecialchars($this->recordIdField, ENT_QUOTES)
            . '" value="' . htmlspecialchars($this->recordId, ENT_QUOTES) . '">' . "\n";
        echo '<input type="hidden" name="_tabs_sel" value="'
            . htmlspecialchars($this->tabSel, ENT_QUOTES) . '">' . "\n";
    }

    /**
     * @return bool Whether the Submit + Cancel footer row is rendered
     *
     * @since 1.0.0
     */
    public function getShowFooter(): bool
    {
        return $this->showFooter;
    }

    /**
     * Echo the FA-style pager row (inside the table) when the record count
     * exceeds the page size. Pager links preserve the `_tabs_sel` value and
     * the record id in the query string.
     *
     * Uses FA's `pager_link()` helper when available, otherwise falls back to
     * simple `?page=N` anchor links.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function renderPager(): void
    {
        $pageCount = $this->getPageCount();
        if ($pageCount <= 1) {
            return;
        }

        $current = $this->page;

        echo "<tr class=\"navibar\">\n";
        echo '<td colspan="' . $this->columnCount() . "\" class=\"navibar\">\n";
        echo "<div style=\"float:right;\">\n";
        echo $this->pagerLink(1, 'First', $current > 1);
        echo $this->pagerLink($current - 1, 'Prev', $current > 1);
        echo $this->pagerLink($current + 1, 'Next', $current < $pageCount);
        echo $this->pagerLink($pageCount, 'Last', $current < $pageCount);
        echo "</div>\n";

        $from = ($current - 1) * $this->perPage + 1;
        $to   = min($current * $this->perPage, $this->total);
        echo $this->localise('Records') . ' ' . $from . '-' . $to . ' of ' . $this->total;

        echo "</td>\n</tr>\n";
    }

    /**
     * Render the full table into a string.
     *
     * @return string Rendered HTML
     *
     * @since 1.0.0
     */
    public function toHtml(): string
    {
        ob_start();
        $this->render();
        return (string) ob_get_clean();
    }

    /**
     * @return string Form field name used to submit the record id
     *
     * @since 1.0.0
     */
    public function getRecordIdField(): string
    {
        return $this->recordIdField;
    }

    /**
     * @return string Row array key that holds the record id
     *
     * @since 1.0.0
     */
    public function getRowIdField(): string
    {
        return $this->rowIdField;
    }

    /**
     * @return string Currently selected record id
     *
     * @since 1.0.0
     */
    public function getRecordId(): string
    {
        return $this->recordId;
    }

    /**
     * @return string FA `_tabs_sel` tab selector value
     *
     * @since 1.0.0
     */
    public function getTabSel(): string
    {
        return $this->tabSel;
    }

    /**
     * @return int Current page number
     *
     * @since 1.0.0
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * @return int Rows per page
     *
     * @since 1.0.0
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * @return int Total number of records
     *
     * @since 1.0.0
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return int Total number of pages (minimum 1)
     *
     * @since 1.0.0
     */
    public function getPageCount(): int
    {
        if ($this->total < 1) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * @return string Form action ('' = current page)
     *
     * @since 1.0.0
     */
    public function getFormAction(): string
    {
        return $this->formAction;
    }

    /**
     * @return string Div id re-activated via $Ajax->activate() after a row action
     *
     * @since 1.0.0
     */
    public function getNotificationDiv(): string
    {
        return $this->notificationDiv;
    }

    /**
     * @return string Name of the footer Submit submit-button (gates the save)
     *
     * @since 1.0.0
     */
    public function getSubmitButtonName(): string
    {
        return $this->submitButtonName;
    }

    /**
     * @return string Name of the footer Cancel submit-button
     *
     * @since 1.0.0
     */
    public function getCancelButtonName(): string
    {
        return $this->cancelButtonName;
    }

    /**
     * @return string Optional title rendered above the table
     *
     * @since 1.0.0
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Values of the hidden fields rendered inside the form, keyed by field
     * name, so pages can build their own forms.
     *
     * @return array<string, string>
     *
     * @since 1.0.0
     */
    public function getHiddenFieldValues(): array
    {
        return [
            $this->recordIdField => $this->recordId,
            '_tabs_sel'          => $this->tabSel,
        ];
    }

    /**
     * @return array<int, array{key: string, label: string}> Column definitions
     *
     * @since 1.0.0
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, array<string, mixed>> Row records
     *
     * @since 1.0.0
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, bool> Row action configuration
     *
     * @since 1.0.0
     */
    public function getRowActions(): array
    {
        return $this->rowActions;
    }

    /**
     * Whether the table renders per-row action buttons (and the Actions column).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function hasRowActions(): bool
    {
        foreach ($this->rowActions as $enabled) {
            if ($enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the POST is a "save and return to tab" submission for this
     * table: the footer Submit button or any of the table's row action
     * buttons (edit_<id> / delete_<id>) is present. Ordinary tab-change and
     * record-selector posts never match.
     *
     * @param array $post POST data (e.g. $_POST)
     * @return bool
     *
     * @since 1.0.0
     */
    public function isSubmitPost(array $post): bool
    {
        if (isset($post[$this->submitButtonName])) {
            return true;
        }

        return $this->getPostedAction($post) !== null;
    }

    /**
     * Detect a row action in the POST: returns `['action' => 'edit'|'delete',
     * 'id' => '<record id>']` when an enabled action button name is present,
     * otherwise null.
     *
     * @param array $post POST data (e.g. $_POST)
     * @return array{action: string, id: string}|null
     *
     * @since 1.0.0
     */
    public function getPostedAction(array $post): ?array
    {
        foreach ($this->getActionNames() as $action) {
            $prefix = $action . '_';
            foreach ($post as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (strpos($key, $prefix) === 0 && strlen($key) > strlen($prefix)) {
                    return [
                        'action' => $action,
                        'id'     => substr($key, strlen($prefix)),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Echo the centred footer Submit + Cancel row.
     *
     * @return void
     *
     * @since 1.0.0
     */
    private function renderFooterRow(): void
    {
        if (!$this->showFooter) {
            return;
        }

        echo '<tr>' . "\n";
        echo '<td colspan="' . $this->columnCount() . '">' . "\n";
        $footer = new FormFooter($this->submitButtonName, $this->cancelButtonName);
        $footer->render();
        echo "</td>\n</tr>\n";
    }

    /**
     * Render the per-row Edit / Delete action buttons.
     *
     * @param array $row Record data
     * @return string Buttons HTML
     *
     * @since 1.0.0
     */
    private function renderRowActions(array $row): string
    {
        $id  = isset($row[$this->rowIdField]) ? (string) $row[$this->rowIdField] : '';
        $out = '';

        if (!empty($this->rowActions['edit'])) {
            $out .= $this->actionButton('edit', $id, $this->localise('Edit'), false);
        }
        if (!empty($this->rowActions['delete'])) {
            $out .= $this->actionButton('delete', $id, $this->localise('Delete'), true);
        }

        return $out;
    }

    /**
     * Build a single row action submit button: name `<action>_<id>`,
     * `type="submit"`, `formnovalidate`, and a `confirm()` guard on delete.
     * Uses FA's submit_js_confirm() when available so the ajaxsubmit handler
     * still runs the confirm; otherwise falls back to an inline onclick.
     *
     * @param string $action  Action name ('edit' or 'delete')
     * @param string $id      Record id
     * @param string $label   Button label
     * @param bool   $confirm Whether to guard with a confirm()
     * @return string Button HTML
     *
     * @since 1.0.0
     */
    private function actionButton(string $action, string $id, string $label, bool $confirm): string
    {
        $name    = $action . '_' . $id;
        $escName = htmlspecialchars($name, ENT_QUOTES);
        $escId   = htmlspecialchars($id, ENT_QUOTES);
        $escLbl  = htmlspecialchars($label, ENT_QUOTES);

        $extra = '';
        if ($confirm) {
            if (function_exists('submit_js_confirm')) {
                submit_js_confirm($name, $this->deleteConfirmMessage);
            } else {
                $extra = ' onclick="return confirm(\'' . $this->jsEscape($this->deleteConfirmMessage) . '\');"';
            }
        }

        $btnClass = $this->useAjax ? 'ajaxsubmit' : 'inputsubmit';

        return '<button class="' . $btnClass . '" type="submit" formnovalidate name="' . $escName
            . '" id="' . $escName . '" value="' . $escId . '"' . $extra
            . '><span>' . $escLbl . '</span></button>';
    }

    /**
     * Build a single pager anchor link (or disabled span) for the given page.
     *
     * @param int    $pageNo  Target page number
     * @param string $label   Link label
     * @param bool   $enabled Whether the link is reachable from the current page
     * @return string Link/span HTML
     *
     * @since 1.0.0
     */
    private function pagerLink(int $pageNo, string $label, bool $enabled): string
    {
        $escLabel = htmlspecialchars($this->localise($label), ENT_QUOTES);

        if (!$enabled) {
            return '<span>' . $escLabel . '</span>&nbsp;';
        }

        $url = $this->pagerUrl($pageNo);

        if (function_exists('pager_link')) {
            return pager_link($escLabel, $url) . '&nbsp;';
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $escLabel . '</a>&nbsp;';
    }

    /**
     * Build the pager URL for a page, preserving the record id and `_tabs_sel`
     * values in the query string.
     *
     * @param int $pageNo Target page number
     * @return string Relative URL like `?page=2&stock_id=X&_tabs_sel=settings`
     *
     * @since 1.0.0
     */
    private function pagerUrl(int $pageNo): string
    {
        $params = ['page=' . $pageNo];

        if ($this->recordId !== '') {
            $params[] = rawurlencode($this->recordIdField) . '=' . rawurlencode($this->recordId);
        }
        if ($this->tabSel !== '') {
            $params[] = '_tabs_sel=' . rawurlencode($this->tabSel);
        }

        return '?' . implode('&', $params);
    }

    /**
     * List of enabled action names.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    private function getActionNames(): array
    {
        $names = [];
        foreach (['edit', 'delete'] as $action) {
            if (!empty($this->rowActions[$action])) {
                $names[] = $action;
            }
        }

        return $names;
    }

    /**
     * Number of table columns (data columns + optional Actions column).
     *
     * @return int
     *
     * @since 1.0.0
     */
    private function columnCount(): int
    {
        return count($this->columns) + ($this->hasRowActions() ? 1 : 0);
    }

    /**
     * Escape a string for embedding inside a single-quoted JS string in an
     * HTML attribute (backslashes and single quotes).
     *
     * @param string $value Raw value
     * @return string JS-safe value
     *
     * @since 1.0.0
     */
    private function jsEscape(string $value): string
    {
        return str_replace(
            ['\\', "'"],
            ['\\\\', "\\'"],
            $value
        );
    }

    /**
     * Translate a message when FrontAccounting's _() helper is available.
     *
     * @param string $message Untranslated message
     * @return string Translated message (or original outside FA)
     *
     * @since 1.0.0
     */
    private function localise(string $message): string
    {
        return function_exists('_') ? _($message) : $message;
    }
}
