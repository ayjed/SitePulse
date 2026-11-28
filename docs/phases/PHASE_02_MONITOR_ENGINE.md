# Phase 02 — Monitor Engine

**Status:** IN PROGRESS

**Authorized:** `2026-11-27` following maintainer approval of Phase 01 (PR #1 `d3d7ffc`, PR #2 `776333f`, PR #3 `12dbd17`), PR #4 Task 12 merge (`d1d85b8`), PR #5 Task 13 merge (`0f29c215`), and PR #6 Task 14 merge (`57ed871e`).

**Current task:** Task 15 — Incident Lifecycle, Alert Deduplication & History Retention (`feat/phase-02-incidents-retention`, **IN REVIEW**).

## Scope
- [x] Monitor CRUD and safe configuration validation (Task 13 **MERGED** via PR #5 `0f29c215573a64786c64208cad2e64b2210e68fd`).
- [x] Periodic CLI checker and concurrency control (`sitepulse:check-monitors`, `MonitorCheckRunner`, `CurlMultiTransportDriver`, Task 14 **MERGED** via PR #6 `57ed871e06408b6faf748cd86e2a142f821bf099`).
- [x] Store status, latency and incidents (`check_results` in Task 14; `incidents`, `incident_notifications`, outbox delivery, TLS expiry alerts & history pruning implemented in Task 15 **IN REVIEW**).
- [x] Strong outbound request policy resistant to SSRF (`SafeHttpClient` foundation merged in Task 12 `d1d85b8`; bounded `checkBatch` integration merged in Task 14 `57ed871e`).
- [x] Unit/integration/security tests (`SEC-01`–`SEC-16` `Security`, `MonitorCrudTest`, `ScheduledMonitoringEngineTest`, `SafeHttpCurlIntegrationTest`, `IncidentLifecycleAndAlertsTest` `STATE-01`–`STATE-05`, and `HistoryPruningTest` implemented).

## Exit criteria
- [x] Monitoring scenarios verified with repeatable tests (`SCHED-01`–`SCHED-06`, loopback `curl_multi`, `STATE-01`–`STATE-05`, crash/retry/TLS/pruning suites verified locally; pending final CI & maintainer review).
- [x] Private network bypass attempts blocked (`SEC-01`–`SEC-16` passing and verified in concurrent loopback `checkBatch` redirect checks).
- [x] Failed cron/checks are observable (`check_results`, `incidents`, `incident_notifications`, sanitized logs, and escaped alert emails implemented; dashboard UI arrives in Phase 03).
- [ ] Maintainer approves evidence.

## Task 12 — Safe HTTP Client Foundation (`MERGED` via PR #4: `d1d85b8ceae988c36013f3220657a9ed873f3987`)
- **Merged SHA:** `d1d85b8ceae988c36013f3220657a9ed873f3987` (`2026-11-27T21:15:59Z`; CI runs `46068385086` and `46068377174` passed 6/6 jobs with `224 passed, 965 assertions`).
- **Components implemented:**
  - `App\Exceptions\BlockedDestinationException`
  - `App\Support\SafeHttp\IpAddressPolicy` (canonical dotted-decimal IPv4 and IPv6 validation rejecting zone IDs, IPv4-mapped/compatible/SIIT/NAT64/6to4/Teredo/benchmarking/ORCHID/site-local ranges, cloud metadata endpoints, and all ADR-0002 restricted IPv4/IPv6 CIDRs)
  - `App\Support\SafeHttp\CanonicalUrlValidator` & `ValidatedUrl` (lowercase `http`/`https`, `2048`-byte length cap, strict authority character allowlist rejecting credentials/userinfo/backslashes/delimiters, `80`/`443`/`8080`/`8443` port allowlist + sensitive port blocklist, ambiguous/leading-zero IP rejection, RFC 1123 FQDN syntax, and backslash-safe relative redirect resolution)
  - `App\Support\SafeHttp\Contracts\DnsResolverInterface` & `NativeDnsResolver` (resolves `DNS_A + DNS_AAAA` records; all returned IPs must pass SSRF policy prior to opening any transport socket)
  - `App\Support\SafeHttp\Contracts\HttpTransportInterface`, `CurlHttpTransport`, & `BoundedBodyBuffer` (custom request headers disallowed cleanly with zero `CURLOPT_HTTPHEADER`/`CURLOPT_COOKIE*` emission, `CURLOPT_RESOLVE` socket IP pinning + `CURLOPT_IPRESOLVE` family lock, `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`, `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_UNRESTRICTED_AUTH => false`, `CURLOPT_DNS_CACHE_TIMEOUT => 0`, `CURLOPT_FORBID_REUSE => true`, `CURLOPT_FRESH_CONNECT => true`, `CURLOPT_NOSIGNAL => true`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_CERTINFO => true`, millisecond connect/total timeouts, low-speed abort, `65536`-byte/`256`-header cap, and strict 500 KB / `512000`-byte body buffer cap)
  - `App\Support\SafeHttp\SafeHttpClient`, `SafeHttpResponse`, `SafeHttpCheckResult`, `SafeHttpFailureReason`, & `SafeHttpRequestException` (User-Agent CR/LF/NUL rejection, connected peer `CURLINFO_PRIMARY_IP` verification, manual up-to-3-hop redirect traversal with cycle detection `MAX_REDIRECTS_EXCEEDED`, millisecond cumulative deadline enforcement `DEADLINE_EXCEEDED`, header-flood failure classification, credential redaction in failure reports, and TLS certificate expiry inspection)

## Task 13 — Monitor Database & CRUD (`MERGED` via PR #5: `0f29c215573a64786c64208cad2e64b2210e68fd`)
- **Merged SHA:** `0f29c215573a64786c64208cad2e64b2210e68fd` (PR #5 merged cleanly into `main`).
- **Schema & Models:**
  - `database/migrations/2026_11_27_000002_create_monitors_table.php` (SQLite & MySQL 8.0+ compatible schema, foreign key `user_id` cascade delete, composite indexes `monitors_user_active_idx`, `monitors_sched_last_checked_idx`, and `monitors_sched_next_check_idx`)
  - `App\Enums\MonitorStatus` & `App\Models\Monitor` (strict `$fillable` config-only fields preventing `user_id`/`status`/telemetry mass assignment, `pause()` / `resume()` / `resetTelemetryForUrlChange()`, `forOwner()`, `eligibleForCheck()`, portable `dueForCheck()` SQL scopes), plus `User::monitors()` and `Database\Factories\MonitorFactory`
- **Authorization, Validation, & UI:**
  - `App\Policies\MonitorPolicy` (fail-closed single-owner checks registered via `Gate::policy()`) + `auth` and `can:access-dashboard` route middleware
  - `App\Rules\SafeMonitorUrl` & `App\Http\Requests\MonitorConfigurationRequest` (validates URLs via `SafeHttpClient::validateUrl()` with zero DNS/HTTP probes, normalizes canonical URLs, enforces intervals `{1, 5, 10, 15, 30, 60}`, timeouts `2–15`, expected HTTP status codes `100–599` with `max >= min`, rejects control chars/arrays/booleans, and suppresses credential old-input flashing on invalid URLs)
  - `App\Http\Controllers\MonitorController` & minimal accessible Tailwind Blade UI (`resources/views/monitors/*`, `resources/views/partials/owner-header.blade.php`, updated `resources/views/dashboard.blade.php`), inheriting `no-store` cache and CSP security headers without enabling cron jobs or live network probes.

## Task 14 — Scheduled Monitoring Engine (`MERGED` via PR #6: `57ed871e06408b6faf748cd86e2a142f821bf099`)
- **Merged SHA:** `57ed871e06408b6faf748cd86e2a142f821bf099` (PR #6 merged into `main`).
- **Unchecked State, Revisions & Persistence:**
  - `App\Enums\MonitorStatus::UNKNOWN` (`'unknown'`) for newly created, resumed, or URL-reset active monitors so monitors never claim `operational` prior to a completed successful probe, upgraded safely via `database/migrations/2026_11_27_000003_update_monitors_default_status_to_unknown.php` (preserving checked and paused monitors)
  - `database/migrations/2026_11_28_000005_add_revision_to_monitors_table.php` adding a persistent `monitors.revision` counter incremented monotonically on Eloquent configuration/state updates (`Monitor::booted()`) and atomically compare-and-swapped inside `CheckBatchPersister` (`where('id', ...)->where('revision', ...)`), defeating same-second edit/revert and pause/resume TOCTOU races across SQLite and MySQL
  - `database/migrations/2026_11_27_000004_create_check_results_table.php`, `App\Enums\CheckResultStatus`, and `App\Models\CheckResult` (`monitor_id` FK cascade, `check_results_monitor_checked_idx` and `check_results_checked_at_idx`)
  - `App\Support\Monitoring\MonitorStateTransition` ($N=2$ failure threshold, $M=2$ recovery threshold) and `App\Support\Monitoring\CheckBatchPersister` (atomic `DB::transaction` with deterministic ascending `lockForUpdate()`, revision + field stale-result guards, TLS expiry retention across transient errors, and `<= 255`-char redacted `error_message` values)
- **Bounded Concurrent HTTP Engine & Scheduler:**
  - `App\Support\SafeHttp\Contracts\MultiTransportDriverInterface`, `App\Support\SafeHttp\Contracts\DeadlineAwareDnsResolverInterface`, `NativeDnsResolver` (checks monotonic deadlines before combined queries and between split `DNS_A`/`DNS_AAAA` fallbacks without partial IPv4-only SSRF bypasses; synchronous OS `dns_get_record()` lacks a per-call timeout in standard PHP and remains an explicit bounded-deadline caveat if an upstream resolver stalls), `CurlPreparedHandle`, `CurlMultiTransportDriver` (using `curl_multi` + shared `CurlHttpTransport::prepareHandle()` preserving `CURLOPT_RESOLVE` DNS pinning, `CURLINFO_PRIMARY_IP` peer checks, TLS verification, proxy isolation, manual 3-hop redirects, 500 KB cap, and clean `curl_multi_remove_handle` + `curl_close` cancellation), `SequentialMultiTransportDriver`, and `SafeHttpClient::checkBatch()` (anchored to whole-run monotonic `softDeadlineNs`/`hardDeadlineNs` from `MonitorCheckRunner::run()`, omitting raw response bodies to keep memory bounded)
  - `config/sitepulse.php`, `App\Support\Monitoring\MonitorCheckRunner` (whole-run `hrtime(true)` deadline accounting across lock acquisition, selection, DNS/HTTP execution, and persistence with synchronous DB/DNS preemption caveats documented accurately; atomic `Cache::lock('sitepulse:check-monitors:execution', 600)`, `5–10` check batch flushes, sanitized metadata logging), `App\Console\Commands\CheckMonitorsCommand` (`sitepulse:check-monitors`), and `routes/console.php` (`everyMinute()->withoutOverlapping(10)->when(...)` gated behind opt-in `config('sitepulse.scheduled_checks_enabled')` defaulting to `false`, preserving `schedule:list`, `config:cache`, and manual CLI execution without installing host cron entries)
- **Automated Verification (`Unit`, `Feature`, `Security`, `Integration`):**
  - `tests/Feature/ScheduledMonitoringEngineTest.php` (`SCHED-01`–`SCHED-06`, migration `000003` upgrade check, database overlap lock & crash TTL recovery, stale-race guards, log sanitization, and 50-monitor SQLite WAL batch persistence)
  - `tests/Integration/SafeHttpCurlIntegrationTest.php` & `tests/Support/LoopbackTestServer.php` (isolated loopback HTTP/HTTPS `curl_multi` concurrency, `CURLOPT_RESOLVE` DNS pinning, ECDSA SHA-256 TLS verification & expiry extraction, SSRF/loop/redirect/body-cap checks, and hard-deadline handle cancellation without production SSRF bypass flags)

## Task 15 — Incident Lifecycle, Alert Deduplication & History Retention (`IN REVIEW`)
- **Branch:** `feat/phase-02-incidents-retention` (from `origin/main` at `57ed871e06408b6faf748cd86e2a142f821bf099`)
- **SQLite & MySQL-Compatible Incident & Notification Outbox Schema:**
  - `database/migrations/2026_11_28_000006_add_tls_warning_tracking_to_monitors_table.php` (`tls_warning_expires_at`, `tls_warning_cycle`)
  - `database/migrations/2026_11_28_000007_create_incidents_table.php` (`open_monitor_id` nullable unique lock column enforcing single open incident per monitor portably across SQLite and MySQL 8.0+)
  - `database/migrations/2026_11_28_000008_create_incident_notifications_table.php` (durable outbox with `UNIQUE(event_key)` and composite `UNIQUE(incident_id, type)`, allowing multiple `NULL`-incident TLS warnings while guaranteeing at-most-once `down`/`recovery` alert records per incident)
  - `App\Enums\IncidentStatus`, `App\Enums\IncidentNotificationType`, `App\Enums\NotificationDeliveryStatus`, `App\Models\Incident`, `App\Models\IncidentNotification`, and updated `App\Models\Monitor` + `App\Http\Controllers\MonitorController` (silent open-incident closure on pause/resume/URL change; FK cascade on delete)
- **Incident Lifecycle, Outbox Mail Delivery, & TLS Expiry Tracking:**
  - `App\Support\Monitoring\CheckBatchPersister`: opens incidents and queues `'down'` alerts on $N=2$ outage confirmation, resolves open incidents with `duration_seconds` and queues `'recovery'` alerts on $M=2$ recovery, ignores stale revisions, and evaluates independent 14-day TLS expiry alerts (`tls_warning` deduplicated per warning episode and rearmed via `tls_warning_cycle` upon certificate renewal)
  - `App\Mail\SitePulseAlertMail` & Blade HTML/plain-text views (`resources/views/emails/sitepulse-alert*.blade.php`): strict HTML escaping, stripped single-line subjects, stripped URL userinfo/query tokens, sanitized errors
  - `App\Support\Notifications\NotificationDispatcher`: opt-in gate (`SITEPULSE_ALERTS_ENABLED=false` default), server-side verified owner recipient lookup only, atomic row claim + external `Mail::send()` strictly outside DB transactions/locks, bounded exponential retry backoff, and stale-claim crash recovery (at-least-once delivery semantics if a process crashes after SMTP acceptance)
- **Check-History Retention Pruning:**
  - `App\Support\Monitoring\CheckHistoryPruner` & `App\Console\Commands\PruneChecksCommand` (`sitepulse:prune-checks`): portable PK-chunked deletion strictly older than `SITEPULSE_HISTORY_RETENTION_DAYS=30`, overlap lock protection, and full preservation of `incidents` and `incident_notifications`
  - `routes/console.php`: daily `sitepulse:prune-checks` schedule gated behind opt-in `SITEPULSE_PRUNE_CHECKS_ENABLED=false` default
- **Automated Verification:**
  - `tests/Feature/IncidentLifecycleAndAlertsTest.php` (`STATE-01`–`STATE-05`, SMTP failure/retry/crash recovery, stale check guards, pause/URL/delete cleanup, TLS 14d warning/renewal cycle, XSS/credential sanitization, owner-only recipients, `SITEPULSE_ALERTS_ENABLED=false` default)
  - `tests/Feature/HistoryPruningTest.php` (exact 30-day boundary, chunked SQLite/MySQL-portable deletion, incident/notification preservation, idempotency, CLI validation, overlap lock, opt-in daily schedule)
  - Draft PR pending remote CI (`PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, `PHP 8.3 / MySQL 8.0`) and maintainer review; Phase 02 remains **IN PROGRESS**.
