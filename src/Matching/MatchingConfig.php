<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * MatchingConfig — tunables for the generic matcher.
 *
 * Holds field-name mappings, scoring weights, and decision thresholds so the
 * same matcher can be reused by ISP, Bank Import, Square, or any other module
 * that stages external records against FA master data.
 *
 * Field mappings translate module-specific column names into the matcher's
 * canonical field names:
 *   person_id   → person id (0_crm_persons.id)
 *   email       → email address
 *   amount      → monetary value for amount scoring
 *   date        → transaction date for date scoring
 *   name        → customer/supplier name for name scoring
 *   phone       → phone number for phone scoring
 *   reference   → transaction reference/invoice no for reference scoring
 *   gl_account  → GL account code for account scoring
 *
 * Weights sum to 1.0 by default and control how much each factor contributes
 * to the overall 0.0–1.0 confidence score.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-001 configurable field mappings
 * @BABOK Related: FR-COM-MATCH-002 configurable scoring weights
 * @BABOK Related: FR-COM-MATCH-003 configurable auto-approve threshold
 * @BABOK Related: FR-COM-MATCH-004 configurable review threshold
 * @BABOK Related: FR-COM-MATCH-018 person_id filtering
 * @BABOK Related: FR-COM-MATCH-019 email filtering
 * @BABOK Related: FR-COM-MATCH-020 name filtering
 * @BABOK Related: FR-COM-MATCH-021 phone filtering
 * @BABOK Related: NFR-COM-MATCH-001 PHP 7.3 compatible
 */
class MatchingConfig
{
    /** Default scoring weights (sum to 1.0) */
    const DEFAULT_WEIGHT_PERSON_ID   = 0.25;
    const DEFAULT_WEIGHT_EMAIL       = 0.20;
    const DEFAULT_WEIGHT_AMOUNT      = 0.10;
    const DEFAULT_WEIGHT_DATE        = 0.05;
    const DEFAULT_WEIGHT_NAME        = 0.20;
    const DEFAULT_WEIGHT_PHONE       = 0.10;
    const DEFAULT_WEIGHT_REFERENCE   = 0.10;

    const DEFAULT_AUTO_APPROVE = 0.95;
    const DEFAULT_REVIEW       = 0.80;

    const DEFAULT_AMOUNT_TOLERANCE = 0.01;
    const DEFAULT_DATE_TOLERANCE_DAYS = 1;

    /** @var array Field name mapping: canonical => module-specific column */
    private $fieldMap = array(
        'person_id'  => 'person_id',
        'email'      => 'email',
        'amount'     => 'amount',
        'date'       => 'date',
        'name'       => 'name',
        'phone'      => 'phone',
        'reference'  => 'reference',
        'gl_account' => 'gl_account',
    );

    /** @var array Scoring weights: field => weight (0.0–1.0) */
    private $weights = array(
        'person_id'  => self::DEFAULT_WEIGHT_PERSON_ID,
        'email'      => self::DEFAULT_WEIGHT_EMAIL,
        'amount'     => self::DEFAULT_WEIGHT_AMOUNT,
        'date'       => self::DEFAULT_WEIGHT_DATE,
        'name'       => self::DEFAULT_WEIGHT_NAME,
        'phone'      => self::DEFAULT_WEIGHT_PHONE,
        'reference'  => self::DEFAULT_WEIGHT_REFERENCE,
    );

    /** @var float Auto-approve threshold (0.0–1.0) */
    private $autoApprove = self::DEFAULT_AUTO_APPROVE;

    /** @var float Review threshold (0.0–1.0) */
    private $review = self::DEFAULT_REVIEW;

    /** @var float Amount tolerance for "same amount" */
    private $amountTolerance = self::DEFAULT_AMOUNT_TOLERANCE;

    /** @var int Date tolerance in days */
    private $dateToleranceDays = self::DEFAULT_DATE_TOLERANCE_DAYS;

    /** @var bool Whether soundex name matching is enabled */
    private $nameSoundex = true;

