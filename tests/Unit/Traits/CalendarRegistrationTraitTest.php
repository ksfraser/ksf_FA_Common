<?php

declare(strict_types=1);

namespace KsfCommon\Tests\Unit\Traits;

use KsfCommon\ExtensionRegistry\ExtensionRegistry;
use KsfCommon\Traits\CalendarRegistrationTrait;
use PHPUnit\Framework\TestCase;

if (!defined('MENU_MAIN'))  { define('MENU_MAIN',  1); }
if (!defined('MENU_ENTRY')) { define('MENU_ENTRY', 2); }

class CalendarRegistrationTraitTest extends TestCase
{
    /** @var object|null Class using the trait */
    private $traitUser;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::destroyInstance();

        // Create an anonymous class that uses the trait.
        $this->traitUser = new class {
            use CalendarRegistrationTrait;

            public function callRegisterSourceType(string $type, string $label, array $options = []): void
            {
                $this->registerCalendarSourceType($type, $label, $options);
            }

            public function callRegisterMenuItem(string $key, string $label, $faType = null, int $priority = 50): void
            {
                $this->registerCalendarMenuItem($key, $label, $faType, $priority);
            }

            public function callRegisterTypeLabel(string $type, string $label, array $fields = []): void
            {
                $this->registerCalendarTypeLabel($type, $label, $fields);
            }

            public function callRegisterStatus(string $type, string $status, string $label): void
            {
                $this->registerCalendarStatus($type, $status, $label);
            }
        };
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::destroyInstance();
        $this->traitUser = null;
        parent::tearDown();
    }

    public function testRegisterSourceTypeWritesToRegistry(): void
    {
        $this->traitUser->callRegisterSourceType('campaign', 'Campaign', [
            'statuses' => ['planned', 'active'],
            'color'    => '#4CAF50',
        ]);

        $registry = ExtensionRegistry::instance();
        $this->assertTrue($registry->isValid('calendar_source_types', 'campaign'));

        $def = $registry->get('calendar_source_types', 'campaign');
        $this->assertSame('Campaign', $def['label']);
        $this->assertSame(['planned', 'active'], $def['statuses']);
        $this->assertSame('#4CAF50', $def['color']);
    }

    public function testRegisterSourceTypeDefaultOptions(): void
    {
        $this->traitUser->callRegisterSourceType('task', 'Task');

        $registry = ExtensionRegistry::instance();
        $def = $registry->get('calendar_source_types', 'task');
        $this->assertSame('Task', $def['label']);
        $this->assertArrayNotHasKey('statuses', $def);
    }

    public function testRegisterMenuItemWritesToRegistry(): void
    {
        $this->traitUser->callRegisterMenuItem('my_campaigns', 'Campaigns', MENU_ENTRY, 60);

        $registry = ExtensionRegistry::instance();
        $this->assertTrue($registry->isValid('calendar_menu_items', 'my_campaigns'));

        $def = $registry->get('calendar_menu_items', 'my_campaigns');
        $this->assertSame('my_campaigns', $def['key']);
        $this->assertSame('Campaigns', $def['label']);
        $this->assertSame(MENU_ENTRY, $def['fa_type']);
        $this->assertSame(60, $def['priority']);
    }

    public function testRegisterMenuItemDefaultPriority(): void
    {
        $this->traitUser->callRegisterMenuItem('test_view', 'Test View');

        $registry = ExtensionRegistry::instance();
        $def = $registry->get('calendar_menu_items', 'test_view');
        $this->assertSame(50, $def['priority']);
        $this->assertNull($def['fa_type']);
    }

    public function testRegisterTypeLabelWritesToRegistry(): void
    {
        $this->traitUser->callRegisterTypeLabel('campaign', 'Campaign', ['online_url']);

        $registry = ExtensionRegistry::instance();
        $this->assertTrue($registry->isValid('calendar_type_labels', 'campaign'));

        $def = $registry->get('calendar_type_labels', 'campaign');
        $this->assertSame('campaign', $def['type']);
        $this->assertSame('Campaign', $def['label']);
        $this->assertSame(['online_url'], $def['fields']);
    }

    public function testRegisterTypeLabelDefaultFields(): void
    {
        $this->traitUser->callRegisterTypeLabel('event', 'Event');

        $registry = ExtensionRegistry::instance();
        $def = $registry->get('calendar_type_labels', 'event');
        $this->assertSame([], $def['fields']);
    }

    public function testRegisterStatusWritesToRegistry(): void
    {
        $this->traitUser->callRegisterStatus('campaign', 'campaign_planned', 'Campaign Planned');

        $registry = ExtensionRegistry::instance();
        $this->assertTrue($registry->isValid('calendar_statuses', 'campaign_planned'));

        $def = $registry->get('calendar_statuses', 'campaign_planned');
        $this->assertSame('campaign_planned', $def['status']);
        $this->assertSame('Campaign Planned', $def['label']);
        $this->assertSame('campaign', $def['type']);
    }

    public function testMultipleRegistrationsAccumulate(): void
    {
        $this->traitUser->callRegisterSourceType('campaign', 'Campaign');
        $this->traitUser->callRegisterSourceType('promotion', 'Promotion');
        $this->traitUser->callRegisterMenuItem('my_campaigns', 'Campaigns', MENU_ENTRY, 60);

        $registry = ExtensionRegistry::instance();
        $this->assertCount(2, $registry->getRegistered('calendar_source_types'));
        $this->assertCount(1, $registry->getRegistered('calendar_menu_items'));
    }
}
