# Phase 02 Final Acceptance Audit

**Audit Date:** 2026-11-28  
**Audit Branch:** `audit/phase-02-final-acceptance` (merged via PR [#8](https://github.com/ayjed/SitePulse/pull/8) at `91562c61241cd2cf49ac919489db42cb68ac83b1`; closeout recorded on `docs/phase-02-approval`)
**Repository:** `ayjed/SitePulse`  
**Phase Status:** APPROVED by @ayjed on 2026-11-28 (Phase 03 NOT STARTED)

---

## 1. Executive Summary

Phase 02 (Tasks 12–15) delivers SitePulse's SSRF-hardened outbound HTTP monitoring engine, single-owner monitor CRUD and validation, bounded `curl_multi` scheduled probe runner with persistent `monitors.revision` stale-result protection, N=2/M=2 incident lifecycle, fenced database outbox alert dispatch (`sitepulse:dispatch-notifications`), 14-day repeatable TLS warning tracking, and chunked SQLite/MySQL-portable check-history pruning (`sitepulse:prune-checks`).

All functional, security (`SEC-01`–`SEC-16` plus transport hardening), concurrency, cross-database portability, and deployment opt-in checks pass cleanly on `main` (`283` automated tests, `1898` assertions, `0` failed, `0` skipped; passing across local `PHP 8.4.28` SQLite and GitHub Actions `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0`). One measurable performance target variance was observed empirically: a 30-day representative dataset of `432,000` `check_results` rows (`10` monitors polled every `1` minute) occupied **`68.92 MiB`** (`72,265,728` bytes) under file-backed SQLite WAL, exceeding the documented **`<= 50 MB`** storage target by `18.92 MiB`. No Phase 02 code modifications were introduced by this audit; mitigation options are documented below.

---

## 2. Exact `main` Commit

- **Verified `origin/main` HEAD:** `053ca85dd061e7caa0acea8a79d363c8eb36ddcf`
- **GitHub Branch Protection:** Ruleset `#28256290` (`Protect SitePulse Main`, `enforcement: active`)
- **Post-Merge CI Run on `053ca85dd061e7caa0acea8a79d363c8eb36ddcf`:** Run `46134436723` (`3/3` jobs passed)

---

## 3. PR #4–#7 Merge Verification

| Task | PR | Title | Merge Commit SHA on `main` | Reviewed Head SHA | Merged At (UTC) | Status |
|---|---|---|---|---|---|---|
| 12 | [#4](https://github.com/ayjed/SitePulse/pull/4) | SafeHttpClient SSRF foundation & `SEC-01`–`SEC-16` | `d1d85b8ceae988c36013f3220657a9ed873f3987` | `c7a94d512c316ea2a58a03da4f134ef888ce6acc` | `2026-11-27T21:15:59Z` | Merged |
| 13 | [#5](https://github.com/ayjed/SitePulse/pull/5) | Monitor DB & single-owner CRUD | `0f29c215573a64786c64208cad2e64b2210e68fd` | `4a59c4aa6aa271a3b98753f9c7d289a22db75237` | `2026-11-27T22:19:24Z` | Merged |
| 14 | [#6](https://github.com/ayjed/SitePulse/pull/6) | Scheduled monitoring engine & `curl_multi` | `57ed871e06408b6faf748cd86e2a142f821bf099` | `234369286448402f95428d841dfd63e64586fd1c` | `2026-11-28T08:58:24Z` | Merged |
| 15 | [#7](https://github.com/ayjed/SitePulse/pull/7) | Incident lifecycle, outbox alerts & retention | `053ca85dd061e7caa0acea8a79d363c8eb36ddcf` | `87be52c3f0018498a280868e8aeb9c1c33960f94` | `2026-11-28T10:17:02Z` | Merged |

---

## 4. Requirements Traceability Matrix

| # | Requirement | Status | Primary Source Evidence | Verification Evidence |
|---|---|---|---|---|
| 1 | Monitor CRUD & owner authorization | **PASS** | `app/Policies/MonitorPolicy.php`, `app/Http/Requests/MonitorConfigurationRequest.php`, `app/Http/Controllers/MonitorController.php`, `Monitor::$fillable`, `2026_11_27_000002_create_monitors_table.php` | `tests/Feature/MonitorCrudTest.php` |
| 2 | Safe HTTP client & SSRF prevention | **PASS** | `CanonicalUrlValidator`, `IpAddressPolicy`, `SafeHttpClient`, `CurlHttpTransport` | `tests/Security/*` (`159 passed`), `SafeHttpCurlIntegrationTest` |
| 3 | DNS validation & IP pinning | **PASS** | `NativeDnsResolver` (pre/split deadline checks), `CURLOPT_RESOLVE`, `CURLINFO_PRIMARY_IP` | `SafeHttpClientSecurityTest`, `SafeHttpCurlIntegrationTest`, `ScheduledMonitoringEngineTest` |
| 4 | Redirect & TLS protections | **PASS** | `CURLOPT_FOLLOWLOCATION => false`, 3-hop `prepareHop()`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_CERTINFO => true` | `SafeHttpClientSecurityTest` (`SEC-09`, `SEC-10`), `SafeHttpCurlIntegrationTest` |
| 5 | Bounded concurrency | **PASS** | `config/sitepulse.php` (`max_concurrency` clamped `1..10`), `CurlMultiTransportDriver` | `SafeHttpCurlIntegrationTest`, Step 4 loopback benchmark (`5` & `10` slots) |
| 6 | Scheduler overlap prevention | **PASS** | `MonitorCheckRunner::EXECUTION_LOCK_KEY` (`600s`), `NotificationDispatcher::EXECUTION_LOCK_KEY` (`300s`), `CheckHistoryPruner::EXECUTION_LOCK_KEY` (`1800s`), `withoutOverlapping()` | `ScheduledMonitoringEngineTest` (`SCHED-01`, `SCHED-02`), `NotificationDispatchConcurrencyTest`, `HistoryPruningTest` |
| 7 | Deadline & interruption behavior | **PASS** | Whole-run `hrtime(true)` 45s/50s in `MonitorCheckRunner` + `SafeHttpClient::checkBatch()`, handle removal/close, documented sync DNS/DB caveats | `ScheduledMonitoringEngineTest` (`SCHED-03`, `SCHED-04`), `SafeHttpCurlIntegrationTest` |
| 8 | Atomic check persistence | **PASS** | `CheckBatchPersister::persistBatch()` inside single `DB::transaction()` with sorted `lockForUpdate()`, `CheckResult` bulk insert | `ScheduledMonitoringEngineTest`, Step 4 `db_batch` benchmark (`2.08x`) |
| 9 | Revision-based stale-result rejection | **PASS** | `Monitor::booted()` monotonic `revision`, `MonitorProbeSnapshot::matchesLockedMonitor()`, CAS `UPDATE ... WHERE revision = ?` | `ScheduledMonitoringEngineTest` (same-second edit-revert & pause-resume tests), `IncidentLifecycleAndAlertsTest` |
| 10 | Incident state transitions | **PASS** | `MonitorStateTransition` (`N=2`, `M=2`), `CheckBatchPersister` open/resolve with `duration_seconds`, silent resolution in `Monitor::resolveOpenIncidentSilently()` | `IncidentLifecycleAndAlertsTest` (`STATE-01`–`STATE-04`) |
| 11 | Duplicate incident prevention | **PASS** | Nullable unique `incidents.open_monitor_id` + `MonitorStateTransition::isConfirmedOutage()` + `lockForUpdate()` | `IncidentLifecycleAndAlertsTest` (`STATE-05` `QueryException`, SQLite & MySQL 8.0 CI) |
| 12 | Notification outbox & claim fencing | **PASS** | `2026_11_28_000008` (`claim_token`, `claim_version`), `NotificationDispatcher::fencedUpdate()`, `SitePulseAlertMail`, decoupled `DispatchNotificationsCommand` | `NotificationDispatchConcurrencyTest`, `IncidentLifecycleAndAlertsTest` |
| 13 | Stale final-attempt reconciliation | **PASS** | `NotificationDispatcher::reconcileStaleExhaustedClaims()` atomic CAS transition to `FAILED` without re-sending or bumping `attempts` | `NotificationDispatchConcurrencyTest` (crash, idempotency, bi-directional race tests) |
| 14 | TLS warning deduplication | **PASS** | `monitors.tls_warning_expires_at`, `tls_warning_cycle`, deterministic `monitor:{id}:tls:c{cycle}:{ts}` `event_key`, renewal rearming | `IncidentLifecycleAndAlertsTest` (independent HTTP/TLS, null-expiry skip, renewal rearm) |
| 15 | History retention & incident preservation | **PASS** | `CheckHistoryPruner` (`checked_at < cutoff` PK-chunked `whereIn('id')->delete()`), `PruneChecksCommand` | `HistoryPruningTest`, Step 4 `storage_prune` benchmark (`14,400` rows in `1.12s`, `0` incident loss) |
| 16 | Default-disabled unattended operations | **PASS** | `scheduled_checks_enabled=false`, `alerts_enabled=false`, `scheduled_notifications_enabled=false`, `prune_checks_enabled=false` | `OP_DEFAULTS`, `ScheduledMonitoringEngineTest`, `NotificationDispatchConcurrencyTest`, `HistoryPruningTest` |

---

## 5. Security Audit Findings (`SEC-01`–`SEC-16` & Delivery Hardening)

- **SSRF & Network Isolation (`SEC-01`–`SEC-16` PASS):** Verified `159` security tests (`373` assertions) covering IPv4/IPv6 loopback, RFC 1918 private subnets, cloud metadata IPs/hostnames, hex/octal/dword/leading-zero IP literals, split dual-homed DNS rejection, 30x redirects to restricted hosts and redirect loops (3-hop cap), internal database/admin port blocking, non-`http/https` scheme rejection, environment proxy override suppression (`CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`), connected peer IP verification (`CURLINFO_PRIMARY_IP`), Slowloris timeouts, and 500 KB streaming caps.
- **Header, Credential, & Render Safety:** `SafeHttpClient` prohibits caller-supplied custom request headers, rejects CR/LF/NUL characters in `User-Agent`, redacts URL credentials and `Authorization`/`Cookie`/`Set-Cookie`/`X-API-Key` header values in stored `error_message` fields, and renders escaped HTML Blade views + credential-free canonical URLs in `SitePulseAlertMail`.
- **Single-Owner & Outbox Isolation:** `MonitorPolicy` + `User::isOwner()` restrict CRUD and state transitions exclusively to `is_owner = true` (`owner_slot = 1`); `NotificationDispatcher` resolves alert recipients server-side from the verified `User` owner record; stale/cancelled/failed probes cannot trigger false incidents; tests run with `Mail::fake()` / `MAIL_MAILER=array` and `0` live external email or HTTP probes; `git ls-files` contains zero tracked secrets or `.env` files.
- **Cancellation & Transport Boundaries:** `NativeDnsResolver` `dns_get_record()` calls, synchronous DB queries/commits, and in-flight Symfony SMTP `Mail::send()` calls cannot be preempted mid-call by PHP `hrtime(true)`; deadlines stop intake/claims cleanly before the next call while relying on the bounded `<= 5s` SMTP socket timeout (`config/mail.php`) and `5s`/`2–15s` cURL timeouts.

---

## 6. Test and CI Evidence

- **Local Verification (`PHP 8.4.28 / Windows`, `task-2912`):**
  - `composer validate --strict`: **PASS**
  - `composer audit --locked`: **PASS** (`0` advisories)
  - `composer check-platform-reqs --lock`: **PASS**
  - `php vendor/bin/pint --test`: **PASS** (`105 files`)
  - `php artisan test --testsuite=Security`: **PASS** (`159 passed`, `373 assertions`, `7.98s`)
  - `php artisan test`: **PASS** (`283 passed`, `1898 assertions`, `164.50s`, `0` skipped, `0` failed, `0` warnings)
  - `npm ci` (`0` vulnerabilities) & `npm run build` (`vite v7.3.8`, `1.15s`): **PASS**
  - `git diff --check`: **PASS**
- **GitHub Actions CI (`053ca85dd061e7caa0acea8a79d363c8eb36ddcf`, Run `46134436723`):**
  - `PHP 8.3 / SQLite`: **PASS** (`https://github.com/ayjed/SitePulse/actions/runs/46134436723/job/140946477449`)
  - `PHP 8.4 / SQLite`: **PASS** (`https://github.com/ayjed/SitePulse/actions/runs/46134436723/job/140946477432`)
  - `PHP 8.3 / MySQL 8.0 Compatibility`: **PASS** (`https://github.com/ayjed/SitePulse/actions/runs/46134436723/job/140946477175`)

---

## 7. Performance and Resource Benchmarks (`task-2912`)

Measured in isolated file-backed SQLite WAL processes (`journal_mode=wal`, `busy_timeout=5000`, `synchronous=1`, `foreign_keys=1`) using `LoopbackTestServer` (`50ms` simulated latency per HTTP request) and a representative `432,000`-row dataset (`0` external HTTP requests or real emails):

| Benchmark | Target | Measured Result | Status | Conditions & Caveats |
|---|---|---|---|---|
| **Peak CLI Memory (50 monitors, concurrency 5)** | $\le 45\text{ MB}$ | **`40.00 MB`** real (`39.32 MB` emalloc) | **PASS** | `LoopbackTestServer` small payload; live 500 KB bodies add up to ~2.5 MB transient buffer |
| **Peak CLI Memory (50 monitors, concurrency 10)** | $\le 45\text{ MB}$ | **`42.00 MB`** real (`39.72 MB` emalloc) | **PASS** | `50` monitors, `10` concurrent handles |
| **50-Monitor Execution Time (concurrency 5)** | $\le 25\text{ s}$ | **`760.76 ms`** wall (`732 ms` runner) | **PASS** | `50ms` local loopback latency (`peak_server_concurrency = 5`); real WAN DNS/TLS latency is additive |
| **50-Monitor Execution Time (concurrency 10)** | $\le 25\text{ s}$ | **`408.67 ms`** wall (`408 ms` runner) | **PASS** | `peak_server_concurrency = 10`, `10` insert batches |
| **Batch DB Persistence (50 checks)** | Faster than sequential | **`67.67 ms`** (`5 x 10`) vs **`140.61 ms`** (`50 x 1`) | **PASS (`2.08x`)** | Measured directly on file-backed SQLite WAL |
| **SQLite WAL & Busy Timeout Defaults** | WAL + `5000 ms` | `wal`, `5000 ms`, `normal (1)`, `fk=1` | **PASS** | Runtime `PRAGMA` inspection on file-backed SQLite |
| **30-Day SQLite Storage (10 × 1-min = 432,000 rows)** | $\le 50\text{ MB}$ | **`68.92 MiB`** (`72,265,728` bytes; post-checkpoint) | **EXCEEDED (`+18.92 MiB`)** | 90% success + 10% failure (`error_message` populated) + 50 incidents + 100 notifications + 2 secondary B-tree indexes |
| **Chunked History Pruning (`chunk = 1000`)** | Preserves incidents & bounds locks | **`14,400` rows in `1,117.51 ms`** (`15` chunks) | **PASS** | Remaining `417,600` checks + `50` incidents + `100` notifications preserved |
| **Bounded Notification Throughput (`limit = 25`)** | Bounded per invocation | **`25` sent in `130.96 ms`** (`40.00 MB` real peak) | **PASS** | File-backed SQLite WAL + `Mail::fake()`, `0` real SMTP sockets |

### Proposed Narrow Storage Variance Fixes (No Code Modified in Audit)
1. Document `~69 MiB` specifically for 10 monitors at `1`-minute intervals over 30 days, while noting the application's default `5`-minute interval produces `86,400` rows (~14 MiB); **or**
2. Recommend `SITEPULSE_HISTORY_RETENTION_DAYS=21` (`~48 MiB`) or `14` (`~32 MiB`) when polling `>= 10` monitors every minute.

---

## 8. Operational Safety Verification

- Verified runtime defaults (`OP_DEFAULTS`): `scheduled_checks_enabled = false`, `alerts_enabled = false`, `scheduled_notifications_enabled = false`, `prune_checks_enabled = false`, `max_concurrency = 5`, `smtp_timeout = 5`, `default_mailer = 'log'`.
- Verified `artisan schedule:list` exposes `sitepulse:check-monitors` (`* * * * *`), `sitepulse:dispatch-notifications` (`* * * * *`), and `sitepulse:prune-checks` (`0 0 * * *`) with `withoutOverlapping()` while `when(...)` predicates block unattended execution when opt-in flags are `false`, including under `php artisan config:cache`.
- Verified manual CLI commands preserve overlap locks (`MonitorCheckRunner`, `NotificationDispatcher`, `CheckHistoryPruner`), argument validation, and `SITEPULSE_ALERTS_ENABLED=false` blocking.
- Verified FK-safe migration rollbacks and re-applications in `MonitorCrudTest` (`--step=7`) and `ScheduledMonitoringEngineTest` (`--step=6`) on both SQLite and MySQL 8.0 CI.

---

## 9. Documentation Discrepancies Corrected

1. Reconciled [SITEPULSE_PHASE_TRACKER.md](../../SITEPULSE_PHASE_TRACKER.md) to reflect PR #7 merged at `053ca85dd061e7caa0acea8a79d363c8eb36ddcf`, removing `IN REVIEW` / Draft references on Task 15 and logging Task 16 Phase 02 audit while keeping Phase 02 `IN PROGRESS`.
2. Reconciled [docs/phases/PHASE_02_MONITOR_ENGINE.md](../phases/PHASE_02_MONITOR_ENGINE.md) to mark Task 15 merged (`053ca85d`), record final CI/reconciliation state, and note the `68.92 MiB` storage variance.
3. Reconciled [docs/ARCHITECTURE.md](../ARCHITECTURE.md) Section 2.4 (`at-least-once` SMTP crash-window semantics instead of "exact-once") and Section 4 benchmark status table.
4. Reconciled [docs/TEST_PLAN.md](../TEST_PLAN.md) evidence log with PR #7 merge/CI data and Phase 02 audit results.

---

## 10. Remaining Risks and Limitations

1. **SQLite 30-day 1-minute storage footprint (`68.92 MiB` vs `<= 50 MB` target):** Measured empirical storage for `432,000` rows exceeds `50 MB`.
2. **Synchronous I/O preemption limits:** Blocking `dns_get_record()`, DB transactions, and `<= 5s` SMTP socket sends cannot be preempted mid-call.
3. **At-least-once external email crash window:** Crash between remote SMTP acceptance and DB acknowledgement re-sends after `notification_claim_timeout_seconds` (`60s`).
4. **Live shared-hosting deployment validation:** Real cPanel/CloudLinux LVE behavior remains deferred to Phase 05 per `HOSTING_REQUIREMENTS.md`.

---

## 11. Merge-Blocking Findings

- **Security / Functional / Operational / Portability Blockers:** **None.**
- **Empirical Target Variance:** `68.92 MiB` SQLite WAL footprint for `432,000` 1-minute rows vs `<= 50 MB` target (documented above for maintainer acceptance or retention/interval documentation adjustment prior to or alongside Phase 03 authorization).

---

## 12. GO / NO-GO Recommendation for Phase 02 Acceptance

**Audit Recommendation (2026-11-28):** **GO (Conditional on Maintainer Sign-Off of the 1-Minute 30-Day SQLite Storage Variance [`68.92 MiB` vs `<= 50 MB` Target]).**

Phase 02 implementation, security posture, concurrency safety, and automated SQLite/MySQL verification met acceptance standards; formal closeout decision follows in Section 13.

---

## 13. Final Maintainer Decision (2026-11-28)

- **Decision:** **APPROVED** by Maintainer `@ayjed` on `2026-11-28`.
- **Audit Merge Reference:** PR [#8](https://github.com/ayjed/SitePulse/pull/8) merged into `main` at `91562c61241cd2cf49ac919489db42cb68ac83b1`.
- **Accepted Performance Exception:** Explicitly accepted the documented empirical SQLite 30-day 1-minute storage footprint (`68.92 MiB` measured across `432,000` rows for 10 monitors at 1-minute intervals over 30 days) against the original `<= 50 MB` target. The benchmark table retains its original target-comparison outcome alongside this approved variance.
- **Retention & Deployment Policy:** Default 30-day check-history retention (`SITEPULSE_HISTORY_RETENTION_DAYS=30`) remains unchanged. Scheduled monitoring (`SITEPULSE_SCHEDULED_CHECKS_ENABLED=false`), outbound alerts (`SITEPULSE_ALERTS_ENABLED=false`), scheduled notifications (`SITEPULSE_SCHEDULED_NOTIFICATIONS_ENABLED=false`), and scheduled pruning (`SITEPULSE_PRUNE_CHECKS_ENABLED=false`) remain disabled by default. Phase 05 shared-hosting production validation has not yet occurred.
- **Phase Gate Status:** Phase 02 is **APPROVED**; Phase 03 remains **NOT STARTED** pending explicit maintainer authorization.
