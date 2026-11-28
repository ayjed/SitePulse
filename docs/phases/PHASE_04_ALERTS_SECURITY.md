# Phase 04 — Alerts, TLS, and Hardening

**Status:** IN PROGRESS (P04-01 requirements and existing engine reconciliation active)

## Proposed execution sequence

The maintainer authorized P04-01 after Phase 03 conditional functional approval took effect through PR #15 at `1034bd6e936aa2a25d49b8ec4f39a19a9c820439`. The [P04-01 reconciliation](../audits/P04_01_REQUIREMENTS_RECONCILIATION.md) maps evidence, gaps and proposed decisions. P04-02 through P04-04 require subsequent authorization.

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
- [ ] Meet the existing no-duplicate-alert criterion during rechecks and retries. P04-01 identifies its unresolved distinction between unique database events and external SMTP acceptance. Any wording change requires maintainer approval.
- [ ] HTTPS expiry cases verified.
- [ ] Security test plan passes.
- [ ] Maintainer approves evidence.
