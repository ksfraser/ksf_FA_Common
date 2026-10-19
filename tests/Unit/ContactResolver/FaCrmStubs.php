<?php
/**
 * FA native CRM function stubs for the contact resolver's unit tests.
 *
 * FAMock does not stub FA's CRM API (get_crm_person, get_crm_persons,
 * get_crm_contact, get_person_contacts), so this file provides it — following
 * the same convention as this package's existing tests/Unit/App/FaHookStub.php
 * (a plain stub file the test requires; not named *Test.php, so PHPUnit does
 * not collect it).
 *
 * The stubs reproduce FA 2.4.3's `includes/db/crm_contacts_db.inc` semantics
 * faithfully, including the parts that surprise people:
 *
 *   - get_crm_persons() returns "t.*, p.*, r.id AS contact_id", so the row's
 *     'id' is the PERSON id, 'name' is the person's name, and 'type'/'action'
 *     come from the category;
 *   - get_crm_contact() returns "t.type, t.action, p.*, r.person_id, r.id", so
 *     the row's 'id' is the CONTACT id and 'person_id' is the person id;
 *   - get_person_contacts() returns CATEGORY ids, not contact-row ids;
 *   - get_crm_persons() falls back to the 'general' action when a filtered
 *     query finds nothing.
 *
 * The result set is returned as an ArrayIterator so that FA's db_fetch() (the
 * FAMock stub) can read it exactly as it reads a mysqli_result.
 *
 * @package ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver
 * @since   2.2.0
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_crm_person')) {
        /**
         * FA 2.4.3: one person row plus their contact category ids.
         *
         * FA writes $person['contacts'] on the db_fetch() result, so a missing
         * person is a fatal on PHP 8; the stub reproduces that so the
         * resolver's defensive handling is exercised.
         *
         * @param int $id Person id
         * @return array
         */
        function get_crm_person($id)
        {
            return \Ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver\FaCrmStub::getPerson((int) $id);
        }
    }

    if (!function_exists('get_crm_persons')) {
        /**
         * FA 2.4.3: the relation query over crm_persons, crm_contacts and
         * crm_categories.
         *
         * @param string|null $type   Contact type
         * @param string|null $action Contact action
         * @param string|null $entity Related entity id
         * @param int|null    $person Person id
         * @param bool        $unique Group by person
         * @return ArrayIterator
         */
        function get_crm_persons($type = null, $action = null, $entity = null, $person = null, $unique = false)
        {
            return \Ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver\FaCrmStub::getPersons(
                $type,
                $action,
                $entity,
                $person,
                (bool) $unique
            );
        }
    }

    if (!function_exists('get_crm_contact')) {
        /**
         * FA 2.4.3: one contact relation row.
         *
         * @param int $id Contact relation id
         * @return array
         */
        function get_crm_contact($id)
        {
            return \Ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver\FaCrmStub::getContact((int) $id);
        }
    }

    if (!function_exists('get_person_contacts')) {
        /**
         * FA 2.4.3: the person's contact CATEGORY ids.
         *
         * @param int $id Person id
         * @return int[]
         */
        function get_person_contacts($id)
        {
            return \Ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver\FaCrmStub::getPersonCategoryIds((int) $id);
        }
    }
}

namespace ksfraser\FrontAccounting\Common\Tests\Unit\ContactResolver {
    /**
     * The data behind the FA native CRM stubs.
     */
    final class FaCrmStub
    {
        /** @var array<int, array> Person id => person row */
        private static $persons = [];

        /** @var array<int, array> Contact relation id => relation row */
        private static $contacts = [];

        /** @var array<string, int> "type/action" => category id */
        private static $categories = [];

        /** @var int Next contact relation id */
        private static $nextContactId = 1;

        /**
         * Forget every person, contact and category.
         *
         * @return void
         */
        public static function reset(): void
        {
            self::$persons     = [];
            self::$contacts    = [];
            self::$categories  = [];
            self::$nextContactId = 1;
        }

        /**
         * Add (or replace) a person row.
         *
         * @param array $row Person row; 'id' required
         * @return void
         */
        public static function addPerson(array $row): void
        {
            $id = (int) ($row['id'] ?? 0);
            self::$persons[$id] = $row + [
                'id'       => $id,
                'ref'      => '',
                'name'     => '',
                'name2'    => '',
                'address'  => '',
                'phone'    => '',
                'phone2'   => '',
                'fax'      => '',
                'email'    => '',
                'lang'     => '',
                'notes'    => '',
                'inactive' => 0,
            ];
        }

        /**
         * Attach a person to an entity, creating a contact relation.
         *
         * @param int    $personId Person id
         * @param string $type     Contact type
         * @param string $action   Contact action
         * @param string $entityId Related entity id
         * @return int The new contact relation id
         */
        public static function addContact(int $personId, string $type, string $action, string $entityId): int
        {
            $contactId = self::$nextContactId++;
            self::$contacts[$contactId] = [
                'id'        => $contactId,
                'person_id' => $personId,
                'type'      => $type,
                'action'    => $action,
                'entity_id' => $entityId,
            ];

            return $contactId;
        }

