<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

/**
 * Matcher — concrete implementation of MatcherInterface using GenericMatcher
 * and SqlPreFilter.
 *
 * Combines SQL-side candidate pre-filtering with PHP-side scoring in a
 * single class. Suitable for direct use by ISP, Bank Import, Square, or any
 * module that stages external records against FA master data.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-001 concrete matcher implementation
 * @BABOK Related: FR-COM-MATCH-017 SQL pre-filter integration
 */
class Matcher implements MatcherInterface
{
    /** @var GenericMatcher */
    private $matcher;

    /** @var MatchingConfig */
    private $config;

    /**
     * @param MatchingConfig|null $config
     * @since 3.0.0
     */
    public function __construct(MatchingConfig $config = null)
    {
        $this->config = $config !== null ? $config : new MatchingConfig();
        $this->matcher = new GenericMatcher($this->config);
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $stagedRecord, array $candidates): MatchResult
    {
        return $this->matcher->match($stagedRecord, $candidates);
    }

    /**
     * {@inheritDoc}
     */
    public function matchWithFilter(
        SqlPreFilter $preFilter,
        SearchCriteria $criteria,
        array $stagedRecord,
        $limit = 100
    ): MatchResult {
        $candidates = $preFilter->filter($criteria, (int)$limit);
        return $this->matcher->match($stagedRecord, $candidates);
    }

    /**
     * {@inheritDoc}
     */
    public function autoApprove($confidence): bool
    {
        return $this->config->shouldAutoApprove($confidence);
    }

    /**
     * {@inheritDoc}
     */
    public function needsReview($confidence): bool
    {
        return $this->config->needsReview($confidence);
    }

    /**
     * {@inheritDoc}
     */
    public function getConfig(): MatchingConfig
    {
        return $this->config;
    }
}
