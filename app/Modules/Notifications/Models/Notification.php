<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Domain\Priority;
use App\Support\DbTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read model for a notification. Status changes never go through save():
 * they are conditional UPDATEs in NotificationLifecycle.
 *
 * @property string $id
 * @property string|null $batch_id
 * @property int|null $batch_index
 * @property Channel $channel
 * @property Priority $priority
 * @property string $recipient
 * @property string $content
 * @property NotificationStatus $status
 * @property int $attempts
 * @property int $max_attempts
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property string|null $correlation_id
 * @property string|null $trace_context
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $enqueued_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
#[Table(name: 'notifications')]
class Notification extends Model
{
    use HasUuids;

    protected $dateFormat = DbTime::FORMAT;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'priority' => Priority::class,
            'status' => NotificationStatus::class,
            'batch_index' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'enqueued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }
}
