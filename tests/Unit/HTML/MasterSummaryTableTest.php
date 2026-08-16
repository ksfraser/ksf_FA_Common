<?php
/**
 * MasterSummaryTable unit tests.
 *
 * @package Ksfraser\Frontaccounting\HTML\Tests\Unit
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML\Tests\Unit;

use Ksfraser\Frontaccounting\HTML\MasterSummaryTable;
use PHPUnit\Framework\TestCase;

class MasterSummaryTableTest extends TestCase
{
    private function makeTable(array $options = [], array $rowActions = ['edit' => true, 'delete' => true]): MasterSummaryTable
    {
        $columns = [
            ['key' => 'stock_id', 'label' => 'Item Code'],
            ['key' => 'description', 'label' => 'Description'],
        ];
        $rows = [
            ['id' => '1', 'stock_id' => 'SKU001', 'description' => 'Widget A'],
            ['id' => '2', 'stock_id' => 'SKU002', 'description' => 'Widget B'],
            ['id' => '3', 'stock_id' => 'SKU003', 'description' => 'Widget C'],
        ];

        return new MasterSummaryTable($columns, $rows, $rowActions, $options);
    }

    public function testRenderProducesTableWithHeadersAndRowData(): void
    {
        $table = $this->makeTable();
        $html  = $table->toHtml();

        $this->assertStringContainsString('<table class="tablestyle">', $html);
        $this->assertStringContainsString('Item Code', $html);
        $this->assertStringContainsString('Description', $html);
        $this->assertStringContainsString('SKU001', $html);
        $this->assertStringContainsString('Widget B', $html);
        $this->assertStringContainsString('SKU003', $html);
    }

    public function testEditAndDeleteButtonsAppearPerRow(): void
    {
        $table = $this->makeTable();
        $html  = $table->toHtml();

        $this->assertStringContainsString('name="edit_1"', $html);
        $this->assertStringContainsString('name="edit_2"', $html);
        $this->assertStringContainsString('name="delete_3"', $html);
        $this->assertStringContainsString('formnovalidate', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }

    public function testDeleteButtonHasConfirmGuard(): void
    {
        $table = $this->makeTable();
        $html  = $table->toHtml();

        $this->assertStringContainsString("return confirm('Delete this record?');", $html);
    }

    public function testNoRowActionsColumnWhenActionsDisabled(): void
    {
        $table = $this->makeTable([], []);
        $html  = $table->toHtml();

        $this->assertStringNotContainsString('edit_1', $html);
        $this->assertStringNotContainsString('delete_1', $html);
    }

    public function testHiddenFieldsEmitted(): void
    {
        $table = $this->makeTable([
            'record_id' => 'SKU002',
            'tab_sel'   => 'sales_pricing',
        ]);
        $html = $table->toHtml();

        $this->assertStringContainsString('name="stock_id" value="SKU002"', $html);
        $this->assertStringContainsString('name="_tabs_sel" value="sales_pricing"', $html);
    }

    public function testHiddenFieldsUseCustomRecordIdField(): void
    {
        $table = $this->makeTable([
            'record_id_field' => 'employee_id',
            'record_id'       => 'EMP-7',
        ]);
        $html = $table->toHtml();

        $this->assertStringContainsString('name="employee_id" value="EMP-7"', $html);
    }

    public function testGetHiddenFieldValues(): void
    {
        $table = $this->makeTable([
            'record_id_field' => 'customer_id',
            'record_id'       => 'CUST-9',
            'tab_sel'         => 'details',
        ]);

        $this->assertSame(
            ['customer_id' => 'CUST-9', '_tabs_sel' => 'details'],
            $table->getHiddenFieldValues()
        );
    }

    public function testFooterSubmitAndCancelButtonsRendered(): void
    {
        $table = $this->makeTable([
            'submit_button_name' => 'ksf_items_submit',
            'cancel_button_name' => 'ksf_items_cancel',
        ]);
        $html = $table->toHtml();

        $this->assertStringContainsString('name="ksf_items_submit"', $html);
        $this->assertStringContainsString('name="ksf_items_cancel"', $html);
        $this->assertSame('ksf_items_submit', $table->getSubmitButtonName());
        $this->assertSame('ksf_items_cancel', $table->getCancelButtonName());
    }

    public function testFooterHiddenWhenShowFooterDisabled(): void
    {
        $table = $this->makeTable(['show_footer' => false]);
        $html  = $table->toHtml();

        $this->assertFalse($table->getShowFooter());
        $this->assertStringNotContainsString('name="submit"', $html);
        $this->assertStringNotContainsString('name="cancel"', $html);
        $this->assertStringContainsString('name="edit_1"', $html);
        $this->assertStringContainsString('name="delete_1"', $html);
    }

    public function testShowFooterDefaultsToTrue(): void
    {
        $table = $this->makeTable();

        $this->assertTrue($table->getShowFooter());
        $this->assertStringContainsString('name="submit"', $table->toHtml());
    }

    public function testPagerShownWhenTotalExceedsPerPage(): void
    {
        $table = $this->makeTable([
            'total'     => 3,
            'per_page'  => 2,
            'page'      => 1,
            'record_id' => 'SKU001',
            'tab_sel'   => 'settings',
        ]);
        $html = $table->toHtml();

        $this->assertStringContainsString("class=\"navibar\"", $html);
        $this->assertStringContainsString('Records 1-2 of 3', $html);
        $this->assertStringContainsString('href="?page=2&amp;stock_id=SKU001&amp;_tabs_sel=settings"', $html);
        $this->assertStringNotContainsString('href="?page=3', $html);
    }

    public function testPagerHiddenWhenTotalWithinPerPage(): void
    {
        $table = $this->makeTable([
            'total'    => 2,
            'per_page' => 2,
        ]);
        $html = $table->toHtml();

        $this->assertStringNotContainsString("class=\"navibar\"", $html);
        $this->assertStringNotContainsString('Records 1-2 of 2', $html);
    }

    public function testRenderPagerDirectlyPreservesTabAndRecordId(): void
    {
        $table = $this->makeTable([
            'total'            => 5,
            'per_page'         => 2,
            'page'             => 2,
            'record_id_field'  => 'employee_id',
            'record_id'        => 'EMP-3',
            'tab_sel'          => 'details',
        ]);

        ob_start();
        $table->renderPager();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('href="?page=1&amp;employee_id=EMP-3&amp;_tabs_sel=details"', $html);
        $this->assertStringContainsString('href="?page=3&amp;employee_id=EMP-3&amp;_tabs_sel=details"', $html);
        $this->assertStringContainsString('Records 3-4 of 5', $html);
    }

    public function testRenderPagerEmptyWhenSinglePage(): void
    {
        $table = $this->makeTable([
            'total'    => 3,
            'per_page' => 10,
        ]);

        ob_start();
        $table->renderPager();
        $html = (string) ob_get_clean();

        $this->assertSame('', $html);
    }

    public function testRowValuesAreEscaped(): void
    {
        $columns = [
            ['key' => 'id', 'label' => 'Id'],
            ['key' => 'name', 'label' => 'Name'],
        ];
        $rows = [
            ['id' => '1', 'name' => '<script>alert(1)</script>'],
        ];

        $table = new MasterSummaryTable($columns, $rows);
        $html  = $table->toHtml();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testRowIdIsEscapedInActionButtonNames(): void
    {
        $columns = [
            ['key' => 'id', 'label' => 'Id'],
            ['key' => 'name', 'label' => 'Name'],
        ];
        $rows = [
            ['id' => '1<2', 'name' => 'Odd'],
        ];

        $table = new MasterSummaryTable($columns, $rows, ['edit' => true]);
        $html  = $table->toHtml();

        $this->assertStringContainsString('name="edit_1&lt;2"', $html);
        $this->assertStringNotContainsString('name="edit_1<2"', $html);
    }

    public function testTitleRenderedAboveTable(): void
    {
        $table = $this->makeTable(['title' => 'Stock Items']);
        $html  = $table->toHtml();

        $this->assertStringContainsString('<h3>Stock Items</h3>', $html);
        $this->assertSame('Stock Items', $table->getTitle());
    }

    public function testTitleIsEscaped(): void
    {
        $table = $this->makeTable(['title' => '<b>Stock</b>']);
        $html  = $table->toHtml();

        $this->assertStringContainsString('&lt;b&gt;Stock&lt;/b&gt;', $html);
    }

    public function testIsSubmitPostRecognisesFooterSubmitButton(): void
    {
        $table = $this->makeTable(['submit_button_name' => 'ksf_items_submit']);

        $this->assertTrue($table->isSubmitPost(['ksf_items_submit' => 'Submit']));
        $this->assertFalse($table->isSubmitPost(['_tabs_sel' => 'settings']));
    }

    public function testIsSubmitPostRecognisesRowAction(): void
    {
        $table = $this->makeTable();

        $this->assertTrue($table->isSubmitPost(['edit_2' => '2']));
        $this->assertTrue($table->isSubmitPost(['delete_3' => '3']));
        $this->assertFalse($table->isSubmitPost(['_tabs_sel' => 'settings', 'stock_id' => 'SKU001']));
    }

    public function testGetPostedActionDetectsEditAndDelete(): void
    {
        $table = $this->makeTable();

        $this->assertSame(['action' => 'edit', 'id' => '2'], $table->getPostedAction(['edit_2' => '2']));
        $this->assertSame(['action' => 'delete', 'id' => '3'], $table->getPostedAction(['delete_3' => '3']));
        $this->assertNull($table->getPostedAction(['_tabs_sel' => 'settings']));
    }

    public function testGetPostedActionIgnoresDisabledActions(): void
    {
        $table = $this->makeTable([], ['delete' => true]);

        $this->assertNull($table->getPostedAction(['edit_2' => '2']));
        $this->assertSame(['action' => 'delete', 'id' => '2'], $table->getPostedAction(['delete_2' => '2']));
    }

    public function testDefaults(): void
    {
        $table = $this->makeTable();

        $this->assertSame('stock_id', $table->getRecordIdField());
        $this->assertSame('id', $table->getRowIdField());
        $this->assertSame('', $table->getRecordId());
        $this->assertSame('', $table->getTabSel());
        $this->assertSame(1, $table->getPage());
        $this->assertSame(20, $table->getPerPage());
        $this->assertSame(3, $table->getTotal());
        $this->assertSame('', $table->getFormAction());
        $this->assertSame('stock_id', $table->getNotificationDiv());
        $this->assertSame('submit', $table->getSubmitButtonName());
        $this->assertSame('cancel', $table->getCancelButtonName());
    }

    public function testNotificationDivOption(): void
    {
        $table = $this->makeTable(['notification_div' => 'items_div']);

        $this->assertSame('items_div', $table->getNotificationDiv());
    }

    public function testPageCountCalculations(): void
    {
        $this->assertSame(2, $this->makeTable(['total' => 5, 'per_page' => 3])->getPageCount());
        $this->assertSame(1, $this->makeTable(['total' => 3, 'per_page' => 10])->getPageCount());
        $this->assertSame(1, $this->makeTable(['total' => 0, 'per_page' => 10])->getPageCount());
    }
}
