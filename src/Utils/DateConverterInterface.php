<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Utils;

/**
 * Date format conversion contract.
 *
 * SRP: Converts date strings between the user's display format
 * (configured per-installation) and ISO Y-m-d. Implementations are
 * responsible for knowing the active display format; consumers depend
 * only on this interface.
 *
 * @since 1.2.0
 */
interface DateConverterInterface
{
    /**
     * Convert a date string from user display format to ISO Y-m-d.
     *
     * @param string $date Date in user's display format (e.g. "08/22/2026")
     * @return string Date in Y-m-d format (e.g. "2026-08-22"), or "" if empty input
     */
    public function toISO(string $date): string;

    /**
     * Convert a date string from ISO Y-m-d to user display format.
     *
     * @param string $isoDate Date in Y-m-d format (e.g. "2026-08-22")
     * @return string Date in user's display format, or "" if empty input
     */
    public function fromISO(string $isoDate): string;

    /**
     * Return today's date in ISO Y-m-d format.
     *
     * @return string Today in Y-m-d format
     */
    public function today(): string;

    /**
     * Validate that a string is a parseable date in the user's display format.
     *
     * @param string $date Date string in user's display format
     * @return bool True if the date is valid and parseable
     */
    public function isValid(string $date): bool;
}
