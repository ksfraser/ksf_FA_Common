<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * FrontAccounting date format converter.
 *
 * Wraps FA's date2sql() / sql2date() / Today() globals behind a
 * clean interface so consuming modules can DI the converter instead
 * of calling FA date functions directly.
 *
 * @since 1.2.0
 */
class FADateConverter implements DateConverterInterface
{
    /**
     * Convert user-format date to ISO Y-m-d.
     *
     * Delegates to FA's date2sql() global function which respects the
     * installed date_format and date_sep preferences.
     */
    public function toISO(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        return \date2sql($date);
    }

    /**
     * Convert ISO Y-m-d to user-format date.
     *
     * Delegates to FA's sql2date() global function.
     */
    public function fromISO(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return '';
        }

        return \sql2date($isoDate);
    }

    /**
     * Return today in ISO Y-m-d format.
     *
     * Delegates to FA's Today() global function.
     */
    public function today(): string
    {
        return \Today();
    }

    /**
     * Validate a date string in user display format.
     *
     * Attempts conversion via date2sql() and checks the result is a
     * well-formed Y-m-d string with a valid calendar date.
     */
    public function isValid(string $date): bool
    {
        $date = trim($date);
        if ($date === '') {
            return false;
        }

        $iso = $this->toISO($date);
        if ($iso === '') {
            return false;
        }

        // Verify it's a valid date (Y-m-d with real calendar values)
        $parts = explode('-', $iso);
        if (count($parts) !== 3) {
            return false;
        }

        $year  = (int)$parts[0];
        $month = (int)$parts[1];
        $day   = (int)$parts[2];

        return checkdate($month, $day, $year);
    }
}
