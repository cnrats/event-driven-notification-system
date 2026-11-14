<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Retry;

use App\Support\NotificationsConfig;

/**
 * How long to wait before the next delivery attempt.
 *
 * Exponential backoff (base * factor^(attempt-1), capped) with "equal
 * jitter": half of the delay is fixed, half is random. The fixed half keeps a
 * minimum distance between attempts; the random half stops thousands of
 * notifications that failed in the same second from all retrying in the same
 * second again and hitting a recovering provider as one wave.
 *
 * With the defaults (base 2 s, factor 3, 5 attempts) the waits are at most
 * 2, 6, 18 and 54 seconds: a short provider hiccup is ridden out in seconds,
 * a longer one within about a minute and a half.
 */
final readonly class RetryPolicy
{
    public function __construct(private NotificationsConfig $config) {}

    /**
     * @param  int  $attemptsMade  delivery attempts so far (>= 1)
     */
    public function delaySeconds(int $attemptsMade): int
    {
        [$min, $max] = $this->bounds($attemptsMade);

        return random_int($min, $max);
    }

    /**
     * @return array{int, int} inclusive lower and upper bound of the delay
     */
    public function bounds(int $attemptsMade): array
    {
        $exponent = max(0, $attemptsMade - 1);
        $ceiling = (int) min(
            $this->config->backoffMaxSeconds,
            $this->config->backoffBaseSeconds * ($this->config->backoffFactor ** $exponent),
        );
        $ceiling = max(1, $ceiling);

        return [max(1, intdiv($ceiling + 1, 2)), $ceiling];
    }
}
