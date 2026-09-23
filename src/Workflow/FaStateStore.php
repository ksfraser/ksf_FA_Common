<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

use ksfraser\FrontAccounting\Common\Workflow\Contract\StateStoreInterface;

/**
 * FA runtime state store — native db_* calls only (no PDO, no mysqli direct).
 *
 * Tables: {TB_PREF}ksf_wf_state (current_state, upsert + append-only history
 * {TB_PREF}ksf_wf_history). Schema is created by sql/install.sql (0_ prefix is
 * translated by FA's install engine); tableName() flips 0_ -> TB_PREF so the
 * runtime SQL matches the actual prefixed table.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class FaStateStore implements StateStoreInterface
{
    private const TABLE_STATE   = 'ksf_wf_state';
    private const TABLE_HISTORY = 'ksf_wf_history';

    public function loadState(string $recordType, string $recordId): ?array
    {
        $sql = \sprintf(
            'SELECT * FROM %s WHERE record_type=%s AND record_id=%s LIMIT 1',
            $this->tableName(self::TABLE_STATE),
            db_escape($recordType),
            db_escape($recordId)
        );
        $result = db_query($sql, 'Failed to load process state');
        if (!$result) {
            return null;
        }
        $row = db_fetch_assoc($result);
        return $row ? $row : null;
    }

    public function saveState(array $row): void
    {
        $existing = $this->loadState((string) $row['record_type'], (string) $row['record_id']);
        $at = \date('Y-m-d H:i:s');

        if ($existing) {
            $sql = \sprintf(
                'UPDATE %s SET state=%s, definition_version=%s, updated_at=%s
                 WHERE record_type=%s AND record_id=%s',
                $this->tableName(self::TABLE_STATE),
                db_escape((string) $row['state']),
                db_escape((string) ($row['definition_version'] ?? 1)),
                db_escape($at),
                db_escape((string) $row['record_type']),
                db_escape((string) $row['record_id'])
            );
            db_query($sql, 'Failed to update process state');
            return;
        }

        $sql = \sprintf(
            'INSERT INTO %s (record_type, record_id, state, definition_version, created_at, updated_at)
             VALUES (%s, %s, %s, %s, %s, %s)',
            $this->tableName(self::TABLE_STATE),
            db_escape((string) $row['record_type']),
            db_escape((string) $row['record_id']),
            db_escape((string) $row['state']),
            db_escape((string) ($row['definition_version'] ?? 1)),
            db_escape((string) ($row['created_at'] ?? $at)),
            db_escape($at)
        );
        db_query($sql, 'Failed to insert process state');
    }

    public function appendHistory(array $row): void
    {
        $sql = \sprintf(
            'INSERT INTO %s (record_type, record_id, from_state, to_state, actor, reason, failed, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
            $this->tableName(self::TABLE_HISTORY),
            db_escape((string) ($row['record_type'] ?? '')),
            db_escape((string) ($row['record_id'] ?? '')),
            db_escape((string) ($row['from'] ?? '')),
            db_escape((string) ($row['to'] ?? '')),
            db_escape((string) ($row['actor'] ?? '')),
            db_escape((string) ($row['reason'] ?? '')),
            db_escape($row['failed'] ? '1' : '0'),
            db_escape((string) ($row['at'] ?? \date('Y-m-d H:i:s')))
        );
        db_query($sql, 'Failed to append process history');
    }

    public function listHistory(string $recordType, string $recordId): array
    {
        $sql = \sprintf(
            'SELECT * FROM %s WHERE record_type=%s AND record_id=%s ORDER BY id ASC',
            $this->tableName(self::TABLE_HISTORY),
            db_escape($recordType),
            db_escape($recordId)
        );
        $result = db_query($sql, 'Failed to list process history');
        $rows = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param callable $fn fn(): mixed
     *
     * @return mixed
     */
    public function transaction(callable $fn)
    {
        if (!function_exists('begin_transaction')) {
            return $fn();
        }
        begin_transaction(sprintf('%s: state transition', self::TABLE_STATE));
        try {
            $out = $fn();
            commit_transaction();
            return $out;
        } catch (\Throwable $e) {
            rollback_transaction();
            throw $e;
        }
    }

    private function tableName(string $suffix): string
    {
        return \defined('TB_PREF') ? TB_PREF . $suffix : '0_' . $suffix;
    }
}