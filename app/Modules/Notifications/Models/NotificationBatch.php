<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Support\DbTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int $total
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Unguarded]
#[Table(name: 'notification_batches')]
class NotificationBatch extends Model
{
    use HasUuids;

    protected $dateFormat = DbTime::FORMAT;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }
}
