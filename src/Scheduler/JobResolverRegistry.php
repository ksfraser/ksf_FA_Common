<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Scheduler;

/**
 * Named job resolver registry (BR-COM-04 FR-COM-04-003).
 *
 * Jobs reference resolvers by key (e.g. 'hrm.accrual'). Resolvers are
 * callables receiving the job's params array. Mirrors the Workflow
 * CalcRegistry pattern, scoped to scheduler jobs.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class JobResolverRegistry
{
    /** @var array<string, callable> */
    private $resolvers = [];

    /**
     * @param string   $key
     * @param callable $resolver fn(array $params): mixed
     */
    public function register(string $key, callable $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }

    /**
     * @param string $key
     *
     * @return callable|null
     */
    public function resolve(string $key): ?callable
    {
        return $this->resolvers[$key] ?? null;
    }
}