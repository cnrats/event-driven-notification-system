<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\CreateNotifications;
use App\Modules\Notifications\Application\Exceptions\InvalidNotification;
use App\Modules\Notifications\Application\NotificationLifecycle;
use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Http\RequestContext;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationBatch;
use App\Support\DbTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class BatchController
{
    public function __construct(private NotificationLifecycle $lifecycle) {}

    public function store(Request $request, CreateNotifications $create): JsonResponse
    {
        $items = $request->input('notifications');

        if (! is_array($items)) {
            throw new InvalidNotification(['notifications' => ['The notifications field must be an array.']]);
        }

        $batch = $create->batch($items, RequestContext::idempotencyKey($request), RequestContext::meta());

        return response()->json([
            'data' => [
                'batch_id' => $batch->batchId,
                'created' => $batch->created,
                'duplicates' => $batch->duplicates,
                'notifications' => $batch->entries,
            ],
        ], $batch->replayed ? 200 : 202, ['Idempotent-Replayed' => $batch->replayed ? 'true' : 'false']);
    }

    public function show(string $id): JsonResponse
    {
        $batch = NotificationBatch::query()->findOrFail($id);

        return response()->json(['data' => $this->summary($batch)]);
    }

    public function cancel(string $id): JsonResponse
    {
        $batch = NotificationBatch::query()->findOrFail($id);
        $cancelled = $this->lifecycle->cancelBatch($batch->id, DbTime::now());

        return response()->json(['data' => $this->summary($batch) + ['cancelled' => $cancelled]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(NotificationBatch $batch): array
    {
        /** @var array<string, int> $counted */
        $counted = Notification::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();

        $statuses = [];
        $pending = 0;

        foreach (NotificationStatus::cases() as $status) {
            $statuses[$status->value] = $counted[$status->value] ?? 0;

            if (! $status->isTerminal()) {
                $pending += $statuses[$status->value];
            }
        }

        return [
            'id' => $batch->id,
            'total' => $batch->total,
            'statuses' => $statuses,
            'finished' => $pending === 0,
            'created_at' => NotificationResource::iso($batch->created_at),
        ];
    }
}
