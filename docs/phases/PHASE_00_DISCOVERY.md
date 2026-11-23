# Phase 00 — Discovery and Feasibility

**Status:** IN PROGRESS  
**Owner:** Maintainer (@ayjed)  
**Gate:** Explicit review required before Phase 01.

## Goal
Validate that a secure and useful monitoring MVP can run on the target hosting model.

## Tasks
- [x] Write user problem statement and 2–3 real use cases.
- [x] Decide minimum deployment requirements (PHP CLI/cron/mail/database).
- [x] Compare Laravel vs lighter alternatives against hosting budget.
- [x] Confirm MVP features and explicit non-goals.
- [x] Map data model: monitor, check result, incident, notification.
- [x] Create threat model for untrusted monitor URLs.
- [x] Specify initial alert and outage state rules.
- [x] Record critical architecture decisions as ADRs (ADR-0001, ADR-0002, ADR-0003, ADR-0004).
- [x] Estimate operating cost and identify external service dependencies.

## Acceptance criteria
- [ ] Requirements and non-goals agreed.
- [ ] Documented deployment feasibility and limitations.
- [ ] Architecture and SSRF protection approach reviewed.
- [ ] Implementation tasks are sufficiently small for Phase 01.
- [ ] Maintainer explicitly approves Phase 00.

## Deliverables
- [`docs/REQUIREMENTS.md`](../REQUIREMENTS.md)
- [`docs/ARCHITECTURE.md`](../ARCHITECTURE.md)
- [`docs/SECURITY.md`](../SECURITY.md)
- [`docs/decisions/ADR-0001-stack.md`](../decisions/ADR-0001-stack.md)
- [`docs/decisions/ADR-0002-safe-http-client.md`](../decisions/ADR-0002-safe-http-client.md)
- [`docs/decisions/ADR-0003-data-retention-and-storage.md`](../decisions/ADR-0003-data-retention-and-storage.md)
- [`docs/decisions/ADR-0004-incident-state-and-alerts.md`](../decisions/ADR-0004-incident-state-and-alerts.md)
- Master tracker: [`SITEPULSE_PHASE_TRACKER.md`](../../SITEPULSE_PHASE_TRACKER.md)

## Review log
- 2026-11-23: Draft created; no approval claimed.
- 2026-11-23: Lead software architect completed comprehensive Phase 00 discovery review. Requirements, architecture, SSRF threat model, data model, and ADRs (0001–0004) documented and submitted for maintainer review. Phase remains IN PROGRESS awaiting maintainer sign-off.
