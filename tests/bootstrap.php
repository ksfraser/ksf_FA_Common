<?php
/**
 * PHPUnit bootstrap for ksf_FA_Common.
 *
 * Loads the package autoloader, then FAMock — the estate-wide library of
 * FrontAccounting function stubs.
 *
 * WHY ONLY FaBusinessStubs.php: FAMock's full entry point (php/FAMock.php)
 * also defines db_query(), db_fetch_assoc() and the TB_PREF constant.  Several
 * tests in this package deliberately exercise the "no FA runtime available"
 * path (DbItemChangeStateStoreTest, PreferenceRepositoryTest,
 * ModulePreferencesTraitTest), and those tests assert that path by the
 * ABSENCE of db_query()/db_fetch_assoc().  Loading the full FAMock would
 * flip that assumption and break them.
 *
 * FaBusinessStubs.php supplies db_fetch() — the one FA function the contact
 * resolver's FA adapter needs to read a native result set — plus the FA
 * business-function stubs, and nothing that the "no FA runtime" tests depend
 * on.  The CRM functions FA's contact API is built on (get_crm_person,
 * get_crm_persons, get_crm_contact, get_person_contacts) are NOT in FAMock;
 * they are stubbed locally in
 * tests/Unit/ContactResolver/FaCrmStubs.php, following this package's
 * existing stub convention (tests/Unit/App/FaHookStub.php).
 *
 * @package ksfraser\FrontAccounting\Common\Tests
 * @since   2.2.0
 */

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('ksf_FA_Common vendor/autoload.php not found — run composer install.');
}
require_once $autoload;

$famock = __DIR__ . '/../vendor/ksfraser/famock/php/FaBusinessStubs.php';
if (is_file($famock)) {
    require_once $famock;
}
