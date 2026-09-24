<?php

declare(strict_types=1);

/**
 * Scheduled automation CLI entry (BR-COM-04 FR-COM-04-005).
 *
 * Runs every due job EXACTLY once per minute from cron:
 *
 *   * * * * * php <path>/modules/ksf_FA_Common/cron.php >/dev/null
 *
 * Boots FA's DB connection (config_db.php + mysqli + FA-shaped db_* wrappers),
 * wires the FA runtime adapter + wall clock into the DI scheduler engine, and
 * consumes due jobs. Resolver bodies are registered by owning modules via the
 * `scheduler_resolvers` hook (each returns [key => callable], merged
 * last-wins).
 *
 * Exit codes: 0 green, 1 fatal (never re-raised per job — the loop catches),
 * 2 boot failure.
 *
 * @since 2.0.0
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load the package classes. Priority: own vendor (dev tree) → consumer's
// vendor/autoload.php (this file vendored at <mod>/vendor/ksfraser/kf-fa-common/)
// → bare PSR-4 fallback over our own src/ so the CLI still boots standalone.
$ownAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($ownAutoload)) {
    require_once $ownAutoload;
} elseif (file_exists(dirname(__DIR__, 3) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'ksfraser\\FrontAccounting\\Common\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use ksfraser\FrontAccounting\Common\Notification\Inbox\FaInboxStore;
use ksfraser\FrontAccounting\Common\Notification\Inbox\FaNotificationPreferenceStore;
use ksfraser\FrontAccounting\Common\Scheduler\FaJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;
use ksfraser\FrontAccounting\Common\Scheduler\NotificationDigest;
use ksfraser\FrontAccounting\Common\Scheduler\SystemClock;
use ksfraser\FrontAccounting\Common\Scheduler\WaitTransition;
use ksfraser\FrontAccounting\Common\Workflow\FaStateStore;
use ksfraser\FrontAccounting\Common\Workflow\StateMachine;

// ── 1. boot FA DB connection ──────────────────────────────────────────────
$root = dirname(__DIR__, 3);               // <FA>/modules -> <FA>
$config = '/var/www/html/config_db.php';
if (file_exists($config)) {
    include $config;
} elseif (file_exists($root . '/config_db.php')) {
    include $root . '/config_db.php';
}

if (!isset($db_connections[0])) {
    fwrite(STDERR, "BOOT FAIL: no db_connections\n");
    exit(2);
}

$connInfo = $db_connections[0];
$db = new mysqli(
    $connInfo['host'],
    $connInfo['dbuser'],
    $connInfo['dbpassword'],
    $connInfo['dbname'],
    $connInfo['port'] ?? 3306
);
if ($db->connect_errno) {
    fwrite(STDERR, "CONNECT FAIL: " . $db->connect_error . "\n");
    exit(2);
}

// FA connect_db_mysqli.inc sets an empty sql_mode on every new connection
$db->query("SET sql_mode = ''");

if (!defined('TB_PREF')) {
    define('TB_PREF', $connInfo['tbpref'] ?? '0_');
}

/**
 * FA-shaped procedural wrappers over the live handle (mirror
 * connect_db_mysqli.inc + connect_db.inc). PREPARE/QUOTE_UNLESS — FA has no
 * prepared statements; callers escape via db_escape().
 */
function db_query($sql, $err_msg = null)
{
    global $db;
    $result = $db->query($sql);
    if ($result === false) {
        $err = $db->error . ' [' . $sql . ']';
        if ($err_msg !== null && $err_msg !== '') {
            $err = $err_msg . "\n" . $err;
        }
        error_log('[ksf scheduler] db_query: ' . $err);
    }
    return $result;
}

function db_escape($value)
{
    global $db;
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $db->real_escape_string((string) $value) . "'";
}

function db_fetch_assoc($result)
{
    if ($result === false || $result === true) {
        return false;
    }
    return $result ? $result->fetch_assoc() : false;
}

function db_insert_id()
{
    global $db;
    return (int) $db->insert_id;
}

// ── 2. wire the engine ────────────────────────────────────────────────────
$resolvers = new JobResolverRegistry();
$adapter = new FaJobsAdapter();

// Common-owned resolvers (registered here so the bootstrap works even when no
// module exposes `scheduler_resolvers`):
//   wf.transition.wait     -> BR-COM-02 time-wait wires (FR-COM-04-007)
//   brcom03.notification.digest -> BR-COM-03 per-user digests (FR-COM-04-008)
if (class_exists(WaitTransition::class)
    && class_exists(StateMachine::class)
    && class_exists(FaStateStore::class)
) {
    $resolvers->register(WaitTransition::RESOLVER_KEY, new WaitTransition(
        new StateMachine(new FaStateStore()),
        $adapter
    ));
}
if (class_exists(NotificationDigest::class)
    && class_exists(FaInboxStore::class)
    && class_exists(FaNotificationPreferenceStore::class)
) {
    $resolvers->register(NotificationDigest::RESOLVER_KEY, new NotificationDigest(
        new FaInboxStore(),
        new FaNotificationPreferenceStore()
    ));
}

// Owning modules expose their resolvers via the `scheduler_resolvers` hook
// (last-wins over the built-ins above).
$resolverData = [];
if (function_exists('hook_invoke_first')) {
    $resolverData = (array) hook_invoke_first('scheduler_resolvers', $resolverData);
}
foreach ($resolverData as $key => $resolver) {
    $resolvers->register((string) $key, $resolver);
}

$runner = new JobRunner(
    $adapter,
    new SystemClock(),
    $resolvers
);

// ── 3. consume due jobs ───────────────────────────────────────────────────
try {
    $results = $runner->consume();
    fwrite(STDOUT, sprintf(
        "%s processed=%d\n",
        date('Y-m-d H:i:s'),
        count($results)
    ));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
    exit(1);
}