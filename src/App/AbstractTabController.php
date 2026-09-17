<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

use Ksfraser\Frontaccounting\HTML\FormFooter;
use Ksfraser\Frontaccounting\HTML\MasterSummaryTable;
use Ksfraser\Frontaccounting\HTML\TabContext;
use Ksfraser\HTML\Crud\FieldForm;

/**
 * AbstractTabController — templated SRP for a single CRUD tab.
 *
 * The controller coordinates the page flow for one app tab:
 *
 *   1. read the request state via TabContext (DI),
 *   2. route POST actions (add / update / delete / edit-preview),
 *   3. render the SUMMARY UI SRP above (MasterSummaryTable, paged, Edit/Delete),
 *   4. render the ENTRY-FORM UI SRP below (FieldForm — blank-injected for add,
 *      or a DTO-derived values array for update; the footer Submit label flips
 *      from "Save"/"Add" to "Update").
 *
 * Layout contract (mirrors FA items.php "Sales Pricing"): summary table on top
 * with paging (10 entries default), entry form immediately below — the form is
 * always on the page, there are no "Add XYZ" links. Edit on the summary row
 * pre-loads the form; the Save button becomes Update.
 *
 * Subclasses implement the data-access contract (list/find/save/update/delete)
 * delegating DI'd DAOs/Repositories/Services, and describe fields via the
 * FR-006-007 getFieldMetadata() schema (fields[].showInTable / showInForm).
 *
 * PHP 7.3 compatible — no typed properties, no PHP 8+ syntax.
 *
 * @package KsfCommon\App
 * @since   1.0.0
 *
 * @UML Note: APP_TAB_ARCHITECTURE.md §2 (controller SRP role) + §10 (SRP renderers)
 * @BABOK Related: FR-HRM-001, FR-006-007 (field metadata)
 */
abstract class AbstractTabController
{
    /** @var TabContext Request state (DI). */
    protected $context;

    /** @var int Default rows per page (matches FA paging default of 10). */
    protected $perPage = 10;

    /** @var string|null Order-by column for the summary list (null = default). */
    protected $orderBy = null;

    /** @var array<string, mixed> Extra constructor options. */
    protected $options = [];

    /**
     * @param TabContext|null    $context DI request state (built from $_POST/GET when null)
     * @param array<string, mixed> $options { per_page?: int, order_by?: ?string }
     *
     * @since 1.0.0
     */
    public function __construct(?TabContext $context = null, array $options = [])
    {
        $this->options = $options;
        $this->perPage = (int) ($options['per_page'] ?? $this->perPage);
        $this->orderBy = $options['order_by'] ?? $this->orderBy;

        $pk = $this->getPkField();
        $this->context = $context !== null
            ? $context
            : TabContext::fromPost($_POST, $pk);
    }

    // ─── Data-access contract (override in concrete tabs) ─────────────

    /**
     * @return string Primary key field name (e.g. 'department_id').
     *
     * @since 1.0.0
     */
    abstract protected function getPkField(): string;

    /**
     * FR-006-007 field metadata: entity/table/label/pk/fields/fk_ddls.
     * Fields with showInTable become summary columns; showInForm the entry form.
     *
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    abstract protected function getFieldMetadata(): array;

    /**
     * Paged row list for the summary table.
     *
     * @param int $page   1-based page
     * @param int $perPage Rows per page
     * @return array<int, array<string, mixed>> Row arrays keyed by column key
     *
     * @since 1.0.0
     */
    abstract protected function listRows(int $page, int $perPage): array;

    /**
     * Total record count (for the pager).
     *
     * @return int
     *
     * @since 1.0.0
     */
    abstract protected function countRows(): int;

    /**
     * Load a single record for the update (pre-edit) form.
     *
     * @param string $pk
     * @return array<string, mixed>|null DTO-derived values, or null when not found
     *
     * @since 1.0.0
     */
    abstract protected function findRecord(string $pk): ?array;

    /**
     * Persist a new record. Return the new primary key.
     *
     * @param array<string, mixed> $data Values from the entry form
     * @return string|int
     *
     * @since 1.0.0
     */
    abstract protected function createRecord(array $data);

    /**
     * Persist changes to an existing record.
     *
     * @param string $pk
     * @param array<string, mixed> $data
     * @return void
     *
     * @since 1.0.0
     */
    abstract protected function updateRecord(string $pk, array $data): void;

    /**
     * Delete a record.
     *
     * @param string $pk
     * @return void
     *
     * @since 1.0.0
     */
    abstract protected function deleteRecord(string $pk): void;

    // ─── Page flow ─────────────────────────────────────────────────────

