<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * GenericMatcher — PHP-side scoring engine for candidate matching.
 *
 * Takes a staged record and an array of candidate records, applies configurable
 * field-name mappings and scoring weights, and returns a MatchResult with the
 * best candidate and full candidate list sorted by confidence.
 *
 * The matcher is transport-agnostic: it works on in-memory PHP arrays. Callers
 * are responsible for fetching candidates (via SqlPreFilter or their own SQL)
 * and passing them in. This keeps the matcher testable without a database and
 * keeps FA runtime free of PDO (db_* calls only).
 *
 * Supported scoring factors (configurable weights):
 *   person_id   — exact match on 0_crm_persons.id (strongest signal)
 *   email       — exact case-insensitive email match
 *   name        — customer/supplier name match via soundex + levenshtein ratio
 *   phone       — phone match (exact or last-7-digit)
 *   amount      — monetary amount match, tolerance-based with proportional decay
 *   date        — transaction date match, day-tolerance-based with decay
 *   reference   — transaction reference match via exact/substring similarity
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-013 amount scoring
 * @BABOK Related: FR-COM-MATCH-014 date scoring
 * @BABOK Related: FR-COM-MATCH-015 name scoring
 * @BABOK Related: FR-COM-MATCH-016 reference scoring
 * @BABOK Related: FR-COM-MATCH-022 person_id scoring
 * @BABOK Related: FR-COM-MATCH-023 email scoring
 * @BABOK Related: FR-COM-MATCH-024 phone scoring
 * @BABOK Related: NFR-COM-MATCH-002 weighted scoring engine
 */
class GenericMatcher
{
    /** @var MatchingConfig */
    private $config;

    /**
     * @param MatchingConfig $config
     * @since 3.0.0
     */
    public function __construct(MatchingConfig $config = null)
    {
        $this->config = $config !== null ? $config : new MatchingConfig();
    }

