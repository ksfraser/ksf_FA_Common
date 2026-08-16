<?php
/**
 * FormFooter unit tests.
 *
 * @package Ksfraser\Frontaccounting\HTML\Tests\Unit
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Ksfraser\Frontaccounting\HTML\Tests\Unit;

use Ksfraser\Frontaccounting\HTML\FormFooter;
use PHPUnit\Framework\TestCase;

class FormFooterTest extends TestCase
{
    public function testRenderProducesSubmitAndCancelButtons(): void
    {
        $footer = new FormFooter('ksf_items_submit', 'ksf_items_cancel');
        $html   = $footer->toHtml();

        $this->assertStringContainsString('name="ksf_items_submit"', $html);
        $this->assertStringContainsString('name="ksf_items_cancel"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('formnovalidate', $html);
        $this->assertStringContainsString('<span>Submit</span>', $html);
        $this->assertStringContainsString('<span>Cancel</span>', $html);
    }

    public function testCustomLabels(): void
    {
        $footer = new FormFooter('save', 'reset', 'Save All', 'Reset');
        $html   = $footer->toHtml();

        $this->assertStringContainsString('<span>Save All</span>', $html);
        $this->assertStringContainsString('<span>Reset</span>', $html);
    }

    public function testLabelsAreEscaped(): void
    {
        $footer = new FormFooter('save', 'reset', '<b>Save</b>', '');
        $html   = $footer->toHtml();

        $this->assertStringContainsString('&lt;b&gt;Save&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Save</b>', $html);
    }

    public function testDefaults(): void
    {
        $footer = new FormFooter();

        $this->assertSame('submit', $footer->getSubmitName());
        $this->assertSame('cancel', $footer->getCancelName());
    }

    public function testRenderEchoesOutput(): void
    {
        $footer = new FormFooter('submit', 'cancel');

        ob_start();
        $footer->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<center>', $html);
        $this->assertStringContainsString('</center>', $html);
        $this->assertStringContainsString('name="submit"', $html);
        $this->assertStringContainsString('name="cancel"', $html);
    }
}
