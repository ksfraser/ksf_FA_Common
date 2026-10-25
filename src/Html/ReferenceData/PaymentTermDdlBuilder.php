<?php
/**
 * PaymentTermDdlBuilder — produces FA array_selector option arrays from a
 * PaymentTermProviderInterface.
 *
 * Single responsibility: turn reference data into DDL option arrays. No DB
 * access of its own.
 *
 * @package ksfraser\FrontAccounting\Common\Html\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Html\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\PaymentTermProviderInterface;

class PaymentTermDdlBuilder
{
    /** @var PaymentTermProviderInterface */
    private $provider;

    public function __construct(PaymentTermProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Build an options array keyed by terms indicator, suitable for
     * array_selector('payment_destination', ...).
     *
     * @param int|null $selected Terms indicator to pre-select
     *
     * @return array<int, string> [indicator => 'terms', ...]
     */
    public function build(?int $selected = null): array
    {
        $options = [0 => '-- Default --'];
        foreach ($this->provider->findAllActive() as $term) {
            $options[$term['termsIndicator']] = $term['terms'];
        }
        return $options;
    }
}
