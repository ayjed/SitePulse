# Phase 04 — Alerts, TLS, and Hardening

**Status:** NOT STARTED

## Proposed execution sequence

Phase 04 requires separate maintainer authorization after Phase 03 functional closeout. Reuse the [final audit's dependency and risk assessment](../audits/PHASE_03_FINAL_ACCEPTANCE.md#7-phase-04-readiness-and-proposed-sequence).

| Task | Depends on | Outcome |
|---|---|---|
| 04-01 — Requirements and existing engine reconciliation | Phase 03 functional closeout and maintainer authorization | Confirm FR-5 and FR-6 gaps, TLS failure semantics and the existing alert delivery contract. Obtain separate approval for architectural changes. |
| 04-02 — TLS and transport verification | 04-01 | Verify certificate threshold, expiry, renewal and negative transport behavior with controlled fixtures. |
| 04-03 — Alert delivery, deduplication and retry verification | The event contract from 04-01 and TLS evidence from 04-02 | Verify transition and renewal events, claims, retries and recipient controls. Record the SMTP acknowledgement gap. |
| 04-04 — Security and acceptance evidence | 04-02 and 04-03 | Run the security and portability suites and collect maintainer review evidence. |

## Scope
- [ ] TLS expiry checks with clear failure semantics.
- [ ] Email alerts for confirmed incidents and recoveries.
- [ ] Notification deduplication and retry behavior.
- [ ] Full negative-path and SSRF regression tests.
- [ ] Authentication, rate limit and error-reporting review.

## Exit criteria
- [ ] No duplicate alerts during rechecks/retries.
- [ ] HTTPS expiry cases verified.
- [ ] Security test plan passes.
- [ ] Maintainer approves evidence.
