# 2. Delivery attempts are counted on the notification, not by the queue

## Context

The obvious way to retry in Laravel is `$tries = 5` with a `backoff` on the
job. The obvious way to rate limit is to `release()` a job back to the queue
when the limit is reached.

Combined, these two are a trap. Laravel increments a job's attempt counter
**every time the job is popped**, in the Lua script that reserves it
(`Illuminate\Queue\LuaScripts::pop`). A job that is popped, finds the rate
limit reached and releases itself has used an attempt. The documentation says
so: *"Releasing a rate limited job back onto the queue will still increment
the job's total number of attempts."*

During a burst this is exactly what happens to most jobs. With 10,000
notifications queued and 100 per second allowed, a job near the end of the
queue waits for over a minute. If each wait costs one of five attempts, the
queue marks it failed, although the provider never saw it, let alone
rejected it.

`tests/Feature/Delivery/QueueAttemptsTest.php` reproduces this with a real
worker: a job with three tries that only ever waits ends in `failed_jobs`
with `MaxAttemptsExceededException`.

## Decision

Two counters with two meanings:

- **Delivery attempts** (`notifications.attempts`): incremented when a worker
  claims the row to call the provider. This is what `max_attempts` limits and
  what the API shows.
- **Queue attempts**: unlimited. The job declares `Tries(0)`.

The queue still protects against real defects:

- `MaxExceptions(3)`: an *uncaught exception* is a bug or a poison job; after
  three the job fails and its `failed()` hook marks the notification failed.
- `CountCrashesAsExceptions`: a worker killed mid-job (OOM, SIGKILL) throws
  nothing; without this attribute such a job would be redelivered forever.
- `Backoff([5, 30])`: so three exceptions are not burned in milliseconds.

Provider failures are never exceptions. The handler turns every response into
an outcome and records it.

Waiting is designed not to pop the job in the first place: a worker waits for
its rate-limit slot in-process (ADR 3). The cases that do release the job
(open circuit, channel paused by the provider) are free.

## Consequences

- The burst scenario delivers 10,000 notifications with zero failures and
  exactly one delivery attempt each.
- A notification's attempt count means what a reader expects: how often the
  provider was called.
- Unlimited queue attempts need another bound, or a job could circulate
  forever. That bound is the notification's time to live (`expires_at`,
  24 hours by default), checked every time the job runs.

## Alternatives considered

- **`retryUntil()`** instead of `Tries(0)`. It is fixed when the job is
  dispatched and makes the worker fail the job at pop time once passed, which
  again fails notifications for waiting. The deadline is enforced from the
  row instead.
- **A very high `tries`.** Hides the problem until a larger burst.
