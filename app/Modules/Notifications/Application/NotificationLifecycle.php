<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Contracts\QueueTicket;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Events\NotificationStatusChanged;
use App\Modules\Notifications\Models\Notification;
use App\Support\DbTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a notification's status.
 *
 * Every transition is a single conditional UPDATE ("... WHERE status IN the
 * states this transition may start from") and the caller learns from the
 * affected-row count whether it won. That is what makes the pipeline safe
 * under concurrency: two workers holding the same job, a cancel racing a
 * delivery, a retried job after a crash. The loser simply sees 0 rows.
 */
final readonly class NotificationLifecycle
{
    public function __construct(private Dispatcher $events) {}

    /**
     * queued → processing, taking a lease. Also claims a processing row whose
     * lease has expired (the previous worker died mid-delivery).
     */
    public function claim(Notification $notification, CarbonImmutable $now, int $leaseSeconds): bool
    {
        $nowDb = DbTime::format($now);
        $lockedUntil = $now->addSeconds($leaseSeconds);

        $claimed = Notification::query()
            ->whereKey($notification->id)
            ->where(function (Builder $query) use ($nowDb): void {
                $query->where('status', NotificationStatus::Queued->value)
                    ->orWhere(function (Builder $expired) use ($nowDb): void {
                        $expired->where('status', NotificationStatus::Processing->value)
                            ->where('locked_until', '<=', $nowDb);
                    });
            })
            ->update([
                'status' => NotificationStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'locked_until' => DbTime::format($lockedUntil),
            ]) === 1;

        if ($claimed) {
            $this->remember($notification, [
                'status' => NotificationStatus::Processing,
                'attempts' => $notification->attempts + 1,
                'locked_until' => $lockedUntil,
            ]);
        }

        return $claimed;
    }

    /**
     * processing → queued without having called the provider: the worker
     * claimed the row and then learned that the channel is paused. The
     * attempt taken by the claim is given back.
     */
    public function unclaim(Notification $notification): bool
    {
        $released = Notification::query()
            ->whereKey($notification->id)
            ->where('status', NotificationStatus::Processing->value)
            ->update([
                'status' => NotificationStatus::Queued->value,
                'attempts' => DB::raw('GREATEST(attempts, 1) - 1'),
                'locked_until' => null,
            ]) === 1;

        if ($released) {
            $this->remember($notification, [
                'status' => NotificationStatus::Queued,
                'attempts' => max(0, $notification->attempts - 1),
                'locked_until' => null,
            ]);
        }

        return $released;
    }

    public function markSent(Notification $notification, ?string $providerMessageId, CarbonImmutable $now): bool
    {
        $changes = [
            'status' => NotificationStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => $now,
            'locked_until' => null,
            'next_attempt_at' => null,
            'last_error' => null,
        ];

        return $this->transition($notification, [NotificationStatus::Processing], $changes, $now);
    }

    /**
     * processing → retrying, due again at $dueAt. With $refundAttempt the
     * attempt is not counted: the provider throttled us, it did not fail.
     */
    public function scheduleRetry(
        Notification $notification,
        CarbonImmutable $dueAt,
        string $error,
        CarbonImmutable $now,
        bool $refundAttempt = false,
    ): bool {
        $attempts = $refundAttempt ? max(0, $notification->attempts - 1) : $notification->attempts;

        $changes = [
            'status' => NotificationStatus::Retrying,
            'attempts' => $attempts,
            'next_attempt_at' => $dueAt,
            'locked_until' => null,
            'enqueued_at' => null,
            'last_error' => $this->truncate($error),
        ];

        return $this->transition($notification, [NotificationStatus::Processing], $changes, $now, $error);
    }

    public function markFailed(Notification $notification, string $error, CarbonImmutable $now): bool
    {
        return $this->fail($notification, [NotificationStatus::Processing], $error, $now);
    }

    /**
     * Fail a notification from any non-terminal state. Used when it expired
     * and by the job's failed() hook, where the state at the time of the
     * failure is unknown; a sent or cancelled row is never touched.
     */
    public function failFromAnyActiveState(Notification $notification, string $error, CarbonImmutable $now): bool
    {
        return $this->fail($notification, [
            NotificationStatus::Scheduled,
            NotificationStatus::Queued,
            NotificationStatus::Processing,
            NotificationStatus::Retrying,
        ], $error, $now);
    }

    /**
     * Cancel a notification that has not been handed to the provider yet.
     * A worker holding its job will find the row is no longer claimable.
     */
    public function cancel(Notification $notification, CarbonImmutable $now): bool
    {
        $changes = [
            'status' => NotificationStatus::Cancelled,
            'cancelled_at' => $now,
            'next_attempt_at' => null,
            'locked_until' => null,
        ];

        return $this->transition($notification, self::cancellable(), $changes, $now);
    }

    /**
     * Cancel everything in a batch that is still cancellable.
     *
     * @return int number of notifications cancelled
     */
    public function cancelBatch(string $batchId, CarbonImmutable $now): int
    {
        return Notification::query()
            ->where('batch_id', $batchId)
            ->whereIn('status', array_map(static fn (NotificationStatus $s): string => $s->value, self::cancellable()))
            ->update([
                'status' => NotificationStatus::Cancelled->value,
                'cancelled_at' => DbTime::format($now),
                'next_attempt_at' => null,
                'locked_until' => null,
            ]);
    }

    /**
     * failed → queued with a fresh attempt budget (manual retry from the
     * dead-letter list).
     */
    public function requeueFailed(Notification $notification, CarbonImmutable $now, int $ttlSeconds): bool
    {
        $changes = [
            'status' => NotificationStatus::Queued,
            'attempts' => 0,
            'failed_at' => null,
            'next_attempt_at' => null,
            'locked_until' => null,
            'enqueued_at' => null,
            'expires_at' => $now->addSeconds($ttlSeconds),
        ];

        return $this->transition($notification, [NotificationStatus::Failed], $changes, $now);
    }

    /**
     * scheduled | retrying → queued for rows that are due. SKIP LOCKED lets
     * several sweepers run side by side without picking the same rows.
     *
     * @return list<QueueTicket>
     */
    public function promoteDue(CarbonImmutable $now, int $limit): array
    {
        return DB::transaction(function () use ($now, $limit): array {
            $due = Notification::query()
                ->whereIn('status', [NotificationStatus::Scheduled->value, NotificationStatus::Retrying->value])
                ->where('next_attempt_at', '<=', DbTime::format($now))
                ->oldest('next_attempt_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get(['id', 'channel', 'priority']);

            return $this->moveToQueued($due);
        });
    }

    /**
     * processing rows whose lease ran out: the worker died between claiming
     * and recording the result. Back to queued; the attempt stays counted.
     *
     * @return list<QueueTicket>
     */
    public function requeueExpiredLeases(CarbonImmutable $now, int $graceSeconds, int $limit): array
    {
        return DB::transaction(function () use ($now, $graceSeconds, $limit): array {
            $expired = Notification::query()
                ->where('status', NotificationStatus::Processing->value)
                ->where('locked_until', '<=', DbTime::format($now->subSeconds($graceSeconds)))
                ->limit($limit)
                ->lock('for update skip locked')
                ->get(['id', 'channel', 'priority']);

            return $this->moveToQueued($expired);
        });
    }

    /**
     * queued rows whose push to Redis was never confirmed (the process died
     * between the database commit and the push, or Redis was unavailable).
     *
     * @return list<QueueTicket>
     */
    public function findUnconfirmed(CarbonImmutable $now, int $graceSeconds, int $limit): array
    {
        $rows = Notification::query()
            ->where('status', NotificationStatus::Queued->value)
            ->whereNull('enqueued_at')
            ->where('updated_at', '<=', DbTime::format($now->subSeconds($graceSeconds)))
            ->limit($limit)
            ->get(['id', 'channel', 'priority']);

        return $this->tickets($rows);
    }

    /**
     * queued rows that were pushed long ago and still have not been picked
     * up: the job was most likely lost (Redis data loss). Pushing again is
     * harmless, the claim guard drops duplicates.
     *
     * @return list<QueueTicket>
     */
    public function findStale(CarbonImmutable $now, int $staleAfterSeconds, int $limit): array
    {
        $rows = Notification::query()
            ->where('status', NotificationStatus::Queued->value)
            ->where('enqueued_at', '<=', DbTime::format($now->subSeconds($staleAfterSeconds)))
            ->limit($limit)
            ->get(['id', 'channel', 'priority']);

        return $this->tickets($rows);
    }

    /**
     * Record that jobs for these notifications are now in Redis.
     *
     * @param  list<string>  $ids
     */
    public function confirmEnqueued(array $ids, CarbonImmutable $now): void
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            Notification::query()->whereIn('id', $chunk)->update(['enqueued_at' => DbTime::format($now)]);
        }
    }

    /**
     * @return list<NotificationStatus>
     */
    public static function cancellable(): array
    {
        return [NotificationStatus::Scheduled, NotificationStatus::Queued, NotificationStatus::Retrying];
    }

    /**
     * @param  list<NotificationStatus>  $from
     */
    private function fail(Notification $notification, array $from, string $error, CarbonImmutable $now): bool
    {
        $changes = [
            'status' => NotificationStatus::Failed,
            'failed_at' => $now,
            'locked_until' => null,
            'next_attempt_at' => null,
            'last_error' => $this->truncate($error),
        ];

        return $this->transition($notification, $from, $changes, $now, $error);
    }

    /**
     * Run one guarded transition and, if it won, mirror it on the in-memory
     * model and announce it.
     *
     * @param  list<NotificationStatus>  $from
     * @param  array<string, mixed>  $changes  must contain the new 'status'
     */
    private function transition(
        Notification $notification,
        array $from,
        array $changes,
        CarbonImmutable $now,
        ?string $detail = null,
    ): bool {
        $columns = array_map(
            static fn (mixed $value): mixed => match (true) {
                $value instanceof NotificationStatus => $value->value,
                $value instanceof CarbonImmutable => DbTime::format($value),
                default => $value,
            },
            $changes,
        );

        $won = Notification::query()
            ->whereKey($notification->id)
            ->whereIn('status', array_map(static fn (NotificationStatus $s): string => $s->value, $from))
            ->update($columns) === 1;

        if (! $won) {
            return false;
        }

        $this->remember($notification, $changes);

        $this->events->dispatch(new NotificationStatusChanged(
            notificationId: $notification->id,
            batchId: $notification->batch_id,
            channel: $notification->channel,
            status: $notification->status,
            attempts: $notification->attempts,
            occurredAt: $now,
            detail: $detail === null ? null : $this->truncate($detail),
        ));

        return true;
    }

    /**
     * @param  Collection<int, Notification>  $rows
     * @return list<QueueTicket>
     */
    private function moveToQueued(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        Notification::query()
            ->whereIn('id', $rows->modelKeys())
            ->update([
                'status' => NotificationStatus::Queued->value,
                'next_attempt_at' => null,
                'locked_until' => null,
                'enqueued_at' => null,
            ]);

        return $this->tickets($rows);
    }

    /**
     * @param  Collection<int, Notification>  $rows
     * @return list<QueueTicket>
     */
    private function tickets(Collection $rows): array
    {
        return array_values($rows->map(
            static fn (Notification $row): QueueTicket => new QueueTicket($row->id, $row->channel, $row->priority),
        )->all());
    }

    /**
     * Keep the already-loaded model in step with what was just written, so
     * the caller does not need to reload it.
     *
     * @param  array<string, mixed>  $changes
     */
    private function remember(Notification $notification, array $changes): void
    {
        $notification->forceFill($changes)->syncOriginal();
    }

    private function truncate(string $text): string
    {
        return mb_strimwidth($text, 0, 500, '…');
    }
}
