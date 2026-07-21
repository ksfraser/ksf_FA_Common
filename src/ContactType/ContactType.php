<?php
/**
 * ContactType — immutable value object representing a platform-level contact
 * type that can be invited to calendar events, assigned to tasks, associated
 * with projects, etc.
 *
 * Each business module (RBAC, CRM, HRM, Assets, Projects, etc.) defines its
 * own types through the activation-time registration system in
 * ContactTypeRegistry.
 *
 * Contact types are a PLATFORM concept (not calendar-specific), managed by
 * ksf_FA_Common.  The `ksf_contact_types` DB table persists definitions
 * across requests.
 *
 * @package KsfCommon\ContactType
 */

declare(strict_types=1);

namespace KsfCommon\ContactType;

class ContactType
{
    private $name;
    private $label;
    private $module;
    private $description;

    /**
     * @param string      $name        Machine name (e.g. 'fa_user', 'employee', 'resource')
     * @param string      $label       Human-readable label (e.g. 'FA User', 'Employee')
     * @param string      $module      Owning module identifier (e.g. 'ksf_FA_Common', 'ksf_HRM')
     * @param string|null $description Optional explanation of what this type represents
     */
    public function __construct(
        string $name,
        string $label,
        string $module,
        ?string $description = null
    ) {
        $this->name        = $name;
        $this->label       = $label;
        $this->module      = $module;
        $this->description = $description;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array{name: string, label: string, module: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'label'       => $this->label,
            'module'      => $this->module,
            'description' => $this->description,
        ];
    }

    /**
     * Reconstruct from an array (inverse of toArray).
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name']        ?? ''),
            (string) ($data['label']       ?? ''),
            (string) ($data['module']      ?? ''),
            isset($data['description']) ? (string) $data['description'] : null
        );
    }
}
