<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * Dot-path data accessor for DTO arrays (BR-COM-01 dtoValue).
 *
 * get($dto, 'current_approver') -> scalar
 * get($dto, 'person.name')      -> nested via dot path
 * set(&$dto, 'a.b', value)      -> writes through to nested arrays
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class DtoValue
{
    private function __construct()
    {
    }

    /**
     * Read a value from $dto by dot path. Missing paths resolve to null.
     *
     * @param array       $dto  DTO array
     * @param string      $path Dot-separated path
     * @param mixed       $default
     *
     * @return mixed
     */
    public static function get(array $dto, string $path, $default = null)
    {
        if ($path === '') {
            return $dto;
        }

        $cursor = $dto;
        foreach (\explode('.', $path) as $segment) {
            if (\is_array($cursor) && \array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }
            return $default;
        }

        return $cursor;
    }

    /**
     * Write a value into $dto by dot path (creates missing branches).
     *
     * @param array  $dto   Passed by reference and mutated
     * @param string $path  Dot-separated path
     * @param mixed  $value
     *
     * @return void
     */
    public static function set(array &$dto, string $path, $value): void
    {
        if ($path === '') {
            $dto = \is_array($value) ? $value : $dto;
            return;
        }

        $segments = \explode('.', $path);
        $cursor   = &$dto;
        $last     = \count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if ($i === $last) {
                $cursor[$segment] = $value;
                return;
            }

            if (!isset($cursor[$segment]) || !\is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }
}