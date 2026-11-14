<?php

declare(strict_types=1);

use App\Modules\Delivery\DeliveryHandler;
use App\Modules\Delivery\DeliveryOutcome;
use App\Modules\Notifications\Application\CreateNotifications;
use App\Modules\Notifications\Application\RequestMeta;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Accept a notification through the application service, as the API does.
 *
 * @param  array<string, mixed>  $overrides
 */
function accept(array $overrides = [], bool $fakeQueue = true): Notification
{
    if ($fakeQueue) {
        Queue::fake();
    }

    return resolve(CreateNotifications::class)->single(
        array_merge(['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello'], $overrides),
        null,
        new RequestMeta('test-correlation-id'),
    )->notification;
}

/**
 * Run the delivery pipeline for a notification, as a worker would.
 */
function deliver(Notification $notification): DeliveryOutcome
{
    return resolve(DeliveryHandler::class)->deliver($notification->id);
}

/**
 * Make the provider answer every request the same way. Can be called again
 * within a test to change how the provider behaves from then on.
 *
 * @param  array<string, string>  $headers
 */
function providerResponds(int $status = 202, array $headers = [], string $messageId = 'msg-123'): void
{
    $body = $status === 202
        ? ['messageId' => $messageId, 'status' => 'accepted', 'timestamp' => '2026-11-14T10:00:00Z']
        : ['error' => 'simulated'];

    app()->instance('test.provider.response', [$body, $status, $headers]);

    if (app()->bound('test.provider.faked')) {
        return;
    }

    app()->instance('test.provider.faked', true);

    Http::fake(['provider.test/*' => function () {
        [$body, $status, $headers] = resolve('test.provider.response');

        return Http::response($body, $status, $headers);
    }]);
}
