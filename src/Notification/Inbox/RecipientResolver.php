<?php

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\Notification\Inbox;

use ksfraser\FrontAccounting\Common\Notification\Inbox\Contract\RecipientResolverInterface;

/**
 * Recipient resolution (BR-COM-03 FR-COM-03-002).
 *
 * Expands routing targets into concrete FA user ids:
 *   ['users' => [3, 7]]              explicit ids, filtered to live FA users
 *   ['roles' => ['SA_LEAVE_APPROVE']] users whose security role grants the area
 *   ['dto'   => ['type'=>..,'id'=>..,'field'=>'owner']]
 *                                    value of a DTO field via get_dto schema;
 *                                    the value may be an int, an id list, or a
 *                                    comma/pipe separated string
 *   ['all'   => true]                @all broadcast -> uid 0 placeholder
 *
 * Role lookup uses the FA security areas model: users.role_id ->
 * security_roles.id, where role.areas holds a ';'-joined list of the numeric
 * area codes from `$security_areas['<NAME>'][0]`. Injected additionally for
 * test/standalone embedding (pure-array fixtures).
 *
 * Silence is safe: unknown selectors or unresolvable values yield [].
 *
 * @package KSF\Common
 * @since   2.0.0
 */
final class RecipientResolver implements RecipientResolverInterface
{
    /** @var callable|null fn(int $id): bool  live-user check (FA db_* default) */
    private $userExists;

    /** @var callable|null fn(array $areaNames): array  role -> user id lookup */
    private $roleUsers;

    /** @var callable|null fn(string $type, $id): array  DTO loader (get_dto) */
    private $dtoLoader;

    /**
     * @param callable|null $userExists fn(int $id): bool
     * @param callable|null $roleUsers  fn(array $areaNames): int[]
     * @param callable|null $dtoLoader  fn(string $type, $id): array
     */
    public function __construct(
        ?callable $userExists = null,
        ?callable $roleUsers = null,
        ?callable $dtoLoader = null
    ) {
        $this->userExists = $userExists ?: function (int $id): bool {
            return $this->faUserExists($id);
        };
        $this->roleUsers = $roleUsers ?: function (array $areaNames): array {
            return $this->faRoleUsers($areaNames);
        };
        $this->dtoLoader = $dtoLoader ?: function (string $type, $id): array {
            return $this->faDtoLoader($type, $id);
        };
    }

    /**
     * @param array $target
     * @param array $context optional ['dto' => array] for dto field routing
     *
     * @return int[]
     */
    public function resolve(array $target, array $context = []): array
    {
        $ids = [];

        if (isset($target['users']) && \is_array($target['users'])) {
            foreach ($target['users'] as $id) {
                $uid = $this->cleanId($id);
                if ($uid !== null
                    && (($this->userExists)($uid) || $this->isForce($target))) {
                    $ids[] = $uid;
                }
            }
        }

        if (isset($target['roles']) && \is_array($target['roles'])) {
            $areaNames = [];
            foreach ($target['roles'] as $area) {
                if (\is_string($area) && $area !== '') {
                    $areaNames[] = $area;
                }
            }
            foreach (($this->roleUsers)($areaNames) as $uid) {
                $ids[] = (int) $uid;
            }
        }

        if (isset($target['dto']) && \is_array($target['dto'])) {
            $type  = isset($target['dto']['type']) ? (string) $target['dto']['type'] : '';
            $id    = $target['dto']['id'] ?? null;
            $field = isset($target['dto']['field']) ? (string) $target['dto']['field'] : 'owner';
            if ($type !== '' && $id !== null) {
                $dto = ($this->dtoLoader)($type, $id);
                foreach ($this->idsFromField($dto, $field, $context) as $uid) {
                    $ids[] = $uid;
                }
            }
        }

        if (isset($target['all']) && $target['all']) {
            $ids[] = 0;
        }

        $ids = \array_map('intval', $ids);
        $ids = \array_values(\array_unique($ids));
        $ids = \array_filter($ids, function (int $id): bool {
            return $id >= 0;
        });

        return \array_values($ids);
    }

