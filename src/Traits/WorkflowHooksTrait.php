<?php

declare(strict_types=1);

/**
 * Workflow Hooks Trait - SuiteCRM-style lifecycle management
 *
 * This trait provides the KSF_FA_Calendar pattern where modules define
 * record types and hook handlers that execute on lifecycle events:
 * pre_save, post_save, pre_delete, post_delete, new, edited, linked, unlinked.
 *
 * @package KSF\Common\Traits
 * @since   1.4.0
 */
namespace ksfraser\FrontAccounting\Common\Traits;
trait WorkflowHooksTrait
{
    /**
     * Registry of record types and their hook prefixes.
     *
     * @var array<string, string>
     */
    protected array $workflowRecordTypes = [];

    /**
     * Register a record type for workflow hooks.
     *
     * @param string $recordType   Machine name (e.g., 'customer', 'employee')
     * @param string $hookPrefix  Prefix used when firing hooks
     *
     * @example
     *   // Register a 'customer' record type with 'crm_customer' hook prefix
     *   $this->registerWorkflowType('customer', 'crm_customer');
     *
     *   // Hook handlers expected:
     *   // - crm_customer_before_save
     *   // - crm_customer_after_save
     *   // - crm_customer_before_delete
     *   // - crm_customer_after_delete
     *   // - crm_customer_new
     *   // - crm_customer_edited
     *   // - crm_customer_linked
     *   // - crm_customer_unlinked
     *
     * @since 1.4.0
     */
    protected function registerWorkflowType(string $recordType, string $hookPrefix): void
    {
        $this->workflowRecordTypes[$recordType] = $hookPrefix;
    }

    /**
     * Fire a specific workflow hook.
     *
     * This is the core hook dispatcher - modules define record types
     * and this method routes to the correct FA hook handlers.
     *
     * @param string $recordType The record type identifier
     * @param string $hookKey    Hook suffix (e.g., 'before_save', 'new')
     * @param array  $payload    Data to pass by reference to handlers
     *
     * @return array Modified payload after hooks execute
     *
     * @example
     *   // $this->fireWorkflowHook('customer', 'before_save', $data);
     *   // Calls hook_invoke_all('crm_customer_before_save', $data)
     *   // If hook returns non-null, execution stops early
     *
     * @since 1.4.0
     */
    protected function fireWorkflowHook(string $recordType, string $hookKey, array &$payload): array
    {
        $hookPrefix = $this->workflowRecordTypes[$recordType] ?? 'entity';
        $hookName = $hookPrefix . '_' . $hookKey;

        if (function_exists('hook_invoke_all')) {
            hook_invoke_all($hookName, $payload);
        }

        return $payload;
    }

    /**
     * Fire all workflow hooks in the correct order.
     *
     * This convenience method fires hooks in standard sequence:
     * 1. before_save -> 2. new/edited -> 3. after_save
     * 4. before_delete -> 5. after_delete
     *
     * @param string $recordType  The record type identifier
     * @param array  $payload      Data passed by reference
     * @param bool   $isNew       Is this a new record?
     *
     * @return array Modified payload
     *
     * @since 1.4.0
     */
    protected function fireWorkflowHooks(string $recordType, array &$payload, bool $isNew = false): array
    {
        $payload = $this->firePreSaveHook($recordType, $payload);
        $payload = $this->fireEditedHook($recordType, $payload, $isNew);
        $payload = $this->firePostSaveHook($recordType, $payload);
        $payload = $this->firePreDeleteHook($recordType, $payload);
        $payload = $this->firePostDeleteHook($recordType, $payload);

        return $payload;
    }

    // Convenience methods for common hook points
    public function firePreSaveHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'before_save', $payload);
    }

    public function firePostSaveHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'after_save', $payload);
    }

    public function firePreDeleteHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'before_delete', $payload);
    }

    public function firePostDeleteHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'after_delete', $payload);
    }

    public function fireNewHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'new', $payload);
    }

    public function fireEditedHook(string $recordType, array &$payload, bool $isNew = false): array
    {
        if (!$isNew) {
            return $this->fireWorkflowHook($recordType, 'edited', $payload);
        }
        return $payload;
    }

    public function fireLinkedHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'linked', $payload);
    }

    public function fireUnlinkedHook(string $recordType, array &$payload): array
    {
        return $this->fireWorkflowHook($recordType, 'unlinked', $payload);
    }

    /**
     * Get the full hook name for a record type and operation.
     *
     * @param string $recordType The record type identifier
     * @param string $hookKey    Hook suffix (e.g., 'new', 'edited')
     * @return string The complete hook name
     */
    protected function getWorkflowHookName(string $recordType, string $hookKey): string
    {
        $hookPrefix = $this->workflowRecordTypes[$recordType] ?? 'entity';
        return $hookPrefix . '_' . $hookKey;
    }

    /**
     * Check if a record type has workflow hooks configured.
     *
     * @param string $recordType The record type identifier
     * @return bool True if configured
     */
    protected function hasWorkflowType(string $recordType): bool
    {
        return isset($this->workflowRecordTypes[$recordType]);
    }

    /**
     * Get all configured workflow types.
     *
     * @return array Array of record types and hook prefixes
     */
    protected function getWorkflowTypes(): array
    {
        return $this->workflowRecordTypes;
    }

    /**
     * Clear all registered workflow types.
     *
     * Primarily for testing purposes.
     *
     * @return void
     *
     * @since 1.4.0
     */
    protected function clearWorkflowTypes(): void
    {
        $this->workflowRecordTypes = [];
    }
}