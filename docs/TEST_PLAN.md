# Test Plan & Quality Assurance Strategy

**Status:** PROPOSED (Phase 00 Final Technical Corrections)  
**Date:** 2026-11-23  

---

## 1. Test Levels & Strategy

SitePulse employs an automated test strategy using **Pest 4.x with PHPUnit 12** (actual package resolution verified in Phase 01) to ensure deterministic behavior across health checks, state transitions, notifications, and security boundaries.

```
       ▲
      / \     E2E / Browser Smoke (Dashboard, Accessibility, Mobile)
     /---\    
    /     \   Integration & Benchmarks (Migrations, Scheduler, Batch DB, Mailer)
   /-------\  
  /         \ Security Suite (SSRF, DNS Pinning, Ambiguous IPs, Peer Verification)
 /-----------\
/             \ Unit (Canonical URL, CIDR Denylist, State Machine, Deduplication)
```

---

## 2. Test Suites & Coverage Requirements

### 2.1 Security & Network Protection Suite (Phase 02 & Phase 04 Gate)
Automated tests executed against mock sockets and DNS fakes (no live public egress required):

| ID | Test Scenario | Acceptance Criteria |
|---|---|---|
| **SEC-01** | Loopback IPv4 (`127.0.0.1`, `127.0.0.2`) | Throws `BlockedDestinationException`, 0 network packets |
| **SEC-02** | Loopback IPv6 (`[::1]`) | Throws `BlockedDestinationException`, 0 network packets |
| **SEC-03** | RFC 1918 Private Subnets (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) | Throws `BlockedDestinationException` |
| **SEC-04** | Cloud Metadata Endpoints (`169.254.169.254`, `metadata.google.internal`) | Throws `BlockedDestinationException` |
| **SEC-05** | Ambiguous IP Literals (Dword, Hex, Octal, Mixed Radix) | Rejected during canonical URL validation |
| **SEC-06** | Ambiguous IP with Leading Zeros (`0127.0.0.1`, `127.000.000.001`) | Rejected during canonical URL validation |
| **SEC-07** | DNS Resolving to Private IP | Blocked upon DNS resolution check |
| **SEC-08** | Dual-Homed / Split DNS (Public IP + Private IP in response) | Entire request aborted if any IP is restricted |
| **SEC-09** | Redirect to Private Destination | Traversal intercepted at Hop 1, aborts before connection |
| **SEC-10** | Circular Redirect Loop | Aborts cleanly after 3 hops with `MAX_REDIRECTS_EXCEEDED` |
| **SEC-11** | Sensitive Internal Ports (SSH 22, Redis 6379, MySQL 3306, etc.) | Validation fails, 0 network packets |
| **SEC-12** | Forbidden Protocols (`file://`, `gopher://`, `ftp://`) | Validation fails, only canonical `http` and `https` allowed |
| **SEC-13** | Environment Proxy Variable Override (`http_proxy`, `ALL_PROXY`) | System proxy ignored (`CURLOPT_PROXY => ''`), direct safe socket opened |
| **SEC-14** | Connected Peer IP Mismatch (`CURLINFO_PRIMARY_IP`) | Socket connected IP does not match pinned IP; response discarded |
| **SEC-15** | Slowloris / Hung Socket (Response delay > 10s) | Terminated by `CURLOPT_TIMEOUT`, logged as `TIMEOUT` |
| **SEC-16** | Giant / Unbounded Response Body (> 500 KB) | Buffer aborted at 500 KB cap, memory usage stable |

