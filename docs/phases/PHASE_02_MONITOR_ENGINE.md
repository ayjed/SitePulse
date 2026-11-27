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
  - `App\Support\SafeHttp\IpAddressPolicy` (canonical dotted-decimal IPv4 and IPv6 validation rejecting zone IDs, IPv4-mapped/compatible/SIIT/NAT64/6to4/Teredo/benchmarking/ORCHID/site-local ranges, cloud metadata endpoints, and all ADR-0002 restricted IPv4/IPv6 CIDRs)
  - `App\Support\SafeHttp\CanonicalUrlValidator` & `ValidatedUrl` (lowercase `http`/`https`, `2048`-byte length cap, strict authority character allowlist rejecting credentials/userinfo/backslashes/delimiters, `80`/`443`/`8080`/`8443` port allowlist + sensitive port blocklist, ambiguous/leading-zero IP rejection, RFC 1123 FQDN syntax, and backslash-safe relative redirect resolution)
  - `App\Support\SafeHttp\Contracts\DnsResolverInterface` & `NativeDnsResolver` (resolves `DNS_A + DNS_AAAA` records; all returned IPs must pass SSRF policy prior to opening any transport socket)
  - `App\Support\SafeHttp\Contracts\HttpTransportInterface`, `CurlHttpTransport`, & `BoundedBodyBuffer` (custom request headers disallowed cleanly with zero `CURLOPT_HTTPHEADER`/`CURLOPT_COOKIE*` emission, `CURLOPT_RESOLVE` socket IP pinning + `CURLOPT_IPRESOLVE` family lock, `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`, `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_UNRESTRICTED_AUTH => false`, `CURLOPT_DNS_CACHE_TIMEOUT => 0`, `CURLOPT_FORBID_REUSE => true`, `CURLOPT_FRESH_CONNECT => true`, `CURLOPT_NOSIGNAL => true`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_SSL_VERIFYHOST => 2`, `CURLOPT_CERTINFO => true`, millisecond connect/total timeouts, low-speed abort, `65536`-byte/`256`-header cap, and strict 500 KB / `512000`-byte body buffer cap)
  - `App\Support\SafeHttp\SafeHttpClient`, `SafeHttpResponse`, `SafeHttpCheckResult`, `SafeHttpFailureReason`, & `SafeHttpRequestException` (User-Agent CR/LF/NUL rejection, connected peer `CURLINFO_PRIMARY_IP` verification, manual up-to-3-hop redirect traversal with cycle detection `MAX_REDIRECTS_EXCEEDED`, millisecond cumulative deadline enforcement `DEADLINE_EXCEEDED`, header-flood failure classification, credential redaction in failure reports, and TLS certificate expiry inspection)
- **Verification:**
  - `php vendor/bin/pint --test`: PASS (`56 files`)
  - `php vendor/bin/pest --testsuite=Security`: PASS (`159 passed, 373 assertions`; deterministic `SEC-01`–`SEC-16` + remediation coverage without live public network probing)
  - `php artisan test`: PASS (`224 passed, 959 assertions`)
  - Draft PR `#4` pending CI matrix & independent review; monitor CRUD, scheduler, persistence, and notifications remain out of scope for Task 12.
