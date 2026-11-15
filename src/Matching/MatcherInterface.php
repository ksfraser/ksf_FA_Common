<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * MatcherInterface — generic contract for matching staged/external records
 * against existing FA master data.
 *
 * Implementations provide scoring-based candidate matching with configurable
 * field mappings, weights, and thresholds. The same interface is used by ISP,
 * Bank Import, Square, and any other module that reconciles external data
 * with FA records.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-001 generic matcher contract
 * @BABOK Related: NFR-COM-MATCH-005 interface-based implementation
 */
interface MatcherInterface
{
    /**
     * Match a staged record against candidate existing records.
     *
     * @param array $stagedRecord   The staged/external record
     * @param array $candidates     Candidate existing records (pre-filtered)
     * @return MatchResult
     * @since 3.0.0
     */
    public function match(array $stagedRecord, array $candidates): MatchResult;

    /**
     * Match a staged record against candidates found via SQL pre-filtering.
     *
     * Combines SqlPreFilter candidate retrieval with GenericMatcher scoring
     * in a single call.
     *
     * @param SqlPreFilter $preFilter   SQL pre-filter instance
     * @param SearchCriteria $criteria  Search/filter criteria
     * @param array $stagedRecord       The staged/external record
     * @param int $limit                Maximum candidates to fetch
     * @return MatchResult
     * @since 3.0.0
     */
    public function matchWithFilter(
        SqlPreFilter $preFilter,
        SearchCriteria $criteria,
        array $stagedRecord,
        $limit = 100
    ): MatchResult;

    /**
     * Determine whether a confidence score qualifies for auto-approval.
     *
     * @param float $confidence Match confidence (0.0–1.0)
     * @return bool
     * @since 3.0.0
     */
    public function autoApprove($confidence): bool;

    /**
     * Determine whether a confidence score needs manual review.
     *
     * @param float $confidence Match confidence (0.0–1.0)
     * @return bool
     * @since 3.0.0
     */
    public function needsReview($confidence): bool;

    /**
     * Get the matcher configuration.
     *
     * @return MatchingConfig
     * @since 3.0.0
     */
    public function getConfig(): MatchingConfig;
}
