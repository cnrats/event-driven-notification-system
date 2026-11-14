<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

/**
 * Either a valid draft or the validation errors, keyed by field name.
 */
final readonly class DraftResult
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public ?NotificationDraft $draft,
        public array $errors,
    ) {}
}
