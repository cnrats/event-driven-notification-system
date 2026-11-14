<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

final readonly class CreatedBatch
{
    /**
     * @param  list<array{index: int, id: string, status: string, duplicate: bool}>  $entries
     */
    public function __construct(
        public string $batchId,
        /** Notifications created by this batch. */
        public int $created,
        /** Items that matched an existing idempotency key and were not created again. */
        public int $duplicates,
        public array $entries,
        /** True when the batch idempotency key matched an earlier request. */
        public bool $replayed,
    ) {}
}
