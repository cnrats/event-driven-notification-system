# 6. One queue per channel and priority; strict priority plus a fairness pool

## Context

Requirements: priority (high, normal, low) and independent channels. Risks:
a throttled or broken channel holding up the others, and low priority never
being delivered while there is always something more important.

## Decision

- **Queues**: one per channel and priority (`sms-high` ... `push-low`).
- **Workers**: per channel, managed by Horizon as two pools:
  - a **priority pool** (4 processes by default) reading
    `high, normal, low`; a Laravel worker drains its queues strictly in the
    order listed;
  - a **fairness pool** (1 process) reading `low, normal, high`.
- Horizon runs each pool with `balance => false` (one process group handed
  the ordered queue list; the other strategies create a group per queue and
  lose the order) and `minProcesses = maxProcesses` (otherwise an idle pool is
  scaled down to one process).

## Consequences

- A paused or failing channel only stops its own workers.
- High priority is not delayed by a low-priority backlog: in the burst
  scenario the median time to delivery is under 1 s for high, about 9 s for
  normal and about 23 s for low priority.
- Low priority always has at least one worker, so it keeps moving during a
  long high-priority backlog. The share is the ratio of the two pools and can
  be set to zero for strict priority.
- Workers are synchronous. The number needed to *reach* the rate limit is
  about `rate x provider response time`: at 100/s, a provider answering in
  50 ms needs 5 processes per channel, one answering in 200 ms needs 20.

## Alternatives considered

- **One queue with a priority field.** Redis lists have no priority; it would
  need a sorted set and a custom driver.
- **Weighted round robin in one pool.** More even, but needs a custom worker
  loop; two pools reach the same goal with configuration only.
