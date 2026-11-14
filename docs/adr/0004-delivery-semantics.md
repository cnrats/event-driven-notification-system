# 4. At-least-once delivery, made safe by an idempotency key

## Context

A worker sends a message to the provider and then records the result. These
are two steps, and the process can die, or the response can be lost, in
between. From the sender's side "the provider never got it" and "the provider
got it but I did not hear back" look identical.

Exactly-once delivery across a network is not available. The choice is
between possibly losing a message (at-most-once) and possibly sending it twice
(at-least-once).

## Decision

**At-least-once, with a stable idempotency key so the second send is not a
second delivery.**

- A worker **claims** the notification before sending: one conditional
  `UPDATE` moves it from `queued` to `processing` and sets a lease
  (`locked_until`, 30 seconds). Exactly one worker can win; duplicates of the
  job find nothing to claim and stop.
- The request to the provider carries `Idempotency-Key: <notification id>`.
  The id never changes, so every retry of the same notification carries the
  same key.
- If the worker dies or the response is lost, the lease runs out, the sweeper
  puts the row back in the queue (the queue's own `retry_after` does the same
  for the job), and another worker sends it again **with the same key**.
- A cancel only succeeds while the notification is `scheduled`, `queued` or
  `retrying`. Once claimed, it is being sent.

Timing chain: provider timeout 5 s < job timeout 20 s < lease 30 s <
`retry_after` 45 s.

## Consequences

- Nothing is lost: the `crash` scenario kills workers mid-request and every
  notification still ends as `sent`.
- Nothing is delivered twice **if the provider honours the key**: in the
  `crash` and `lost-response` scenarios the resends are recognised and the
  provider's count of unique deliveries equals the number of notifications.
- The guarantee depends on the provider. The bundled mock honours the key, as
  real SMS and email providers do. webhook.site does not; against it a resend
  after a lost response would show up as a second request.
- A notification stuck behind a dead worker is delayed by the lease (30 s)
  plus the sweeper's grace period.
- If a response is lost on every attempt, the notification ends as `failed`
  although it was delivered. This is the residual uncertainty of any
  at-least-once design; the delivery attempts on the notification show what
  happened.

## Alternatives considered

- **Mark as sent before sending** (at-most-once). A crash then loses the
  message silently. For notifications, a missed one-time code is worse than a
  recognisable repeat.
- **Rely on the queue's `ShouldBeUnique`.** It prevents duplicate *jobs*, not
  duplicate *sends* after a crash, and its lock lives in the cache.
