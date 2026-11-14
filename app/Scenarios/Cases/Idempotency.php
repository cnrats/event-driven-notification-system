<?php

declare(strict_types=1);

namespace App\Scenarios\Cases;

use App\Modules\Notifications\Domain\NotificationStatus;
use App\Modules\Notifications\Models\Notification;
use App\Scenarios\Report;
use App\Scenarios\Scenario;
use App\Scenarios\ScenarioKit;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;

/**
 * A client retries aggressively: the same request with the same
 * Idempotency-Key arrives 30 times at the same moment.
 */
final class Idempotency implements Scenario
{
    private const int REQUESTS = 30;

    public function name(): string
    {
        return 'idempotency';
    }

    public function title(): string
    {
        return 'The same request arrives 30 times at once';
    }

    public function run(ScenarioKit $kit): Report
    {
        $kit->reset();

        $url = $kit->api().'/api/v1/notifications';
        $payload = ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Your order 42 has shipped'];

        $responses = $kit->http()->pool(static function (Pool $pool) use ($url, $payload): array {
            $requests = [];

            for ($i = 0; $i < self::REQUESTS; $i++) {
                $requests[] = $pool->withHeaders(['Idempotency-Key' => 'order-42-shipped'])->timeout(30)->post($url, $payload);
            }

            return $requests;
        });

        $created = 0;
        $replayed = 0;
        $ids = [];

        foreach ($responses as $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $created += $response->status() === 201 ? 1 : 0;
            $replayed += $response->status() === 200 ? 1 : 0;
            $id = $response->json('data.id');
            if (is_string($id)) {
                $ids[$id] = true;
            }
        }

        $kit->waitUntilFinished(60);
        $stats = $kit->providerStats();

        return (new Report($this->name(), $this->title()))
            ->equals('Exactly one request created the notification (HTTP 201)', 1, $created)
            ->equals('All others were answered as replays (HTTP 200)', self::REQUESTS - 1, $replayed)
            ->equals('All responses carry the same notification id', 1, count($ids))
            ->equals('One notification exists', 1, Notification::query()->count())
            ->equals('It was delivered once', 1, is_numeric($stats['delivered'] ?? null) ? (int) $stats['delivered'] : -1)
            ->equals('And it ended as sent', 1, $kit->count(NotificationStatus::Sent))
            ->fact('concurrent_requests', self::REQUESTS);
    }
}
