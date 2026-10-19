<?php
/**
 * Contact — immutable value object for a resolved contact/person.
 *
 * A contact is a row of FA core's `0_crm_persons` (the person) optionally
 * carrying the relation to an entity (FA core's `0_crm_contacts`), i.e. the
 * type/action pair ('customer'/'general', 'supplier'/'general', …).
 *
 * TWO IDS, TWO TABLES — do not confuse them:
 *   - getId()       the PERSON id   (`0_crm_persons`.`id`)
 *   - getContactId() the CONTACT RELATION id (`0_crm_contacts`.`id`), null
 *                   when the contact is not attached to an entity.
 *
 * House style: the same immutable value-object shape as
 * src/ContactType/ContactType.php (typed constructor, getters, toArray() and
 * the inverse fromArray()).
 *
 * @BABOK Related: TBD — no requirement id for the shared contact resolver
 *                 exists in this repo's ProjectDcs (doc/ProjectDocuments/
 *                 ProjectDcs is empty); the decision and the native function
 *                 list are recorded in ksf_Architecture_Docs AGENTS_ARCH.md
 *                 §12.2. The closest estate requirement is FR-EM-001-03
 *                 (ksf_Architecture_Docs/Requirements/).
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

/**
 * A resolved contact/person.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.2.0
 */
final class Contact
{
    /** @var int Person id (`0_crm_persons`.`id`) */
    private $id;

    /** @var string Person name */
    private $name;

    /** @var string Person second name */
    private $name2;

    /** @var string Person email address */
    private $email;

    /** @var string Person phone */
    private $phone;

    /** @var string Person reference */
    private $ref;

    /** @var string Person address */
    private $address;

    /** @var string|null Contact type ('customer', 'supplier', …) */
    private $type;

    /** @var string|null Contact action ('general', 'delivery', …) */
    private $action;

    /** @var string|null The related entity's id */
    private $entityId;

    /** @var int|null Contact relation id (`0_crm_contacts`.`id`) */
    private $contactId;

    /** @var bool Whether the person is flagged inactive */
    private $inactive;

    /**
     * @param int         $id        Person id
     * @param string      $name      Person name
     * @param string      $name2     Person second name
     * @param string      $email     Person email address
     * @param string      $phone     Person phone
     * @param string      $ref       Person reference
     * @param string      $address   Person address
     * @param string|null $type      Contact type ('customer', 'supplier', …)
     * @param string|null $action    Contact action ('general', 'delivery', …)
     * @param string|null $entityId  Related entity id
     * @param int|null    $contactId Contact relation id (`0_crm_contacts`.`id`)
     * @param bool        $inactive  Whether the person is flagged inactive
     */
    public function __construct(
        int $id,
        string $name = '',
        string $name2 = '',
        string $email = '',
        string $phone = '',
        string $ref = '',
        string $address = '',
        ?string $type = null,
        ?string $action = null,
        ?string $entityId = null,
        ?int $contactId = null,
        bool $inactive = false
    ) {
        $this->id        = $id;
        $this->name      = $name;
        $this->name2     = $name2;
        $this->email     = $email;
        $this->phone     = $phone;
        $this->ref       = $ref;
        $this->address   = $address;
        $this->type      = $type;
        $this->action    = $action;
        $this->entityId  = $entityId;
        $this->contactId = $contactId;
        $this->inactive  = $inactive;
    }

    /**
     * Person id (`0_crm_persons`.`id`).
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Person name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Person second name.
     *
     * @return string
     */
    public function getName2(): string
    {
        return $this->name2;
    }

    /**
     * Person email address.
     *
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Person phone.
     *
     * @return string
     */
    public function getPhone(): string
    {
        return $this->phone;
    }

    /**
     * Person reference.
     *
     * @return string
     */
    public function getRef(): string
    {
        return $this->ref;
    }

    /**
     * Person address.
     *
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Contact type ('customer', 'supplier', 'cust_branch', …).
     *
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Contact action ('general', 'delivery', 'invoice', …).
     *
     * @return string|null
     */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * The related entity's id, when known.
     *
     * @return string|null
     */
    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /**
     * Contact relation id (`0_crm_contacts`.`id`), null when the contact is
     * not attached to an entity.
     *
     * @return int|null
     */
    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    /**
     * Whether the person is flagged inactive.
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        return $this->inactive;
    }

    /**
     * The person's display name (name plus second name).
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $display = trim($this->name);
        $second  = trim($this->name2);
        if ($second !== '') {
            $display = $display === '' ? $second : $display . ' ' . $second;
        }
        if ($display === '') {
            $display = $this->email;
        }

        return $display;
    }

    /**
     * The contact as a plain array (hook payload friendly).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'name2'      => $this->name2,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'ref'        => $this->ref,
            'address'    => $this->address,
            'type'       => $this->type,
            'action'     => $this->action,
            'entity_id'  => $this->entityId,
            'contact_id' => $this->contactId,
            'inactive'   => $this->inactive,
        ];
    }

    /**
     * Reconstruct a contact from an array (inverse of toArray()).
     *
     * Accepts both this class' own key names and the raw FA column names
     * ('id', 'name', 'name2', 'email', 'phone', 'ref', 'address', 'type',
     * 'action', 'entity_id', 'contact_id', 'inactive'), so a row fetched with
     * FA's native functions can be converted directly.
     *
     * @param array $data Contact data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $inactive = $data['inactive'] ?? false;
        if (is_string($inactive)) {
            $inactive = !in_array(strtolower($inactive), ['', '0', 'false', 'no'], true);
        }

        $contactId = $data['contact_id'] ?? null;
        if ($contactId !== null && !is_numeric($contactId)) {
            $contactId = null;
        }

        $entityId = $data['entity_id'] ?? null;
        if ($entityId !== null && !is_scalar($entityId)) {
            $entityId = null;
        }

        $type   = $data['type'] ?? null;
        $action = $data['action'] ?? null;

        return new self(
            (int) ($data['id'] ?? 0),
            (string) ($data['name'] ?? ''),
            (string) ($data['name2'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['phone'] ?? ''),
            (string) ($data['ref'] ?? ''),
            (string) ($data['address'] ?? ''),
            $type === null ? null : (string) $type,
            $action === null ? null : (string) $action,
            $entityId === null ? null : (string) $entityId,
            $contactId === null ? null : (int) $contactId,
            (bool) $inactive
        );
    }
}