    /**
     * Extract user ids from a DTO field value: int, int[], comma/pipe string.
     *
     * @return int[]
     */
    private function idsFromField(array $dto, string $field, array $context): array
    {
        if ($dto === [] && isset($context['dto']) && \is_array($context['dto'])) {
            $dto = $context['dto'];
        }
        $value = $dto[$field] ?? null;

        if (\is_int($value)) {
            return [$value];
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $uid = $this->cleanId($v);
                if ($uid !== null) {
                    $out[] = $uid;
                }
            }
            return $out;
        }
        if (\is_string($value) && $value !== '') {
            $parts = \preg_split('/[,|;]/', $value) ?: [];
            $out = [];
            foreach ($parts as $p) {
                $uid = $this->cleanId(\trim($p));
                if ($uid !== null) {
                    $out[] = $uid;
                }
            }
            return $out;
        }
        return [];
    }

    private function cleanId($id): ?int
    {
        if (\is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (\is_string($id) && \preg_match('/^\d+$/', $id)) {
            return (int) $id > 0 ? (int) $id : null;
        }
        return null;
    }

    private function isForce(array $target): bool
    {
        return isset($target['force']) && $target['force'] === true;
    }

    private function faUserExists(int $id): bool
    {
        if (!function_exists('db_query') || !\defined('TB_PREF')) {
            return true; // standalone: trust given ids (tests seed resolvers)
        }
        $sql = \sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE id=%s',
            TB_PREF . 'users',
            db_escape((string) $id)
        );
        $result = db_query($sql, 'Failed to check inbox recipient');
        if ($result && ($row = db_fetch_assoc($result))) {
            return (int) ($row['n'] ?? 0) > 0;
        }
        return false;
    }

    /**
     * @return int[] FA user ids whose role grants any of the given areas.
     */
    private function faRoleUsers(array $areaNames): array
    {
        if ($areaNames === [] || !function_exists('db_query') || !\defined('TB_PREF')) {
            return [];
        }

        $codes = [];
        foreach ($areaNames as $name) {
            $code = $this->securityAreaCode($name);
            if ($code !== null) {
                $codes[] = (int) $code;
            }
        }
        if ($codes === []) {
            return [];
        }

        $roles = [];
        $result = db_query(
            'SELECT id, areas FROM ' . TB_PREF . 'security_roles',
            'Failed to list security roles for inbox routing'
        );
        while ($result && ($row = db_fetch_assoc($result))) {
            $roleAreas = \array_map('intval', \array_filter(
                \explode(';', (string) ($row['areas'] ?? ''))
            ));
            if (\array_intersect($codes, $roleAreas) !== []) {
                $roles[] = (int) $row['id'];
            }
        }
        if ($roles === []) {
            return [];
        }

        $in = \implode(',', $roles);
        $result = db_query(
            'SELECT id FROM ' . TB_PREF . 'users WHERE role_id IN (' . $in . ')',
            'Failed to resolve inbox role recipients'
        );
        $ids = [];
        while ($result && ($row = db_fetch_assoc($result))) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    private function securityAreaCode(string $name): ?int
    {
        if (isset($GLOBALS['security_areas'][$name])
            && isset($GLOBALS['security_areas'][$name][0])) {
            return (int) $GLOBALS['security_areas'][$name][0];
        }
        // Fallback: external modules register areas via hook during boot; the
        // global is usually set. Unknown area -> no code -> no recipients.
        return null;
    }

    private function faDtoLoader(string $type, $id): array
    {
        $payload = ['type' => $type, 'id' => $id];
        if (function_exists('hook_invoke_all')) {
            hook_invoke_all('get_dto', $payload);
        }
        return isset($payload['dto']) && \is_array($payload['dto']) ? $payload['dto'] : [];
    }
}