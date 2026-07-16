<?php

declare(strict_types=1);

namespace KsfCommon\Tests\Unit\Menu;

use KsfCommon\Menu\FAModuleMenu;
use PHPUnit\Framework\TestCase;

if (!defined('MENU_MAIN'))  { define('MENU_MAIN',  1); }
if (!defined('MENU_ENTRY')) { define('MENU_ENTRY', 2); }

class FAModuleMenuTest extends TestCase
{
    public function testAddItemReturnsFluent(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');

        $result = $menu->addItem('test', 'Test', null);

        $this->assertSame($menu, $result);
    }

    public function testAddItemStoresCorrectly(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addItem('home', 'Home', MENU_MAIN);

        $items = $menu->getItems();
        $this->assertCount(1, $items);
        $this->assertSame('home', $items[0]['key']);
        $this->assertSame('Home', $items[0]['label']);
        $this->assertSame(MENU_MAIN, $items[0]['fa_type']);
    }

    public function testAddItemNullFaType(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addItem('sub', 'Sub Menu', null);

        $items = $menu->getItems();
        $this->assertNull($items[0]['fa_type']);
    }

    public function testAddItemsBatch(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addItems([
            ['key' => 'a', 'label' => 'A', 'fa_type' => MENU_MAIN],
            ['key' => 'b', 'label' => 'B', 'fa_type' => MENU_ENTRY],
        ]);

        $this->assertCount(2, $menu->getItems());
    }

    public function testAddRegisteredItemsSortsByPriority(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addRegisteredItems([
            ['key' => 'z', 'label' => 'Z', 'fa_type' => null, 'priority' => 100],
            ['key' => 'a', 'label' => 'A', 'fa_type' => null, 'priority' => 10],
            ['key' => 'm', 'label' => 'M', 'fa_type' => null, 'priority' => 50],
        ]);

        $items = $menu->getItems();
        $this->assertSame('a', $items[0]['key']);
        $this->assertSame('m', $items[1]['key']);
        $this->assertSame('z', $items[2]['key']);
    }

    public function testSetActiveReturnsFluent(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');

        $result = $menu->setActive('home');

        $this->assertSame($menu, $result);
    }

    public function testRenderEmptyMenu(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');

        $this->assertSame('', $menu->render());
    }

    public function testRenderWithItems(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', 'home');
        $menu->addItem('home', 'Home', null)
             ->addItem('about', 'About', null);

        $html = $menu->render();

        $this->assertStringContainsString('ksf-module-menu', $html);
        // Active item should be bolded
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('About', $html);
        // Pipe separator
        $this->assertStringContainsString('|', $html);
    }

    public function testRenderUrlsContainViewParam(): void
    {
        $menu = new FAModuleMenu('cal.php', 'view', '');
        $menu->addItem('test', 'Test', null);

        $html = $menu->render();

        $this->assertStringContainsString('cal.php?view=test', $html);
    }

    public function testRenderEscapesHtmlInLabels(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addItem('test', '<script>alert("xss")</script>', null);

        $html = $menu->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testGetItemsReturnsEmptyArrayForNewMenu(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');

        $this->assertSame([], $menu->getItems());
    }

    public function testMultipleItemsPreserveOrder(): void
    {
        $menu = new FAModuleMenu('test.php', 'view', '');
        $menu->addItem('first', 'First', null)
             ->addItem('second', 'Second', null)
             ->addItem('third', 'Third', null);

        $items = $menu->getItems();
        $this->assertSame('first', $items[0]['key']);
        $this->assertSame('second', $items[1]['key']);
        $this->assertSame('third', $items[2]['key']);
    }
}
