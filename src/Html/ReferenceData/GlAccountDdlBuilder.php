<?php
/**
 * GlAccountDdlBuilder — produces FA array_selector option arrays from a
 * GlAccountProviderInterface.
 *
 * Single responsibility: turn reference data into DDL option arrays. No DB
 * access of its own.
 *
 * @package ksfraser\FrontAccounting\Common\Html\ReferenceData
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Html\ReferenceData;

use ksfraser\FrontAccounting\Common\ReferenceData\Contract\GlAccountProviderInterface;

/**
 * @template-implements \ArrayAccess<int, string>
 */
class GlAccountDdlBuilder
{
    /** @var GlAccountProviderInterface */
    private $provider;

    public function __construct(GlAccountProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Build an options array keyed by account code, suitable for
     * array_selector('gl_account', ...).
     *
     * @param string|null $selected Account code to pre-select
     *
     * @return array<string, string> ['code' => 'CODE - Name', ...]
     */
    public function build(?string $selected = null): array
    {
        $options = [];
        foreach ($this->provider->findAllActive() as $account) {
            $options[$account['accountCode']] =
                $account['accountCode'] . ' - ' . $account['accountName'];
        }
        return $options;
    }
}
