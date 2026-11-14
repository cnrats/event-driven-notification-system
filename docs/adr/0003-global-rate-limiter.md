# 3. Rate limiting: evenly spaced slots shared by all workers

## Context

"Maximum 100 messages per second per channel" is a property of what the
provider receives. It has to hold:

- across all worker processes, however many there are,
- in every one-second window, not only in windows aligned to the clock,
- during a burst, when thousands of jobs want to send at once.

## Decision

A limiter in Redis that hands out **time slots spaced 1/rate apart** (GCRA
with no burst allowance). Per channel Redis holds one value: the time of the
next free slot. One Lua script, using Redis' own clock:

1. `slot = max(next_free_slot, now)`
2. `next_free_slot = slot + interval`
3. return how long the caller must wait for `slot`

The worker sleeps that long **in-process** and then sends. The slot is taken
as the last step before the request, after the row is claimed, so nothing
with a variable duration sits between the slot and the request.

The limiter spaces the slots; the sender still has to hit them. Three things
keep what the provider sees close to what the limiter handed out:

- The interval is 1/rate plus a **5 % safety margin** (10.5 ms for 100/s).
  The limit is a ceiling at the provider; with this margin a message may
  arrive up to 50 ms late without pushing 101 arrivals into one second.
- A worker that wakes up more than 10 ms late for its slot (CPU contention,
  garbage collection) **gives the slot up and takes a fresh one** instead of
  sending late, bunched with the messages behind it.
- Each worker **keeps its connection to the provider open**, so a request
  does not start with a TCP handshake of unpredictable length.

When the provider answers `429` with `Retry-After`, the limiter's pointer is
moved forward by that time. Every worker then backs off together.

## Consequences

- **Measured at the provider**: see `docs/results/burst.json` and
  `burst-double-workers.json` for the largest number of requests in any
  one-second window, per channel, with the default and with twice the number
  of workers.
- The first measurement with twice the workers found 101 in one second on one
  channel. The slots were spaced correctly; the messages were not sent on
  time. That finding is the reason for the second and third point above: a
  larger margin alone would only have hidden it.
- Adding workers never raises the rate. Workers only need to be numerous
  enough to *reach* it: roughly rate x provider response time.
- A waiting worker holds one PHP process for up to a few tens of
  milliseconds. With N workers per channel the wait is at most N intervals.
- The 5 % margin costs 5 % of the allowed throughput: about 95 messages per
  second per channel instead of 100.
- One Redis round trip per message. Redis is already on the hot path for the
  queue.

## Alternatives considered

- **`Redis::throttle()` / `RateLimited` middleware.** A fixed-window counter
  (`Illuminate\Redis\Limiters\DurationLimiter`): 100 messages at 0.99 s and
  100 more at 1.01 s both pass, so a one-second window can see 200. It also
  releases the job when limited, which costs a queue attempt (ADR 2).
- **Sliding-window log** (a sorted set of timestamps). Correct, but stores
  one entry per message and still needs release-and-retry when full.
- **Token bucket per worker process.** No shared state, but the limit becomes
  N x 100 with N workers and has to be divided by hand.
