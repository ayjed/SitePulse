# SitePulse Master Phase Tracker

**Project:** SitePulse  
**Current phase:** 01 — Foundation and local development  
**Project status:** IN PROGRESS  
**Approved by:** @ayjed  
**Approval date:** 2026-11-23  
**Last reviewed:** 2026-11-25
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
- [x] Development environment audited and resolved (PHP 8.4.28 NTS, Composer 2.11.2, Node `v26.13.0`, npm `11.20.0`).
- [x] Non-destructive scaffolding executed safely into Git repository.
- [x] Phase 01 remediation Task 1: Diagnosed Windows Smart App Control (`VerifiedAndReputableDesktop`) blocking unsigned `php_openssl.dll` / `php_fileinfo.dll` in sandboxed/uncached execution; documented trusted runtime recommendations without disabling Windows security.
- [x] Phase 01 remediation Task 2: Resolved `composer.lock` back to PHP 8.3+ compatibility via Symfony 7.4 LTS (`composer.json` conflict against `symfony/* >= 8.0`); zero packages exclude PHP 8.3; `composer audit` clean.
- [x] Phase 01 remediation Task 3: Removed predictable `'Test User'` (`test@example.com`) seeding from `DatabaseSeeder` and hardcoded `'password'` from `UserFactory` while preserving factory test support.
- [x] Phase 01 remediation Task 4: Added `App\Support\EnvironmentSetup` and guarded `composer.json` setup scripts so existing `APP_KEY` is preserved across retries.
- [x] Phase 01 remediation Task 5: Generated and committed `package-lock.json`, switched `composer setup` to `npm ci`, and verified `npm run build` (`vite v7.3.8`).
- [x] Phase 01 remediation Task 6: Authored `.github/workflows/ci.yml` covering PHP `8.3` & `8.4`, extensions, `composer audit`, `npm ci` + `npm run build`, SQLite WAL migrations, Pint, Pest tests, and MySQL `8.0` compatibility.
- [x] Phase 01 targeted remediation corrections: Dotenv/Encrypter `APP_KEY` verification, shell-free `proc_open()` array invocation (paths with spaces/`!`), SQLite `mkdir`/`touch` error propagation, SHA-pinned CI actions (`persist-credentials: false`), `config.platform.php` evaluation, `MAIL_TIMEOUT=5`, and aligned setup/SQLite documentation.
- [x] Phase 01 final security corrections: Dotenv-aware top-level edit preserving multiline values (`APP_KEY=part-of-note`), atomic `.env` replacement, leak-free Dotenv errors with synthetic secret-canary verification, and MySQL CI non-root disposable user (`sitepulse_ci`) + loopback binding (`127.0.0.1:33306:3306`).
- [x] PR #1 mandatory security corrections (`fix/phase-01-foundation-remediation`): Staged candidate encryption verification & `bootstrap/cache/config.php` pre-publish guard leaving `.env` byte-for-byte unchanged on failure, exclusive `.env.tmp.<24-hex>` temp creation matching `.gitignore` `.env.*` (`0600` on Unix / NTFS ACLs on Windows, replacing `tempnam()`), and `docker run --env-file` masked disposable MySQL CI without container credential log exposure.
- [x] PR #1 final `APP_KEY` correctness & rollback remediation: Simplified single-stage `.env` lifecycle with SHA-256 effective-key verification (`encrypter->getKey()`), explicit target binding rejecting `.env.<APP_ENV>` / cached-config mismatches, verified rollback + `.env.recovery.<24-hex>` fallback on denied writes, destination symlink/hardlink rejection, and accurate Windows vs Unix permission guarantees.
- [ ] PR #1 live CI verification on replacement commit and independent Codex / maintainer review (`@ayjed`).
- [ ] Phase 01: Build initial owner authentication and CLI provisioner (`sitepulse:create-owner`) after remediation sign-off.
- [ ] Phase 01: Finalize Phase 01 completion evidence and request maintainer approval.

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
| 2026-11-24 | 01 | Task 03 (Remediation on `fix/phase-01-foundation-remediation`): Diagnosed Smart App Control DLL block; restored PHP 8.3+ lockfile compatibility via Symfony 7.4 LTS; removed default account seeding & predictable factory password; made setup repeatable (`EnvironmentSetup`); added `package-lock.json` (`npm ci` + build); authored `.github/workflows/ci.yml` | `php vendor/bin/pest` (8 passing, 40 assertions), `php vendor/bin/pint --test`, `composer audit`, `npm run build` | In Progress |
| 2026-11-24 | 01 | Task 04 (Targeted corrections on `fix/phase-01-foundation-remediation`): Dotenv/Encrypter `APP_KEY` validation & genuine Artisan tests, array-based `proc_open()`, SQLite error checks, SHA-pinned CI, `MAIL_TIMEOUT=5`, aligned docs & `README.md` | `php vendor/bin/pest` (22 passing, 134 assertions), `php vendor/bin/pint --test` (29 files), `composer validate --strict`, `composer audit` | In Progress |
| 2026-11-24 | 01 | Task 05 (Final security corrections on `fix/phase-01-foundation-remediation`): Dotenv-aware top-level chunk editing (preserving multiline values), atomic `.env` writes, leak-free Dotenv errors + secret-canary tests, hardened `mysql-compatibility` CI | `php artisan test` (29 passing, 190 assertions), `php vendor/bin/pint --test` (29 files), `composer validate --strict`, `composer audit`, `npm ci && npm run build` | In Progress |
| 2026-11-25 | 01 | Task 06 (PR #1 mandatory security corrections after Codex FAIL on `c67086960bf0ee28efb8b5508b093bb7d75db9f8`): Pre-publish staged verification + `bootstrap/cache/config.php` failure preservation, `.env.tmp.<24-hex>` exclusive temp files, `docker run --env-file` masked MySQL CI | `2e44c39` passed local `32` tests & GitHub Actions CI (`45510794534`, `45510786337`) cleanly for MySQL, but failed Codex audit on rollback reporting & `.env.testing` mismatch | In Progress |
| 2026-11-25 | 01 | Task 07 (PR #1 final `APP_KEY` correctness & rollback remediation after Codex FAIL on `2e44c397c55064d7c7195fd40073a1ff83ef1407`): Simplified setup lifecycle, SHA-256 `encrypter->getKey()` matching against `.env`/`.env.<APP_ENV>`/cached config, verified rollback & `.env.recovery.<24-hex>` copy, symlink/hardlink checks, Unix vs Windows ACL docs | `php artisan test` (`38 passed, 317 assertions`), `php vendor/bin/pint --test` (`29 files`), `composer validate --strict`, `composer audit`, `verifyApplicationEncryption`, `composer run setup` x2, SQLite WAL `5000ms`, `npm ci && npm run build`; replacement CI pending | In Progress |

## Change approval protocol
1. Propose task with scope and acceptance criteria.
2. Implement only approved phase scope on a branch.
3. Run quality/security checks; record actual commands and outputs.
4. Review diff, document limitations, approve or reject.
5. Update tracker and phase file in same PR.

**Rule:** Never mark a phase `APPROVED` merely because AI says it is complete. The maintainer approves only after checking evidence.