    /**
     * Match a staged record against candidate existing records.
     *
     * Each candidate is scored on the enabled factors (person_id, email, name,
     * phone, amount, date, reference). Candidates are returned sorted by
     * confidence descending.
     *
     * @param array $stagedRecord   The staged record (module-specific field names)
     * @param array $candidates     Candidate records (module-specific field names)
     * @return MatchResult
     * @since 3.0.0
     */
    public function match(array $stagedRecord, array $candidates)
    {
        $fieldMap = $this->config->getFieldMap();
        $weights  = $this->config->getWeights();

        $personIdField  = $this->config->getPersonIdField();
        $emailField     = $this->config->getEmailField();
        $amountField    = $this->config->getAmountField();
        $dateField      = $this->config->getDateField();
        $nameField      = $this->config->getNameField();
        $phoneField     = $this->config->getPhoneField();
        $referenceField = $this->config->getReferenceField();

        $candidateObjects = array();

        foreach ($candidates as $candidateRecord) {
            $fieldScores   = array();
            $fieldDetails  = array();
            $weightedScore = 0.0;
            $totalWeight   = 0.0;

            // Person id scoring — exact match on 0_crm_persons.id
            if ($personIdField !== null && isset($weights['person_id']) && $weights['person_id'] > 0) {
                $stagedVal = isset($stagedRecord[$personIdField]) ? $stagedRecord[$personIdField] : null;
                $candVal   = isset($candidateRecord[$personIdField]) ? $candidateRecord[$personIdField] : null;
                if ($stagedVal !== null && $candVal !== null) {
                    $score = $this->scorePersonId((int)$stagedVal, (int)$candVal);
                    $fieldScores['person_id'] = $score;
                    $fieldDetails['person_id'] = $this->personIdDetail((int)$stagedVal, (int)$candVal, $score);
                    $weightedScore += $score * $weights['person_id'];
                    $totalWeight += $weights['person_id'];
                }
            }

            // Email scoring — exact case-insensitive match
            if ($emailField !== null && isset($weights['email']) && $weights['email'] > 0) {
                $stagedVal = isset($stagedRecord[$emailField]) ? $stagedRecord[$emailField] : null;
                $candVal   = isset($candidateRecord[$emailField]) ? $candidateRecord[$emailField] : null;
                if ($stagedVal !== null && $candVal !== null && (string)$stagedVal !== '' && (string)$candVal !== '') {
                    $score = $this->scoreEmail((string)$stagedVal, (string)$candVal);
                    $fieldScores['email'] = $score;
                    $fieldDetails['email'] = $this->emailDetail((string)$stagedVal, (string)$candVal, $score);
                    $weightedScore += $score * $weights['email'];
                    $totalWeight += $weights['email'];
                }
            }

            // Amount scoring
            if ($amountField !== null && isset($weights['amount']) && $weights['amount'] > 0) {
                $stagedVal    = isset($stagedRecord[$amountField]) ? $stagedRecord[$amountField] : null;
                $candVal      = isset($candidateRecord[$amountField]) ? $candidateRecord[$amountField] : null;
                if ($stagedVal !== null && $candVal !== null) {
                    $score = $this->scoreAmount((float)$stagedVal, (float)$candVal);
                    $fieldScores['amount'] = $score;
                    $fieldDetails['amount'] = $this->amountDetail((float)$stagedVal, (float)$candVal, $score);
                    $weightedScore += $score * $weights['amount'];
                    $totalWeight += $weights['amount'];
                }
            }

            // Date scoring
            if ($dateField !== null && isset($weights['date']) && $weights['date'] > 0) {
                $stagedVal = isset($stagedRecord[$dateField]) ? $stagedRecord[$dateField] : null;
                $candVal   = isset($candidateRecord[$dateField]) ? $candidateRecord[$dateField] : null;
                if ($stagedVal !== null && $candVal !== null) {
                    $score = $this->scoreDate((string)$stagedVal, (string)$candVal);
                    $fieldScores['date'] = $score;
                    $fieldDetails['date'] = $this->dateDetail((string)$stagedVal, (string)$candVal, $score);
                    $weightedScore += $score * $weights['date'];
                    $totalWeight += $weights['date'];
                }
            }

            // Name scoring
            if ($nameField !== null && isset($weights['name']) && $weights['name'] > 0) {
                $stagedVal = isset($stagedRecord[$nameField]) ? $stagedRecord[$nameField] : null;
                $candVal   = isset($candidateRecord[$nameField]) ? $candidateRecord[$nameField] : null;
                if ($stagedVal !== null && $candVal !== null && (string)$stagedVal !== '' && (string)$candVal !== '') {
                    $score = $this->scoreName((string)$stagedVal, (string)$candVal);
                    $fieldScores['name'] = $score;
                    $fieldDetails['name'] = $this->nameDetail((string)$stagedVal, (string)$candVal, $score);
                    $weightedScore += $score * $weights['name'];
                    $totalWeight += $weights['name'];
                }
            }

            // Phone scoring
            if ($phoneField !== null && isset($weights['phone']) && $weights['phone'] > 0) {
                $stagedVal = isset($stagedRecord[$phoneField]) ? $stagedRecord[$phoneField] : null;
                $candVal   = isset($candidateRecord[$phoneField]) ? $candidateRecord[$phoneField] : null;
                if ($stagedVal !== null && $candVal !== null && (string)$stagedVal !== '' && (string)$candVal !== '') {
                    $score = $this->scorePhone((string)$stagedVal, (string)$candVal);
                    $fieldScores['phone'] = $score;
                    $fieldDetails['phone'] = $this->phoneDetail((string)$stagedVal, (string)$candVal, $score);
                    $weightedScore += $score * $weights['phone'];
                    $totalWeight += $weights['phone'];
                }
            }

            // Reference scoring
            if ($referenceField !== null && isset($weights['reference']) && $weights['reference'] > 0) {
                $stagedVal = isset($stagedRecord[$referenceField]) ? $stagedRecord[$referenceField] : null;
                $candVal   = isset($candidateRecord[$referenceField]) ? $candidateRecord[$referenceField] : null;
                if ($stagedVal !== null && $candVal !== null && (string)$stagedVal !== '' && (string)$candVal !== '') {
                    $score = $this->scoreReference((string)$stagedVal, (string)$candVal);
                    $fieldScores['reference'] = $score;
                    $fieldDetails['reference'] = $this->referenceDetail((string)$stagedVal, (string)$candVal, $score);
                    $weightedScore += $score * $weights['reference'];
                    $totalWeight += $weights['reference'];
                }
            }

            $confidence = $totalWeight > 0.0 ? $weightedScore / $totalWeight : 0.0;
            $matchType  = $this->determineMatchType($confidence);

            $candidateObjects[] = new MatchCandidate(
                $candidateRecord,
                $confidence,
                $fieldScores,
                $fieldDetails,
                $matchType
            );
        }

        // Sort by confidence descending
        usort($candidateObjects, function ($a, $b) {
            return $b->getConfidence() <=> $a->getConfidence();
        });

        $bestCandidate = count($candidateObjects) > 0 ? $candidateObjects[0] : null;

        $result = new MatchResult(
            $bestCandidate,
            $candidateObjects,
            array('weights' => $this->config->getWeights()),
            sprintf('Matched %d candidate(s), best confidence %.2f', count($candidateObjects), $confidence)
        );

        return $result;
    }

