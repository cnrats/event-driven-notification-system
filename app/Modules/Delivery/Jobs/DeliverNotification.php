<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Jobs;

use App\Modules\Delivery\DeliveryHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\CountCrashesAsExceptions;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Delivers one notification. The payload is only the ID; the database row is
 * the source of truth, which is what makes duplicate and redelivered jobs
 * harmless.
 *
 * Laravel's attempt counter is deliberately not used for delivery retries.
 * It goes up every time a job is popped, including when the job is merely
 * put back because of the rate limit or an open circuit, so under a burst a
 * fixed `tries` would fail notifications the provider never rejected.
 * Delivery attempts are counted on the row instead (see docs/adr/0002).
 *
 *  - Tries(0): unlimited pops; waiting is free.
 *  - MaxExceptions(3) + CountCrashesAsExceptions: an uncaught exception or a
 *    crashed worker is a bug or a poison job; give up after three.
 *  - Backoff: space those three out instead of burning them in milliseconds.
 *  - Timeout: above the provider timeout, below the queue's retry_after.
 */
#[Tries(0)]
#[MaxExceptions(3)]
#[CountCrashesAsExceptions]
#[Backoff([5, 30])]
#[Timeout(20)]
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $notificationId) {}

    public function handle(DeliveryHandler $handler): void
    {
        $outcome = $handler->deliver($this->notificationId);

        if ($outcome->releaseAfterSeconds !== null) {
            $this->release($outcome->releaseAfterSeconds);
        }
    }

    /**
     * Called by Laravel once the job itself is given up on.
     */
    public function failed(?Throwable $exception): void
    {
        resolve(DeliveryHandler::class)->abandon($this->notificationId, $exception);
    }
}
