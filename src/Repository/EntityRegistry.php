<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Repository;

use ksfraser\FrontAccounting\Common\Contract\EntityRegistryInterface;

/**
 * Entity registry - source of truth for entity type definitions.
 *
 * Base registry with common entity types. Modules can extend this to add
 * their own entity types.
 *
 * @BABOK Related: FR-NT-001-002
 * @since 1.0.0
 */
class EntityRegistry implements EntityRegistryInterface
{
    /**
     * @var array<string, array>
     */
    private $registry;

    /**
     * @param array<string, array>|null $registry Pre-built registry (for testing).
     *        When null, builds from static definitions + NOTES_ENTITY_TYPES hook.
     * @since 1.0.0
     */
    public function __construct(?array $registry = null)
    {
        if ($registry !== null) {
            $this->registry = $registry;
        } else {
            $this->registry = $this->buildRegistry();
        }
    }

    /**
     * Build the entity type registry, honouring the NOTES_ENTITY_TYPES hook.
     *
     * @return array<string, array>
     * @since 1.0.0
     */
    protected function buildRegistry(): array
    {
        $types = array(
            'customer' => array(
                'title'    => 'Customer',
                'table'    => 'debtors_master',
                'pk'       => 'debtor_no',
                'label'    => 'name',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
            'contact' => array(
                'title'    => 'Contact',
                'table'    => 'crm_persons',
                'pk'       => 'id',
                'label'    => 'name',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
            'meeting' => array(
                'title'    => 'Meeting',
                'table'    => 'ksf_crm_meetings',
                'pk'       => 'id',
                'label'    => 'meeting_name',
                'module'   => 'ksf_FA_CRM',
                'picker'   => true,
                'multiple' => true,
            ),
            'opportunity' => array(
                'title'    => 'Opportunity',
                'table'    => 'ksf_crm_opportunities',
                'pk'       => 'id',
                'label'    => 'opportunity_name',
                'module'   => 'ksf_FA_CRM',
                'picker'   => true,
                'multiple' => true,
            ),
            'contract' => array(
                'title'    => 'Contract',
                'table'    => 'ksf_contract',
                'pk'       => 'id',
                'label'    => 'name',
                'module'   => 'ksf_FA_Contract',
                'picker'   => false,
                'multiple' => true,
            ),
            'project' => array(
                'title'    => 'Project',
                'table'    => 'ksf_projectmanagement_projects',
                'pk'       => 'project_id',
                'label'    => 'name',
                'module'   => 'ksf_FA_ProjectManagement',
                'picker'   => true,
                'multiple' => true,
            ),
            'task' => array(
                'title'    => 'Task',
                'table'    => 'ksf_projectmanagement_tasks',
                'pk'       => 'task_id',
                'label'    => 'name',
                'module'   => 'ksf_FA_ProjectManagement',
                'picker'   => true,
                'multiple' => true,
            ),
            'calendar' => array(
                'title'    => 'Calendar Entry',
                'table'    => 'ksf_calendar_entries',
                'pk'       => 'id',
                'label'    => 'title',
                'module'   => 'ksf_FA_Calendar',
                'picker'   => true,
                'multiple' => true,
            ),
            'attachment' => array(
                'title'    => 'Attachment',
                'table'    => 'attachments',
                'pk'       => 'id',
                'label'    => 'description',
                'module'   => '',
                'picker'   => true,
                'multiple' => true,
            ),
        );

        if (function_exists('hook_invoke_all')) {
            $data = array('types' => $types);
            hook_invoke_all('NOTES_ENTITY_TYPES', $data);
            if (isset($data['types']) && is_array($data['types'])) {
                $types = $data['types'];
            }
        }

        return $types;
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        return $this->registry;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $entity_type): ?array
    {
        return $this->registry[$entity_type] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function pickable(): array
    {
        return array_filter($this->registry, function ($def) {
            return !empty($def['picker']);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function typeLabels(): array
    {
        $labels = array();

        foreach ($this->registry as $entity_type => $def) {
            $labels[$entity_type] = (string) ($def['title'] ?? $def['label'] ?? $entity_type);
        }

        return $labels;
    }

    /**
     * {@inheritdoc}
     */
    public function linkRolesFor(string $entity_type): array
    {
        $roles = $this->linkRoles();

        return isset($roles[$entity_type]) ? $roles[$entity_type] : array();
    }

    /**
     * {@inheritdoc}
     */
    public function defaultLinkRoles(): array
    {
        return array('reference' => 'Reference');
    }

    /**
     * Get note types - can be overridden by modules.
     *
     * @return array<string, string>
     * @since 1.0.0
     */
    public function noteTypes(): array
    {
        $types = array(
            'Comment' => _('Comment'),
            'Call' => _('Call'),
            'Email' => _('Email'),
            'Meeting' => _('Meeting'),
            'Task' => _('Task'),
            'Document' => _('Document'),
            'Issue' => _('Issue'),
            'Reminder' => _('Reminder'),
        );

        if (function_exists('hook_invoke_all')) {
            $payload = array('types' => $types);
            hook_invoke_all('NOTES_NOTE_TYPES', $payload);
            if (isset($payload['types']) && is_array($payload['types'])) {
                $types = $payload['types'];
            }
        }

        return $types;
    }

    /**
     * Link roles per entity type.
     *
     * @return array<string, array<string, string>>
     * @since 1.0.0
     */
    protected function linkRoles(): array
    {
        return array(
            'customer' => array(
                'regarding' => 'Regarding',
                'with' => 'With',
            ),
            'contact' => array(
                'with' => 'With',
                'regarding' => 'Regarding',
            ),
            'meeting' => array(
                'regarding' => 'Regarding',
                'with' => 'Attended With',
            ),
            'opportunity' => array('regarding' => 'Regarding'),
            'contract' => array('regarding' => 'Regarding'),
            'project' => array('regarding' => 'Regarding'),
            'task' => array('regarding' => 'Regarding'),
            'calendar' => array('regarding' => 'Regarding'),
            'attachment' => array('document' => 'Document'),
        );
    }
}