<?php
/**
 * FaPaymentTermProvider — FA runtime implementation of PaymentTermProviderInterface.
 *
 * Uses FA's native db_* functions against 0_payment_terms. Never raw mysqli,
 * never PDO — consistent with AGENTS_ARCH.md §10 hard rule.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\PaymentTermProviderInterface;

class FaPaymentTermProvider implements PaymentTermProviderInterface
{
    /** @var string FA company table prefix (e.g. '0_') */
    private string $tablePrefix;

    public function __construct(string $tablePrefix = '0_')
    {
        $this->tablePrefix = $tablePrefix;
    }

    /** {@inheritDoc} */
    public function findAllActive(): array
    {
        $terms = [];
        $result = db_query(
            "SELECT terms_indicator, terms
             FROM {$this->tablePrefix}payment_terms
             WHERE inactive = 0
             ORDER BY terms_indicator"
        );
        if ($result !== false) {
            while (($row = db_fetch_assoc($result)) !== false) {
                $terms[] = [
                    'termsIndicator' => (int)$row['terms_indicator'],
                    'terms' => $row['terms'],
                ];
            }
        }
        return $terms;
    }

    /** {@inheritDoc} */
    public function findByIndicator(int $termsIndicator): ?array
    {
        $result = db_query(
            "SELECT terms_indicator, terms
             FROM {$this->tablePrefix}payment_terms
             WHERE terms_indicator = " . (int)$termsIndicator . "
               AND inactive = 0
             LIMIT 1"
        );
        if ($result !== false && ($row = db_fetch_assoc($result)) !== false) {
            return [
                'termsIndicator' => (int)$row['terms_indicator'],
                'terms' => $row['terms'],
            ];
        }
        return null;
    }
}