    /**
     * Score two person ids, 0.0–1.0.
     *
     * Exact match scores 1.0, otherwise 0.0.
     *
     * @param int $a
     * @param int $b
     * @return float
     * @since 3.0.0
     */
    public function scorePersonId($a, $b)
    {
        return (int)$a === (int)$b ? 1.0 : 0.0;
    }

    /**
     * Score two email addresses, 0.0–1.0.
     *
     * Exact case-insensitive match scores 1.0, otherwise 0.0.
     *
     * @param string $a
     * @param string $b
     * @return float
     * @since 3.0.0
     */
    public function scoreEmail($a, $b)
    {
        return strcasecmp(trim((string)$a), trim((string)$b)) === 0 ? 1.0 : 0.0;
    }

    /**
     * Score two phone numbers, 0.0–1.0.
     *
     * Exact match scores 1.0, last-7-digit match scores 0.7, otherwise 0.0.
     *
     * @param string $a
     * @param string $b
     * @return float
     * @since 3.0.0
     */
    public function scorePhone($a, $b)
    {
        $cleanA = preg_replace('/[^0-9]/', '', (string)$a);
        $cleanB = preg_replace('/[^0-9]/', '', (string)$b);
        if ($cleanA === '' || $cleanB === '') {
            return 0.0;
        }
        if ($cleanA === $cleanB) {
            return 1.0;
        }
        if (strlen($cleanA) >= 7 && strlen($cleanB) >= 7 && substr($cleanA, -7) === substr($cleanB, -7)) {
            return 0.7;
        }
        return 0.0;
    }

    /**
     * Score two monetary amounts, 0.0–1.0.
     *
     * Exact match (within amount_tolerance) scores 1.0. Beyond tolerance,
     * score decays proportionally based on relative difference.
     *
     * @param float $a
     * @param float $b
     * @return float
     * @since 3.0.0
     */
    public function scoreAmount($a, $b)
    {
        $tolerance = $this->config->getAmountTolerance();

        if ($a == 0.0 && $b == 0.0) {
            return 1.0;
        }

        $diff = abs($a - $b);
        $max  = max(abs($a), abs($b));

        if ($max == 0.0) {
            return 1.0;
        }

        if ($diff <= $tolerance) {
            return 1.0;
        }

        $relative = $diff / $max;
        return max(0.0, 1.0 - $relative);
    }

    /**
     * Score two dates, 0.0–1.0.
     *
     * Exact match (within date_tolerance_days) scores 1.0. Beyond tolerance,
     * score decays proportionally based on day difference.
     *
     * @param string $dateA 'Y-m-d'
     * @param string $dateB 'Y-m-d'
     * @return float
     * @since 3.0.0
     */
    public function scoreDate($dateA, $dateB)
    {
        $toleranceDays = $this->config->getDateToleranceDays();

        $timeA = strtotime((string)$dateA);
        $timeB = strtotime((string)$dateB);

        if ($timeA === false || $timeB === false) {
            return 0.0;
        }

        $diffDays = abs($timeA - $timeB) / 86400.0;

        if ($diffDays <= $toleranceDays) {
            return 1.0;
        }

        // Decay beyond tolerance: 1 day beyond = ~0.9, 7 days beyond = ~0.3
        $decay = $diffDays - $toleranceDays;
        return max(0.0, 1.0 - ($decay / 7.0));
    }

