<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Scheduler;

use PHPUnit\Framework\TestCase;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\InMemoryNotificationPreferenceStore;
use ksfraser\FrontAccounting\Common\Scheduler\NotificationDigest;

/**
 * BR-COM-03 digest job (BR-COM-04 FR-COM-04-008).
 *
 * Recurring, per-user summary: for each digest-enabled recipient it materializes
 * unconsumed @all rows, groups unread inbox rows by notification type (excluding
 * muted types) and hands the summary to an injected sink. Users without the
 * digest flag — and digest users with nothing unread — are skipped; the job
 * body is a read-only computation, so it is idempotent by construction.
 *
 * @BABOK Related: FR-COM-04-008, BR-COM-03 FR-COM-03-005
 */
final class NotificationDigestTest extends TestCase
{
    private InMemoryInboxStore $inbox;
    private InMemoryNotificationPreferenceStore $prefs;

    /** @var array<int,array>  uid => summary, as captured by the test sink */
    private array $delivered = [];

    protected function setUp(): void
    {
        $this->inbox  = new InMemoryInboxStore();
        $this->prefs  = new InMemoryNotificationPreferenceStore();
        $this->delivered = [];
    }

    private function digest(): NotificationDigest
    {
        return new NotificationDigest(
            $this->inbox,
            $this->prefs,
            function (int $uid, array $summary): void {
                $this->delivered[$uid] = $summary;
            }
        );
    }

    public function testSkipsUsersWhoDidNotOptIntoDigest(): void
    {
        $this->inbox->insert([
            'recipient_uid' => 7,
            'type'          => 'hrm.leave_approved',
            'payload'       => ['request_id' => 42],
            'created_at'    => '2026-09-21 09:00:00',
        ]);

        $this->digest()(['user_ids' => [7]]);

        $this->assertSame([], $this->delivered);
    }

    public function testDeliversGroupedSummaryForDigestEnabledUser(): void
    {
        $this->prefs->setDigest(7, true);
        foreach ([
            ['recipient_uid' => 7, 'type' => 'hrm.leave_approved', 'created_at' => '2026-09-21 08:00:00'],
            ['recipient_uid' => 7, 'type' => 'hrm.leave_approved', 'created_at' => '2026-09-21 09:00:00'],
            ['recipient_uid' => 7, 'type' => 'hrm.timesheet.expiry', 'created_at' => '2026-09-21 10:00:00'],
        ] as $row) {
            $this->inbox->insert($row);
        }

        $this->digest()(['user_ids' => [7]]);

        $this->assertArrayHasKey(7, $this->delivered);
        $this->assertSame(3, $this->delivered[7]['total']);
        $this->assertSame(2, $this->delivered[7]['by_type']['hrm.leave_approved']);
        $this->assertSame(1, $this->delivered[7]['by_type']['hrm.timesheet.expiry']);
    }

    public function testExcludesMutedTypesFromSummary(): void
    {
        $this->prefs->setDigest(7, true);
        $this->prefs->setMutedTypes(7, ['hrm.timesheet.expiry']);
        $this->inbox->insert([
            'recipient_uid' => 7,
            'type'          => 'hrm.leave_approved',
            'created_at'    => '2026-09-21 08:00:00',
        ]);
        $this->inbox->insert([
            'recipient_uid' => 7,
            'type'          => 'hrm.timesheet.expiry',
            'created_at'    => '2026-09-21 09:00:00',
        ]);

        $this->digest()(['user_ids' => [7]]);

        $this->assertSame(1, $this->delivered[7]['total']);
        $this->assertSame(1, $this->delivered[7]['by_type']['hrm.leave_approved']);
        $this->assertArrayNotHasKey('hrm.timesheet.expiry', $this->delivered[7]['by_type']);
    }

    public function testDigestUserWithNothingUnreadProducesNoDelivery(): void
    {
        $this->prefs->setDigest(7, true);
        $this->inbox->insert([
            'recipient_uid' => 7,
            'type'          => 'hrm.leave_approved',
            'created_at'    => '2026-09-21 08:00:00',
        ]);
        $this->inbox->markAllRead(7);

        $this->digest()(['user_ids' => [7]]);

        $this->assertSame([], $this->delivered);
    }

    public function testMaterializesUnconsumedAllRowsBeforeSummarizing(): void
    {
        $this->prefs->setDigest(7, true);
        $this->inbox->insert([
            'recipient_uid' => 0,
            'type'          => 'common.changelog',
            'created_at'    => '2026-09-21 07:00:00',
        ]);

        $this->digest()(['user_ids' => [7]]);

        $this->assertSame(1, $this->delivered[7]['total']);
        $this->assertSame(1, $this->delivered[7]['by_type']['common.changelog']);
    }

    public function testEmptyUserListIsANoOp(): void
    {
        $this->prefs->setDigest(7, true);
        $this->inbox->insert([
            'recipient_uid' => 7,
            'type'          => 'hrm.leave_approved',
            'created_at'    => '2026-09-21 08:00:00',
        ]);

        $this->digest()([]);

        $this->assertSame([], $this->delivered);
    }
}