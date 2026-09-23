<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\InboxStorageInterface;

/**
 * FA runtime inbox store — native db_* calls only (no PDO, no raw mysqli).
 *
 * Table {TB_PREF}ksf_notification_inbox (0_ prefix is translated by FA's
 * install engine; tableName() flips 0_ -> TB_PREF for runtime SQL). The
 * `@all` placeholder (recipient_uid = 0) is a single INSERT-only row that is
 * copied per user on first read poll (materializeAll) — keeps reads cheap and
 * the source row immutable.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class FaInboxStore implements InboxStorageInterface
{
    private const TABLE        = 'ksf_notification_inbox';
    private const TABLE_MARKER = 'ksf_notification_watermark';

    public function insert(array $row): int
    {
        $at = (string) ($row['created_at'] ?? \date('Y-m-d H:i:s'));
        $sql = \sprintf(
            'INSERT INTO %s (recipient_uid, type, payload, ref, created_at)
             VALUES (%s, %s, %s, %s, %s)',
            $this->tableName(),
            db_escape((string) (int) ($row['recipient_uid'] ?? 0)),
            db_escape((string) ($row['type'] ?? '')),
            db_escape(\json_encode($row['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            db_escape($row['ref'] ?? null),
            db_escape($at)
        );
        db_query($sql, 'Failed to insert inbox notification');
        return function_exists('db_insert_id') ? (int) db_insert_id() : 0;
    }

    public function materializeAll(int $recipientUid): int
    {
        if ($recipientUid <= 0) {
            return 0;
        }

        $watermark = $this->loadWatermark($recipientUid);
        $missed = $this->findNullOrphanRows($watermark);
        if ($missed === []) {
            return 0;
        }

        $count = 0;
        $maxId = $watermark;
        foreach ($missed as $row) {
            $this->insert([
                'recipient_uid' => $recipientUid,
                'type'          => $row['type'],
                'payload'       => \json_decode((string) $row['payload'], true) ?: [],
                'ref'           => $row['ref'],
                'created_at'    => $row['created_at'],
            ]);
            $count++;
            $maxId = \max($maxId, (int) $row['id']);
        }

        $this->saveWatermark($recipientUid, $maxId);
        return $count;
    }

    public function unread(int $recipientUid): array
    {
        $sql = \sprintf(
            'SELECT * FROM %s WHERE recipient_uid=%s AND read_at IS NULL AND dismissed_at IS NULL
             ORDER BY created_at DESC, id DESC',
            $this->tableName(),
            db_escape((string) (int) $recipientUid)
        );
        return $this->rows($sql);
    }

    public function unreadCount(int $recipientUid): int
    {
        $sql = \sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE recipient_uid=%s AND read_at IS NULL AND dismissed_at IS NULL',
            $this->tableName(),
            db_escape((string) (int) $recipientUid)
        );
        $result = db_query($sql, 'Failed to count unread inbox notifications');
        if ($result && ($row = db_fetch_assoc($result))) {
            return (int) ($row['n'] ?? 0);
        }
        return 0;
    }

    public function listForUser(int $recipientUid, int $limit = 50, ?string $type = null): array
    {
        $limit = \max(1, $limit);
        $sql = \sprintf(
            'SELECT * FROM %s WHERE recipient_uid=%s%s
             ORDER BY created_at DESC, id DESC LIMIT %d',
            $this->tableName(),
            db_escape((string) (int) $recipientUid),
            $type !== null ? ' AND type=' . db_escape($type) : '',
            $limit
        );
        return $this->rows($sql);
    }

    public function markRead(int $id, int $recipientUid): bool
    {
        return $this->touch($id, $recipientUid, 'read_at') !== false;
    }

    public function markAllRead(int $recipientUid): int
    {
        if ($recipientUid <= 0) {
            return 0;
        }
        $sql = \sprintf(
            'UPDATE %s SET read_at=%s WHERE recipient_uid=%s AND read_at IS NULL AND dismissed_at IS NULL',
            $this->tableName(),
            db_escape(\date('Y-m-d H:i:s')),
            db_escape((string) $recipientUid)
        );
        $result = db_query($sql, 'Failed to mark all inbox notifications read');
        return ($result && function_exists('db_affected_rows')) ? (int) db_affected_rows() : 0;
    }

    public function dismiss(int $id, int $recipientUid): bool
    {
        return $this->touch($id, $recipientUid, 'dismissed_at') !== false;
    }

    /**
     * @return resource|bool
     */
    private function touch(int $id, int $recipientUid, string $field)
    {
        $sql = \sprintf(
            'UPDATE %s SET %s=%s WHERE id=%s AND recipient_uid=%s',
            $this->tableName(),
            $field,
            db_escape(\date('Y-m-d H:i:s')),
            db_escape((string) (int) $id),
            db_escape((string) (int) $recipientUid)
        );
        return db_query($sql, "Failed to update inbox notification {$field}");
    }

    /**
     * @return array<int,array> the not-yet-materialized @all rows
     */
    private function findNullOrphanRows(int $watermark): array
    {
        $sql = \sprintf(
            'SELECT * FROM %s WHERE recipient_uid=0%s ORDER BY id ASC',
            $this->tableName(),
            $watermark > 0 ? ' AND id > ' . db_escape((string) $watermark) : ''
        );
        return $this->rows($sql);
    }

    private function loadWatermark(int $recipientUid): int
    {
        $sql = \sprintf(
            'SELECT last_all_id FROM %s WHERE recipient_uid=%s',
            $this->tableName(self::TABLE_MARKER),
            db_escape((string) (int) $recipientUid)
        );
        $result = db_query($sql, 'Failed to read inbox watermark');
        if ($result && ($row = db_fetch_assoc($result))) {
            return (int) ($row['last_all_id'] ?? 0);
        }
        return 0;
    }

    private function saveWatermark(int $recipientUid, int $lastAllId): void
    {
        if ($this->loadWatermark($recipientUid) === 0) {
            $sql = \sprintf(
                'INSERT INTO %s (recipient_uid, last_all_id) VALUES (%s, %s)',
                $this->tableName(self::TABLE_MARKER),
                db_escape((string) (int) $recipientUid),
                db_escape((string) (int) $lastAllId)
            );
        } else {
            $sql = \sprintf(
                'UPDATE %s SET last_all_id=%s WHERE recipient_uid=%s',
                $this->tableName(self::TABLE_MARKER),
                db_escape((string) (int) $lastAllId),
                db_escape((string) (int) $recipientUid)
            );
        }
        db_query($sql, 'Failed to write inbox watermark');
    }

    /**
     * @return array<int,array>
     */
    private function rows(string $sql): array
    {
        $result = db_query($sql, 'Failed to read inbox notifications');
        $rows = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function tableName(string $suffix = self::TABLE): string
    {
        return \defined('TB_PREF') ? TB_PREF . $suffix : '0_' . $suffix;
    }
}