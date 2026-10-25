<?php
/**
 * BankAccountDdlBuilder — produces FA array_selector option arrays from a
 * BankAccountProviderInterface.
 *
 * Single responsibility: turn reference data into DDL option arrays. No DB
 * access of its own.
 *
 * @package ksfraser\FrontAccounting\Common\Html\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Html\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\BankAccountProviderInterface;

class BankAccountDdlBuilder
{
    /** @var BankAccountProviderInterface */
    private $provider;

    public function __construct(BankAccountProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Build an options array keyed by bank account id, suitable for
     * array_selector('bank_account', ...).
     *
     * @param int|null $selected Bank account id to pre-select
     *
     * @return array<int, string> [id => 'id - Name', ...]
     */
    public function build(?int $selected = null): array
    {
        $options = [];
        foreach ($this->provider->findAllActive() as $account) {
            $options[$account['id']] =
                $account['id'] . ' - ' . $account['bankAccountName'];
        }
        return $options;
    }
}