    /**
     * @param array $overrides Optional key => value overrides
     * @since 3.0.0
     */
    public function __construct(array $overrides = array())
    {
        // Field map
        if (isset($overrides['field_map']) && is_array($overrides['field_map'])) {
            $this->fieldMap = array_merge($this->fieldMap, $overrides['field_map']);
        }

        // Weights
        if (isset($overrides['weights']) && is_array($overrides['weights'])) {
            $this->weights = array_merge($this->weights, $overrides['weights']);
        }

        // Thresholds
        $this->autoApprove = $this->clamp01(
            isset($overrides['auto_approve']) ? (float)$overrides['auto_approve'] : self::DEFAULT_AUTO_APPROVE
        );
        $this->review = $this->clamp01(
            isset($overrides['review']) ? (float)$overrides['review'] : self::DEFAULT_REVIEW
        );
        if ($this->review > $this->autoApprove) {
            $this->review = $this->autoApprove;
        }

        $this->amountTolerance = max(
            0.0,
            isset($overrides['amount_tolerance']) ? (float)$overrides['amount_tolerance'] : self::DEFAULT_AMOUNT_TOLERANCE
        );
        $this->dateToleranceDays = max(
            0,
            isset($overrides['date_tolerance_days']) ? (int)$overrides['date_tolerance_days'] : self::DEFAULT_DATE_TOLERANCE_DAYS
        );
        $this->nameSoundex = isset($overrides['name_soundex']) ? (bool)$overrides['name_soundex'] : true;
    }

    /** @return array Field name mapping */
    public function getFieldMap()
    {
        return $this->fieldMap;
    }

    /** @return string|null Field name for person_id, or null if unmapped */
    public function getPersonIdField()
    {
        return isset($this->fieldMap['person_id']) ? $this->fieldMap['person_id'] : null;
    }

    /** @return string|null Field name for email, or null if unmapped */
    public function getEmailField()
    {
        return isset($this->fieldMap['email']) ? $this->fieldMap['email'] : null;
    }

    /** @return string|null Field name for amount, or null if unmapped */
    public function getAmountField()
    {
        return isset($this->fieldMap['amount']) ? $this->fieldMap['amount'] : null;
    }

    /** @return string|null Field name for date, or null if unmapped */
    public function getDateField()
    {
        return isset($this->fieldMap['date']) ? $this->fieldMap['date'] : null;
    }

    /** @return string|null Field name for name, or null if unmapped */
    public function getNameField()
    {
        return isset($this->fieldMap['name']) ? $this->fieldMap['name'] : null;
    }

    /** @return string|null Field name for phone, or null if unmapped */
    public function getPhoneField()
    {
        return isset($this->fieldMap['phone']) ? $this->fieldMap['phone'] : null;
    }

    /** @return string|null Field name for reference, or null if unmapped */
    public function getReferenceField()
    {
        return isset($this->fieldMap['reference']) ? $this->fieldMap['reference'] : null;
    }

    /** @return string|null Field name for GL account, or null if unmapped */
    public function getGlAccountField()
    {
        return isset($this->fieldMap['gl_account']) ? $this->fieldMap['gl_account'] : null;
    }

    /** @return array Scoring weights */
    public function getWeights()
    {
        return $this->weights;
    }

    /** @return float Auto-approve threshold */
    public function getAutoApproveThreshold()
    {
        return $this->autoApprove;
    }

    /** @return float Review threshold */
    public function getReviewThreshold()
    {
        return $this->review;
    }

    /** @return float Amount tolerance */
    public function getAmountTolerance()
    {
        return $this->amountTolerance;
    }

    /** @return int Date tolerance in days */
    public function getDateToleranceDays()
    {
        return $this->dateToleranceDays;
    }

    /** @return bool Whether soundex name matching is enabled */
    public function usesNameSoundex()
    {
        return $this->nameSoundex;
    }

    /** @return bool Whether a confidence score should be auto-approved */
    public function shouldAutoApprove($confidence)
    {
        return (float)$confidence >= $this->autoApprove;
    }

    /** @return bool Whether a confidence score needs human review */
    public function needsReview($confidence)
    {
        $confidence = (float)$confidence;
        return $confidence >= $this->review && $confidence < $this->autoApprove;
    }

    /** @return array Serializable config */
    public function toArray()
    {
        return array(
            'field_map'          => $this->fieldMap,
            'weights'            => $this->weights,
            'auto_approve'       => $this->autoApprove,
            'review'             => $this->review,
            'amount_tolerance'   => $this->amountTolerance,
            'date_tolerance_days'=> $this->dateToleranceDays,
            'name_soundex'       => $this->nameSoundex,
        );
    }

    /**
     * @param float $value
     * @return float
     */
    private function clamp01($value)
    {
        if ($value < 0.0) {
            return 0.0;
        }
        return $value > 1.0 ? 1.0 : $value;
    }
}
