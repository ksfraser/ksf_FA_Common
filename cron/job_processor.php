<?php
/**
 * Job Queue Cron Processor
 *
 * Called by system cron every minute. Processes pending background jobs.
 *
 * Usage in crontab:
 *   * * * * * php /path/to/ksf_FA_Common/cron/job_processor.php >> /var/log/ksf_jobs.log 2>&1
 */

declare(strict_types=1);

$path_to_root = dirname(__FILE__) . '/../../../..';
$_SERVER['REQUEST_URI'] = '/cron/job_processor.php';

require_once $path_to_root . '/includes/session.inc';
require_once $path_to_root . '/vendor/autoload.php';

// Load Common's autoloader
$commonAutoload = dirname(__FILE__) . '/../vendor/autoload.php';
if (file_exists($commonAutoload)) {
    require_once $commonAutoload;
}

use KsfCommon\Queue\JobQueue;

try {
    $results = JobQueue::processJobs(20);
    echo date('Y-m-d H:i:s') . ' processed=' . $results['processed']
        . ' failed=' . $results['failed']
        . ' errors=' . count($results['errors']) . PHP_EOL;
    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $err) {
            echo '  ERROR job#' . $err['job_id'] . ' [' . $err['type'] . ']: ' . $err['message'] . PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . ' FATAL: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
