<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Notifications\Domain\Channel;
use App\Support\DbTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One call to the provider for one notification.
 *
 * @property int $id
 * @property string $notification_id
 * @property Channel $channel
 * @property int $attempt
 * @property string $outcome accepted | retryable | throttled | permanent
 * @property int|null $http_status
 * @property int|null $latency_ms
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
#[Unguarded]
#[Table(name: 'delivery_attempts')]
class DeliveryAttempt extends Model
{
    public const null UPDATED_AT = null;

    protected $dateFormat = DbTime::FORMAT;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'attempt' => 'integer',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
