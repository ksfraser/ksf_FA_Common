<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Traits;

/**
 * CRUD Operations Trait - Standardized CRUD with workflow integration.
 *
 * Provides a clean API for creating and deleting records with full
 * lifecycle hook support. Works alongside WorkflowHooksTrait.
 *
 * @package KSF\Common\Traits
 * @since   1.4.0
 */
trait CrudOperationsTrait
{
    /**
     * Create a record with complete workflow support.
     *
     * This method ensures proper hook ordering and result propagation:
     *
     * 1. pre_save hooks fire
     * 2. Record creation operation
     * 3. *_new/*_edited hooks fire (conditional)
     * 4. post_save hooks fire
     *
     * @param string $recordType The record type identifier
     * @param array  $data       Record data to create
     * @param bool   $isNew      Is this a new record?
     * @return array Created record data with metadata
     *
     * @since 1.4.0
     */
    protected function createRecord(string $recordType, array $data, bool $isNew = false): array
    {
        // Phase 1: Pre-save validation hooks
        $data = $this->firePreSaveHook($recordType, $data);

        // Phase 2: Create the record (implementation varies by module)
        $result = $this->createRecordInternal($recordType, $data);

        // Phase 3: Record-specific hooks (new vs edited)
        if ($isNew) {
            $result = $this->fireNewHook($recordType, $result);
        } else {
            $result = $this->fireEditedHook($recordType, $result);
        }

        // Phase 4: Post-save processing
        $result = $this->firePostSaveHook($recordType, $result);

        return $result;
    }

    /**
     * Delete a record with complete workflow support.
     *
     * @param string $recordType The record type identifier
     * @param array  $data       Record data before deletion
     * @return array Deletion result data
     *
     * @since 1.4.0
     */
    protected function deleteRecord(string $recordType, array $data): array
    {
        // Phase 1: Pre-delete validation hooks
        $data = $this->firePreDeleteHook($recordType, $data);

        // Phase 2: Delete the record (implementation varies by module)
        $result = $this->deleteRecordInternal($recordType, $data);

        // Phase 3: Post-delete cleanup hooks
        $result = $this->firePostDeleteHook($recordType, $result);

        return $result;
    }

    /**
     * Create record implementation (module-specific).
     *
     * Override this method in subclasses to implement actual record creation.
     *
     * @param string $recordType The record type identifier
     * @param array  $data       Record data
     * @return array Created record data
     *
     * @since 1.4.0
     */
    protected function createRecordInternal(string $recordType, array $data): array
    {
        // Modules should override this with their actual
        // database/ORM logic for record creation
        return $data;
    }

    /**
     * Delete record implementation (module-specific).
     *
     * Override this method in subclasses to implement actual record deletion.
     *
     * @param string $recordType The record type identifier
     * @param array  $data       Record data
     * @return array Deletion result data
     *
     * @since 1.4.0
     */
    protected function deleteRecordInternal(string $recordType, array $data): array
    {
        // Modules should override this with their actual
        // database/ORM logic for record deletion
        return $data;
    }
}