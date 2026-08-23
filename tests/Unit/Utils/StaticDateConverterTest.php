<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Tests\Unit\Utils;

use ksfraser\FrontAccounting\Common\Utils\StaticDateConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StaticDateConverter.
 *
 * @covers \ksfraser\FrontAccounting\Common\Utils\StaticDateConverter
 */
class StaticDateConverterTest extends TestCase
{
    // ----------------------------------------------------------------
    // toISO() — mdy input
    // ----------------------------------------------------------------

    public function testToIsoMdyDefault(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('2026-08-22', $converter->toISO('08/22/2026'));
    }

    public function testToIsoMdyJanuaryFirst(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('2026-01-01', $converter->toISO('01/01/2026'));
    }

    public function testToIsoMdyDecemberThirtyFirst(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('2025-12-31', $converter->toISO('12/31/2025'));
    }

    // ----------------------------------------------------------------
    // toISO() — dmy input
    // ----------------------------------------------------------------

    public function testToIsoDmy(): void
    {
        $converter = new StaticDateConverter('d/m/Y', '/', 'dmy');
        $this->assertSame('2026-08-22', $converter->toISO('22/08/2026'));
    }

    public function testToIsoDmyFirstJanuary(): void
    {
        $converter = new StaticDateConverter('d/m/Y', '/', 'dmy');
        $this->assertSame('2026-01-01', $converter->toISO('01/01/2026'));
    }

    // ----------------------------------------------------------------
    // toISO() — ymd input
    // ----------------------------------------------------------------

    public function testToIsoYmd(): void
    {
        $converter = new StaticDateConverter('Y-m-d', '-', 'ymd');
        $this->assertSame('2026-08-22', $converter->toISO('2026-08-22'));
    }

    public function testToIsoYmdWithSlash(): void
    {
        $converter = new StaticDateConverter('Y/m/d', '/', 'ymd');
        $this->assertSame('2026-08-22', $converter->toISO('2026/08/22'));
    }

    // ----------------------------------------------------------------
    // toISO() — edge cases
    // ----------------------------------------------------------------

    public function testToIsoEmptyString(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame('', $converter->toISO(''));
    }

    public function testToIsoWhitespaceString(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame('', $converter->toISO('   '));
    }

    public function testToIsoInvalidDateReturnsEmpty(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('', $converter->toISO('02/30/2026'));
    }

    public function testToIsoWrongSeparatorReturnsEmpty(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('', $converter->toISO('08-22-2026'));
    }

    public function testToIsoTooFewPartsReturnsEmpty(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('', $converter->toISO('08/22'));
    }

    public function testToIsoNonNumericReturnsEmpty(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('', $converter->toISO('ab/cd/ef'));
    }

    public function testToIsoLeapYearValid(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('2024-02-29', $converter->toISO('02/29/2024'));
    }

    public function testToIsoFeb29NonLeapYearReturnsEmpty(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('', $converter->toISO('02/29/2025'));
    }

    // ----------------------------------------------------------------
    // fromISO()
    // ----------------------------------------------------------------

    public function testFromIsoDefault(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertSame('08/22/2026', $converter->fromISO('2026-08-22'));
    }

    public function testFromIsoDmyFormat(): void
    {
        $converter = new StaticDateConverter('d/m/Y', '/', 'dmy');
        $this->assertSame('22/08/2026', $converter->fromISO('2026-08-22'));
    }

    public function testFromIsoYmdFormat(): void
    {
        $converter = new StaticDateConverter('Y-m-d', '-', 'ymd');
        $this->assertSame('2026-08-22', $converter->fromISO('2026-08-22'));
    }

    public function testFromIsoEmptyString(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame('', $converter->fromISO(''));
    }

    public function testFromIsoWhitespaceString(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame('', $converter->fromISO('   '));
    }

    public function testFromIsoInvalidFormatReturnsEmpty(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame('', $converter->fromISO('not-a-date'));
    }

    // ----------------------------------------------------------------
    // today()
    // ----------------------------------------------------------------

    public function testTodayReturnsYmdFormat(): void
    {
        $converter = new StaticDateConverter();
        $result = $converter->today();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
    }

    public function testTodayIsCurrentDate(): void
    {
        $converter = new StaticDateConverter();
        $this->assertSame(date('Y-m-d'), $converter->today());
    }

    // ----------------------------------------------------------------
    // isValid()
    // ----------------------------------------------------------------

    public function testIsValidTrue(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertTrue($converter->isValid('08/22/2026'));
    }

    public function testIsValidFalseEmpty(): void
    {
        $converter = new StaticDateConverter();
        $this->assertFalse($converter->isValid(''));
    }

    public function testIsValidFalseInvalidDate(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertFalse($converter->isValid('02/30/2026'));
    }

    public function testIsValidFalseWrongFormat(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $this->assertFalse($converter->isValid('2026-08-22'));
    }

    // ----------------------------------------------------------------
    // Round-trip: toISO → fromISO
    // ----------------------------------------------------------------

    public function testRoundTripMdy(): void
    {
        $converter = new StaticDateConverter('m/d/Y', '/', 'mdy');
        $iso = $converter->toISO('03/15/2026');
        $this->assertSame('03/15/2026', $converter->fromISO($iso));
    }

    public function testRoundTripDmy(): void
    {
        $converter = new StaticDateConverter('d/m/Y', '/', 'dmy');
        $iso = $converter->toISO('15/03/2026');
        $this->assertSame('15/03/2026', $converter->fromISO($iso));
    }

    public function testRoundTripYmd(): void
    {
        $converter = new StaticDateConverter('Y-m-d', '-', 'ymd');
        $iso = $converter->toISO('2026-03-15');
        $this->assertSame('2026-03-15', $converter->fromISO($iso));
    }

    // ----------------------------------------------------------------
    // Interface contract
    // ----------------------------------------------------------------

    public function testImplementsInterface(): void
    {
        $converter = new StaticDateConverter();
        $this->assertInstanceOf(
            \ksfraser\FrontAccounting\Common\Utils\DateConverterInterface::class,
            $converter
        );
    }
}
