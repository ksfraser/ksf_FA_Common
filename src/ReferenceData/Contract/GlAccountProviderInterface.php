<?php
/**
 * GlAccountProviderInterface — shared contract for looking up GL accounts.
 *
 * NO FA coupling: names no FA function and no FA table, so a non-FA
 * platform can implement it against its own chart of accounts.
 *
 * Implemented at FA runtime by FaGlAccountProvider which calls FA's native
 * db_* functions against 0_chart_master.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData\Contract
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData\Contract;

/**
 * @property-read string $accountCode GL account code
 * @property-read string $accountName GL account name
 */
interface GlAccountProviderInterface
{
    /**
     * Return all active GL accounts, ordered by account code.
     *
     * @return array<int, array{accountCode: string, accountName: string}>
     */
    public function findAllActive(): array;

    /**
     * Return one GL account by its code.
     *
     * @param string $accountCode GL account code
     *
     * @return array{accountCode: string, accountName: string}|null
     */
    public function findByCode(string $accountCode): ?array;
}
