<?php
/**
 * TabContext — immutable value object capturing the FA tab selector context.
 *
 * Wraps the record id, the FA `_tabs_sel` tab selector value, and the
 * optional paging page number so pages can return to the same tab/page after
 * a master summary table row action (Edit/Delete/Submit/Cancel).
 *
 * @package Ksfraser\Frontaccounting\HTML
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML;

class TabContext
{
    /** @var string */
    private $recordId;

    /** @var string */
    private $tabSel;

    /** @var int|null */
    private $page;

    /** @var string */
    private $recordIdField;

    /**
     * @param string   $recordId      Record identifier (e.g. 'ABC-123')
     * @param string   $tabSel        FA `_tabs_sel` tab selector value
     * @param int|null $page          Optional page number for paged summaries
     * @param string   $recordIdField Form field name used to submit the record id
     *
     * @since 1.0.0
     */
    public function __construct(
        string $recordId,
        string $tabSel = '',
        ?int $page = null,
        string $recordIdField = 'stock_id'
    ) {
        $this->recordId      = $recordId;
        $this->tabSel        = $tabSel;
        $this->page          = $page;
        $this->recordIdField = $recordIdField;
    }

    /**
     * Reconstruct a context from the passed POST array (get_post() semantics).
     *
     * @param array  $post          POST data array (e.g. $_POST)
     * @param string $recordIdField Form field name that holds the record id
     * @return self
     *
     * @since 1.0.0
     */
    public static function fromPost(array $post, string $recordIdField = 'stock_id'): self
    {
        $page = null;
        if (isset($post['page']) && $post['page'] !== '' && $post['page'] !== null) {
            $page = (int) $post['page'];
        }

        return new self(
            (string) ($post[$recordIdField] ?? ''),
            (string) ($post['_tabs_sel'] ?? ''),
            $page,
            $recordIdField
        );
    }

    /**
     * @return string Record identifier
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
     * @return int|null Page number, or null when not set
     *
     * @since 1.0.0
     */
    public function getPage(): ?int
    {
        return $this->page;
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
     * Build a redirect target matching FA's no-redirect idiom from issue #24:
     * `$_SERVER['PHP_SELF'] . '?<record_id_field>=...&_tabs_sel=...'`.
     *
     * Used by standalone pages that DO redirect after a row action.
     *
     * @return string Absolute redirect URL (may be empty PHP_SELF base)
     *
     * @since 1.0.0
     */
    public function redirectTarget(): string
    {
        $target = isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '';

        $params = [];
        if ($this->recordId !== '') {
            $params[] = rawurlencode($this->recordIdField) . '=' . rawurlencode($this->recordId);
        }
        if ($this->tabSel !== '') {
            $params[] = '_tabs_sel=' . rawurlencode($this->tabSel);
        }
        if ($this->page !== null && $this->page > 1) {
            $params[] = 'page=' . $this->page;
        }

        if ($params === []) {
            return $target;
        }

        return $target . '?' . implode('&', $params);
    }
}