    /**
     * Score two names, 0.0–1.0.
     *
     * Combines soundex equality (lifts to at least 0.85 on collision) with
     * character-level levenshtein similarity ratio.
     *
     * @param string $nameA
     * @param string $nameB
     * @return float
     * @since 3.0.0
     */
    public function scoreName($nameA, $nameB)
    {
        $nameA = $this->normaliseName((string)$nameA);
        $nameB = $this->normaliseName((string)$nameB);

        if ($nameA === '' || $nameB === '') {
            return 0.0;
        }

        if ($nameA === $nameB) {
            return 1.0;
        }

        $ratio = $this->levenshteinRatio($nameA, $nameB);

        if ($this->config->usesNameSoundex() && $this->soundexMatches($nameA, $nameB)) {
            return max($ratio, 0.85);
        }

        return $ratio;
    }

    /**
     * Score two reference strings, 0.0–1.0.
     *
     * Exact match scores 1.0. Substring containment scores 0.7.
     * Otherwise falls back to levenshtein ratio.
     *
     * @param string $refA
     * @param string $refB
     * @return float
     * @since 3.0.0
     */
    public function scoreReference($refA, $refB)
    {
        $refA = strtolower(trim((string)$refA));
        $refB = strtolower(trim((string)$refB));

        if ($refA === '' || $refB === '') {
            return 0.0;
        }

        if ($refA === $refB) {
            return 1.0;
        }

        if (strpos($refA, $refB) !== false || strpos($refB, $refA) !== false) {
            return 0.7;
        }

        return $this->levenshteinRatio($refA, $refB);
    }

    /**
     * Determine match type from confidence score.
     *
     * @param float $confidence
     * @return string 'exact', 'fuzzy', 'partial', or 'unmatched'
     * @since 3.0.0
     */
    public function determineMatchType($confidence)
    {
        $c = (float)$confidence;
        if ($c >= $this->config->getAutoApproveThreshold()) {
            return 'exact';
        }
        if ($c >= $this->config->getReviewThreshold()) {
            return 'fuzzy';
        }
        if ($c > 0.0) {
            return 'partial';
        }
        return 'unmatched';
    }

    /**
     * Determine whether a confidence score qualifies for auto-approval.
     *
     * @param float $confidence Match confidence (0.0–1.0)
     * @return bool
     * @since 3.0.0
     */
    public function autoApprove($confidence)
    {
        return $this->config->shouldAutoApprove($confidence);
    }

    /**
     * Determine whether a confidence score needs manual review.
     *
     * @param float $confidence Match confidence (0.0–1.0)
     * @return bool
     * @since 3.0.0
     */
    public function needsReview($confidence)
    {
        return $this->config->needsReview($confidence);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Normalise a name for comparison: lowercase, collapse whitespace,
     * keep letters, digits, and common separators.
     *
     * @param string $value
     * @return string
     */
    private function normaliseName($value)
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9\s\-\'\&]/', ' ', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Levenshtein-based similarity ratio, 0.0–1.0.
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    private function levenshteinRatio($a, $b)
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA < $lenB) {
            $tmp = $a; $a = $b; $b = $tmp;
            $tmp = $lenA; $lenA = $lenB; $lenB = $tmp;
        }