    /**
     * Run the tab: handle POST actions, render summary above + entry form below.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function run(): void
    {
        $this->handlePost();

        echo $this->renderStart();

        $this->renderSummaryTable();
        echo $this->renderEntryForm();

        echo $this->renderEnd();
    }

    /**
     * Route POST actions (edit preview / delete / save-update / cancel).
     *
     * Subclasses may override to add per-action hooking, but should call
     * parent where practical.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function handlePost(): void
    {
        $post = $_POST;

        // Delete / Edit row actions arrive as <action>_<pk> submit buttons.
        $action = null;
        $actionPk = '';
        foreach (['delete', 'edit'] as $candidate) {
            foreach ($post as $key => $value) {
                if (strpos((string) $key, $candidate . '_') === 0 && $value !== '') {
                    $action = $candidate;
                    $actionPk = substr((string) $key, strlen($candidate) + 1);
                    break 2;
                }
            }
        }

        if ($action === 'delete' && $actionPk !== '') {
            $this->deleteRecord($actionPk);
            $this->redirectAfterPost('', 'deleted');
            return;
        }

        if ($action === 'edit' && $actionPk !== '') {
            // Pre-load the form for update; summary stays on the same page.
            $this->context = new TabContext($actionPk, $this->tabSel(), $this->page(), $this->getPkField());
            return;
        }

        $submit = $post[$this->getSubmitName()] ?? null;
        if ($submit !== null && $submit !== '') {
            $pk = (string) $this->context->getRecordId();
            $data = $this->collectFormValues();

            if ($pk !== '') {
                $this->updateRecord($pk, $data);
                $this->redirectAfterPost($pk, 'updated');
            } else {
                $newPk = $this->createRecord($data);
                $this->redirectAfterPost((string) $newPk, 'created');
            }
        }

        $cancel = $post[$this->getCancelName()] ?? null;
        if ($cancel !== null && $cancel !== '') {
            $this->redirectAfterPost('', 'cancelled');
        }
    }

    /**
     * Values collected for save/update. Default = all fields flagged
     * showInForm except the pk.
     *
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    protected function collectFormValues(): array
    {
        $data = [];
        $pk = $this->getPkField();
        foreach ($this->getFields('showInForm') as $name => $field) {
            if ($name === $pk) {
                continue;
            }
            $data[$name] = $_POST[$name] ?? ($field['default'] ?? '');
        }
        return $data;
    }

    // ─── Rendering helpers ─────────────────────────────────────────────

    /**
     * @return string Opening `<form>` + hidden state ('' outside FA or when a
     *               host already owns the form).
     *
     * @since 1.0.0
     */
    protected function renderStart(): string
    {
        if (!function_exists('start_form')) {
            return '';
        }
        ob_start();
        start_form(false, '', $this->getPkField());
        echo '<input type="hidden" name="' . $this->getPkField() . '" value="'
            . htmlspecialchars($this->context->getRecordId(), ENT_QUOTES) . '">';
        return (string) ob_get_clean();
    }

    /**
     * @return string Closing `</form>` ('' outside FA).
     *
     * @since 1.0.0
     */
    protected function renderEnd(): string
    {
        if (!function_exists('end_form')) {
            return '';
        }
        ob_start();
        end_form();
        return (string) ob_get_clean();
    }

    /**
     * Render the paged SUMMARY UI SRP on top.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function renderSummaryTable(): void
    {
        $page = max(1, $this->page());
        $rows = $this->listRows($page, $this->perPage);
        $total = $this->countRows();

        $columns = [];
        foreach ($this->getFields('showInTable') as $name => $field) {
            $columns[] = ['key' => $name, 'label' => $field['label'] ?? $name];
        }

        $pk = $this->getPkField();
        $summary = new MasterSummaryTable(
            $columns,
            $rows,
            ['edit' => true, 'delete' => true],
            [
                'record_id_field'  => $pk,
                'row_id_field'     => $pk,
                'record_id'        => $this->context->getRecordId(),
                'tab_sel'          => $this->tabSel(),
                'page'             => $page,
                'per_page'         => $this->perPage,
                'total'            => $total,
                'form_action'      => $this->formAction(),
                'notification_div' => $pk,
                'submit_button_name' => $this->getSubmitName(),
                'cancel_button_name' => $this->getCancelName(),
                'title'            => $this->getTitle(),
                'show_footer'      => false, // footer belongs to the entry form below
                'empty_message'    => $this->localise('No records yet.'),
                'ajax'             => false,
                'preserve_params'  => $this->preserveParams(),
            ]
        );
        $summary->render();
    }

    /**
     * Render the ENTRY-FORM UI SRP below (blank = add, DTO values = update).
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function renderEntryForm(): string
    {
        if (!function_exists('start_table')) {
            return '';
        }

        $pk = $this->context->getRecordId();
        $values = $pk !== ''
            ? ($this->findRecord($pk) ?? [])
            : $this->blankValues();

        $fkOptions = $this->fkOptions();
        $metadata = $this->getFieldMetadata();

        ob_start();
        $this->renderEntryFormControlBlock($metadata, $values, $fkOptions);

        echo '<br>';
        $footer = new FormFooter(
            $this->getSubmitName(),
            $this->getCancelName(),
            $pk !== '' ? $this->localise('Update') : $this->localise('Save'),
            $this->localise('Cancel')
        );
        $footer->render();
        return (string) ob_get_clean();
    }

    /**
     * Render the actual form control grid — defaults to FieldForm when the
     * html package's Crud\FieldForm class is available.
     *
     * @param array<string, mixed> $metadata  Full field metadata
     * @param array<string, mixed> $values    Pre-filled (update) or blank (add)
     * @param array<string, mixed> $fkOptions Field name => options
     * @return void
     *
     * @since 1.0.0
     */
    protected function renderEntryFormControlBlock(array $metadata, array $values, array $fkOptions): void
    {
        if (class_exists(FieldForm::class)) {
            echo FieldForm::renderForm(
                $metadata['fields'] ?? [],
                $values,
                $fkOptions,
                ['hidden' => [$this->getPkField() => $this->context->getRecordId()]]
            );
            return;
        }

        // Fallback: plain FA text rows for fields flagged showInForm.
        if (!function_exists('text_row')) {
            return;
        }
        start_table(TABLESTYLE2);
        foreach ($metadata['fields'] ?? [] as $name => $field) {
            if (empty($field['showInForm'])) {
                continue;
            }
            $value = $values[$name] ?? ($field['default'] ?? '');
            text_row($field['label'] ?? $name, $name, $value, 40, 40);
        }
        end_table(1);
    }

