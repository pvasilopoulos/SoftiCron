<?php

declare(strict_types=1);

// Scheduler entry point. Run every minute from a Plesk Scheduled Task:
//   /opt/plesk/php/8.3/bin/php /var/www/vhosts/example.gr/cronapp/bin/cron.php
// Options: --budget=50  (seconds to keep processing; keep below 60)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Services\Scheduler;

App::boot(dirname(__DIR__));
set_time_limit(0);

$budget = 50;
foreach ($argv as $arg) {
    if (preg_match('/^--budget=(\d+)$/', $arg, $m)) {
        $budget = max(5, min(55, (int) $m[1]));
    }
}

try {
    $stats = (new Scheduler())->tick($budget);
    if (in_array('-v', $argv, true)) {
        echo json_encode($stats) . PHP_EOL;
    }
} catch (Throwable $e) {
    App::log('cron tick failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
