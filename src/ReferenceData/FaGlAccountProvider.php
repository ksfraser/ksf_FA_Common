<?php
/**
 * FaGlAccountProvider — FA runtime implementation of GlAccountProviderInterface.
 *
 * Uses FA's native db_* functions against 0_chart_master. Never raw mysqli,
 * never PDO — consistent with AGENTS_ARCH.md §10 hard rule.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\GlAccountProviderInterface;

class FaGlAccountProvider implements GlAccountProviderInterface
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
        $accounts = [];
        $result = db_query(
            "SELECT account_code, account_name
             FROM {$this->tablePrefix}chart_master
             WHERE inactive = 0
             ORDER BY account_code"
        );
        if ($result !== false) {
            while (($row = db_fetch_assoc($result)) !== false) {
                $accounts[] = [
                    'accountCode' => $row['account_code'],
                    'accountName' => $row['account_name'],
                ];
            }
        }
        return $accounts;
    }

    /** {@inheritDoc} */
    public function findByCode(string $accountCode): ?array
    {
        $result = db_query(
            "SELECT account_code, account_name
             FROM {$this->tablePrefix}chart_master
             WHERE account_code = " . db_escape($accountCode) . "
               AND inactive = 0
             LIMIT 1"
        );
        if ($result !== false && ($row = db_fetch_assoc($result)) !== false) {
            return [
                'accountCode' => $row['account_code'],
                'accountName' => $row['account_name'],
            ];
        }
        return null;
    }
}
