<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * MatchCandidate — a single candidate record with its match score and
 * per-field breakdown.
 *
 * The `record` array holds the raw candidate data (from SQL or in-memory).
 * The `fieldScores` array maps canonical field names to their individual
 * 0.0–1.0 scores. The `confidence` is the weighted overall score.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-010 candidate scoring breakdown
 */
class MatchCandidate
{
    /** @var array Raw candidate record data */
    private $record;

    /** @var float Overall confidence score (0.0–1.0) */
    private $confidence;

    /** @var array Per-field scores: canonical field => 0.0–1.0 */
    private $fieldScores = array();

    /** @var array Per-field details (human-readable explanations) */
    private $fieldDetails = array();

    /** @var string Match type: 'exact', 'fuzzy', 'partial', 'unmatched' */
    private $matchType = 'unmatched';

    /**
     * @param array   $record       Candidate record data
     * @param float   $confidence   Overall confidence (0.0–1.0)
     * @param array   $fieldScores  Per-field scores
     * @param array   $fieldDetails Per-field details
     * @param string  $matchType    Match type string
     * @since 3.0.0
     */
    public function __construct(array $record, $confidence, array $fieldScores = array(), array $fieldDetails = array(), $matchType = 'unmatched')
    {
        $this->record       = $record;
        $this->confidence   = (float)$confidence;
        $this->fieldScores  = $fieldScores;
        $this->fieldDetails = $fieldDetails;
        $this->matchType    = (string)$matchType;
    }

    /** @return array */
    public function getRecord()
    {
        return $this->record;
    }

    /** @return float */
    public function getConfidence()
    {
        return $this->confidence;
    }

    /** @return array */
    public function getFieldScores()
    {
        return $this->fieldScores;
    }

    /** @return array */
    public function getFieldDetails()
    {
        return $this->fieldDetails;
    }

    /** @return string */
    public function getMatchType()
    {
        return $this->matchType;
    }

    /** @return bool Whether this candidate qualifies for auto-approval */
    public function isAutoApprove($autoApproveThreshold)
    {
        return $this->confidence >= (float)$autoApproveThreshold;
    }

    /** @return bool Whether this candidate needs manual review */
    public function needsReview($reviewThreshold, $autoApproveThreshold)
    {
        $c = $this->confidence;
        return $c >= (float)$reviewThreshold && $c < (float)$autoApproveThreshold;
    }

    /** @return array Serializable candidate */
    public function toArray()
    {
        return array(
            'record'        => $this->record,
            'confidence'    => $this->confidence,
            'field_scores'  => $this->fieldScores,
            'field_details' => $this->fieldDetails,
            'match_type'    => $this->matchType,
        );
    }
}
