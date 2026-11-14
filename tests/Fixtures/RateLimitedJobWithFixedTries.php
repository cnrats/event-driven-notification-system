<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * The usual way to rate limit a Laravel job: a fixed number of tries, and
 * the job puts itself back on the queue whenever the limit is reached. It
 * never throws and never calls the provider.
 */
#[Tries(3)]
final class RateLimitedJobWithFixedTries implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // "Rate limit reached, try again in a second."
        $this->release(1);
    }
}
