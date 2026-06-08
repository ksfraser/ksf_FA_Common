<?php

declare(strict_types=1);

namespace KsfCommon\Notification;

use DateTimeInterface;
use KsfCommon\Notification\Contract\NotificationStorageInterface;

final class NotificationRepository implements NotificationStorageInterface
{
    private const TABLE = 'ksf_notifications';

    public function save(Notification $notification): Notification
    {
        $sql = sprintf(
            'INSERT INTO %s (source_module, source_ref, recipient_user_id, notification_type, channel, title, body, payload_json, status, scheduled_at, dispatched_at, acknowledged_at, ack_token, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())',
            $this->tableName(),
            db_escape($notification->getSourceModule()),
            db_escape($notification->getSourceRef()),
            db_escape($notification->getRecipientUserId()),
            db_escape($notification->getNotificationType()),
            db_escape($notification->getChannel()),
            db_escape($notification->getTitle()),
            db_escape($notification->getBody()),
            db_escape(json_encode($notification->getPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            db_escape($notification->getStatus()),
            db_escape($notification->getScheduledAt() ? $notification->getScheduledAt()->format('Y-m-d H:i:s') : null),
            db_escape($notification->getDispatchedAt() ? $notification->getDispatchedAt()->format('Y-m-d H:i:s') : null),
            db_escape($notification->getAcknowledgedAt() ? $notification->getAcknowledgedAt()->format('Y-m-d H:i:s') : null),
            db_escape($notification->getAckToken())
        );
        db_query($sql, 'Failed to save notification');
        $notification->setId(function_exists('db_insert_id') ? (int) db_insert_id() : 0);
        return $notification;
    }

    public function findDue(DateTimeInterface $now): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE status = %s AND (scheduled_at IS NULL OR scheduled_at <= %s) ORDER BY id ASC',
            $this->tableName(),
            db_escape(Notification::STATUS_PENDING),
            db_escape($now->format('Y-m-d H:i:s'))
        );
        $result = db_query($sql, 'Failed to load due notifications');
        $rows = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $rows[] = Notification::fromArray($row);
        }
        return $rows;
    }

    public function findById(int $id): ?Notification
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = %s LIMIT 1', $this->tableName(), db_escape((string) $id));
        $result = db_query($sql, 'Failed to load notification');
        $row = ($result && function_exists('db_fetch_assoc')) ? db_fetch_assoc($result) : false;
        return $row ? Notification::fromArray($row) : null;
    }

    public function acknowledge(int $id, string $ackToken, ?DateTimeInterface $acknowledgedAt = null): bool
    {
        $ackAt = $acknowledgedAt ? $acknowledgedAt->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE %s SET status = %s, acknowledged_at = %s WHERE id = %s AND ack_token = %s',
            $this->tableName(),
            db_escape(Notification::STATUS_ACKNOWLEDGED),
            db_escape($ackAt),
            db_escape((string) $id),
            db_escape($ackToken)
        );
        return db_query($sql, 'Failed to acknowledge notification') !== false;
    }

    public function markDispatched(int $id, ?DateTimeInterface $dispatchedAt = null): bool
    {
        $dispatchedAt = $dispatchedAt ? $dispatchedAt->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
        $sql = sprintf(
            'UPDATE %s SET status = %s, dispatched_at = %s WHERE id = %s',
            $this->tableName(),
            db_escape(Notification::STATUS_DISPATCHED),
            db_escape($dispatchedAt),
            db_escape((string) $id)
        );
        return db_query($sql, 'Failed to mark notification dispatched') !== false;
    }

    private function tableName(): string
    {
        return defined('TB_PREF') ? TB_PREF . self::TABLE : self::TABLE;
    }
}
