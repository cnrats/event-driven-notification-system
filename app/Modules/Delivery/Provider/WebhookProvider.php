<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Provider;

use App\Support\NotificationsConfig;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The provider contract from the brief:
 *
 *   POST {url}  {"to": "...", "channel": "sms", "content": "..."}
 *   202         {"messageId": "...", "status": "accepted", "timestamp": "..."}
 *
 * Works against the bundled mock and against a webhook.site URL.
 */
final class WebhookProvider implements NotificationProvider
{
    /**
     * One Guzzle handler per worker process. Its connection to the provider
     * is kept alive and reused between jobs: a request then costs no TCP
     * handshake, which is faster and, more importantly here, far more even,
     * so messages reach the provider at the spacing the rate limiter set.
     *
     * @var (callable(RequestInterface, array<string, mixed>): PromiseInterface)|null
     */
    private static $handler;

    public function __construct(
        private readonly Factory $http,
        private readonly NotificationsConfig $config,
    ) {}

    public function send(OutboundMessage $message): ProviderResult
    {
        $headers = array_filter([
            // The notification ID never changes across retries, so a provider
            // that honours this header delivers the message at most once even
            // if we resend after a lost response.
            'Idempotency-Key' => $message->notificationId,
            'X-Correlation-ID' => $message->correlationId,
            'traceparent' => $message->traceContext,
        ], static fn (?string $value): bool => $value !== null);

        $startedAt = hrtime(true);

        try {
            $response = $this->http
                ->connectTimeout($this->config->providerConnectTimeoutSeconds)
                ->timeout($this->config->providerTimeoutSeconds)
                ->setHandler(self::$handler ??= Utils::chooseHandler())
                ->withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->post($this->config->providerUrl, [
                    'to' => $message->recipient,
                    'channel' => $message->channel->value,
                    'content' => $message->content,
                ]);
        } catch (ConnectionException $e) {
            // Timeout or connection failure. The provider may or may not have
            // received the message; the idempotency key makes a retry safe.
            return new ProviderResult(
                ProviderOutcome::Retryable,
                $this->secondsSince($startedAt),
                error: 'connection: '.$e->getMessage(),
            );
        }

        return $this->interpret($response, $this->secondsSince($startedAt));
    }

    private function interpret(Response $response, float $latency): ProviderResult
    {
        $status = $response->status();

        if ($response->successful()) {
            $messageId = $response->json('messageId');

            return new ProviderResult(
                ProviderOutcome::Accepted,
                $latency,
                httpStatus: $status,
                messageId: is_string($messageId) ? $messageId : null,
            );
        }

        if ($status === 429) {
            return new ProviderResult(
                ProviderOutcome::Throttled,
                $latency,
                httpStatus: $status,
                error: 'provider asked to slow down (HTTP 429)',
                retryAfterSeconds: $this->retryAfter($response->header('Retry-After')),
            );
        }

        // 408 and 425 are the client-error codes that explicitly invite a retry.
        $retryable = $response->serverError() || in_array($status, [408, 425], true);

        return new ProviderResult(
            $retryable ? ProviderOutcome::Retryable : ProviderOutcome::Permanent,
            $latency,
            httpStatus: $status,
            error: sprintf('provider responded with HTTP %d: %s', $status, mb_strimwidth($response->body(), 0, 200, '…')),
        );
    }

    /**
     * Retry-After is either a number of seconds or an HTTP date.
     */
    private function retryAfter(string $header): int
    {
        if (ctype_digit($header)) {
            return max(1, min(300, (int) $header));
        }

        try {
            $seconds = (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($header), false));
        } catch (Throwable) {
            $seconds = 1;
        }

        return max(1, min(300, $seconds));
    }

    private function secondsSince(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000_000;
    }
}
