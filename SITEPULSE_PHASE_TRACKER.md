# SitePulse Master Phase Tracker

**Project:** SitePulse  
**Current phase:** 00 — Discovery & feasibility  
**Project status:** PLANNING  
**Last reviewed:** 2026-11-23  
**Maintainer:** @ayjed  

## Status legend
- `NOT STARTED`: no work approved
- `IN PROGRESS`: active and assigned
- `BLOCKED`: waiting for prerequisite or decision
- `IN REVIEW`: implementation complete, awaiting evidence
- `APPROVED`: exit criteria met and explicitly accepted

## Phase gates
| Phase | Objective | Status | Exit criteria |
|---|---|---|---|
| 00 | Define users, constraints, feasibility, risks | IN PROGRESS | requirements, threat model, technical choices reviewed |
| 01 | Foundation and local development | NOT STARTED | clean scaffold, reproducible setup, tests/CI running |
| 02 | HTTP monitor engine and persistence | NOT STARTED | scheduled checks, history, security tests |
| 03 | Accessible dashboard | NOT STARTED | responsive monitoring UI and E2E smoke check |
| 04 | Alerts, TLS checks, hardening | NOT STARTED | alert deduplication, SSL checks, negative security tests |
| 05 | Packaging, documentation, public release | NOT STARTED | install verified, license/docs/demo/release checks |

## Current next actions
- [x] Identify primary users and minimum hosting tier.
- [x] Compare CLI cron availability and Laravel scheduling assumptions.
- [x] Decide whether arbitrary URLs are supported; document safe fetch policy.
- [x] Confirm v1 feature limits and non-goals.
- [x] Create architecture decision records for stack, security, storage, and alerts (ADR-0001 to ADR-0004).
- [ ] Maintainer review and explicit approval of Phase 00 deliverables before Phase 01 scaffolding.

## Decisions pending
| ID | Decision | Owner | Status | Due phase | Reference |
|---|---|---|---|---|---|
| D-001 | Framework/runtime and minimum hosting | Maintainer | PROPOSED | 00 | [ADR-0001](docs/decisions/ADR-0001-stack.md) |
| D-002 | Database support and migrations | Maintainer | PROPOSED | 00 | [ADR-0003](docs/decisions/ADR-0003-data-retention-and-storage.md) |
| D-003 | Safe URL/check policies | Maintainer | PROPOSED | 00 | [ADR-0002](docs/decisions/ADR-0002-safe-http-client.md) |
| D-004 | Email notification delivery strategy | Maintainer | PROPOSED | 00 | [ADR-0004](docs/decisions/ADR-0004-incident-state-and-alerts.md) |

## Risks and mitigations
| Risk | Severity | Mitigation | Status |
|---|---|---|---|
| SSRF / private network probing | Critical | strict destination policy, DNS/IP checks, redirects restricted, pinned socket via `CURLOPT_RESOLVE` | MITIGATION DESIGNED (ADR-0002) |
| Misleading shared-hosting compatibility | High | verify on real target hosts, document requirements (PHP 8.3 CLI + cron required) | MITIGATION DESIGNED (ADR-0001) |
| Duplicate notifications | Medium | incident state machine ($N=2$ fail threshold) and notification idempotency table | MITIGATION DESIGNED (ADR-0004) |
| Failed scheduled tasks | High | heartbeat, failure logs, `withoutOverlapping` mutex, recovery tests | MITIGATION DESIGNED (ADR-0001) |
| Unbounded database growth | Medium | daily scheduled pruning command (`sitepulse:prune-checks`) with 30-day default window | MITIGATION DESIGNED (ADR-0003) |
| Over-reliance on AI code | Medium | small PRs, tests, human review and rollback plan | OPEN |

## Active work log
| Date | Phase | Change | Evidence | Review |
|---|---|---|---|---|
| 2026-11-23 | 00 | Planning starter pack initialized | Repository documentation | Initial draft |
| 2026-11-23 | 00 | Phase 00 architectural discovery review & ADR formulation | Updated PRD, ARCHITECTURE, SECURITY, and ADRs 0001–0004 | In Review (Maintainer Gate) |

## Change approval protocol
1. Propose task with scope and acceptance criteria.
2. Implement only approved phase scope on a branch.
3. Run quality/security checks; record actual commands and outputs.
4. Review diff, document limitations, approve or reject.
5. Update tracker and phase file in same PR.

**Rule:** Never mark a phase `APPROVED` merely because AI says it is complete. The maintainer approves only after checking evidence.