        /**
         * The category id of a type/action pair (created on demand).
         *
         * @param string $type   Contact type
         * @param string $action Contact action
         * @return int
         */
        public static function categoryId(string $type, string $action): int
        {
            $key = $type . '/' . $action;
            if (!isset(self::$categories[$key])) {
                self::$categories[$key] = count(self::$categories) + 1;
            }

            return self::$categories[$key];
        }

        /**
         * FA's get_crm_person().
         *
         * @param int $id Person id
         * @return array
         */
        public static function getPerson(int $id): array
        {
            if (!isset(self::$persons[$id])) {
                // FA 2.4.3 does $person['contacts'] = ... on a false db_fetch()
                // result: a warning on PHP 7.4, a fatal Error on PHP 8.
                throw new \Error('Cannot use a scalar value as an array');
            }

            $person = self::$persons[$id];
            $person['contacts'] = self::getPersonCategoryIds($id);

            return $person;
        }

        /**
         * FA's get_crm_persons().
         *
         * @param string|null $type   Contact type
         * @param string|null $action Contact action
         * @param string|null $entity Related entity id
         * @param int|null    $person Person id
         * @param bool        $unique Group by person
         * @return ArrayIterator
         */
        public static function getPersons(
            $type = null,
            $action = null,
            $entity = null,
            $person = null,
            bool $unique = false
        ): \ArrayIterator {
            $rows = self::relationRows($type, $action, $entity, $person);

            if ($rows === [] && $action !== null && $action !== '' && $action !== 'general') {
                // FA's own fallback to the 'general' action.
                $rows = self::relationRows($type, 'general', $entity, $person);
            }

            if ($unique) {
                $seen   = [];
                $unique_rows = [];
                foreach ($rows as $row) {
                    $personId = (int) $row['id'];
                    if (isset($seen[$personId])) {
                        continue;
                    }
                    $seen[$personId] = true;
                    $unique_rows[]  = $row;
                }
                $rows = $unique_rows;
            }

            return new \ArrayIterator($rows);
        }

        /**
         * FA's get_crm_contact().
         *
         * @param int $id Contact relation id
         * @return array
         */
        public static function getContact(int $id): array
        {
            if (!isset(self::$contacts[$id])) {
                return [];
            }

            $contact = self::$contacts[$id];
            $person  = self::$persons[$contact['person_id']] ?? ['id' => $contact['person_id']];

            $row = [
                'type'       => $contact['type'],
                'action'     => $contact['action'],
            ] + $person;

            // r.person_id and r.id come last, so 'id' is the CONTACT id.
            $row['person_id'] = $contact['person_id'];
            $row['id']        = $contact['id'];

            return $row;
        }

        /**
         * FA's get_person_contacts() — CATEGORY ids, not contact-row ids.
         *
         * @param int $personId Person id
         * @return int[]
         */
        public static function getPersonCategoryIds(int $personId): array
        {
            $categories = [];
            foreach (self::$contacts as $contact) {
                if ((int) $contact['person_id'] !== $personId) {
                    continue;
                }
                $categories[] = self::categoryId($contact['type'], $contact['action']);
            }

            return array_values(array_unique($categories));
        }

        /**
         * The joined relation rows (t.*, p.*, r.id AS contact_id).
         *
         * @param string|null $type   Contact type
         * @param string|null $action Contact action
         * @param string|null $entity Related entity id
         * @param int|null    $person Person id
         * @return array<int, array>
         */
        private static function relationRows($type, $action, $entity, $person): array
        {
            $rows = [];

            foreach (self::$contacts as $contact) {
                if ($type !== null && $type !== '' && $contact['type'] !== $type) {
                    continue;
                }
                if ($action !== null && $action !== '' && $contact['action'] !== $action) {
                    continue;
                }
                if ($entity !== null && $entity !== '' && (string) $contact['entity_id'] !== (string) $entity) {
                    continue;
                }
                if ($person !== null && (int) $contact['person_id'] !== (int) $person) {
                    continue;
                }

                $personRow = self::$persons[$contact['person_id']] ?? ['id' => $contact['person_id']];

                // t.* first, then p.* (which wins on the shared column names),
                // then r.id AS contact_id — so the person's values win.
                $category = [
                    'id'          => self::categoryId($contact['type'], $contact['action']),
                    'type'        => $contact['type'],
                    'action'      => $contact['action'],
                    'name'        => ucfirst($contact['type']) . ' ' . $contact['action'],
                    'description' => '',
                    'system'      => 0,
                    'inactive'    => 0,
                ];

                $row = $personRow + $category;
                $row['contact_id'] = $contact['id'];

                $rows[] = $row;
            }

            return $rows;
        }
    }
}
