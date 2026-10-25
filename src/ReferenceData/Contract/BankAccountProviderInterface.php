<?php
/**
 * BankAccountProviderInterface — shared contract for looking up bank accounts.
 *
 * NO FA coupling: names no FA function and no FA table.
 *
 * Implemented at FA runtime by FaBankAccountProvider which calls FA's native
 * db_* functions against 0_bank_accounts.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData\Contract
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData\Contract;

interface BankAccountProviderInterface
{
    /**
     * Return all active bank accounts, ordered by id.
     *
     * @return array<int, array{id: int, bankAccountName: string}>
     */
    public function findAllActive(): array;

    /**
     * Return one bank account by its id.
     *
     * @param int $id Bank account id
     *
     * @return array{id: int, bankAccountName: string}|null
     */
    public function findById(int $id): ?array;
}
