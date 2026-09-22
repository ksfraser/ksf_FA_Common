<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * Named resolver registry (BR-COM-01 FR-COM-01-003).
 *
 * Workflow `then` clauses reference resolvers by key (e.g. 'hrm.approval_chain')
 * and may name a method ('nextStep'). Resolvers are slot-loaded lazily through
 * factories so no module service is instantiated until a step actually uses it.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class CalcRegistry
{
    /** @var array<string, callable> */
    private $resolvers = [];

    /** @var array<string, callable> */
    private $factories = [];

    /**
     * Register a static (already-built) resolver.
     *
     * @param string   $key
     * @param callable $resolver fn(array $dto, array $context): mixed
     */
    public function register(string $key, callable $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    /**
     * Register a lazy resolver factory.
     *
     * The factory is invoked once on first resolution.
     *
     * @param string   $key
     * @param callable $factory fn(): callable
     */
    public function registerFactory(string $key, callable $factory): void
    {
        $this->factories[$key] = $factory;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]) || isset($this->factories[$key]);
    }

    /**
     * Resolve a resolver by key, instantiating factories once.
     *
     * @param string $key
     *
     * @return callable|null
     */
    public function resolve(string $key): ?callable
    {
        if (isset($this->resolvers[$key])) {
            return $this->resolvers[$key];
        }

        if (isset($this->factories[$key])) {
            $factory         = $this->factories[$key];
            $resolver        = $factory();
            $this->resolvers[$key] = $resolver;
            unset($this->factories[$key]);
            return $resolver;
        }

        return null;
    }

    /**
     * Invoke a resolver by key (with optional method) and return its result.
     *
     * @param string $key       Resolver key (e.g. 'hrm.approval_chain')
     * @param array  $dto       DTO passed as first arg
     * @param array  $context   Extra context (result, event, params)
     * @param string|null $method  Optional method name on the resolver object
     *
     * @return mixed
     */
    public function call(string $key, array $dto, array $context = [], ?string $method = null)
    {
        $resolver = $this->resolve($key);
        if ($resolver === null) {
            throw new \RuntimeException("Unknown workflow resolver: {$key}");
        }

        if ($method !== null && \is_array($resolver) && \count($resolver) === 2) {
            $resolver = [$resolver[0], $method];
        } elseif ($method !== null && \is_object($resolver) && \method_exists($resolver, $method)) {
            $resolver = [$resolver, $method];
        }

        return $resolver($dto, $context);
    }
}