<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * Criteria DSL evaluator (BR-COM-01 FR-COM-01-002).
 *
 * Each criterion is `[field, op, value]` on a DTO, e.g.
 *   ['status', 'eq', 'submitted']
 *   ['approver_id', 'isset']
 *   ['days', 'gte', 'result.hours_requested']
 * All criteria are ANDed. Value operands may reference prior resolver output
 * via a `result.` prefix (dot-path into the step context).
 *
 * Ops: eq ne gt gte lt lte in nin contains not_contains is_null not_null isset
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class CriteriaEvaluator
{
    private function __construct()
    {
    }

    /**
     * @param array[]  $criteria  List of [field, op, value] rows
     * @param array    $dto       DTO to evaluate against
     * @param array    $context   Step context (adds the `result.` namespace)
     */
    public static function all(array $criteria, array $dto, array $context = []): bool
    {
        if (\count($criteria) === 0) {
            return true;
        }

        foreach ($criteria as $row) {
            if (!\is_array($row) || \count($row) < 2) {
                continue;
            }

            $field  = (string) $row[0];
            $op     = (string) $row[1];
            $value  = \array_key_exists(2, $row) ? self::resolveReference($row[2], $context) : null;
            $actual = self::resolveField($field, $dto, $context);

            if (!self::evaluate($op, $actual, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a criteria FIELD against the DTO. Plain names read the DTO at
     * that dot-path; 'result.' and 'dto.' prefixes read the step context.
     *
     * @param string $field  Field path or prefixed reference
     * @param array  $dto
     * @param array  $context
     *
     * @return mixed
     */
    private static function resolveField(string $field, array $dto, array $context)
    {
        if (\strpos($field, 'result.') === 0) {
            return isset($context['result'])
                ? DtoValue::get($context['result'], \substr($field, 7)) : null;
        }
        if (\strpos($field, 'dto.') === 0) {
            return DtoValue::get($dto, \substr($field, 4));
        }
        return DtoValue::get($dto, $field);
    }

    /**
     * Evaluate a single predicate.
     *
     * @param string $op
     * @param mixed  $actual
     * @param mixed  $expected
     */
    public static function evaluate(string $op, $actual, $expected): bool
    {
        switch ($op) {
            case 'eq':
                return $actual == $expected;
            case 'ne':
                return $actual != $expected;
            case 'gt':
                return $actual > $expected;
            case 'gte':
                return $actual >= $expected;
            case 'lt':
                return $actual < $expected;
            case 'lte':
                return $actual <= $expected;
            case 'in':
                return \is_array($expected) && \in_array($actual, $expected, true);
            case 'nin':
                return !\is_array($expected) || !\in_array($actual, $expected, true);
            case 'contains':
                return $actual !== null && \mb_strpos((string) $actual, (string) $expected) !== false;
            case 'not_contains':
                return $actual === null || \mb_strpos((string) $actual, (string) $expected) === false;
            case 'is_null':
                return $actual === null;
            case 'not_null':
                return $actual !== null;
            case 'isset':
                return $actual !== null && $actual !== [];
            default:
                return true;
        }
    }

    /**
     * Resolve `result.<path>` references against the step context so criteria
     * can read resolver output (result.*) or plain DTO fields.
     *
     * @param mixed $value
     * @param array $context
     *
     * @return mixed
     */
    private static function resolveReference($value, array $context)
    {
        if (\is_string($value) && \strpos($value, 'result.') === 0) {
            $path = \substr($value, 7);
            if (!isset($context['result'])) {
                return null;
            }
            return DtoValue::get($context['result'], $path);
        }

        if (\is_string($value) && \strpos($value, 'dto.') === 0 && isset($context['dto'])) {
            return DtoValue::get($context['dto'], \substr($value, 4));
        }

        return $value;
    }
}