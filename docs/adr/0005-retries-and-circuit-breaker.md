# 5. Retries, throttling and the circuit breaker

## Context

The brief leaves delivery and retry logic to the candidate. Three different
things can go wrong when calling the provider, and they need three different
reactions:

1. The provider rejects the message (bad recipient, bad content).
2. The provider has a temporary problem (5xx, timeout).
3. The provider is fine but busy and asks us to slow down (429).

On top of that, a provider that is down entirely is not "many temporary
problems"; treating it as such makes things worse.

## Decision

**Classification.** Every response becomes one outcome:

| Outcome | When | Reaction |
|---|---|---|
| accepted | 2xx | `sent` |
| permanent | 4xx except 408, 425, 429 | `failed` at once; retrying cannot help |
| retryable | 5xx, 408, 425, timeout, connection error | retry with backoff, up to 5 attempts |
| throttled | 429 | pause the channel; **not** counted as an attempt |

**Backoff.** Exponential with equal jitter: half of the delay is fixed, half
is random. Base 2 s, factor 3, cap 5 min, so the waits are at most 2, 6, 18
and 54 seconds. The jitter keeps notifications that failed in the same second
from all retrying in the same second.

**Throttling.** On a 429 the rate limiter's pointer for that channel is moved
forward by `Retry-After`. All workers stop sending on that channel without
each having to receive its own 429, and the attempt is given back, so being
throttled can never exhaust the retry budget.

**Circuit breaker**, per channel, in Redis. After 20 consecutive retryable
failures the circuit opens for 15 seconds: jobs are put back without calling
the provider and without spending an attempt. Then a single job is let
through as a probe. Success closes the circuit, failure opens it again.
Rejections and throttling do not count; they say nothing about the provider's
health.

**Dead letters.** A notification that used all attempts stays in the table
as `failed` with its last error and its delivery attempts. It can be listed
(`?status=failed`) and retried (`POST /notifications/{id}/retry`). There is
no separate dead-letter queue to keep in sync.

## Consequences

Measured in `docs/results`:

- 30 % random failures: 99.9 % delivered, the remainder marked failed with
  the reason.
- Six seconds of 429: 14 requests answered with 429 instead of about 600; all
  notifications delivered on their first attempt.
- Twelve seconds of outage: 24 requests sent into it instead of about 1,200;
  no notification failed.

Trade-offs:

- "Consecutive failures" is simple and cannot detect a provider that fails,
  say, 60 % of the time; that case is left to the per-notification retries.
- While a circuit is open, waiting jobs are popped and released once per
  cooldown. Pausing the queue itself would avoid that churn.
