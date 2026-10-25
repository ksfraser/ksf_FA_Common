<?php
/**
 * PaymentTermProviderInterface — shared contract for looking up payment terms.
 *
 * NO FA coupling: names no FA function and no FA table.
 *
 * Implemented at FA runtime by FaPaymentTermProvider which calls FA's native
 * db_* functions against 0_payment_terms.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData\Contract
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData\Contract;

interface PaymentTermProviderInterface
{
    /**
     * Return all active payment terms, ordered by terms indicator.
     *
     * @return array<int, array{termsIndicator: int, terms: string}>
     */
    public function findAllActive(): array;

    /**
     * Return one payment term by its indicator.
     *
     * @param int $termsIndicator Payment terms indicator
     *
     * @return array{termsIndicator: int, terms: string}|null
     */
    public function findByIndicator(int $termsIndicator): ?array;
}
