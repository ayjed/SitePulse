# ADR-0004: Incident State Machine, Flapping Mitigation, and Notification Strategy

**Status:** PROPOSED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
A monitoring system must provide actionable, trusted alerts. If an alert fires on every temporary network blip (false positives) or floods the user's inbox with repeat emails every minute during an outage (alert fatigue), the tool loses its utility.

Furthermore, SitePulse aims to run in environments without long-running queue workers (e.g. `php artisan queue:work` daemon), relying primarily on cron. If notification dispatch blocks synchronous check loops, an unavailable SMTP server could stall health checks for other monitors.

## Options Considered

1. **Option A: Stateless immediate alerts on single check failure**
   - *Pros:* Simplest logic.
   - *Cons:* Causes alert fatigue from transient packet drops; sends an email every check interval while a site remains down.

2. **Option B: Finite State Machine with Consecutive Thresholds & Idempotency Table (Selected)**
   - *Pros:*
     - Requires $N$ consecutive failures (default: 2) before declaring an outage (`DOWN`).
     - Requires $M$ consecutive successes (default: 2) before declaring recovery (`OPERATIONAL`).
     - Alerts fire strictly on state *transitions*, not on steady-state intervals.
     - Persists notification dispatch in an `incident_notifications` table with unique constraints, ensuring absolute idempotency.
     - Outbound email operations are bounded by strict 5-second connection timeouts, preventing SMTP delays from blocking the monitor execution cycle.
   - *Cons:*
     - Minor delay in outage confirmation (e.g., 2 minutes if check interval is 1 minute). This is an acceptable and desirable tradeoff for reliability.

## Decision
1. **Incident State Machine:**
   The monitor's lifecycle moves through 4 states:
   - `OPERATIONAL`: Monitor is healthy.
   - `PENDING_DOWN`: One failed check detected. No alert dispatched.
   - `DOWN`: Two ($N=2$) consecutive failed checks. New `Incident` record opened. "Site Down" email alert queued/dispatched immediately.
   - `PENDING_RECOVERY`: One successful check detected while in `DOWN` state. Site remains marked down; no alert.
   - Back to `OPERATIONAL`: Two ($M=2$) consecutive successful checks. `Incident` record closed (resolved_at timestamp set). "Site Recovered" email alert dispatched.

2. **Notification Delivery & Idempotency:**
   - Every dispatched alert is recorded in `incident_notifications` with `(incident_id, type)`.
   - A unique constraint prevents duplicate emails for the same incident lifecycle event.
   - Email dispatch is executed either via Laravel's `database` queue (processed at the end of the cron tick via `queue:work --stop-when-empty`) or via synchronous mailer with a strict 5-second socket timeout.

## Consequences
- False-positive alerts caused by momentary internet hiccups are eliminated.
- Users receive exactly two emails per outage: one when confirmed DOWN, and one when confirmed RECOVERED.
- Outage metrics (MTTR, duration, incident logs) are accurate and cleanly bounded.

## Evidence & Verification
- Unit tests in Phase 02 and Phase 04 will simulate:
  - Alternating success/fail (flapping) verifying no alerts fire.
  - 2 consecutive failures triggering exactly 1 "DOWN" alert.
  - Ongoing failures (checks 3, 4, 5) triggering 0 additional alerts.
  - 2 consecutive successes triggering exactly 1 "RECOVERED" alert with calculated downtime duration.
