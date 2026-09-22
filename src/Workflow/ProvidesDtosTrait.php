<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Workflow;

/**
 * ProvidesDtosTrait — module-local glue implementing the system-wide
 * 'get_dto' / 'get_dto_list' hook responders (BR-COM-01 FR-COM-01-006).
 *
 * Composed into a module's `hooks_ksf_FA_<M>` class (see the hooks template),
 * this trait routes a system-wide payload to the DTO builders the module
 * registered with registerDtoType(). Participation is opt-in by data: a module
 * that registered nothing responds with null / [] — never throws.
 *
 * The trait carries no module knowledge; it only dispatches payloads to the
 * closures injected here (Ownership preserved). A module that needs full
 * control implements DtoProviderInterface directly and ignores this trait.
 *
 * Payload contracts (mirror the DtoProviderInterface return shapes):
 *   get_dto      -> payload in:  ['type' => string, 'id' => int|string]
 *                -> payload out: ['dto' => array|null, 'schema' => array]
 *   get_dto_list -> payload in:  ['type' => string, 'criteria' => array, 'limit' => int]
 *                -> payload out: ['dtos' => array, 'schema' => array]
 *
 * @package KSF\Common
 * @since   2.0.0
 */
trait ProvidesDtosTrait
{
    /** @var array<string, callable>  type => fn($id): array{0:dto,1:schema} */
    protected $dtoBuilders = [];

    /** @var array<string, callable>  type => fn(array $criteria, int $limit): array{0:dtos,1:schema} */
    protected $dtoListBuilders = [];

    /**
     * Register a builder for one DTO type.
     *
     * @param string   $type    Module-local DTO type (e.g. 'leave_request')
     * @param callable $builder fn(int|string $id): array{0:array|null,1:array}
     * @param callable|null $listBuilder Optional fn(array $criteria, int $limit): array{0:array,1:array}
     */
    protected function registerDtoType(
        string $type,
        callable $builder,
        ?callable $listBuilder = null
    ): void {
        $this->dtoBuilders[$type] = $builder;
        if ($listBuilder !== null) {
            $this->dtoListBuilders[$type] = $listBuilder;
        }
    }

    /**
     * Hook responder for 'get_dto' (invoked via hook_invoke_all).
     *
     * @param array $payload Mutated in place.
     */
    public function respondGetDto(array &$payload): void
    {
        $type = $payload['type'] ?? '';
        if ($type === '' || !isset($this->dtoBuilders[$type])) {
            $payload['dto']    = null;
            $payload['schema'] = [];
            return;
        }

        $id = $payload['id'] ?? null;
        [$dto, $schema] = \call_user_func($this->dtoBuilders[$type], $id);

        $payload['dto']    = $dto;
        $payload['schema'] = $schema;
    }

    /**
     * Hook responder for 'get_dto_list' (invoked via hook_invoke_all).
     *
     * @param array $payload Mutated in place.
     */
    public function respondGetDtoList(array &$payload): void
    {
        $type = $payload['type'] ?? '';
        if ($type === '' || !isset($this->dtoListBuilders[$type])) {
            $payload['dtos']   = [];
            $payload['schema'] = [];
            return;
        }

        $criteria = $payload['criteria'] ?? [];
        $limit    = \is_int($payload['limit'] ?? null) ? (int) $payload['limit'] : 50;

        [$dtos, $schema] = \call_user_func($this->dtoListBuilders[$type], (array) $criteria, $limit);

        $payload['dtos']   = $dtos;
        $payload['schema'] = $schema;
    }
}