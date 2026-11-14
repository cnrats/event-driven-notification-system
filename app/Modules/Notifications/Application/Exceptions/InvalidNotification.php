<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Exceptions;

use DomainException;

/**
 * The request did not pass validation. Nothing was stored.
 */
final class InvalidNotification extends DomainException
{
    /**
     * @param  array<string, list<string>>  $errors  keyed by field path
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }
}
