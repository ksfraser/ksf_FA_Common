<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * Static date converter for testing and non-FA contexts.
 *
 * Accepts explicit format configuration instead of reading from
 * FA globals. Useful for unit tests and CLI tools that need date
 * conversion without a running FA installation.
 *
 * Format tokens follow PHP date() conventions:
 *   Y = 4-digit year, m = month (01-12), d = day (01-31)
 *   n = month (1-12), j = day (1-31)
 *
 * @since 1.2.0
 */
class StaticDateConverter implements DateConverterInterface
{
    /** @var string PHP date format string for display output */
    private string $displayFormat;

    /** @var string Date separator for parsing input */
    private string $separator;

    /** @var string Format hint for parsing input (e.g. 'mdy', 'dmy', 'ymd') */
    private string $inputOrder;

    /**
     * @param string $displayFormat PHP date() format for fromISO() output
     * @param string $separator     Separator used in input dates (e.g. '/', '-')
     * @param string $inputOrder    Order of date parts: 'mdy', 'dmy', or 'ymd'
     */
    public function __construct(
        string $displayFormat = 'm/d/Y',
        string $separator = '/',
        string $inputOrder = 'mdy'
    ) {
        $this->displayFormat = $displayFormat;
        $this->separator = $separator;
        $this->inputOrder = $inputOrder;
    }

    /**
     * Convert display-format date to ISO Y-m-d.
     */
    public function toISO(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $parts = explode($this->separator, $date);
        if (count($parts) !== 3) {
            return '';
        }

        switch ($this->inputOrder) {
            case 'mdy':
                $month = (int)$parts[0];
                $day   = (int)$parts[1];
                $year  = (int)$parts[2];
                break;
            case 'dmy':
                $day   = (int)$parts[0];
                $month = (int)$parts[1];
                $year  = (int)$parts[2];
                break;
            case 'ymd':
                $year  = (int)$parts[0];
                $month = (int)$parts[1];
                $day   = (int)$parts[2];
                break;
            default:
                return '';
        }

        if (!checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Convert ISO Y-m-d to display format.
     */
    public function fromISO(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
            return '';
        }

        $ts = strtotime($isoDate);
        if ($ts === false) {
            return '';
        }

        return date($this->displayFormat, $ts);
    }

    /**
     * Return today in ISO Y-m-d format.
     */
    public function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Validate a date string in the configured display format.
     */
    public function isValid(string $date): bool
    {
        $date = trim($date);
        if ($date === '') {
            return false;
        }

        $iso = $this->toISO($date);
        return $iso !== '';
    }
}
