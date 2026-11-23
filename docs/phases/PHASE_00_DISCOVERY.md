# Phase 00 — Discovery and Feasibility

**Status:** IN PROGRESS  
**Owner:** Maintainer (@ayjed)  
**Gate:** Explicit maintainer review required before Phase 01.

## Goal
Validate that a secure and useful monitoring MVP can run on the target hosting model.

## Tasks
- [x] Write user problem statement and 2–3 real use cases.
- [x] Decide minimum deployment requirements (PHP CLI/cron/mail/database).
- [x] Formulate hosting feasibility specifications ([`docs/HOSTING_REQUIREMENTS.md`](../HOSTING_REQUIREMENTS.md)).
- [x] Evaluate application framework baseline: Laravel 13 on PHP 8.3+.
- [x] Record dependency compatibility requirements: Pest 4 with PHPUnit 12, Livewire 4.
- [x] Confirm MVP features and explicit non-goals.
- [x] Map data model: monitor, check result, incident, notification.
- [x] Formulate comprehensive threat model, strict canonical IP validation, and 16-point security test matrix ([`docs/SECURITY.md`](../SECURITY.md)).
- [x] Specify initial alert and outage state rules ($N=2$ fail threshold, idempotency).
- [x] Specify monotonic scheduler deadline (45s/50s), handle cancellation, lock TTL lifecycle, and batch write targets.
- [x] Record critical architecture decisions as ADRs (ADR-0001 to ADR-0004).
- [x] Develop comprehensive test plan & acceptance criteria ([`docs/TEST_PLAN.md`](../TEST_PLAN.md)).
- [x] Confirm Git environment and remote tracking status.

## Acceptance criteria
- [ ] Requirements and non-goals agreed.
- [ ] Documented deployment feasibility and limitations.
- [ ] Architecture and SSRF protection approach reviewed.
- [ ] Implementation tasks are sufficiently small for Phase 01.
- [ ] Maintainer explicitly approves Phase 00.

## Deliverables
- Requirements: [`docs/REQUIREMENTS.md`](../REQUIREMENTS.md)
- Architecture: [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md)
- Security & Threat Model: [`docs/SECURITY.md`](../SECURITY.md)
- Hosting Requirements: [`docs/HOSTING_REQUIREMENTS.md`](../HOSTING_REQUIREMENTS.md)
- Quality & Test Plan: [`docs/TEST_PLAN.md`](../TEST_PLAN.md)
- Stack Decision: [`docs/decisions/ADR-0001-stack.md`](../decisions/ADR-0001-stack.md)
- Safe Client Decision: [`docs/decisions/ADR-0002-safe-http-client.md`](../decisions/ADR-0002-safe-http-client.md)
- Storage & Retention Decision: [`docs/decisions/ADR-0003-data-retention-and-storage.md`](../decisions/ADR-0003-data-retention-and-storage.md)
- Incident & Alerts Decision: [`docs/decisions/ADR-0004-incident-state-and-alerts.md`](../decisions/ADR-0004-incident-state-and-alerts.md)
- Master Tracker: [`SITEPULSE_PHASE_TRACKER.md`](../../SITEPULSE_PHASE_TRACKER.md)

## Review log
- 2026-11-23: Initial draft created; no approval claimed.
- 2026-11-23: Phase 00 discovery review completed.
- 2026-11-23: Phase 00 architecture revision completed.
- 2026-11-23: Phase 00 final technical corrections completed:
  - Testing baseline specified as Pest 4 with PHPUnit 12 (actual package resolution verified in Phase 01).
  - Replaced `NULLS FIRST` with cross-database portable SQL ordering (`ORDER BY (CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC, last_checked_at ASC`) compatible with SQLite and MySQL.
  - Added cross-database due monitor prioritization tests (DB-01) to test plan.
  - Phase remains `IN PROGRESS` / `IN REVIEW` awaiting maintainer gate review.
