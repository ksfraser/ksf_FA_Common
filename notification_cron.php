<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use KsfCommon\Notification\NotificationService;

$service = new NotificationService();
$count = $service->dispatchDue(function ($notification) {
    error_log('[ksf_FA_Common] notification dispatch: ' . json_encode($notification->toArray()));
});

echo 'dispatched=' . $count . PHP_EOL;