### 2.2 Scheduler, Concurrency, and Deadline Suite (Phase 02 Gate)
- **SCHED-01 (Overlap Prevention):** When `sitepulse:check-monitors` is running, a concurrent invocation detects active lock and exits immediately with code `0`.
- **SCHED-02 (Mutex Lock Expiration TTL):** Simulate an abandoned lock past the 10-minute TTL; verify the subsequent run reclaims the lock without manual intervention.
- **SCHED-03 (Monotonic Deadline Cutoff):** Simulate slow sockets; verify that when monotonic time hits 45s, no new batches are initiated, and in-flight handles are cleanly cancelled by the 50s ceiling.
- **SCHED-04 (Partial Batch Persistence):** In a batch with 3 completed and 2 in-flight checks at cutoff, verify the 3 completed checks are committed in `DB::transaction`, while the 2 in-flight checks remain due without recording false failures.
- **SCHED-05 (Interrupted Execution Recovery):** Terminate a worker process mid-run; verify that uncompleted monitors retain due status and are prioritized on the next run using `ORDER BY (CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC, last_checked_at ASC`.
- **SCHED-06 (Batch Persistence Benchmark):** Benchmark atomic batch-write transactions against sequential single-row writes under 50-monitor simulated load to measure disk I/O differences.

### 2.3 Database Portability Suite (Phase 01 & Phase 02 Gate)
- **DB-01 (Cross-Database Due Monitor Prioritization):** Seed database with monitors having `last_checked_at = NULL` (never checked), older timestamps, and recent timestamps. Execute selector query using `ORDER BY (CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC, last_checked_at ASC` on both SQLite and MySQL test suites. Verify `NULL` records are returned first, followed by oldest timestamps, with zero syntax errors on both database engines.
- **DB-02 (Atomic Batch Commit & Rollback):** Verify that check results written inside `DB::transaction` either fully commit or rollback cleanly under simulated database exceptions on both SQLite and MySQL.

### 2.3 Incident State Machine & Notification Suite (Phase 02 & Phase 04 Gate)
- **STATE-01 (Flapping Suppression):** Alternating success $\to$ failure $\to$ success results in 0 state changes and 0 notifications.
- **STATE-02 (Outage Confirmation):** $N = 2$ consecutive failures transitions monitor from `OPERATIONAL` to `DOWN`, creates an open `Incident`, and dispatches exactly 1 alert email.
- **STATE-03 (Prolonged Outage Deduplication):** Checks 3, 4, 5 failing while in `DOWN` state trigger 0 additional emails.
- **STATE-04 (Recovery Confirmation):** $M = 2$ consecutive successes transitions monitor from `DOWN` to `OPERATIONAL`, closes the `Incident`, calculates duration, and dispatches exactly 1 recovery email.
- **STATE-05 (Idempotency Record):** Verify unique composite constraint on `incident_notifications(incident_id, type)`.

### 2.4 Browser Smoke & Accessibility Suite (Phase 03 Gate)
- **UI-01 (Monitor CRUD):** Add, view, edit, pause, resume, delete monitor via web dashboard.
- **UI-02 (Keyboard Accessibility):** Verify full keyboard tab navigation across all interactive controls.
- **UI-03 (WCAG 2.1 AA Contrast):** Status badges must use high-contrast colors and distinct shape/text labels.
- **UI-04 (Mobile Responsive):** Dashboard layout renders cleanly on viewport width $\le 375\text{px}$.

---

## 3. Evidence Protocol

