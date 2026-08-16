<?php
/**
 * TabContext unit tests.
 *
 * @package Ksfraser\Frontaccounting\HTML\Tests\Unit
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML\Tests\Unit;

use Ksfraser\Frontaccounting\HTML\TabContext;
use PHPUnit\Framework\TestCase;

class TabContextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['PHP_SELF']);
    }

    public function testFromPostMapsRecordIdAndTabSel(): void
    {
        $context = TabContext::fromPost([
            'stock_id'  => 'SKU001',
            '_tabs_sel' => 'sales_pricing',
            'page'      => '3',
        ]);

        $this->assertSame('SKU001', $context->getRecordId());
        $this->assertSame('sales_pricing', $context->getTabSel());
        $this->assertSame(3, $context->getPage());
        $this->assertSame('stock_id', $context->getRecordIdField());
    }

    public function testFromPostUsesCustomRecordIdField(): void
    {
        $context = TabContext::fromPost([
            'employee_id' => 'EMP-9',
            '_tabs_sel'   => 'details',
        ], 'employee_id');

        $this->assertSame('EMP-9', $context->getRecordId());
        $this->assertSame('employee_id', $context->getRecordIdField());
    }

    public function testFromPostDefaultsWhenMissing(): void
    {
        $context = TabContext::fromPost([]);

        $this->assertSame('', $context->getRecordId());
        $this->assertSame('', $context->getTabSel());
        $this->assertNull($context->getPage());
    }

    public function testFromPostIgnoresEmptyPage(): void
    {
        $context = TabContext::fromPost([
            'stock_id'  => 'SKU001',
            '_tabs_sel' => 'settings',
            'page'      => '',
        ]);

        $this->assertNull($context->getPage());
    }

    public function testConstructorDefaults(): void
    {
        $context = new TabContext('ABC-1');

        $this->assertSame('ABC-1', $context->getRecordId());
        $this->assertSame('', $context->getTabSel());
        $this->assertNull($context->getPage());
        $this->assertSame('stock_id', $context->getRecordIdField());
    }

    public function testRedirectTargetWithPhpSelf(): void
    {
        $_SERVER['PHP_SELF'] = '/modules/ksf_FA_Items/pages/items.php';

        $context = new TabContext('SKU001', 'sales_pricing');
        $this->assertSame(
            '/modules/ksf_FA_Items/pages/items.php?stock_id=SKU001&_tabs_sel=sales_pricing',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetUrlEncodesValues(): void
    {
        $_SERVER['PHP_SELF'] = '/items.php';

        $context = new TabContext('A B/1', 'some tab');
        $this->assertSame(
            '/items.php?stock_id=A%20B%2F1&_tabs_sel=some%20tab',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetWithCustomField(): void
    {
        $_SERVER['PHP_SELF'] = '/employees.php';

        $context = new TabContext('EMP-3', 'details', null, 'employee_id');
        $this->assertSame(
            '/employees.php?employee_id=EMP-3&_tabs_sel=details',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetWithPage(): void
    {
        $_SERVER['PHP_SELF'] = '/items.php';

        $context = new TabContext('SKU001', 'settings', 4);
        $this->assertSame(
            '/items.php?stock_id=SKU001&_tabs_sel=settings&page=4',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetOmitsFirstPage(): void
    {
        $_SERVER['PHP_SELF'] = '/items.php';

        $context = new TabContext('SKU001', 'settings', 1);
        $this->assertSame(
            '/items.php?stock_id=SKU001&_tabs_sel=settings',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetWithoutPhpSelf(): void
    {
        $context = new TabContext('SKU001', 'settings');
        $this->assertSame(
            '?stock_id=SKU001&_tabs_sel=settings',
            $context->redirectTarget()
        );
    }

    public function testRedirectTargetEmptyWhenNoContext(): void
    {
        $_SERVER['PHP_SELF'] = '/items.php';

        $context = new TabContext('');
        $this->assertSame('/items.php', $context->redirectTarget());
    }
}