    // ─── Post-success navigation ───────────────────────────────────────

    /**
     * Redirect after a successful action (PRG). Override to echo a
     * notification + $Ajax->activate() instead when inside a host form.
     *
     * @param string $pk     Affected record pk ('' for delete/cancel)
     * @param string $status 'created'|'updated'|'deleted'|'cancelled'
     * @return void
     *
     * @since 1.0.0
     */
    protected function redirectAfterPost(string $pk, string $status): void
    {
        if ($pk !== '') {
            $this->notify($this->localise('Record saved'));
        }

        $url = $this->context->redirectTarget();
        if ($url !== '') {
            header('Location: ' . $url);
            exit;
        }
    }

    // ─── Small helpers ─────────────────────────────────────────────────

    /**
     * Field metadata filtered by a flag.
     *
     * @param string $flag 'showInTable'|'showInForm'
     * @return array<string, array<string, mixed>>
     *
     * @since 1.0.0
     */
    protected function getFields(string $flag): array
    {
        $out = [];
        foreach ($this->getFieldMetadata()['fields'] ?? [] as $name => $field) {
            if (!empty($field[$flag])) {
                $out[$name] = $field;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed> Default values for the add form.
     *
     * @since 1.0.0
     */
    protected function blankValues(): array
    {
        $values = [];
        foreach ($this->getFields('showInForm') as $name => $field) {
            $values[$name] = $field['default'] ?? '';
        }
        return $values;
    }

    /**
     * @return array<string, mixed> Field name => ['value' => 'label'] options.
     *                              Default: from metadata fk_ddls.
     *
     * @since 1.0.0
     */
    protected function fkOptions(): array
    {
        return (array) ($this->getFieldMetadata()['fk_ddls'] ?? []);
    }

    /** @return string Form submit button name. */
    protected function getSubmitName(): string
    {
        return $this->getPkField() . '_submit';
    }

    /** @return string Form cancel button name. */
    protected function getCancelName(): string
    {
        return $this->getPkField() . '_cancel';
    }

    /** @return string Page title / summary table title. */
    protected function getTitle(): string
    {
        return $this->localise((string) ($this->getFieldMetadata()['labelPlural'] ?? ''));
    }

    /** @return string Tab selector value. */
    protected function tabSel(): string
    {
        return $this->context->getTabSel();
    }

    /** @return int Current page (from context or GET). */
    protected function page(): int
    {
        $page = $this->context->getPage();
        if ($page !== null) {
            return $page;
        }
        return (int) ($_GET['page'] ?? 1);
    }

    /** @return string Form action URL ('' = current page). */
    protected function formAction(): string
    {
        return isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '';
    }

    /**
     * @return string[] GET params kept on pager links (default: keep 'view').
     *
     * @since 1.0.0
     */
    protected function preserveParams(): array
    {
        return ['view'];
    }

    /**
     * Emit an FA notification when available.
     *
     * @param string $message
     * @return void
     *
     * @since 1.0.0
     */
    protected function notify(string $message): void
    {
        if (function_exists('display_notification')) {
            display_notification($message);
        }
    }

    /**
     * @param string $message
     * @return string Translated message, or original when FA is absent.
     *
     * @since 1.0.0
     */
    protected function localise(string $message): string
    {
        return function_exists('_') ? _($message) : $message;
    }
}