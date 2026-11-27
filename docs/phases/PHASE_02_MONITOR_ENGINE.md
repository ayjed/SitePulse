# Phase 02 — Monitor Engine

**Status:** IN PROGRESS

**Authorized:** `2026-11-27` following maintainer approval of Phase 01 (PR #1 `d3d7ffc`, PR #2 `776333f`, PR #3 `12dbd17`) and PR #4 Task 12 merge (`d1d85b8ceae988c36013f3220657a9ed873f3987`).

**Current task:** Task 13 — Monitor Database & CRUD (`feat/phase-02-monitor-crud`, **IN REVIEW**).

## Scope
- [x] Monitor CRUD and safe configuration validation (Task 13 **IN REVIEW** on `feat/phase-02-monitor-crud`).
- [ ] Periodic CLI checker and concurrency control.
- [ ] Store status, latency and incidents.
- [x] Strong outbound request policy resistant to SSRF (`SafeHttpClient` foundation merged in Task 12 `d1d85b8`).
- [ ] Unit/integration/security tests (`SEC-01`–`SEC-16` `Security` suite and Task 13 `MonitorCrudTest` passing; scheduler/incident engine tests pending).

## Exit criteria
- [ ] Monitoring scenarios verified with repeatable tests.
- [ ] Private network bypass attempts blocked (`SEC-01`–`SEC-16` implemented and passing in Task 12; full monitor engine integration pending).
- [ ] Failed cron/checks are observable.
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

## Task 13 — Monitor Database & CRUD (`IN REVIEW`)
- **Branch:** `feat/phase-02-monitor-crud` (from `origin/main` at `d1d85b8ceae988c36013f3220657a9ed873f3987`)
- **Schema & Models:**
  - `database/migrations/2026_11_27_000002_create_monitors_table.php` (SQLite & MySQL 8.0+ compatible schema, foreign key `user_id` cascade delete, composite indexes `monitors_user_active_idx`, `monitors_sched_last_checked_idx`, and `monitors_sched_next_check_idx`)
  - `App\Enums\MonitorStatus` & `App\Models\Monitor` (strict `$fillable` config-only fields preventing `user_id`/`status`/telemetry mass assignment, `pause()` / `resume()` / `resetTelemetryForUrlChange()`, `forOwner()`, `eligibleForCheck()`, portable `dueForCheck()` SQL scopes), plus `User::monitors()` and `Database\Factories\MonitorFactory`
- **Authorization, Validation, & UI:**
  - `App\Policies\MonitorPolicy` (fail-closed single-owner checks registered via `Gate::policy()`) + `auth` and `can:access-dashboard` route middleware
  - `App\Rules\SafeMonitorUrl` & `App\Http\Requests\MonitorConfigurationRequest` (validates URLs via `SafeHttpClient::validateUrl()` with zero DNS/HTTP probes, normalizes canonical URLs, enforces intervals `{1, 5, 10, 15, 30, 60}`, timeouts `2–15`, expected HTTP status codes `100–599` with `max >= min`, rejects control chars/arrays/booleans, and suppresses credential old-input flashing on invalid URLs)
  - `App\Http\Controllers\MonitorController` & minimal accessible Tailwind Blade UI (`resources/views/monitors/*`, `resources/views/partials/owner-header.blade.php`, updated `resources/views/dashboard.blade.php`), inheriting `no-store` cache and CSP security headers without enabling cron jobs or live network probes.
- **Verification:**
  - `php vendor/bin/pint --test`: PASS (`65 files`)
  - `php vendor/bin/pest tests/Feature/MonitorCrudTest.php`: PASS (`23 passed, 382 assertions`)
  - `php vendor/bin/pest --testsuite=Security`: PASS (`159 passed, 373 assertions`)
  - `php artisan test`: PASS (`247 passed, 1341 assertions`)
  - Draft PR pending remote CI matrix & independent review; Task 14 (cron worker) remains unstarted and Phase 02 remains **IN PROGRESS**.
