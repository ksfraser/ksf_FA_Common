<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * SearchCriteria — filters for SQL-side candidate pre-filtering.
 *
 * Encapsulates the search parameters that narrow the candidate set before
 * PHP-side scoring. Supports date ranges, amount bounds, GL account,
 * customer/vendor, reference fragment, transaction type, person_id,
 * email, name, and phone — mirroring FA's Journal Inquiry and Bank Import
 * filtering patterns.
 *
 * All fields are optional; unset fields impose no constraint.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-005 date range filtering
 * @BABOK Related: FR-COM-MATCH-006 amount min/max filtering
 * @BABOK Related: FR-COM-MATCH-007 GL account filtering
 * @BABOK Related: FR-COM-MATCH-008 customer/vendor filtering
 * @BABOK Related: FR-COM-MATCH-009 reference fragment filtering
 * @BABOK Related: FR-COM-MATCH-018 person_id filtering
 * @BABOK Related: FR-COM-MATCH-019 email filtering
 * @BABOK Related: FR-COM-MATCH-020 name filtering
 * @BABOK Related: FR-COM-MATCH-021 phone filtering
 */
class SearchCriteria
{
    /** @var string|null Earliest transaction date (Y-m-d) */
    private $dateFrom = null;

    /** @var string|null Latest transaction date (Y-m-d) */
    private $dateTo = null;

    /** @var float|null Minimum absolute amount */
    private $amountMin = null;

    /** @var float|null Maximum absolute amount */
    private $amountMax = null;

    /** @var int|null GL account code */
    private $glAccount = null;

    /** @var string|null Customer/debtor name fragment */
    private $customer = null;

    /** @var string|null Supplier name fragment */
    private $supplier = null;

    /** @var string|null Reference/invoice number fragment */
    private $reference = null;

    /** @var int|null Transaction type */
    private $transactionType = null;

    /** @var int|null Person id (0_crm_persons.id) */
    private $personId = null;

    /** @var string|null Email address fragment */
    private $email = null;

    /** @var string|null Person name fragment */
    private $name = null;

    /** @var string|null Phone number fragment */
    private $phone = null;

    /** @var string|null Additional custom WHERE clause fragment */
    private $customWhere = null;

    /**
     * @param array $criteria Optional key => value criteria
     * @since 3.0.0
     */
    public function __construct(array $criteria = array())
    {
        if (isset($criteria['date_from'])) {
            $this->dateFrom = (string)$criteria['date_from'];
        }
        if (isset($criteria['date_to'])) {
            $this->dateTo = (string)$criteria['date_to'];
        }
        if (isset($criteria['amount_min'])) {
            $this->amountMin = (float)$criteria['amount_min'];
        }
        if (isset($criteria['amount_max'])) {
            $this->amountMax = (float)$criteria['amount_max'];
        }
        if (isset($criteria['gl_account'])) {
            $this->glAccount = (int)$criteria['gl_account'];
        }
        if (isset($criteria['customer'])) {
            $this->customer = (string)$criteria['customer'];
        }
        if (isset($criteria['supplier'])) {
            $this->supplier = (string)$criteria['supplier'];
        }
        if (isset($criteria['reference'])) {
            $this->reference = (string)$criteria['reference'];
        }
        if (isset($criteria['transaction_type'])) {
            $this->transactionType = (int)$criteria['transaction_type'];
        }
        if (isset($criteria['person_id'])) {
            $this->personId = (int)$criteria['person_id'];
        }
        if (isset($criteria['email'])) {
            $this->email = (string)$criteria['email'];
        }
        if (isset($criteria['name'])) {
            $this->name = (string)$criteria['name'];
        }
        if (isset($criteria['phone'])) {
            $this->phone = (string)$criteria['phone'];
        }
        if (isset($criteria['custom_where'])) {
            $this->customWhere = (string)$criteria['custom_where'];
        }
    }

    /** @return string|null */
    public function getDateFrom()
    {
        return $this->dateFrom;
    }

    /** @return string|null */
    public function getDateTo()
    {
        return $this->dateTo;
    }

    /** @return float|null */
    public function getAmountMin()
    {
        return $this->amountMin;
    }

    /** @return float|null */
    public function getAmountMax()
    {
        return $this->amountMax;
    }

    /** @return int|null */
    public function getGlAccount()
    {
        return $this->glAccount;
    }

    /** @return string|null */
    public function getCustomer()
    {
        return $this->customer;
    }

    /** @return string|null */
    public function getSupplier()
    {
        return $this->supplier;
    }

    /** @return string|null */
    public function getReference()
    {
        return $this->reference;
    }

    /** @return int|null */
    public function getTransactionType()
    {
        return $this->transactionType;
    }

    /** @return int|null Person id (0_crm_persons.id) */
    public function getPersonId()
    {
        return $this->personId;
    }

    /** @return string|null */
    public function getEmail()
    {
        return $this->email;
    }

    /** @return string|null */
    public function getName()
    {
        return $this->name;
    }

    /** @return string|null */
    public function getPhone()
    {
        return $this->phone;
    }

    /** @return string|null */
    public function getCustomWhere()
    {
        return $this->customWhere;
    }

    /** @return bool Whether any filter is set */
    public function hasFilters()
    {
        return $this->dateFrom !== null
            || $this->dateTo !== null
            || $this->amountMin !== null
            || $this->amountMax !== null
            || $this->glAccount !== null
            || $this->customer !== null
            || $this->supplier !== null
            || $this->reference !== null
            || $this->transactionType !== null
            || $this->personId !== null
            || $this->email !== null
            || $this->name !== null
            || $this->phone !== null
            || $this->customWhere !== null;
    }

    /** @return array Serializable criteria */
    public function toArray()
    {
        return array_filter(array(
            'date_from'        => $this->dateFrom,
            'date_to'          => $this->dateTo,
            'amount_min'       => $this->amountMin,
            'amount_max'       => $this->amountMax,
            'gl_account'       => $this->glAccount,
            'customer'         => $this->customer,
            'supplier'         => $this->supplier,
            'reference'        => $this->reference,
            'transaction_type' => $this->transactionType,
            'person_id'        => $this->personId,
            'email'            => $this->email,
            'name'             => $this->name,
            'phone'            => $this->phone,
            'custom_where'     => $this->customWhere,
        ), function($v) { return $v !== null; });
    }
}
