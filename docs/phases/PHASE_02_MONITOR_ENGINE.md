# Phase 02 — Monitor Engine

**Status:** IN PROGRESS

**Authorized:** `2026-11-27` following maintainer approval of Phase 01 (PR #1 `d3d7ffc`, PR #2 `776333f`, PR #3 `12dbd17`), PR #4 Task 12 merge (`d1d85b8`), and PR #5 Task 13 merge (`0f29c215`).

**Current task:** Task 14 — Scheduled Monitoring Engine (`feat/phase-02-scheduled-engine`, **IN REVIEW**).

## Scope
- [x] Monitor CRUD and safe configuration validation (Task 13 **MERGED** via PR #5 `0f29c215573a64786c64208cad2e64b2210e68fd`).
- [x] Periodic CLI checker and concurrency control (`sitepulse:check-monitors`, `MonitorCheckRunner`, `CurlMultiTransportDriver`, Task 14 **IN REVIEW**; Incident/alert dispatch stays scoped to Task 15).
- [ ] Store status, latency and incidents (`check_results` & `MonitorStatus` $N=2$/$M=2$ implemented in Task 14; `incidents` table & notifications pending Task 15).
- [x] Strong outbound request policy resistant to SSRF (`SafeHttpClient` foundation merged in Task 12 `d1d85b8`; bounded `checkBatch` integration added in Task 14).
- [ ] Unit/integration/security tests (`SEC-01`–`SEC-16` `Security`, `MonitorCrudTest`, `ScheduledMonitoringEngineTest`, and loopback `SafeHttpCurlIntegrationTest` implemented; Task 15 incident & pruning tests pending).

## Exit criteria
- [ ] Monitoring scenarios verified with repeatable tests (`SCHED-01`–`SCHED-06` and loopback HTTP/HTTPS `curl_multi` checks added; full Phase 02 sign-off awaits Task 15 & CI matrix).
- [x] Private network bypass attempts blocked (`SEC-01`–`SEC-16` passing and verified in concurrent loopback `checkBatch` redirect checks).
- [ ] Failed cron/checks are observable (`check_results` persistence and sanitized logs implemented in Task 14; dashboard & incident UI/alerts arrive in Task 15 / Phase 03).
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

## Task 14 — Scheduled Monitoring Engine (`IN REVIEW`)
- **Branch:** `feat/phase-02-scheduled-engine` (from `origin/main` at `0f29c215573a64786c64208cad2e64b2210e68fd`)
- **Unchecked State & Persistence:**
  - `App\Enums\MonitorStatus::UNKNOWN` (`'unknown'`) for newly created, resumed, or URL-reset active monitors so monitors never claim `operational` prior to a completed successful probe, upgraded safely via `database/migrations/2026_11_27_000003_update_monitors_default_status_to_unknown.php` (preserving checked and paused monitors)
  - `database/migrations/2026_11_27_000004_create_check_results_table.php`, `App\Enums\CheckResultStatus`, and `App\Models\CheckResult` (`monitor_id` FK cascade, `check_results_monitor_checked_idx` and `check_results_checked_at_idx`)
  - `App\Support\Monitoring\MonitorStateTransition` ($N=2$ failure threshold, $M=2$ recovery threshold) and `App\Support\Monitoring\CheckBatchPersister` (atomic `DB::transaction` with deterministic ascending `lockForUpdate()`, stale-result discard guards when monitors are deleted/paused/reconfigured mid-probe, TLS expiry retention across transient errors, and `<= 255`-char redacted `error_message` values)
- **Bounded Concurrent HTTP Engine & Scheduler:**
  - `App\Support\SafeHttp\Contracts\MultiTransportDriverInterface`, `CurlPreparedHandle`, `CurlMultiTransportDriver` (using `curl_multi` + shared `CurlHttpTransport::prepareHandle()` preserving `CURLOPT_RESOLVE` DNS pinning, `CURLINFO_PRIMARY_IP` peer checks, TLS verification, proxy isolation, manual 3-hop redirects, 500 KB cap, and clean `curl_multi_remove_handle` + `curl_close` cancellation), `SequentialMultiTransportDriver`, and `SafeHttpClient::checkBatch()` (omitting raw response bodies in batch outcomes to keep memory bounded; pre-flight `NativeDnsResolver` executes synchronously per concurrency slot while TCP/TLS/HTTP transfers overlap concurrently)
  - `config/sitepulse.php`, `App\Support\Monitoring\MonitorCheckRunner` (atomic `Cache::lock('sitepulse:check-monitors:execution', 600)`, monotonic `hrtime(true)` 45s soft intake cutoff / 50s hard handle cancellation, bounded concurrency `1–10`, 5–10 check batch flushes, sanitized metadata logging), `App\Console\Commands\CheckMonitorsCommand` (`sitepulse:check-monitors`), and `routes/console.php` (`everyMinute()->withoutOverlapping(10)`, no host cron entries installed)
- **Automated Verification (`Unit`, `Feature`, `Security`, `Integration`):**
  - `tests/Feature/ScheduledMonitoringEngineTest.php` (`SCHED-01`–`SCHED-06`, migration `000003` upgrade check, database overlap lock & crash TTL recovery, stale-race guards, log sanitization, and 50-monitor SQLite WAL batch persistence)
  - `tests/Integration/SafeHttpCurlIntegrationTest.php` & `tests/Support/LoopbackTestServer.php` (isolated loopback HTTP/HTTPS `curl_multi` concurrency, `CURLOPT_RESOLVE` DNS pinning, ECDSA SHA-256 TLS verification & expiry extraction, SSRF/loop/redirect/body-cap checks, and hard-deadline handle cancellation without production SSRF bypass flags)
  - Draft PR pending remote CI matrix (`PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, `PHP 8.3 / MySQL 8.0`) & independent review; Task 15 (incidents, alert emails, and daily pruning) remains unstarted and Phase 02 remains **IN PROGRESS**.
