# SitePulse Master Phase Tracker

**Project:** SitePulse  
**Current phase:** 02 — HTTP monitor engine and persistence  
**Project status:** IN PROGRESS  
**Approved by:** @ayjed (Phase 01 approved 2026-11-27)  
**Approval date:** 2026-11-27  
**Last reviewed:** 2026-11-28
**Maintainer:** @ayjed  
**Git tracking:** Branch `feat/phase-02-incidents-retention` (from `main` `57ed871e`; `https://github.com/ayjed/SitePulse.git`)

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
| 01 | Foundation and local development | APPROVED | clean scaffold, reproducible setup, tests/CI running |
| 02 | HTTP monitor engine and persistence | IN PROGRESS | scheduled checks, history, security tests |
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
- [x] PR #1 custom `APP_CONFIG_CACHE` remediation: Validated and inspected effective cache paths from process env, `.env`, active `.env.<APP_ENV>`, and default `bootstrap/cache/config.php` matching Laravel `LoadConfiguration` resolution, failing closed on unsafe/ambiguous/existing caches without mutating `.env` or deleting caches.
- [x] PR #1 live CI verification on `1603b15` (`pull_request` run `45518735867` & `push` run `45518727520`: all 6 jobs passed across `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0` with `41` tests / `386` assertions per job), followed by Symfony 7.4 LTS advisory updates (`c615775`; CI `46041927707` / `46041923653`) and PR #1 merge into `main` at `d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` (`46042368731` passed).
- [x] Phase 01 Task 10 recovery & retrospective security review completed on `feat/phase-01-owner-authentication-reviewed` (`f1e4886`; CI runs `46051330107` & `46051323625`: all 6 jobs passed with `65` tests / `592` assertions per job) and merged via PR #2 (`https://github.com/ayjed/SitePulse/pull/2`) into `main` at `776333f2320ca52c7d2f7255305fc100f818a9b3` (post-merge CI `46053500009` passed).
- [x] Phase 01 branch protection enabled on `main` via GitHub Repository Ruleset `#28256290` (`Protect SitePulse Main`, `enforcement: active`).
- [x] Phase 01 final acceptance audit completed on `audit/phase-01-final-acceptance`, merged via PR #3 (`https://github.com/ayjed/SitePulse/pull/3`) into `main` at `12dbd17a949cd0764ab64cae335fcb9511f70ed1` (`2026-11-27T19:14:15Z`), and explicitly **APPROVED** by maintainer (`@ayjed`) on `2026-11-27`.
- [x] Phase 02 Task 12 (`feat/phase-02-safe-http-client`): Implemented `SafeHttpClient` SSRF foundation and `SEC-01`–`SEC-16` security suite, resolved PR #4 review findings (`c7a94d5`; CI `46068385086` & `46068377174`: `224 passed, 965 assertions` across 6/6 jobs), and merged PR #4 (`https://github.com/ayjed/SitePulse/pull/4`) into `main` at `d1d85b8ceae988c36013f3220657a9ed873f3987` (`2026-11-27T21:15:59Z`).
- [x] Phase 02 Task 13 (`feat/phase-02-monitor-crud`): Implemented SQLite/MySQL-compatible `monitors` schema, `Monitor` model & factory, `MonitorPolicy`, SSRF-validated CRUD (`SafeHttpClient::validateUrl()`), pause/resume state rules, accessible UI, and `MonitorCrudTest`; merged via PR #5 into `main` at `0f29c215573a64786c64208cad2e64b2210e68fd`.
- [x] Phase 02 Task 14 (`feat/phase-02-scheduled-engine` -> PR #6): Implemented unchecked `MonitorStatus::UNKNOWN` semantics, persistent `monitors.revision` compare-and-swap stale-result protection, `check_results` schema & batched atomic persistence, bounded concurrent `CurlMultiTransportDriver` + `SafeHttpClient::checkBatch()`, whole-run `hrtime` 45s/50s accounting, `sitepulse:check-monitors`, and opt-in `SITEPULSE_SCHEDULED_CHECKS_ENABLED=false` gating; merged via PR #6 into `main` at `57ed871e06408b6faf748cd86e2a142f821bf099`.
- [ ] Phase 02 Task 15 (`feat/phase-02-incidents-retention`): Implement SQLite/MySQL-compatible `incidents` (`open_monitor_id` unique lock) & `incident_notifications` outbox/audit schemas, $N=2$ / $M=2$ incident lifecycle & pause/resume/URL-change/delete cleanup, crash-recoverable SMTP dispatch outside DB transactions (`NotificationDispatcher`), opt-in `SITEPULSE_ALERTS_ENABLED=false` & `SITEPULSE_PRUNE_CHECKS_ENABLED=false`, 14-day repeatable TLS expiry alerts, portable chunked `sitepulse:prune-checks` retention command, `IncidentLifecycleAndAlertsTest`, and `HistoryPruningTest` (**IN REVIEW** — Draft PR pending CI & maintainer review; Phase 02 remains `IN PROGRESS`).

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
| 2026-11-25 | 01 | Task 07 (PR #1 final `APP_KEY` correctness & rollback remediation after Codex FAIL on `2e44c397c55064d7c7195fd40073a1ff83ef1407`): Simplified setup lifecycle, SHA-256 `encrypter->getKey()` matching against `.env`/`.env.<APP_ENV>`/cached config, verified rollback & `.env.recovery.<24-hex>` copy, symlink/hardlink checks, Unix vs Windows ACL docs | `1feac7e` passed `38` local tests & GitHub Actions CI (`45514246143`, `45514235856`, `38 passed, 320 assertions`), before Codex reproduced custom `APP_CONFIG_CACHE` in `.env` | In Progress |
| 2026-11-25 | 01 | Task 08 (PR #1 custom `APP_CONFIG_CACHE` remediation after Codex FAIL on `1feac7eefba4eec82e6cc821f4e95d5c271f7d0a`): Fail-closed cache path validation & resolution across process env, `.env`, active variants, and default `bootstrap/cache/config.php`, preserving `.env` & caches unchanged | Local checks PASS (`41 passed, 380 assertions`, Pint `29 files`, `composer validate --strict`, `composer audit`, `verifyApplicationEncryption`, `composer run setup` x2, `npm ci && npm run build`, independent custom-cache check); CI commit `1603b15` verified in `45518735867` (PR, 3/3 passed) & `45518727520` (push, 3/3 passed): `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0` all passed (`41 tests, 386 assertions` per job) | In Progress |
| 2026-11-27 | 01 | Task 09 (PR #1 completion & merge): Documentation sync (`d4aaf37`) hit newly published Symfony advisories on CI (`46026063547`, `46026055330`), resolved in `c615775` via Symfony `v7.4.24`/`v7.4.25` updates (`46041927707`, `46041923653`), and merged cleanly into `main` at `d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` | `https://github.com/ayjed/SitePulse/pull/1`, `46042368731` PASS (`41 passed, 386 assertions` across `PHP 8.3`, `PHP 8.4`, `MySQL 8.0`) | Merged (PR #1) |
| 2026-11-27 | 01 | Task 10 (Retrospective recovery & owner-auth PR #2 merged into `main`): Recovered `08b0212` cleanly via `backup/task10-08b0212` onto `feat/phase-01-owner-authentication-reviewed` (`f1e4886`), fixed malformed-input rate-limit bypass, array `old('email')` HTTP 500, empty `SESSION_SECURE_COOKIE=` fallback, CLI echo-exception handling, bounded STDIN/NUL-byte validation, and login footer disclosure; merged PR #2 (`https://github.com/ayjed/SitePulse/pull/2`) into `main` at `776333f2320ca52c7d2f7255305fc100f818a9b3` | PR #2 CI `46051330107` & `46051323625` (6/6 PASS, `65 passed, 592 assertions` per job), post-merge `main` CI `46053500009` (3/3 PASS) | Merged (PR #2) |
| 2026-11-27 | 01 | Task 11 (Phase 01 final acceptance audit on `audit/phase-01-final-acceptance`): Verified PR #1 (`d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b`) & PR #2 (`776333f2320ca52c7d2f7255305fc100f818a9b3`) merged, active GitHub ruleset `#28256290` (`Protect SitePulse Main`) on `main`, zero tracked secrets, SQLite WAL (`5000ms`), and full local + CI test suites | `composer validate --strict`, `composer audit --locked`, `composer check-platform-reqs --lock` (`0` packages excluding PHP 8.3), `pint --test` (`38 files`), `php artisan test` (`65 passed, 586 assertions`), `npm ci && npm run build`, SQLite `journal=wal, busy_timeout=5000, users=0` | Merged via PR #3 (`12dbd17a949cd0764ab64cae335fcb9511f70ed1`); Phase 01 APPROVED by `@ayjed` (`2026-11-27`) |
| 2026-11-27 | 02 | Task 12 (`feat/phase-02-safe-http-client`, `9d0a382`): Implemented `SafeHttpClient` foundation + deterministic `SEC-01`–`SEC-16` security suite; rejected in independent security review (NO-GO on Finding 1 custom headers/redirect forwarding) | CI `46058197673` & `46058189589` (`207 passed, 887 assertions` across `PHP 8.3`/`8.4`/`MySQL 8.0`), local `142 passed, 295 assertions` Security suite | NO-GO (PR #4 Remediation Required) |
| 2026-11-27 | 02 | Task 12 Security Review Remediation (`feat/phase-02-safe-http-client`, `c7a94d5` -> `d1d85b8`): Disallowed custom headers, added IPv6 special-purpose CIDRs, millisecond budgets, header/URL/credential sanitization | CI `46068385086` & `46068377174` (`224 passed, 965 assertions` across `PHP 8.3`, `PHP 8.4`, `MySQL 8.0`), local `159 passed, 373 assertions` Security suite | Merged via PR #4 (`d1d85b8ceae988c36013f3220657a9ed873f3987`) |
| 2026-11-27 | 02 | Task 13 (`feat/phase-02-monitor-crud` -> PR #5): Implemented SQLite/MySQL `monitors` schema + indexes + cascade FK, `MonitorStatus` enum, `Monitor` model (strict `$fillable`, telemetry reset on URL change, portable `dueForCheck` scopes), `MonitorPolicy`, `SafeMonitorUrl` + `MonitorConfigurationRequest`, `MonitorController`, accessible Blade CRUD/pause/resume UI, and `MonitorCrudTest` | Merged via PR #5 into `main` at `0f29c215573a64786c64208cad2e64b2210e68fd` | Merged (PR #5) |
| 2026-11-28 | 02 | Task 14 (`feat/phase-02-scheduled-engine`, PR [#6](https://github.com/ayjed/SitePulse/pull/6)) -> `57ed871e06408b6faf748cd86e2a142f821bf099` (`main`) | Merged via PR #6 into `main` after remediation of whole-run deadlines, synchronous DNS timeout controls, persistent `revision` CAS, and `SITEPULSE_SCHEDULED_CHECKS_ENABLED=false` opt-in scheduler gate | Merged (PR #6) |
| 2026-11-28 | 02 | Task 15 (`feat/phase-02-incidents-retention`, Draft PR): Implemented `incidents` & `incident_notifications` outbox schemas, N=2/M=2 incident lifecycle & duration tracking, deduplicated SMTP alerts (`NotificationDispatcher` outside DB tx with crash-safe claims & backoff), opt-in `SITEPULSE_ALERTS_ENABLED=false`, repeatable 14d TLS warning cycle, chunked `sitepulse:prune-checks` (`SITEPULSE_PRUNE_CHECKS_ENABLED=false`), `IncidentLifecycleAndAlertsTest`, and `HistoryPruningTest` | Verified locally across full suite, Security suite, Pint, Composer checks, and `npm ci && npm run build`; pending remote CI & independent review | IN REVIEW (Draft PR; Phase 02 IN PROGRESS) |

## Change approval protocol
1. Propose task with scope and acceptance criteria.
2. Implement only approved phase scope on a branch.
3. Run quality/security checks; record actual commands and outputs.
4. Review diff, document limitations, approve or reject.
5. Update tracker and phase file in same PR.

**Rule:** Never mark a phase `APPROVED` merely because AI says it is complete. The maintainer approves only after checking evidence.
