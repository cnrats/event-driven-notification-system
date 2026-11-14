<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Application\Exceptions\IdempotencyConflict;
use App\Modules\Notifications\Application\Exceptions\InvalidNotification;
use App\Modules\Notifications\Contracts\DispatchesNotifications;
use App\Modules\Notifications\Contracts\QueueTicket;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationBatch;
use App\Modules\Observability\Metrics\Metrics;
use App\Support\DbTime;
use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Accepts notifications: validate, store, then hand to the delivery pipeline.
 *
 * The database row is the source of truth. A notification is "accepted" once
 * its row is committed; pushing the job to Redis happens after the commit and
 * is best effort, because the sweeper re-enqueues any row whose push was not
 * confirmed. The API therefore never loses or half-accepts a request when
 * Redis is slow or down.
 */
final readonly class CreateNotifications
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private DraftFactory $drafts,
        private DispatchesNotifications $dispatcher,
        private NotificationsConfig $config,
        private Metrics $metrics,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws InvalidNotification
     * @throws IdempotencyConflict
     */
    public function single(array $input, ?string $idempotencyKey, RequestMeta $meta): CreatedNotification
    {
        $now = DbTime::now();

        // The Idempotency-Key header wins over the body field.
        if ($idempotencyKey !== null) {
            $input['idempotency_key'] = $idempotencyKey;
        }

        $result = $this->drafts->make($input, $now);

        if (! $result->draft instanceof NotificationDraft) {
            throw new InvalidNotification($result->errors);
        }

        $draft = $result->draft;

        if ($draft->idempotencyKey !== null) {
            $existing = Notification::query()->where('idempotency_key', $draft->idempotencyKey)->first();

            if ($existing instanceof Notification) {
                return $this->replay($existing, $draft);
            }
        }

        $id = (string) Str::uuid7();

        try {
            Notification::query()->insert($this->row($id, $draft, null, null, $now, $meta));
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same key arrived together and the other
            // one won the unique index. Answer as if this one came second.
            $winner = Notification::query()->where('idempotency_key', $draft->idempotencyKey)->first();

            if (! $winner instanceof Notification) {
                throw $e;
            }

            return $this->replay($winner, $draft);
        }

        $notification = Notification::query()->findOrFail($id);

        $this->metrics->created($draft->channel, $draft->priority);

        if ($notification->status === NotificationStatus::Queued) {
            $this->handOver([new QueueTicket($id, $draft->channel, $draft->priority)]);
        }

        return new CreatedNotification($notification, replayed: false);
    }

    /**
     * All-or-nothing: if any item is invalid nothing is stored and every
     * problem is reported with its index (notifications.17.recipient).
     *
     * @param  array<array-key, mixed>  $items
     *
     * @throws InvalidNotification
     * @throws IdempotencyConflict
     */
    public function batch(array $items, ?string $idempotencyKey, RequestMeta $meta): CreatedBatch
    {
        $now = DbTime::now();
        $items = array_values($items);

        if ($items === [] || count($items) > $this->config->batchMaxSize) {
            throw new InvalidNotification(['notifications' => [
                sprintf('The notifications field must contain between 1 and %d items.', $this->config->batchMaxSize),
            ]]);
        }

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 255) {
            throw new InvalidNotification(['idempotency_key' => ['The Idempotency-Key header must be at most 255 characters.']]);
        }

        $drafts = $this->draftsFor($items, $now);
        $batchHash = hash('sha256', implode('', array_map(
            static fn (NotificationDraft $draft): string => $draft->requestHash().($draft->idempotencyKey ?? ''),
            $drafts,
        )));

        if ($idempotencyKey !== null) {
            $existing = NotificationBatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof NotificationBatch) {
                return $this->replayBatch($existing, $batchHash, $idempotencyKey);
            }
        }

        $prior = $this->existingByKey($drafts);
        $batchId = (string) Str::uuid7();
        $rows = [];
        $entries = [];
        $tickets = [];
        $created = [];

        foreach ($drafts as $index => $draft) {
            $known = $draft->idempotencyKey === null ? null : ($prior[$draft->idempotencyKey] ?? null);

            if ($known instanceof Notification) {
                if ($known->request_hash !== $draft->requestHash()) {
                    throw IdempotencyConflict::forKey((string) $draft->idempotencyKey);
                }

                $entries[] = ['index' => $index, 'id' => $known->id, 'status' => $known->status->value, 'duplicate' => true];

                continue;
            }

            $id = (string) Str::uuid7();
            $status = $draft->scheduledAt instanceof CarbonImmutable ? NotificationStatus::Scheduled : NotificationStatus::Queued;
            $rows[] = $this->row($id, $draft, $batchId, $index, $now, $meta);
            $entries[] = ['index' => $index, 'id' => $id, 'status' => $status->value, 'duplicate' => false];
            $created[] = $draft;

            if ($status === NotificationStatus::Queued) {
                $tickets[] = new QueueTicket($id, $draft->channel, $draft->priority);
            }
        }

        try {
            DB::transaction(function () use ($batchId, $idempotencyKey, $batchHash, $rows, $now, $meta): void {
                NotificationBatch::query()->insert([
                    'id' => $batchId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $batchHash,
                    'total' => count($rows),
                    'correlation_id' => $meta->correlationId,
                    'created_at' => DbTime::format($now),
                    'updated_at' => DbTime::format($now),
                ]);

                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    Notification::query()->insert($chunk);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $winner = $idempotencyKey === null
                ? null
                : NotificationBatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($winner instanceof NotificationBatch) {
                return $this->replayBatch($winner, $batchHash, $idempotencyKey);
            }

            throw new IdempotencyConflict(
                'Another request used one of the same idempotency keys at the same time. Nothing was stored; retry the request.',
            );
        }

        $this->countCreated($created);

        $this->handOver($tickets);

        return new CreatedBatch(
            batchId: $batchId,
            created: count($rows),
            duplicates: count($entries) - count($rows),
            entries: $entries,
            replayed: false,
        );
    }

    /**
     * One metric update per channel and priority instead of one per item.
     *
     * @param  list<NotificationDraft>  $drafts
     */
    private function countCreated(array $drafts): void
    {
        $groups = [];

        foreach ($drafts as $draft) {
            $key = $draft->channel->value.'|'.$draft->priority->value;
            $groups[$key] ??= ['draft' => $draft, 'count' => 0];
            $groups[$key]['count']++;
        }

        foreach ($groups as $group) {
            $this->metrics->created($group['draft']->channel, $group['draft']->priority, $group['count']);
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return list<NotificationDraft>
     *
     * @throws InvalidNotification
     */
    private function draftsFor(array $items, CarbonImmutable $now): array
    {
        $drafts = [];
        $errors = [];
        $firstUseOfKey = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors["notifications.{$index}"][] = 'Each notification must be an object.';

                continue;
            }

            $result = $this->drafts->make($item, $now);

            if (! $result->draft instanceof NotificationDraft) {
                foreach ($result->errors as $field => $messages) {
                    $errors["notifications.{$index}.{$field}"] = $messages;
                }

                continue;
            }

            $key = $result->draft->idempotencyKey;

            if ($key !== null && isset($firstUseOfKey[$key])) {
                $errors["notifications.{$index}.idempotency_key"][] = sprintf(
                    'The same idempotency_key is already used by notifications.%d in this batch.',
                    $firstUseOfKey[$key],
                );

                continue;
            }

            if ($key !== null) {
                $firstUseOfKey[$key] = $index;
            }

            $drafts[] = $result->draft;
        }

        if ($errors !== []) {
            throw new InvalidNotification($errors);
        }

        return $drafts;
    }

    /**
     * Notifications that already exist for the idempotency keys in a batch.
     *
     * @param  list<NotificationDraft>  $drafts
     * @return array<string, Notification>
     */
    private function existingByKey(array $drafts): array
    {
        $keys = [];

        foreach ($drafts as $draft) {
            if ($draft->idempotencyKey !== null) {
                $keys[] = $draft->idempotencyKey;
            }
        }

        if ($keys === []) {
            return [];
        }

        $existing = [];

        foreach (array_chunk($keys, self::INSERT_CHUNK) as $chunk) {
            $found = Notification::query()
                ->whereIn('idempotency_key', $chunk)
                ->get(['id', 'idempotency_key', 'request_hash', 'status']);

            foreach ($found as $notification) {
                $existing[(string) $notification->idempotency_key] = $notification;
            }
        }

        return $existing;
    }

    private function replay(Notification $existing, NotificationDraft $draft): CreatedNotification
    {
        if ($existing->request_hash !== $draft->requestHash()) {
            throw IdempotencyConflict::forKey((string) $draft->idempotencyKey);
        }

        return new CreatedNotification($existing, replayed: true);
    }

    private function replayBatch(NotificationBatch $existing, string $batchHash, string $key): CreatedBatch
    {
        if ($existing->request_hash !== $batchHash) {
            throw IdempotencyConflict::forKey($key);
        }

        $entries = [];

        $rows = Notification::query()
            ->where('batch_id', $existing->id)
            ->orderBy('batch_index')
            ->get(['id', 'batch_index', 'status']);

        foreach ($rows as $row) {
            $entries[] = [
                'index' => $row->batch_index ?? 0,
                'id' => $row->id,
                'status' => $row->status->value,
                'duplicate' => false,
            ];
        }

        return new CreatedBatch(
            batchId: $existing->id,
            created: $existing->total,
            duplicates: 0,
            entries: $entries,
            replayed: true,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function row(
        string $id,
        NotificationDraft $draft,
        ?string $batchId,
        ?int $batchIndex,
        CarbonImmutable $now,
        RequestMeta $meta,
    ): array {
        $scheduledAt = $draft->scheduledAt instanceof CarbonImmutable ? DbTime::format($draft->scheduledAt) : null;
        $createdAt = DbTime::format($now);

        return [
            'id' => $id,
            'batch_id' => $batchId,
            'batch_index' => $batchIndex,
            'channel' => $draft->channel->value,
            'priority' => $draft->priority->value,
            'recipient' => $draft->recipient,
            'content' => $draft->content,
            'status' => $scheduledAt === null ? NotificationStatus::Queued->value : NotificationStatus::Scheduled->value,
            'attempts' => 0,
            'max_attempts' => $this->config->maxAttempts,
            'idempotency_key' => $draft->idempotencyKey,
            'request_hash' => $draft->requestHash(),
            'correlation_id' => $meta->correlationId,
            'trace_context' => $meta->traceContext,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'expires_at' => DbTime::format(($draft->scheduledAt ?? $now)->addSeconds($this->config->ttlSeconds)),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * Push to the queue after the rows are committed. A failure here is not
     * an error for the client: the rows exist and the sweeper will enqueue
     * them within a few seconds.
     *
     * @param  list<QueueTicket>  $tickets
     */
    private function handOver(array $tickets): void
    {
        if ($tickets === []) {
            return;
        }

        try {
            $this->dispatcher->dispatch($tickets);
        } catch (Throwable $e) {
            $this->log->warning('notification.enqueue_deferred', [
                'count' => count($tickets),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
