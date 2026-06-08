<?php

declare(strict_types=1);

namespace KsfCommon\Notification;

use DateTime;
use DateTimeInterface;

final class Notification
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_FAILED = 'failed';

    public const CHANNEL_BROWSER = 'browser';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';

    private ?int $id = null;
    private string $sourceModule;
    private string $sourceRef;
    private ?string $recipientUserId = null;
    private string $notificationType;
    private string $channel;
    private string $title;
    private ?string $body = null;
    private array $payload = [];
    private string $status;
    private ?DateTime $scheduledAt = null;
    private ?DateTime $dispatchedAt = null;
    private ?DateTime $acknowledgedAt = null;
    private ?string $ackToken = null;
    private DateTime $createdAt;
    private DateTime $updatedAt;

    public function __construct(string $sourceModule, string $sourceRef, string $title)
    {
        $this->sourceModule = $sourceModule;
        $this->sourceRef = $sourceRef;
        $this->title = $title;
        $this->notificationType = 'alert';
        $this->channel = self::CHANNEL_BROWSER;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
        $this->ackToken = bin2hex(random_bytes(16));
    }

    public static function fromArray(array $row): self
    {
        $n = new self(
            (string) ($row['source_module'] ?? ''),
            (string) ($row['source_ref'] ?? ''),
            (string) ($row['title'] ?? '')
        );
        if (isset($row['id'])) { $n->id = (int) $row['id']; }
        if (array_key_exists('recipient_user_id', $row)) { $n->recipientUserId = $row['recipient_user_id'] !== null ? (string) $row['recipient_user_id'] : null; }
        $n->notificationType = (string) ($row['notification_type'] ?? 'alert');
        $n->channel = (string) ($row['channel'] ?? self::CHANNEL_BROWSER);
        $n->body = array_key_exists('body', $row) ? ($row['body'] !== null ? (string) $row['body'] : null) : null;
        $n->payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [];
        $n->status = (string) ($row['status'] ?? self::STATUS_PENDING);
        $n->scheduledAt = !empty($row['scheduled_at']) ? new DateTime((string) $row['scheduled_at']) : null;
        $n->dispatchedAt = !empty($row['dispatched_at']) ? new DateTime((string) $row['dispatched_at']) : null;
        $n->acknowledgedAt = !empty($row['acknowledged_at']) ? new DateTime((string) $row['acknowledged_at']) : null;
        $n->ackToken = array_key_exists('ack_token', $row) ? (string) $row['ack_token'] : null;
        $n->createdAt = !empty($row['created_at']) ? new DateTime((string) $row['created_at']) : new DateTime();
        $n->updatedAt = !empty($row['updated_at']) ? new DateTime((string) $row['updated_at']) : new DateTime();
        return $n;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_module' => $this->sourceModule,
            'source_ref' => $this->sourceRef,
            'recipient_user_id' => $this->recipientUserId,
            'notification_type' => $this->notificationType,
            'channel' => $this->channel,
            'title' => $this->title,
            'body' => $this->body,
            'payload_json' => json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt ? $this->scheduledAt->format('Y-m-d H:i:s') : null,
            'dispatched_at' => $this->dispatchedAt ? $this->dispatchedAt->format('Y-m-d H:i:s') : null,
            'acknowledged_at' => $this->acknowledgedAt ? $this->acknowledgedAt->format('Y-m-d H:i:s') : null,
            'ack_token' => $this->ackToken,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function getId(): ?int { return $this->id; }
    public function setId(int $id): self { $this->id = $id; return $this; }
    public function getSourceModule(): string { return $this->sourceModule; }
    public function getSourceRef(): string { return $this->sourceRef; }
    public function getRecipientUserId(): ?string { return $this->recipientUserId; }
    public function setRecipientUserId(?string $recipientUserId): self { $this->recipientUserId = $recipientUserId; return $this; }
    public function getNotificationType(): string { return $this->notificationType; }
    public function setNotificationType(string $notificationType): self { $this->notificationType = $notificationType; return $this; }
    public function getChannel(): string { return $this->channel; }
    public function setChannel(string $channel): self { $this->channel = $channel; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): self { $this->body = $body; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $payload): self { $this->payload = $payload; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getScheduledAt(): ?DateTimeInterface { return $this->scheduledAt; }
    public function setScheduledAt(?DateTimeInterface $scheduledAt): self { $this->scheduledAt = $scheduledAt ? DateTime::createFromInterface($scheduledAt) : null; return $this; }
    public function getDispatchedAt(): ?DateTimeInterface { return $this->dispatchedAt; }
    public function setDispatchedAt(?DateTimeInterface $dispatchedAt): self { $this->dispatchedAt = $dispatchedAt ? DateTime::createFromInterface($dispatchedAt) : null; return $this; }
    public function getAcknowledgedAt(): ?DateTimeInterface { return $this->acknowledgedAt; }
    public function setAcknowledgedAt(?DateTimeInterface $acknowledgedAt): self { $this->acknowledgedAt = $acknowledgedAt ? DateTime::createFromInterface($acknowledgedAt) : null; return $this; }
    public function getAckToken(): ?string { return $this->ackToken; }
    public function setAckToken(?string $ackToken): self { $this->ackToken = $ackToken; return $this; }
    public function getCreatedAt(): DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(DateTimeInterface $updatedAt): self { $this->updatedAt = DateTime::createFromInterface($updatedAt); return $this; }
}