        $previous = range(0, $lenB);
        for ($i = 1; $i <= $lenA; $i++) {
            $current = array($i);
            for ($j = 1; $j <= $lenB; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost
                );
            }
            $previous = $current;
        }

        $distance = $previous[$lenB];
        return max(0.0, ($lenA - $distance) / $lenA);
    }

    /**
     * Classic Russell soundex code.
     *
     * @param string $value
     * @return string 4-character code, or '' if uncodeable
     */
    private function soundex($value)
    {
        $value = preg_replace('/[^a-z ]+/', '', $this->normaliseName($value));
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $previous = $this->soundexCodeOf($first);

        $digits = '';
        $length = strlen($value);
        for ($i = 1; $i < $length && strlen($digits) < 3; $i++) {
            $char = $value[$i];
            $code = $this->soundexCodeOf($char);

            if ($char === 'h' || $char === 'w') {
                continue;
            }

            if ($code === '') {
                $previous = $code;
                continue;
            }

            if ($code !== $previous) {
                $digits .= $code;
            }
            $previous = $code;
        }

        return strtoupper($first) . str_pad($digits, 3, '0');
    }

    /**
     * Whether two names share a soundex code.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function soundexMatches($a, $b)
    {
        $codeA = $this->soundex($a);
        $codeB = $this->soundex($b);
        if ($codeA === '' || $codeB === '') {
            return false;
        }
        return $codeA === $codeB;
    }

    /**
     * Soundex digit for a letter.
     *
     * @param string $char
     * @return string
     */
    private function soundexCodeOf($char)
    {
        static $map = null;
        if ($map === null) {
            $map = array(
                'b' => '1', 'f' => '1', 'p' => '1', 'v' => '1',
                'c' => '2', 'g' => '2', 'j' => '2', 'k' => '2', 'q' => '2', 's' => '2', 'x' => '2', 'z' => '2',
                'd' => '3', 't' => '3',
                'l' => '4',
                'm' => '5', 'n' => '5',
                'r' => '6',
            );
        }
        return isset($map[$char]) ? $map[$char] : '';
    }

    /**
     * Human-readable person_id detail.
     */
    private function personIdDetail($a, $b, $score)
    {
        if ($score === 1.0) {
            return 'person_id exact match';
        }
        return 'person_id mismatch';
    }

    /**
     * Human-readable email detail.
     */
    private function emailDetail($a, $b, $score)
    {
        if ($score === 1.0) {
            return 'email exact match';
        }
        return 'email mismatch';
    }

    /**
     * Human-readable phone detail.
     */
    private function phoneDetail($a, $b, $score)
    {
        if ($score === 1.0) {
            return 'phone exact match';
        }
        if ($score === 0.7) {
            return 'phone last-7 match';
        }
        return 'phone mismatch';
    }

    /**
     * Human-readable amount detail.
     */
    private function amountDetail($a, $b, $score)
    {
        if ($a == 0.0 && $b == 0.0) {
            return 'both zero';
        }
        $diff = abs($a - $b);
        if ($diff <= $this->config->getAmountTolerance()) {
            return sprintf('amounts match within tolerance (diff %.2f)', $diff);
        }
        return sprintf('amount difference %.2f exceeds tolerance %.2f', $diff, $this->config->getAmountTolerance());
    }

    /**
     * Human-readable date detail.
     */
    private function dateDetail($dateA, $dateB, $score)
    {
        $timeA = strtotime((string)$dateA);
        $timeB = strtotime((string)$dateB);
        if ($timeA === false || $timeB === false) {
            return 'unparseable date';
        }
        $diffDays = abs($timeA - $timeB) / 86400.0;
        if ($diffDays <= $this->config->getDateToleranceDays()) {
            return sprintf('dates match within %d day(s)', $this->config->getDateToleranceDays());
        }
        return sprintf('dates differ by %.1f day(s)', $diffDays);
    }

    /**
     * Human-readable name detail.
     */
    private function nameDetail($nameA, $nameB, $score)
    {
        return sprintf('name similarity %.2f', $score);
    }

    /**
     * Human-readable reference detail.
     */
    private function referenceDetail($refA, $refB, $score)
    {
        if ($refA === $refB) {
            return 'exact reference match';
        }
        if (strpos($refA, $refB) !== false || strpos($refB, $refA) !== false) {
            return 'reference substring match';
        }
        return sprintf('reference similarity %.2f', $score);
    }
}
