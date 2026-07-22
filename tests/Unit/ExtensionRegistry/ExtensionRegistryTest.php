<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ExtensionRegistry;

use ksfraser\FrontAccounting\Common\ExtensionRegistry\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

class ExtensionRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::destroyInstance();
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::destroyInstance();
        parent::tearDown();
    }

    public function testInstanceReturnsSingleton(): void
    {
        $a = ExtensionRegistry::instance();
        $b = ExtensionRegistry::instance();

        $this->assertSame($a, $b);
    }

    public function testRegisterAndRetrieve(): void
    {
        $registry = ExtensionRegistry::instance();

        $registry->register('test_category', 'test_key', ['label' => 'Test']);

        $result = $registry->get('test_category', 'test_key');
        $this->assertSame(['label' => 'Test'], $result);
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $registry = ExtensionRegistry::instance();

        $this->assertNull($registry->get('nonexistent', 'key'));
    }

    public function testGetReturnsNullForUnknownCategory(): void
    {
        $registry = ExtensionRegistry::instance();

        $this->assertNull($registry->get('nonexistent_category', 'any_key'));
    }

    public function testIsValidReturnsTrueForRegisteredKey(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('types', 'campaign', ['label' => 'Campaign']);

        $this->assertTrue($registry->isValid('types', 'campaign'));
    }

    public function testIsValidReturnsFalseForUnknownKey(): void
    {
        $registry = ExtensionRegistry::instance();

        $this->assertFalse($registry->isValid('types', 'nonexistent'));
    }

    public function testGetRegisteredReturnsAllForCategory(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('types', 'a', ['label' => 'A']);
        $registry->register('types', 'b', ['label' => 'B']);
        $registry->register('other', 'c', ['label' => 'C']);

        $types = $registry->getRegistered('types');

        $this->assertCount(2, $types);
        $this->assertArrayHasKey('a', $types);
        $this->assertArrayHasKey('b', $types);
        $this->assertArrayNotHasKey('c', $types);
    }

    public function testGetRegisteredReturnsEmptyArrayForUnknownCategory(): void
    {
        $registry = ExtensionRegistry::instance();

        $this->assertSame([], $registry->getRegistered('nonexistent'));
    }

    public function testGetCategoriesReturnsAllCategories(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('cat_a', 'key1', []);
        $registry->register('cat_b', 'key2', []);
        $registry->register('cat_a', 'key3', []);

        $categories = $registry->getCategories();

        $this->assertContains('cat_a', $categories);
        $this->assertContains('cat_b', $categories);
        $this->assertCount(2, $categories);
    }

    public function testRegisterOverwritesExistingKey(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('types', 'x', ['label' => 'Old']);
        $registry->register('types', 'x', ['label' => 'New']);

        $result = $registry->get('types', 'x');
        $this->assertSame('New', $result['label']);
    }

    public function testResetClearsAllRegistrations(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('types', 'a', ['label' => 'A']);
        $registry->register('menus', 'b', ['label' => 'B']);

        $registry->reset();

        $this->assertSame([], $registry->getRegistered('types'));
        $this->assertSame([], $registry->getRegistered('menus'));
        $this->assertSame([], $registry->getCategories());
    }

    public function testBootIsIdempotent(): void
    {
        $registry = ExtensionRegistry::instance();

        // boot() without hooks should not throw
        $registry->boot([]);
        $registry->boot([]);

        // No error — idempotent behavior
        $this->assertTrue(true);
    }

    public function testDestroyInstanceResetsSingleton(): void
    {
        $a = ExtensionRegistry::instance();
        $a->register('test', 'key', ['value' => 1]);

        ExtensionRegistry::destroyInstance();

        $b = ExtensionRegistry::instance();
        $this->assertNotSame($a, $b);
        $this->assertNull($b->get('test', 'key'));
    }

    public function testRegisterMultipleKeysInCategory(): void
    {
        $registry = ExtensionRegistry::instance();
        $registry->register('source_types', 'event', ['label' => 'Event']);
        $registry->register('source_types', 'meeting', ['label' => 'Meeting']);
        $registry->register('source_types', 'call', ['label' => 'Call']);

        $all = $registry->getRegistered('source_types');

        $this->assertCount(3, $all);
        $this->assertSame('Event', $all['event']['label']);
        $this->assertSame('Meeting', $all['meeting']['label']);
        $this->assertSame('Call', $all['call']['label']);
    }
}
