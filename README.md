# ksf_FA_Common

FrontAccounting module ksf_FA_Common.

---

## What this module does

FrontAccounting module ksf_FA_Common.

> No `ProjectDcs/BR*.md` recorded yet - business requirements for this
> module have not been written down. See *Contributing* below.

## Installing

This is a FrontAccounting extension; it installs into the FA `modules/` tree.

```bash
rsync -a --exclude .git --exclude vendor \
    ~/Documents/ksf_FA_Common/ ~/Documents/ksf_Infrastructure/fa_modules/ksf_FA_Common/
cd ~/Documents/ksf_Infrastructure/fa_modules/ksf_FA_Common \
    && composer install --no-dev
```

Then in the FA UI: **Setup -> Extensions**, tick the module and press
**Refresh/Update**. FA reads `_init/config` for the package name and
version, so that file must stay in sync with this README.

Notes:

- Do **not** deploy `vendor/` from the dev checkout - it carries dev
  dependencies (PHPUnit needs PHP >= 8.1) that fatal on the 7.4 runtime.
- Never edit anything under `fa_modules/` by hand. It is build output;
  development happens in `~/Documents/ksf_FA_Common`.

## Where to find it in the UI

This module exposes no `pages/*.php` entry points of its own; it is
driven through FA hooks and other modules' pages.

Menu placement is added by the page code (see `pages/` above); if a tab is
missing, check that the module is activated for your company and that your
user holds the listed security areas.

## Shared contact resolver

One contact registry exists: FA core's `0_crm_persons` (the person) and
`0_crm_contacts` (the person-to-entity relation). Our own
`0_ksf_crm_contacts` table is **deprecated** — do not use it, migrate to it,
or replace it with another `0_ksf_*` counterpart. A module that needs a
contact goes through the shared resolver in this package rather than
querying a contact table itself, and it accesses native FA data through FA's
**native functions**, never raw SQL against an FA core table. The decision
and the verified function list are in `AGENTS_ARCH.md` §12.2.

```php
use ksfraser\FrontAccounting\Common\ContactResolver\ContactResolver;

$resolver = new ContactResolver();          // FA runtime (native functions)

$person    = $resolver->findById(7);                       // one person
$byEmail   = $resolver->findByEmail('a@example.com');      // every match
$customers = $resolver->findByCustomer('CUST-1');          // a debtor's contacts
$entity    = $resolver->findByEntity('cust_branch', 'BR-9');
$contact   = $resolver->findContactById(21);               // one relation
$categories = $resolver->listContactCategoryIds(7);        // category ids
$hits      = $resolver->search('alice', 20);               // name/email/phone
```

- Contract: `ksfraser\FrontAccounting\Common\ContactResolver\Contract\ContactResolverInterface`
- FA implementation: `…\ContactResolver\FaContactResolver` — native functions
  only (`get_crm_person`, `get_crm_persons`, `get_crm_contact`,
  `get_person_contacts`).
- Non-FA implementation: `…\ContactResolver\InMemoryContactResolver` — for
  tests, CLI tools and platforms that keep their directory in process.
- A non-FA platform (WordPress, Nextcloud, standalone) either injects its own
  implementation of the contract, or answers the `ksf_contact_resolve` hook
  (`ContactResolverHookContract`); `ContactResolver` asks the hook first and
  falls back to its implementation. A shared seam is never gated on another
  module being activated.

## Repository layout

```
ksf_FA_Common/
├── hooks.php            # FA extension entry point, security areas
├── _init/config         # FA package manifest (plain Key: Value)
├── composer.json        # PSR-4 autoload, ksfraser/* dependencies
├── src/                 # PSR-4 classes
├── sql/                 # schema (literal 0_ table prefix)
├── tests/               # PHPUnit suite
└── vendor/              # not deployed; composer install --no-dev in fa_modules
```

## Contributing

1. Work in `~/Documents/ksf_FA_Common` - never in `fa_modules/`.
2. Branch: `feat/`, `fix/`, `refactor/`, `docs/`.
3. Tests first: `cd ~/Documents/ksf_FA_Common && php vendor/bin/phpunit`.
4. Requirements live in `ProjectDcs/` as individual files
   (`BR-<MOD>-<seq>-<slug>.md`, `FR-...`, `UC-...`, `UT-...`), and code
   carries `@BABOK Related: <ID>` in its docblock so traceability can be
   generated from the source.
5. Commit per logical change, then push. Deploys are rsync from dev;
   they must never carry commits of their own.

Conventions that apply across every module are documented in
`AGENTS_ARCH.md` (shared, hardlinked) and `AGENTS_APPENDIX.md`.
