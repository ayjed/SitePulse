# SitePulse Master Phase Tracker

**Project:** SitePulse  
**Current phase:** 01 — Foundation and local development  
**Project status:** IN PROGRESS  
**Approved by:** @ayjed  
**Approval date:** 2026-11-23  
**Last reviewed:** 2026-11-24  
**Maintainer:** @ayjed  
**Git tracking:** Branch `main` tracking `origin/main` (`https://github.com/ayjed/SitePulse.git`)

## Status legend
- `NOT STARTED`: no work approved
- `IN PROGRESS`: active and assigned
- `BLOCKED`: waiting for prerequisite or decision
- `IN REVIEW`: implementation complete, awaiting evidence
- `APPROVED`: exit criteria met and explicitly accepted

## Phase gates
| Phase | Objective | Status | Exit criteria |
|---|---|---|---|
| 00 | Define users, constraints, feasibility, risks | APPROVED | requirements, threat model, technical choices reviewed |
| 01 | Foundation and local development | IN PROGRESS | clean scaffold, reproducible setup, tests/CI running |
| 02 | HTTP monitor engine and persistence | NOT STARTED | scheduled checks, history, security tests |
| 03 | Accessible dashboard | NOT STARTED | responsive monitoring UI and E2E smoke check |
| 04 | Alerts, TLS checks, hardening | NOT STARTED | alert deduplication, SSL checks, negative security tests |
| 05 | Packaging, documentation, public release | NOT STARTED | install verified, license/docs/demo/release checks |

## Current next actions
- [x] Phase 00 maintainer review and explicit approval (@ayjed).
- [x] Git remote verified (`https://github.com/ayjed/SitePulse.git`) and branch history synchronized.
- [x] Development environment audited and resolved (PHP 8.4.28 NTS, Composer 2.11.2, required extensions enabled).
- [x] Non-destructive scaffolding executed safely into Git repository.
- [x] Composer dependency resolution confirmed (Laravel 13.43.0, Pest 4.7.8, PHPUnit 12.5.33, Livewire 4.4.9).
- [x] Local `.env.example`, `.env`, and SQLite WAL mode (busy_timeout 5000ms) verified.
- [x] Framework boot verified and baseline automated tests executed passing (Pest 4 / PHPUnit 12).
- [ ] Phase 01: Build initial owner authentication and CLI provisioner (`sitepulse:create-owner`).
- [ ] Phase 01: Configure CI pipeline (GitHub Actions) for linting, testing, and security checks.
- [ ] Phase 01: Prepare Phase 01 completion report and request maintainer review.

## Decisions pending
| ID | Decision | Owner | Status | Due phase | Reference |
|---|---|---|---|---|---|
| D-001 | Framework (Laravel 13 on PHP 8.3+), Pest 4 with PHPUnit 12, Livewire 4 & Hosting Targets | Maintainer | ACCEPTED | 00 | [ADR-0001](docs/decisions/ADR-0001-stack.md), [HOSTING_REQUIREMENTS](docs/HOSTING_REQUIREMENTS.md) |
| D-002 | Database (SQLite WAL default + MySQL), Portable Monitor Ordering & Batch Pruning | Maintainer | ACCEPTED | 00 | [ADR-0003](docs/decisions/ADR-0003-data-retention-and-storage.md) |
| D-003 | Safe HTTP Client, Strict IP Validation & Peer Verification | Maintainer | ACCEPTED | 00 | [ADR-0002](docs/decisions/ADR-0002-safe-http-client.md), [SECURITY](docs/SECURITY.md) |
| D-004 | Incident State Machine & Notification Deduplication | Maintainer | ACCEPTED | 00 | [ADR-0004](docs/decisions/ADR-0004-incident-state-and-alerts.md) |

## Risks and mitigations
| Risk | Severity | Mitigation | Status |
|---|---|---|---|
| SSRF / private network probing | Critical | Strict canonical IP validation, rejection of ambiguous IPs (leading zeros, hex, octal, dword), CIDR denylists, `CURLOPT_RESOLVE` socket pinning, post-connect `CURLINFO_PRIMARY_IP` check, proxy isolation | MITIGATION DESIGNED (ADR-0002, SECURITY.md) |
| Scheduler process overruns / stalls | High | Monotonic execution deadline (`hrtime` 45s/50s), in-flight handle cancellation, 10-minute lock expiration TTL, interruption recovery | MITIGATION DESIGNED (ARCHITECTURE.md, HOSTING_REQUIREMENTS.md) |
| Duplicate notifications / Alert fatigue | Medium | Incident state machine ($N=2$ fail threshold, $M=2$ recovery) and notification idempotency table | MITIGATION DESIGNED (ADR-0004) |
| Unbounded database growth | Medium | Daily scheduled pruning command (`sitepulse:prune-checks`) with 30-day default window and batch writes | MITIGATION DESIGNED (ADR-0003) |
| Over-reliance on unverified performance claims | Medium | Replaced dogmatic guarantees with measurable engineering targets; benchmarks and CI tests required | MITIGATION DESIGNED (ADR-0001, ADR-0003) |

## Active work log
| Date | Phase | Change | Evidence | Review |
|---|---|---|---|---|
| 2026-11-23 | 00 | Planning starter pack initialized | Repository documentation | Initial draft |
| 2026-11-23 | 00 | Phase 00 architectural discovery review & ADR formulation | Updated PRD, ARCHITECTURE, SECURITY, ADRs 0001–0004 | In Review |
| 2026-11-23 | 00 | Phase 00 architecture revision | PRD, ARCHITECTURE, SECURITY, HOSTING_REQUIREMENTS, TEST_PLAN, ADR-0001 to ADR-0003 | In Review |
| 2026-11-23 | 00 | Phase 00 final sign-off prep: Pest 4 with PHPUnit 12, portable SQL monitor ordering, DB tests added | PRD, ARCHITECTURE, SECURITY, HOSTING_REQUIREMENTS, TEST_PLAN, ADR-0001 to ADR-0003 | In Review |
| 2026-11-23 | 00 | Phase 00 architectural direction officially APPROVED by maintainer (@ayjed) | ADR-0001 to ADR-0004 ACCEPTED | APPROVED |
| 2026-11-23 | 01 | Task 01: Git verified, environment audited, scaffold planned; BLOCKED by PHP 8.2 & missing Composer | docs/phases/PHASE_01_FOUNDATION.md | BLOCKED |
| 2026-11-24 | 01 | Task 02: Environment resolved (PHP 8.4, Composer 2.11), Laravel 13 scaffolded, dependencies locked (Pest 4, PHPUnit 12, Livewire 4), SQLite WAL configured, baseline tests passing | `composer.lock`, `php artisan about`, `php artisan test` (2 passing) | In Progress |

## Change approval protocol
1. Propose task with scope and acceptance criteria.
2. Implement only approved phase scope on a branch.
3. Run quality/security checks; record actual commands and outputs.
4. Review diff, document limitations, approve or reject.
5. Update tracker and phase file in same PR.

**Rule:** Never mark a phase `APPROVED` merely because AI says it is complete. The maintainer approves only after checking evidence.
