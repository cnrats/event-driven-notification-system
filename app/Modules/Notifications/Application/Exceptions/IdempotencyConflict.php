<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Exceptions;

use DomainException;

/**
 * An idempotency key was reused with a different payload. Returning the
 * first result would hide a client bug, so the request is rejected.
 */
final class IdempotencyConflict extends DomainException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'The idempotency key "%s" was already used with a different request body.',
            $key,
        ));
    }
}
