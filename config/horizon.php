<?php

declare(strict_types=1);

use App\Modules\Delivery\Queue\QueueRouter;
use App\Modules\Notifications\Domain\Channel;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Worker pools
|--------------------------------------------------------------------------
| Two pools per channel, both reading that channel's three priority queues:
|
|   {channel}-priority   high, normal, low   strict priority
|   {channel}-fairness   low, normal, high   keeps low priority moving
|
| `balance => false` gives each pool a single process group that is handed
| its queues as one ordered list, which is what makes the order strict (the
| "simple" and "auto" strategies create a group per queue and ignore order).
| minProcesses = maxProcesses pins the pool size; otherwise Horizon would
| scale an idle pool down to one process.
|
| Workers are synchronous, so the processes needed to reach the rate limit
| are roughly: rate per second x provider response time in seconds.
*/

$worker = [
    'connection' => 'redis',
    'balance' => false,
    // Delivery retries are counted on the notification row, not by the
    // queue; see DeliverNotification for why.
    'tries' => 0,
    // Above the provider timeout (5 s), below the queue retry_after (45 s).
    'timeout' => 20,
    // Idle workers look for new jobs this often.
    'sleep' => 0.2,
    'memory' => 128,
    // Recycle long-lived PHP processes before small leaks add up.
    'maxJobs' => 10_000,
    'maxTime' => 3600,
    'nice' => 0,
];

$priorityWorkers = max(1, (int) env('WORKERS_PER_CHANNEL', 4));
$fairnessWorkers = max(0, (int) env('FAIR_WORKERS_PER_CHANNEL', 1));

$supervisors = [];

foreach (Channel::cases() as $channel) {
    $supervisors[$channel->value.'-priority'] = $worker + [
        'queue' => QueueRouter::priorityOrder($channel),
        'minProcesses' => $priorityWorkers,
        'maxProcesses' => $priorityWorkers,
    ];

    if ($fairnessWorkers > 0) {
        $supervisors[$channel->value.'-fairness'] = $worker + [
            'queue' => QueueRouter::fairnessOrder($channel),
            'minProcesses' => $fairnessWorkers,
            'maxProcesses' => $fairnessWorkers,
        ];
    }
}

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    // Seconds a queue may wait before Horizon raises a "long wait" event.
    'waits' => [
        'redis:default' => 60,
    ],

    // Minutes to keep job records for the dashboard.
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => $supervisors,

    // The same pools in every environment; sizes come from the environment.
    'environments' => [
        '*' => [],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],
];
