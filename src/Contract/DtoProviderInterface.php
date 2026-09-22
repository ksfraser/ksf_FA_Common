<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Contract;

/**
 * System-wide DTO factory contract (BR-COM-01 FR-COM-01-006).
 *
 * The engine and designer depend on this interface only — never on module
 * classes. Modules register factory closures (see ProvidesDtosTrait) and the
 * system-wide hooks ('get_dto' / 'get_dto_list') are the front door, so the
 * listener can ask any activated module for its DTOs.
 *
 * A successful answer is `['dto' => array, 'schema' => array]` (getDto) or
 * `['dtos' => array[], 'schema' => array]` (getDtoList). Silence = empty:
 * an unresolved type must yield null / [] — never throw.
 *
 * @package KSF\Common
 * @since   2.0.0
 */
interface DtoProviderInterface
{
    /**
     * Fetch one fillable DTO plus its field schema.
     *
     * @param string       $type Module-local DTO type (e.g. 'leave_request')
     * @param int|string   $id   Record id
     *
     * @return array ['dto' => array, 'schema' => array] or empty on unknown
     */
    public function getDto(string $type, $id): array;

    /**
     * Fetch a list of DTOs plus the shared field schema.
     *
     * @param string $type     Module-local DTO type
     * @param array  $criteria Filter predicates (BR-COM-01 criteria DSL shape)
     * @param int    $limit
     *
     * @return array ['dtos' => array[], 'schema' => array] — empty on unknown
     */
    public function getDtoList(string $type, array $criteria = [], int $limit = 50): array;
}