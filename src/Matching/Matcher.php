<?php
declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Matching;

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface;
use ksfraser\FrontAccounting\Common\Matching\GenericMatcher;
use ksfraser\FrontAccounting\Common\Matching\MatchingConfig;

/**
 * Matcher — concrete implementation of MatcherInterface using GenericMatcher
 * and SqlPreFilter.
 *
 * Combines SQL-side candidate pre-filtering with PHP-side scoring in a
 * single class. Suitable for direct use by ISP, Bank Import, Square, or any
 * module that stages external records against FA master data.
 *
 * When a ContactResolverInterface is provided, the matcher can resolve
 * person_id from email/name fields via ContactResolver::findByEmail() and
 * ContactResolver::search(), enabling person-aware matching even when the
 * staged record only has contact details.
 *
 * @since 3.0.0
 * @BABOK Related: FR-COM-MATCH-001 concrete matcher implementation
 * @BABOK Related: FR-COM-MATCH-017 SQL pre-filter integration
 * @BABOK Related: FR-COM-MATCH-025 ContactResolver person resolution
 */
class Matcher implements MatcherInterface
{
    /** @var GenericMatcher */
    private $matcher;

    /** @var MatchingConfig */
    private $config;

    /** @var ContactResolverInterface|null */
    private $contactResolver = null;

    /**
     * @param MatchingConfig|null $config
     * @param ContactResolverInterface|null $contactResolver
     * @since 3.0.0
     */
    public function __construct(MatchingConfig $config = null, ContactResolverInterface $contactResolver = null)
    {
        $this->config = $config !== null ? $config : new MatchingConfig();
        $this->matcher = new GenericMatcher($this->config);
        $this->contactResolver = $contactResolver;
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $stagedRecord, array $candidates): MatchResult
    {
        // Resolve person_id from email/name via ContactResolver if available
        // and not already present on the staged record.
        $resolvedPersonIds = $this->resolvePersonIds($stagedRecord);

        // Merge resolved person_ids into staged record for scoring
        $enrichedStaged = $stagedRecord;
        if (!empty($resolvedPersonIds) && !isset($stagedRecord[$this->config->getPersonIdField()])) {
            $enrichedStaged[$this->config->getPersonIdField()] = $resolvedPersonIds[0];
        }

        return $this->matcher->match($enrichedStaged, $candidates);
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
        return $this->match($stagedRecord, $candidates);
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

    /**
     * Score two monetary amounts, 0.0–1.0.
     * Delegates to GenericMatcher::scoreAmount().
     *
     * @param float $a
     * @param float $b
     * @return float
     * @since 3.0.0
     */
    public function scoreAmount($a, $b)
    {
        return $this->matcher->scoreAmount($a, $b);
    }

    /**
     * Score two dates, 0.0–1.0.
     * Delegates to GenericMatcher::scoreDate().
     *
     * @param string $dateA
     * @param string $dateB
     * @return float
     * @since 3.0.0
     */
    public function scoreDate($dateA, $dateB)
    {
        return $this->matcher->scoreDate($dateA, $dateB);
    }

    /**
     * Score two names, 0.0–1.0.
     * Delegates to GenericMatcher::scoreName().
     *
     * @param string $nameA
     * @param string $nameB
     * @return float
     * @since 3.0.0
     */
    public function scoreName($nameA, $nameB)
    {
        return $this->matcher->scoreName($nameA, $nameB);
    }

    /**
     * Resolve person_id(s) from the staged record using ContactResolver.
     *
     * Priority:
     *   1. If staged record already has person_id, use it directly.
     *   2. If ContactResolver is available and staged record has email,
     *      call findByEmail() to resolve person_id(s).
     *   3. If ContactResolver is available and staged record has name,
     *      call search() to resolve person_id(s).
     *
     * @param array $stagedRecord
     * @return int[] Resolved person ids
     * @since 3.0.0
     */
    private function resolvePersonIds(array $stagedRecord): array
    {
        $personIdField = $this->config->getPersonIdField();
        $emailField    = $this->config->getEmailField();
        $nameField     = $this->config->getNameField();

        // Already has person_id — use it directly.
        if ($personIdField !== null && isset($stagedRecord[$personIdField]) && $stagedRecord[$personIdField] !== null) {
            return array((int)$stagedRecord[$personIdField]);
        }

        $resolved = array();

        if ($this->contactResolver === null) {
            return $resolved;
        }

        // Resolve by email.
        if ($emailField !== null && isset($stagedRecord[$emailField]) && $stagedRecord[$emailField] !== '') {
            $contacts = $this->contactResolver->findByEmail((string)$stagedRecord[$emailField]);
            foreach ($contacts as $contact) {
                $pid = $contact->getId();
                if ($pid > 0 && !in_array($pid, $resolved, true)) {
                    $resolved[] = $pid;
                }
            }
        }

        // Resolve by name search (limit to top 5 to avoid noise).
        if ($nameField !== null && isset($stagedRecord[$nameField]) && $stagedRecord[$nameField] !== '' && empty($resolved)) {
            $contacts = $this->contactResolver->search((string)$stagedRecord[$nameField], 5);
            foreach ($contacts as $contact) {
                $pid = $contact->getId();
                if ($pid > 0 && !in_array($pid, $resolved, true)) {
                    $resolved[] = $pid;
                }
            }
        }

        return $resolved;
    }
}