| Date | Commit / Branch | Test Command | Result | Evidence Notes |
|---|---|---|---|---|
| 2026-11-24 | `fix/phase-01-foundation-remediation` (PHP 8.4.28 / Windows host) | `php vendor/bin/pest` / `php artisan test` | PASS (29 passed, 190 assertions) | Baseline, genuine Artisan/Encrypter tests, multiline `.env` preservation, atomic write & secret-canary non-leakage on PHP 8.4.28 + SQLite |
| 2026-11-24 | `c67086960bf0ee28efb8b5508b093bb7d75db9f8` (`fix/phase-01-foundation-remediation`, PR #1 prior run) | GitHub Actions `ci.yml` (`45505787002` `pull_request`, `45505776723` `push`) | CI Jobs PASS; Rejected in Codex PR #1 audit | `PHP 8.3`, `PHP 8.4`, `MySQL 8.0` passed (`29 passed, 190 assertions`), but Codex found 3 merge blockers (`config:cache` `.env` mutation, Windows `tempnam()` `.en*.tmp` non-ignored temp filenames, and `services.mysql` credential exposure in logs) |
| 2026-11-25 | `2e44c397c55064d7c7195fd40073a1ff83ef1407` (`fix/phase-01-foundation-remediation`, PR #1 prior run) | `php artisan test`, `php vendor/bin/pint --test`, GitHub Actions `ci.yml` (`45510794534` `pull_request`, `45510786337` `push`) | CI Jobs PASS (`32 passed, 231 assertions`); Rejected in Codex PR #1 audit | `PHP 8.3`, `PHP 8.4`, and `MySQL 8.0` passed with masked disposable DB credentials, but Codex reproduced unverified rollback claiming success when `.env` restoration failed and `.env.testing` / `APP_ENV=testing` key mismatch when updating `.env` |
| 2026-11-25 | `1feac7eefba4eec82e6cc821f4e95d5c271f7d0a` (`fix/phase-01-foundation-remediation`, PR #1 prior run) | `php vendor/bin/pint --test`, `php artisan test`, GitHub Actions `ci.yml` (`45514246143` `pull_request`, `45514235856` `push`) | CI Jobs PASS (`38 passed, 320 assertions`); Rejected in Codex PR #1 audit | `PHP 8.3`, `PHP 8.4`, and `MySQL 8.0` passed with `0` skipped tests, but Codex reproduced `APP_CONFIG_CACHE` declared inside `.env` bypassing default `bootstrap/cache/config.php` detection |
| 2026-11-25 | `1603b15abb028a0092385b33efb070adb8932da1` (`fix/phase-01-foundation-remediation`, PHP 8.4.28 / Windows host) | `php vendor/bin/pint --test`, `composer validate --strict`, `composer audit`, `php artisan test`, `verifyApplicationEncryption`, `composer run setup` x2, `npm ci && npm run build`, Codex custom-cache repro check | PASS (41 passed, 380 assertions; Pint 29 files) | Local verification of `.env` & process `APP_CONFIG_CACHE` detection across blank, matching, mismatched & uncached states, unsafe cache path rejection (`..`, `phar://`, empty/whitespace, symlinks), space/`!` Windows paths, SQLite WAL (`5000ms`), and Vite build |
| 2026-11-25 | `1603b15abb028a0092385b33efb070adb8932da1` (`fix/phase-01-foundation-remediation`, PR #1 CI runs) | GitHub Actions `ci.yml` (`45518735867` `pull_request`, `45518727520` `push`) | PASS (all 6 jobs passed; 41 tests, 386 assertions per job) | Both PR run `45518735867` (3/3 jobs) and push run `45518727520` (3/3 jobs) passed across `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0` (including symlink `.env`/`APP_CONFIG_CACHE` checks and zero unmasked MySQL credentials); independent source review confirmed no remaining merge blockers |
| 2026-11-27 | `d4aaf37c20561e93364c52673af3973c4730e0c2` (`fix/phase-01-foundation-remediation`, PR #1 doc-sync CI) | GitHub Actions `ci.yml` (`46026063547` `pull_request`, `46026055330` `push`) | FAIL (SQLite `composer audit`); PASS (`MySQL 8.0`) | `composer audit --no-interaction` failed on 5 newly published Symfony advisories (`CVE-2026-133743`, `CVE-2026-133751`, `CVE-2026-133755`, `CVE-2026-133746`, `CVE-2026-133754`); blocked merge safely per policy |
| 2026-11-27 | `c6157758d494bfd23f0129fde26e4a3693c982bf` -> `d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` (PR #1 merge to `main`) | GitHub Actions `ci.yml` (`46041927707`, `46041923653`, `46042368731`) | PASS (all jobs passed) | Patched `symfony/http-kernel`, `symfony/mailer`, `symfony/routing` to `v7.4.24`/`v7.4.25` in `c615775`; PR #1 (`https://github.com/ayjed/SitePulse/pull/1`) merged into `main` at `d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` (`46042368731` PASS) |
| 2026-11-27 | `08b0212637a291adebffa00b46b1d441109950d9` (inadvertent direct `main` push baseline) | GitHub Actions `ci.yml` (`46044416132` `push` to `main`) | PASS (`3/3` jobs; 63 passed, 555 assertions) | Owner-authentication baseline (`20` files) passed CI directly on `main` (`PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, `PHP 8.3 / MySQL 8.0`), later reverted on `main` by `5b1162b8c6465607b1495df3a82e794f0804c035`; recovered into review branch `feat/phase-01-owner-authentication-reviewed` without rewriting `main` |
| 2026-11-27 | `feat/phase-01-owner-authentication-reviewed` (local Windows PHP 8.4.28 host verification after retrospective security fixes) | `composer validate --strict`, `composer audit --locked`, `composer check-platform-reqs --lock`, `php vendor/bin/pint --test`, `php artisan test`, `npm ci && npm run build` | PASS (`65 passed, 586 assertions`; Pint `38 files`; `0` advisories) | Covers `FoundationRemediationTest` + `OwnerAuthenticationTest` including malformed/array `/login` rate-limiting, `old('email')` type safety, `config:cache` & empty `SESSION_SECURE_COOKIE=` HTTPS fallback, bounded `--password-stdin`, and NUL-byte password rejection |
| 2026-11-27 | `f1e48864d74ebeefea8640f61c03e897c50a6ea2` (`feat/phase-01-owner-authentication-reviewed`, PR #2) -> `776333f2320ca52c7d2f7255305fc100f818a9b3` (`main`) | GitHub Actions `ci.yml` (`46051330107` `pull_request`, `46051323625` `push`, `46053500009` `main` push) | PASS (all 9 jobs across 3 runs; 65 passed, 592 assertions per job) | Both PR #2 CI runs (`46051330107`, `46051323625` — all 6 jobs) and post-merge `main` CI run (`46053500009` on `776333f`) passed across `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0` (`composer audit` clean, Pint `38 files`, SQLite `journal=wal, busy_timeout=5000, seeded_users=0`, masked MySQL credentials); PR #2 (`https://github.com/ayjed/SitePulse/pull/2`) merged at `776333f2320ca52c7d2f7255305fc100f818a9b3` |
| 2026-11-27 | `audit/phase-01-final-acceptance` (branched from `776333f2320ca52c7d2f7255305fc100f818a9b3`, local Windows PHP 8.4.28 host) | `composer validate --strict`, `composer audit --locked`, `composer check-platform-reqs --lock`, `php vendor/bin/pint --test`, `php artisan test`, `npm ci && npm run build`, SQLite `PRAGMA` + tracked-file credential audit | PASS (65 passed, 586 assertions; Pint `38 files`; 0 advisories; 0 npm vulnerabilities) | Final Phase 01 acceptance verification: verified PHP 8.3+ lock compatibility (`0` excluded packages), `driver=sqlite, journal=wal, busy_timeout=5000, synchronous=1, users=0`, `git ls-files` zero tracked secrets/credentials, and GitHub Ruleset `#28256290` active on `main`; merged via PR #3 at `12dbd17a949cd0764ab64cae335fcb9511f70ed1` and Phase 01 approved by `@ayjed` |
| 2026-11-27 | `feat/phase-02-safe-http-client` (local Windows PHP 8.4.28 host) | `php vendor/bin/pest --testsuite=Security`, `php artisan test`, `php vendor/bin/pint --test`, `composer validate --strict`, `composer audit --locked`, `composer check-platform-reqs --lock`, `npm run build` | PASS (`Security`: 142 passed, 295 assertions; Full suite: 207 passed, 881 assertions; Pint `56 files`; 0 advisories) | Phase 02 Task 12 (`SafeHttpClient`): deterministic `SEC-01`–`SEC-16` SSRF & transport security tests executed without live external network probing |
| Pending | `feat/phase-02-safe-http-client` (Draft PR targeting `main`) | GitHub Actions `ci.yml` (`PHP 8.3`, `PHP 8.4`, `MySQL 8.0`) | PENDING | Draft PR automated CI verification pending push & workflow execution |

> **Rule:** Never mark a test passed without having executed the command and verified the output.
