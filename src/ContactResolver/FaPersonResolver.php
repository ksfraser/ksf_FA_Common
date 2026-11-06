<?php
/**
 * FaPersonResolver — the FrontAccounting implementation of PersonResolverInterface.
 *
 * Calls FA's NATIVE functions only (get_crm_person, get_crm_persons with
 * $unique=true, get_person_contacts) — never raw SQL against an FA core table.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.3.0
 */

declare(strict_types=1);

namespace ksfraser\FrontAccounting\Common\ContactResolver;

use ksfraser\FrontAccounting\Common\ContactResolver\Contract\PersonResolverInterface;

/**
 * Person resolution backed by FA's native CRM functions.
 *
 * @package ksfraser\FrontAccounting\Common\ContactResolver
 * @since   2.3.0
 */
final class FaPersonResolver implements PersonResolverInterface
{
    /** @var bool Whether the native CRM include has already been attempted */
    private static $includeAttempted = false;

    /**
     * Resolve one person by its person id.
     *
     * @param int $personId Person id
     *
     * @return Contact|null The person, or null when there is no such person
     */
    public function findById(int $personId): ?Contact
    {
        if ($personId <= 0) {
            return null;
        }

        $this->ensureNativeFunctions();

        $row = $this->callFa('get_crm_person', [$personId]);
        if (!\is_array($row) || !isset($row['id'])) {
            return null;
        }

        $row['contact_id'] = null;

        return Contact::fromArray($row);
    }

    /**
     * Resolve every person whose email address matches.
     *
     * FA has no native person-by-email lookup, so this scans the native
     * relation query (get_crm_persons with $unique=true) and matches on email.
     * People with no contact relation are not visible to the native API.
     *
     * @param string $email           Email address to match
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function findByEmail(string $email, bool $includeInactive = false): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        $needle = strtolower($email);
        $found  = [];

        foreach ($this->personRows() as $row) {
            if (strtolower((string) ($row['email'] ?? '')) !== $needle) {
                continue;
            }
            $contact = Contact::fromArray($row);
            if (!$includeInactive && $contact->isInactive()) {
                continue;
            }
            $found[] = $contact;
        }

        return $found;
    }

    /**
     * Search people by name, email or phone.
     *
     * @param string $query          Search term
     * @param int    $limit          Maximum rows to return
     * @param bool   $includeInactive Include people flagged inactive
     *
     * @return Contact[] Matching people (empty when none)
     */
    public function search(string $query, int $limit = 20, bool $includeInactive = false): array
    {
        $query = trim($query);
        if ($query === '' || $limit <= 0) {
            return [];
        }

        $needle = strtolower($query);
        $found  = [];

        foreach ($this->personRows() as $row) {
            if (!$this->matches($row, $needle)) {
                continue;
            }
            $contact = Contact::fromArray($row);
            if (!$includeInactive && $contact->isInactive()) {
                continue;
            }
            $found[] = $contact;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * Return the contact CATEGORY ids of a person.
     *
     * @param int $personId Person id
     *
     * @return int[] Category ids (empty when the person has no contacts)
     */
    public function listContactCategoryIds(int $personId): array
    {
        if ($personId <= 0) {
            return [];
        }

        $this->ensureNativeFunctions();

        $ids = $this->callFa('get_person_contacts', [$personId]);
        if (!\is_array($ids)) {
            return [];
        }

        $categories = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $categories[] = (int) $id;
            }
        }

        return $categories;
    }

    /**
     * Every person the native relation query can see, one row per person.
     *
     * @return array<int, array> Person rows
     */
    private function personRows(): array
    {
        $this->ensureNativeFunctions();

        $result = $this->callFa('get_crm_persons', [null, null, null, null, true]);

        return $this->rowsFrom($result);
    }

    /**
     * Whether a person row matches a lower-cased search term.
     *
     * @param array  $row    Person row
     * @param string $needle Lower-cased search term
     *
     * @return bool
     */
    private function matches(array $row, string $needle): bool
    {
        $fields = ['name', 'name2', 'email', 'phone', 'phone2', 'ref'];
        foreach ($fields as $field) {
            $value = (string) ($row[$field] ?? '');
            if ($value !== '' && strpos(strtolower($value), $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Call a native FA function defensively.
     *
     * @param string $function  Native function name
     * @param array  $arguments Call arguments
     *
     * @return mixed The function's return value, or null when it is absent
     *               or throws
     */
    private function callFa(string $function, array $arguments)
    {
        if (!function_exists($function)) {
            return null;
        }

        try {
            return call_user_func_array($function, $arguments);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalise a native result into a list of rows.
     *
     * @param mixed $result Native return value
     *
     * @return array<int, array> Rows
     */
    private function rowsFrom($result): array
    {
        if (\is_array($result)) {
            $rows = [];
            foreach ($result as $row) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        if (\is_object($result) && function_exists('db_fetch')) {
            $rows = [];
            while (($row = db_fetch($result)) !== false && $row !== null) {
                if (\is_array($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return [];
    }

    /**
     * Make sure FA's native CRM functions are loaded.
     *
     * @return void
     */
    private function ensureNativeFunctions(): void
    {
        if (function_exists('get_crm_person')) {
            return;
        }

        if (self::$includeAttempted) {
            return;
        }
        self::$includeAttempted = true;

        $pathToRoot = isset($GLOBALS['path_to_root']) ? (string) $GLOBALS['path_to_root'] : '';
        if ($pathToRoot === '') {
            return;
        }

        $file = $pathToRoot . '/includes/db/crm_contacts_db.inc';
        if (is_file($file)) {
            include_once $file;
        }
    }
}
