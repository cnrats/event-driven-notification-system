<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Resources;

use App\Modules\Notifications\Models\DeliveryAttempt;
use App\Modules\Notifications\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Notification $resource
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;

        return [
            'id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'recipient' => $notification->recipient,
            'content' => $notification->content,
            'status' => $notification->status->value,
            'attempts' => $notification->attempts,
            'max_attempts' => $notification->max_attempts,
            'provider_message_id' => $notification->provider_message_id,
            'last_error' => $notification->last_error,
            'correlation_id' => $notification->correlation_id,
            'scheduled_at' => self::iso($notification->scheduled_at),
            'next_attempt_at' => self::iso($notification->next_attempt_at),
            'expires_at' => self::iso($notification->expires_at),
            'sent_at' => self::iso($notification->sent_at),
            'failed_at' => self::iso($notification->failed_at),
            'cancelled_at' => self::iso($notification->cancelled_at),
            'created_at' => self::iso($notification->created_at),
            'updated_at' => self::iso($notification->updated_at),
            'delivery_attempts' => $this->whenLoaded('deliveryAttempts', fn (): array => array_values(
                $notification->deliveryAttempts->map(static fn (DeliveryAttempt $attempt): array => [
                    'attempt' => $attempt->attempt,
                    'outcome' => $attempt->outcome,
                    'http_status' => $attempt->http_status,
                    'latency_ms' => $attempt->latency_ms,
                    'error' => $attempt->error,
                    'at' => self::iso($attempt->created_at),
                ])->all(),
            )),
        ];
    }

    public static function iso(?CarbonImmutable $time): ?string
    {
        return $time?->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
