<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\CreateNotifications;
use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Contracts\DispatchesNotifications;
use App\Modules\Notifications\Contracts\QueueTicket;
use App\Modules\Notifications\Domain\Channel;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Domain\Priority;
use App\Modules\Notifications\Http\RequestContext;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use App\Support\DbTime;
use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class NotificationController
{
    public function __construct(
        private NotificationLifecycle $lifecycle,
        private DispatchesNotifications $dispatcher,
        private NotificationsConfig $config,
    ) {}

    public function store(Request $request, CreateNotifications $create): JsonResponse
    {
        $created = $create->single(
            $request->all(),
            RequestContext::idempotencyKey($request),
            RequestContext::meta(),
        );

        return NotificationResource::make($created->notification)
            ->response()
            ->setStatusCode($created->replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $created->replayed ? 'true' : 'false');
    }

    public function show(string $id): NotificationResource
    {
        $notification = Notification::query()->with('deliveryAttempts')->findOrFail($id);

        return NotificationResource::make($notification);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array<string, mixed> $filters */
        $filters = Validator::make($request->query(), [
            'status' => ['sometimes', Rule::in(NotificationStatus::values())],
            'channel' => ['sometimes', Rule::in(Channel::values())],
            'priority' => ['sometimes', Rule::in(Priority::values())],
            'batch_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string'],
        ])->validate();

        $query = Notification::query();

        foreach (['status', 'channel', 'priority', 'batch_id'] as $column) {
            if (isset($filters[$column]) && is_string($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['from']) && is_string($filters['from'])) {
            $query->where('created_at', '>=', DbTime::format(CarbonImmutable::parse($filters['from'])->utc()));
        }

        if (isset($filters['to']) && is_string($filters['to'])) {
            $query->where('created_at', '<=', DbTime::format(CarbonImmutable::parse($filters['to'])->utc()));
        }

        $perPage = isset($filters['per_page']) && is_numeric($filters['per_page']) ? (int) $filters['per_page'] : 25;

        // Cursor pagination: stable under concurrent inserts and no deep OFFSET.
        return NotificationResource::collection(
            $query->latest()->orderByDesc('id')->cursorPaginate($perPage)->withQueryString(),
        );
    }

    public function cancel(string $id): JsonResponse
    {
        $notification = Notification::query()->findOrFail($id);

        if (! $this->lifecycle->cancel($notification, DbTime::now())) {
            return $this->conflict($notification, 'Only scheduled, queued or retrying notifications can be cancelled.');
        }

        return NotificationResource::make($notification)->response();
    }

    /**
     * Manual retry from the dead-letter list: a failed notification goes back
     * to the queue with a fresh attempt budget.
     */
    public function retry(string $id): JsonResponse
    {
        $notification = Notification::query()->findOrFail($id);

        if (! $this->lifecycle->requeueFailed($notification, DbTime::now(), $this->config->ttlSeconds)) {
            return $this->conflict($notification, 'Only failed notifications can be retried.');
        }

        $this->dispatcher->dispatch([
            new QueueTicket($notification->id, $notification->channel, $notification->priority),
        ]);

        return NotificationResource::make($notification)->response();
    }

    private function conflict(Notification $notification, string $message): JsonResponse
    {
        // Re-read: the row may have moved on between the lookup and the UPDATE.
        $current = $notification->fresh() ?? $notification;

        return response()->json([
            'message' => $message,
            'status' => $current->status->value,
        ], 409);
    }
}
