<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\NotificationPreferenceStoreInterface;

/**
 * FA runtime preference store — native db_* calls only.
 *
 * Table {TB_PREF}ksf_notification_prefs; per-user, upsert on change. A missing
 * row defaults to all-on (nothing muted, digest off) — v1 minimal semantics.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class FaNotificationPreferenceStore implements NotificationPreferenceStoreInterface
{
    private const TABLE = 'ksf_notification_prefs';

    public function mutedTypes(int $recipientUid): array
    {
        $sql = \sprintf(
            'SELECT muted_types FROM %s WHERE recipient_uid=%s',
            $this->tableName(),
            db_escape((string) (int) $recipientUid)
        );
        $result = db_query($sql, 'Failed to read notification prefs');
        if ($result && ($row = db_fetch_assoc($result))) {
            $decoded = \json_decode((string) ($row['muted_types'] ?? 'null'), true);
            return \is_array($decoded) ? \array_values(\array_map('strval', $decoded)) : [];
        }
        return [];
    }

    public function setMutedTypes(int $recipientUid, array $types): void
    {
        $this->upsert($recipientUid, ['muted_types' => \json_encode(
            \array_values(\array_map('strval', $types)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )]);
    }

    public function isDigest(int $recipientUid): bool
    {
        $sql = \sprintf(
            'SELECT digest FROM %s WHERE recipient_uid=%s',
            $this->tableName(),
            db_escape((string) (int) $recipientUid)
        );
        $result = db_query($sql, 'Failed to read notification digest flag');
        if ($result && ($row = db_fetch_assoc($result))) {
            return (bool) ((int) ($row['digest'] ?? 0));
        }
        return false;
    }

    public function setDigest(int $recipientUid, bool $digest): void
    {
        $this->upsert($recipientUid, ['digest' => $digest ? '1' : '0']);
    }

    private function upsert(int $recipientUid, array $fields): void
    {
        $existing = false;
        $sql = \sprintf(
            'SELECT recipient_uid FROM %s WHERE recipient_uid=%s',
            $this->tableName(),
            db_escape((string) (int) $recipientUid)
        );
        $result = db_query($sql, 'Failed to probe notification prefs');
        if ($result && db_fetch_assoc($result)) {
            $existing = true;
        }

        $sets = [];
        foreach ($fields as $col => $value) {
            $sets[] = sprintf('%s=%s', $col, db_escape($value));
        }
        $sets[] = sprintf('updated_at=%s', db_escape(\date('Y-m-d H:i:s')));

        if ($existing) {
            $sql = \sprintf(
                'UPDATE %s SET %s WHERE recipient_uid=%s',
                $this->tableName(),
                \implode(',', $sets),
                db_escape((string) (int) $recipientUid)
            );
        } else {
            $sql = \sprintf(
                'INSERT INTO %s (recipient_uid, %s) VALUES (%s, %s)',
                $this->tableName(),
                \implode(',', \array_keys($fields)),
                db_escape((string) (int) $recipientUid),
                \implode(',', \array_map(function ($value) {
                    return db_escape($value);
                }, $fields))
            );
        }
        db_query($sql, 'Failed to write notification prefs');
    }
}