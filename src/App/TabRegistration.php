<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\App;

/**
 * TabRegistration — value object describing a tab contributed to an application shell.
 *
 * A sub-module answers an application's "register with me" hook by appending
 * TabRegistration definitions to the hook payload. The app shell consumes the
 * collection to build its menu and dispatch control to the matching controller.
 *
 * This is the registration DTO for the KSF app-shell pattern. Consumers able to
 * provide only a legacy page file (procedural page script) may register via
 * `pageFile` instead of `controllerClass`; the shell includes the file directly.
 *
 * PHP 7.3 compatible — no typed properties.
 *
 * @package KsfCommon\App
 * @since   1.0.0
 *
 * @UML Note: Class diagram in APP_TAB_ARCHITECTURE.md (Roles in the pattern)
 * @BABOK Related: FR-HRM-001 (App shell tab registration)
 */
class TabRegistration
{
    /** @var string Unique tab key (must match the ?view= value). */
    private $key;

    /** @var string Display label (already translated/localised by caller). */
    private $label;

    /** @var string FA security area controlling access (e.g. 'SA_HRM_DEPARTMENT'). */
    private $security;

    /** @var string|null FQCN of the tab controller SRP (extends AbstractTabController). */
    private $controllerClass;

    /** @var string|null Absolute path to a legacy procedural page script. */
    private $pageFile;

    /** @var int Menu sort priority (lower = higher, default 50). */
    private $priority;

    /** @var int Within-priority sort order (default 0). */
    private $order;

    /** @var int|null Optional FA MENU_* constant for app-level registration. */
    private $faType;

    /** @var array<string, mixed> Extra consumer-specific options. */
    private $options;

    /**
     * @param string      $key             Unique tab key (matches ?view=)
     * @param string      $label           Display label
     * @param string      $security        FA security area ('' = app default)
     * @param string|null $controllerClass FQCN of the tab controller SRP
     * @param int         $priority        Menu sort priority (lower = higher)
     * @param int         $order           Within-priority order
     * @param string|null $pageFile        Optional legacy page script path
     * @param int|null    $faType          Optional MENU_* constant
     * @param array       $options         Extra consumer options
     *
     * @since 1.0.0
     */
    public function __construct(
        string $key,
        string $label,
        string $security = '',
        ?string $controllerClass = null,
        int $priority = 50,
        int $order = 0,
        ?string $pageFile = null,
        ?int $faType = null,
        array $options = []
    ) {
        $this->key             = $key;
        $this->label           = $label;
        $this->security        = $security;
        $this->controllerClass = $controllerClass;
        $this->priority        = $priority;
        $this->order           = $order;
        $this->pageFile        = $pageFile;
        $this->faType          = $faType;
        $this->options         = $options;
    }

    /**
     * Build a TabRegistration from a definition array contributed via a hook.
     *
     * @param array<string, mixed> $def Definition keys: key, label, security,
     *                                  controller_class, controllerClass, priority,
     *                                  order, page_file, pageFile, fa_type, options
     * @return self
     *
     * @since 1.0.0
     */
    public static function fromArray(array $def): self
    {
        return new self(
            (string) ($def['key'] ?? ''),
            (string) ($def['label'] ?? ''),
            (string) ($def['security'] ?? ''),
            isset($def['controller_class']) ? (string) $def['controller_class'] : (isset($def['controllerClass']) ? (string) $def['controllerClass'] : null),
            (int) ($def['priority'] ?? 50),
            (int) ($def['order'] ?? 0),
            isset($def['page_file']) ? (string) $def['page_file'] : (isset($def['pageFile']) ? (string) $def['pageFile'] : null),
            isset($def['fa_type']) ? (int) $def['fa_type'] : null,
            is_array($def['options'] ?? null) ? $def['options'] : []
        );
    }

    /**
     * Serialize for registry storage / hook payloads.
     *
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    public function toArray(): array
    {
        return [
            'key'             => $this->key,
            'label'           => $this->label,
            'security'        => $this->security,
            'controllerClass' => $this->controllerClass,
            'priority'        => $this->priority,
            'order'           => $this->order,
            'pageFile'        => $this->pageFile,
            'faType'          => $this->faType,
            'options'         => $this->options,
        ];
    }

    /** @return string */
    public function getKey(): string
    {
        return $this->key;
    }

    /** @return string */
    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return string */
    public function getSecurity(): string
    {
        return $this->security;
    }

    /** @return string|null */
    public function getControllerClass(): ?string
    {
        return $this->controllerClass;
    }

    /** @return string|null */
    public function getPageFile(): ?string
    {
        return $this->pageFile;
    }

    /** @return int */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /** @return int */
    public function getOrder(): int
    {
        return $this->order;
    }

    /** @return int|null */
    public function getFaType(): ?int
    {
        return $this->faType;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return string|null Option value or null when absent.
     *
     * @since 1.0.0
     */
    public function getOption(string $name)
    {
        return $this->options[$name] ?? null;
    }

    /**
     * Whether this tab is controller backed (priority over a page file).
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function hasController(): bool
    {
        return $this->controllerClass !== null && $this->controllerClass !== '';
    }
}