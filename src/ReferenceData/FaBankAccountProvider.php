<?php
/**
 * FaBankAccountProvider — FA runtime implementation of BankAccountProviderInterface.
 *
 * Uses FA's native db_* functions against 0_bank_accounts. Never raw mysqli,
 * never PDO — consistent with AGENTS_ARCH.md §10 hard rule.
 *
 * @package ksfraser\FrontAccounting\Common\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\BankAccountProviderInterface;

class FaBankAccountProvider implements BankAccountProviderInterface
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
            "SELECT id, bank_account_name
             FROM {$this->tablePrefix}bank_accounts
             WHERE inactive = 0
             ORDER BY id"
        );
        if ($result !== false) {
            while (($row = db_fetch_assoc($result)) !== false) {
                $accounts[] = [
                    'id' => (int)$row['id'],
                    'bankAccountName' => $row['bank_account_name'],
                ];
            }
        }
        return $accounts;
    }

    /** {@inheritDoc} */
    public function findById(int $id): ?array
    {
        $result = db_query(
            "SELECT id, bank_account_name
             FROM {$this->tablePrefix}bank_accounts
             WHERE id = " . (int)$id . "
               AND inactive = 0
             LIMIT 1"
        );
        if ($result !== false && ($row = db_fetch_assoc($result)) !== false) {
            return [
                'id' => (int)$row['id'],
                'bankAccountName' => $row['bank_account_name'],
            ];
        }
        return null;
    }
}
