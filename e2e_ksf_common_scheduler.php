<?php

/**
 * Integration/e2e: BR-COM-04 scheduler against the live FA DB (FR-COM-04-009).
 *
 * Runs INSIDE the FA container (php7.4) via:
 *   podman exec <container> php /var/www/html/modules/ksf_FA_HRM/vendor/ksfraser/ksf-fa-common/cron.php
 *   (or this script directly). It bootstraps FA's mysqli connection with the
 *   FA-shaped db_* wrappers, ensures 0_ksf_wf_jobs + 0_ksf_wf_run_log exist,
 *   seeds a recurring job due now, drives JobRunner + FaJobsAdapter + SystemClock
 *   exactly as cron.php does, and asserts:
 *     - run rows appear in 0_ksf_wf_run_log with status 'ok'
 *     - the job's resolver side-effect (a probe row) is recorded
 *     - a second consume within the interval adds NO new run (idempotence:
 *       recurring recomputes next_run_at past now; the per-job GET_LOCK guards
 *       true concurrency).
 *
 * The engine classes are loaded from vendor/autoload.php of the consumer that
 * vendors this package (HRM below), keeping the deployed tree the source of
 * truth under test.
 *
 * Usage:   php e2e_ksf_common_scheduler.php [auto]
 * Exit:    0 = green, 1 = failed (asserts), 2 = boot failure
 *
 * @since 2.0.0
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$failures = 0;

// ── 1. boot live FA connection (mirror e2e_hrm_event_windows.php) ─────────
$root = dirname(__DIR__, 4);              // .../modules/ksf_FA_HRM/vendor/ksfraser/ksf-fa-common
include '/var/www/html/config_db.php';
if (!isset($db_connections[0])) {
    fwrite(STDERR, "BOOT FAIL: no db_connections\n");
    exit(2);
}
$c = $db_connections[0];
$DB = new mysqli($c['host'], $c['dbuser'], $c['dbpassword'], $c['dbname'], $c['port'] ?? 3306);
if ($DB->connect_errno) {
    fwrite(STDERR, "CONNECT FAIL: " . $DB->connect_error . "\n");
    exit(2);
}
$DB->query("SET sql_mode = ''");
if (!defined('TB_PREF')) {
    define('TB_PREF', $c['tbpref'] ?? '0_');
}

function db_query($sql, $err_msg = null)
{
    global $DB;
    $r = $DB->query($sql);
    if ($r === false) {
        throw new RuntimeException('db_query: ' . $DB->error . ' SQL: ' . $sql);
    }
    return $r;
}
function db_fetch_assoc($result)
{
    return $result ? $result->fetch_assoc() : false;
}
function db_escape($value)
{
    global $DB;
    if ($value === null) {
        return 'NULL';
    }
    return "'" . $DB->real_escape_string((string)$value) . "'";
}
function db_insert_id()
{
    global $DB;
    return (int)$DB->insert_id;
}

echo "connected to {$c['name']} ({$c['dbname']}) tbpref=" . TB_PREF . "\n";

// ── 2. load the deployed engine (fresh-synced package via consumer vendor) ─
$consumerAutoload = '/var/www/html/modules/ksf_FA_HRM/vendor/autoload.php';
if (!file_exists($consumerAutoload)) {
    fwrite(STDERR, "BOOT FAIL: consumer autoload missing: $consumerAutoload\n");
    exit(2);
}
require_once $consumerAutoload;

use ksfraser\FrontAccounting\Common\Scheduler\Contract\JobsAdapterInterface;
use ksfraser\FrontAccounting\Common\Scheduler\FaJobsAdapter;
use ksfraser\FrontAccounting\Common\Scheduler\JobResolverRegistry;
use ksfraser\FrontAccounting\Common\Scheduler\JobRunner;
use ksfraser\FrontAccounting\Common\Scheduler\SystemClock;

if (!class_exists(JobRunner::class)) {
    fwrite(STDERR, "BOOT FAIL: scheduler engine not found in deployed Common\n");
    exit(2);
}

// ── 3. ensure scheduler tables (BR schema, literal 0_ per FA convention) ──
db_query("CREATE TABLE IF NOT EXISTS `0_ksf_wf_jobs` (
  `job_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`      VARCHAR(60)  NOT NULL,
  `job_key`     VARCHAR(120) NOT NULL,
  `job_type`    VARCHAR(10)  NOT NULL,
  `interval_p`  VARCHAR(20)  NOT NULL,
  `resolver`    VARCHAR(120) NOT NULL,
  `params`      TEXT         NULL,
  `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_run_at` DATETIME     NULL,
  `next_run_at` DATETIME     NULL,
  `last_error`  TEXT         NULL,
  `fail_count`  TINYINT      NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`job_id`),
  KEY `idx_next_run` (`next_run_at`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
db_query("CREATE TABLE IF NOT EXISTS `0_ksf_wf_run_log` (
  `run_id`      INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`      INT(11) NOT NULL,
  `started_at`  DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `status`      VARCHAR(12) NOT NULL,
  `detail`      TEXT NULL,
  `source`      VARCHAR(10) NOT NULL DEFAULT 'cli',
  PRIMARY KEY (`run_id`),
  KEY `idx_job_status` (`job_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "scheduler tables ensured\n";

// probe side-effect table: the resolver appends one row per actual run
db_query("CREATE TABLE IF NOT EXISTS `0_ksf_wf_e2e_probe` (
  `id`       INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key`  VARCHAR(120) NOT NULL,
  `ran_at`   DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_probe_job` (`job_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$JOB_KEY = 'e2e.common.probe';
$nowSql  = date('Y-m-d H:i:s');

// clean previous runs of THIS test key only (idempotent seed)
db_query("DELETE FROM 0_ksf_wf_jobs WHERE job_key = " . db_escape($JOB_KEY));
db_query("DELETE FROM 0_ksf_wf_e2e_probe WHERE job_key = " . db_escape($JOB_KEY));
// leave run_log untouched except for this job's prior rows
db_query("DELETE r FROM 0_ksf_wf_run_log r
            JOIN 0_ksf_wf_jobs j ON j.job_id = r.job_id
           WHERE j.job_key = " . db_escape($JOB_KEY));

// ── 4. seed a recurring job due now ────────────────────────────────────────
$adapter = new FaJobsAdapter();
$seed = [
    'module'      => 'common',
    'job_key'     => $JOB_KEY,
    'job_type'    => 'recurring',
    'interval_p'  => '1 hour',
    'resolver'    => 'e2e.common.probe',
    'params'      => ['job' => $JOB_KEY],
    'enabled'     => true,
    'next_run_at' => new DateTimeImmutable($nowSql),
];
$jobId = $adapter->registerJob($seed);
$jobId && $jobId = (int)$jobId;
if (!$jobId) {
    $jobId = (int) db_insert_id();
}
echo "seeded job_id=$jobId " . $JOB_KEY . " due $nowSql\n";

$fed = (array) db_fetch_assoc(db_query(
    "SELECT job_id FROM 0_ksf_wf_jobs WHERE job_key = " . db_escape($JOB_KEY)
));
if ((int)($fed['job_id'] ?? 0) !== (int) $jobId && (int)($fed['job_id'] ?? 0) !== 0) {
    $jobId = (int) $fed['job_id'];
}

// resolver: append one probe row per ACTUAL run (idempotence detector)
$resolvers = new JobResolverRegistry();
$resolvers->register('e2e.common.probe', function (array $params) use ($JOB_KEY): void {
    db_query(sprintf(
        "INSERT INTO 0_ksf_wf_e2e_probe (job_key, ran_at) VALUES (%s, %s)",
        db_escape($JOB_KEY),
        db_escape(date('Y-m-d H:i:s'))
    ));
    echo "resolver ran for {$params['job']}\n";
});

$runner = new JobRunner($adapter, new SystemClock(), $resolvers);

// ── 5. run the engine once ─────────────────────────────────────────────────
$runs1 = $runner->consume();

$runRows1 = db_fetch_assoc(db_query(
    "SELECT r.status, r.source, j.job_type
       FROM 0_ksf_wf_run_log r
       JOIN 0_ksf_wf_jobs j ON j.job_id = r.job_id
      WHERE j.job_key = " . db_escape($JOB_KEY) . "
      ORDER BY r.run_id DESC
      LIMIT 1"
));
$probe1 = (int) db_fetch_assoc(db_query(
    "SELECT COUNT(*) c FROM 0_ksf_wf_e2e_probe WHERE job_key = " . db_escape($JOB_KEY)
))['c'];

if (($runRows1['status'] ?? '') === 'ok') {
    echo "PASS: first consume wrote run_log status=ok (source={$runRows1['source']})\n";
} else {
    echo "FAIL: first consume run_log=" . json_encode($runRows1) . "\n";
    $failures++;
}
if ($probe1 === 1) {
    echo "PASS: resolver fired exactly once on first consume\n";
} else {
    echo "FAIL: probe count after first consume = $probe1\n";
    $failures++;
}

// ── 6. run again immediately → no new run (idempotent + next recompute) ────
$runs2 = $runner->consume();

$runCount2 = (int) db_fetch_assoc(db_query(
    "SELECT COUNT(*) c FROM 0_ksf_wf_run_log r
       JOIN 0_ksf_wf_jobs j ON j.job_id = r.job_id
      WHERE j.job_key = " . db_escape($JOB_KEY)
))['c'];
$probe2 = (int) db_fetch_assoc(db_query(
    "SELECT COUNT(*) c FROM 0_ksf_wf_e2e_probe WHERE job_key = " . db_escape($JOB_KEY)
))['c'];
$next = db_fetch_assoc(db_query(
    "SELECT next_run_at FROM 0_ksf_wf_jobs WHERE job_key = " . db_escape($JOB_KEY)
));

if ($probe2 === $probe1) {
    echo "PASS: second consume within interval ran nothing (idempotent)\n";
} else {
    echo "FAIL: second consume ran again (probe $probe1 -> $probe2)\n";
    $failures++;
}
if ((int)$runCount2 >= 1) {
    echo "PASS: run_log retained on second consume ($runCount2 rows total)\n";
} else {
    echo "FAIL: run_log empty after second consume\n";
    $failures++;
}
if (($next['next_run_at'] ?? '') > date('Y-m-d H:i:s')) {
    echo "PASS: recurring job recomputed next_run_at past now ({$next['next_run_at']})\n";
} else {
    echo "FAIL: next_run_at not pushed forward: " . json_encode($next) . "\n";
    $failures++;
}
echo "consume() returns: 1st=" . json_encode($runs1) . " 2nd=" . json_encode($runs2) . "\n";

// ── 7. cleanup test-only rows ──────────────────────────────────────────────
db_query("DELETE FROM 0_ksf_wf_e2e_probe WHERE job_key = " . db_escape($JOB_KEY));
db_query("DELETE FROM 0_ksf_wf_jobs WHERE job_key = " . db_escape($JOB_KEY));
db_query("DELETE r FROM 0_ksf_wf_run_log r
            JOIN 0_ksf_wf_jobs j ON j.job_id = r.job_id
           WHERE j.job_key = " . db_escape($JOB_KEY));
echo "cleaned up test rows\n";

if ($failures > 0) {
    fwrite(STDERR, "E2E FAILED ($failures)\n");
    exit(1);
}
echo "E2E GREEN\n";
exit(0);