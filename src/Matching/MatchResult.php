<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * MatchResult — the overall result of matching a staged record against
 * candidate existing records.
 *
 * Contains the best candidate, all candidates sorted by score, the overall
 * confidence, match type, and per-field scoring details.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-011 match result structure
 * @BABOK Related: FR-COM-MATCH-012 auto-approve and review classification
 */
class MatchResult
{
    /** @var MatchCandidate|null Best candidate, or null if no candidates */
    private $bestCandidate = null;

    /** @var MatchCandidate[] All candidates sorted by confidence descending */
    private $candidates = array();

    /** @var float Overall confidence (0.0–1.0) */
    private $confidence = 0.0;

    /** @var string Match type: 'exact', 'fuzzy', 'partial', 'unmatched' */
    private $matchType = 'unmatched';

    /** @var array Per-field aggregate score details */
    private $fieldDetails = array();

    /** @var string Human-readable match explanation */
    private $details = '';

    /** @var int Total candidates considered */
    private $totalCandidates = 0;

    /**
     * @param MatchCandidate|null $bestCandidate
     * @param MatchCandidate[]    $candidates
     * @param array               $fieldDetails
     * @param string              $details
     * @since 3.0.0
     */
    public function __construct(
        MatchCandidate $bestCandidate = null,
        array $candidates = array(),
        array $fieldDetails = array(),
        $details = ''
    ) {
        $this->bestCandidate = $bestCandidate;
        $this->candidates    = $candidates;
        $this->fieldDetails  = $fieldDetails;
        $this->details       = (string)$details;
        $this->totalCandidates = count($candidates);

        if ($bestCandidate !== null) {
            $this->confidence  = $bestCandidate->getConfidence();
            $this->matchType   = $bestCandidate->getMatchType();
        }
    }

    /** @return MatchCandidate|null */
    public function getBestCandidate()
    {
        return $this->bestCandidate;
    }

    /** @return MatchCandidate[] */
    public function getCandidates()
    {
        return $this->candidates;
    }

    /** @return float */
    public function getConfidence()
    {
        return $this->confidence;
    }

    /** @return string */
    public function getMatchType()
    {
        return $this->matchType;
    }

    /** @return array */
    public function getFieldDetails()
    {
        return $this->fieldDetails;
    }

    /** @return string */
    public function getDetails()
    {
        return $this->details;
    }

    /** @return int */
    public function getTotalCandidates()
    {
        return $this->totalCandidates;
    }

    /** @return int Number of candidates returned */
    public function getCandidateCount()
    {
        return count($this->candidates);
    }

    /** @return bool Whether any candidates were found */
    public function hasCandidates()
    {
        return count($this->candidates) > 0;
    }

    /** @return bool Whether the best candidate qualifies for auto-approval */
    public function isAutoApprove($autoApproveThreshold)
    {
        if ($this->bestCandidate === null) {
            return false;
        }
        return $this->bestCandidate->isAutoApprove($autoApproveThreshold);
    }

    /** @return bool Whether the best candidate needs manual review */
    public function needsReview($reviewThreshold, $autoApproveThreshold)
    {
        if ($this->bestCandidate === null) {
            return false;
        }
        return $this->bestCandidate->needsReview($reviewThreshold, $autoApproveThreshold);
    }

    /** @return array Serializable result */
    public function toArray()
    {
        return array(
            'best_candidate' => $this->bestCandidate !== null ? $this->bestCandidate->toArray() : null,
            'candidates'     => array_map(function ($c) { return $c->toArray(); }, $this->candidates),
            'confidence'     => $this->confidence,
            'match_type'     => $this->matchType,
            'field_details'  => $this->fieldDetails,
            'details'        => $this->details,
            'total_candidates' => $this->totalCandidates,
            'candidate_count' => count($this->candidates),
        );
    }
}
