# 1. The database row is the source of truth; a sweeper feeds the queue

## Context

Accepting a notification touches two systems: MySQL (the record) and Redis
(the job). They cannot be updated atomically. If the API writes both and the
process dies in between, either a notification exists that no worker will ever
pick up, or a job exists for a notification that was rolled back.

Scheduled notifications and retries add a second question: where does "send
this at 09:00" or "try again in 18 seconds" live? Laravel's answer is a
delayed job in Redis.

## Decision

- A notification is **accepted when its row is committed**. The API answers
  `201`/`202` on that basis alone.
- The push to Redis happens **after** the commit and is best effort. The row
  records it (`enqueued_at`). If Redis is down the client still gets its
  `201`; nothing is lost.
- A small long-running process, the **sweeper**, moves rows into the queue:
  - `scheduled` and `retrying` rows whose `next_attempt_at` has passed,
  - `processing` rows whose lease expired (the worker died),
  - `queued` rows whose push was never confirmed,
  - `queued` rows that were pushed long ago and never picked up (job lost).
- **No delayed jobs.** A schedule and a retry are both "a row that is due at
  T". One mechanism handles both.
- The job payload is only the notification id.

## Consequences

- Redis can be restarted or flushed without losing a notification, a schedule
  or a retry. Everything is rebuilt from the database.
- The sweeper may enqueue a notification that already has a job. That is safe:
  the worker claims the row with a conditional `UPDATE`, so only one job
  delivers it (ADR 4).
- Scheduling and retry precision is the sweep interval (one second). Fine for
  notifications; not for sub-second timing.
- The sweeper is one more process to run. Several can run side by side
  (`SELECT ... FOR UPDATE SKIP LOCKED`), so it is not a single point of
  failure when deployed with more than one instance.
- "Lost job" recovery uses a time threshold (15 minutes by default). With a
  backlog older than that, some rows get a second job. Harmless, but wasted
  work; the threshold should exceed the longest backlog expected.

## Alternatives considered

- **Transactional outbox table.** Same guarantee, but the notification row
  already is the outbox; a second table would duplicate it.
- **Dispatch inside the transaction with `after_commit`.** Still a dual
  write: commit succeeds, push fails, row is stranded. It needs the sweeper
  anyway.
- **Delayed jobs for schedules and retries.** Works until Redis loses data,
  and makes "cancel a scheduled notification" depend on finding a job in a
  sorted set.
