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
- [x] Confirm MVP features and explicit non-goals.
- [x] Map data model: monitor, check result, incident, notification.
- [x] Formulate comprehensive threat model & 11-layer SSRF defense ([`docs/SECURITY.md`](../SECURITY.md)).
- [x] Specify initial alert and outage state rules ($N=2$ fail threshold, idempotency).
- [x] Specify scheduler concurrency, timeout budgets, interruption recovery, and batch writes.
- [x] Record critical architecture decisions as ADRs (ADR-0001, ADR-0002, ADR-0003, ADR-0004).
- [x] Develop comprehensive test plan & acceptance criteria ([`docs/TEST_PLAN.md`](../TEST_PLAN.md)).

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
- 2026-11-23: Phase 00 architecture revision completed:
  - Framework baseline updated to Laravel 13 on PHP 8.3+.
  - Comprehensive 11-layer SafeHttpClient SSRF defense design specified with 14 concrete security test scenarios.
  - Scheduler concurrency (5 workers), per-request timeouts, 50s execution cap, mutex locking, interruption recovery, and batch database transactions defined.
  - Dedicated `docs/HOSTING_REQUIREMENTS.md` created.
  - Test plan updated with exact acceptance criteria.
  - Workspace Git environment investigated and reported.
  - Phase remains `IN PROGRESS` awaiting maintainer gate review.
