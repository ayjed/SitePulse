# Phase 02 — Monitor Engine

**Status:** IN PROGRESS  
**Authorized:** `2026-11-27` following maintainer approval of Phase 01 (PR #1 `d3d7ffc`, PR #2 `776333f`, PR #3 `12dbd17`).  
**Current task:** Task 12 — Safe HTTP Client Foundation (`feat/phase-02-safe-http-client`).

## Scope
- [ ] Monitor CRUD and safe configuration validation.
- [ ] Periodic CLI checker and concurrency control.
- [ ] Store status, latency and incidents.
- [x] Strong outbound request policy resistant to SSRF (`SafeHttpClient` foundation implemented in Task 12).
- [ ] Unit/integration/security tests (`SEC-01`–`SEC-16` `Security` suite complete; full Phase 02 monitor/engine tests pending).

## Exit criteria
- [ ] Monitoring scenarios verified with repeatable tests.
- [ ] Private network bypass attempts blocked (`SEC-01`–`SEC-16` implemented and passing in Task 12; full monitor engine integration pending).
- [ ] Failed cron/checks are observable.
- [ ] Maintainer approves evidence.

## Task 12 — Safe HTTP Client Foundation (`IN PROGRESS`)
- **Branch:** `feat/phase-02-safe-http-client` (from `origin/main` at `12dbd17a949cd0764ab64cae335fcb9511f70ed1`)
- **Components implemented:**
  - `App\Exceptions\BlockedDestinationException`
  - `App\Support\SafeHttp\IpAddressPolicy` (canonical dotted-decimal IPv4 and IPv6 validation rejecting zone IDs, IPv4-mapped/compatible embedded private IPv4 addresses, cloud metadata endpoints, and all ADR-0002 restricted IPv4/IPv6 CIDRs)
  - `App\Support\SafeHttp\CanonicalUrlValidator` & `ValidatedUrl` (lowercase `http`/`https`, credentials/userinfo and backslash rejection, `80`/`443`/`8080`/`8443` allowlist + sensitive port blocklist, ambiguous/leading-zero IP rejection, RFC 1123 FQDN syntax, and safe relative redirect target resolution)
  - `App\Support\SafeHttp\Contracts\DnsResolverInterface` & `NativeDnsResolver` (resolves `DNS_A + DNS_AAAA` records; all returned IPs must pass SSRF policy prior to opening any transport socket)
  - `App\Support\SafeHttp\Contracts\HttpTransportInterface`, `CurlHttpTransport`, & `BoundedBodyBuffer` (`CURLOPT_RESOLVE` socket IP pinning, `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`, `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_CERTINFO => true`, 5s connect / 10s default <= 15s timeout, low-speed abort, strict 500 KB / `512000`-byte body buffer cap)
  - `App\Support\SafeHttp\SafeHttpClient`, `SafeHttpResponse`, `SafeHttpCheckResult`, `SafeHttpFailureReason`, & `SafeHttpRequestException` (connected peer `CURLINFO_PRIMARY_IP` verification, manual up-to-3-hop redirect traversal with cycle detection `MAX_REDIRECTS_EXCEEDED`, cumulative deadline enforcement `DEADLINE_EXCEEDED`, TLS certificate expiry inspection)
- **Verification:**
  - `php vendor/bin/pint --test`: PASS (`56 files`)
  - `php vendor/bin/pest --testsuite=Security`: PASS (`142 passed, 295 assertions`; deterministic `SEC-01`–`SEC-16` coverage without live public network probing)
  - `php artisan test`: PASS (`207 passed, 881 assertions`)
  - Draft PR pending CI matrix & independent review; monitor CRUD, scheduler, persistence, and notifications remain out of scope for Task 12.
