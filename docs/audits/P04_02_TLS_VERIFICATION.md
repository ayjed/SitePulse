# P04-02 — TLS contract and transport verification

**Status:** IN REVIEW. Phase 04 remains IN PROGRESS.
**Baseline:** `44f9d5132257a9269f216de1b9386defcf2de0db` on main.

[PR #16](https://github.com/ayjed/SitePulse/pull/16) was squash-merged from reviewed head `b80c23e7628b5bf190c0e3b899704956f4932fe9` after [run 46162917984](https://github.com/ayjed/SitePulse/actions/runs/46162917984) passed all four jobs. Ruleset `28256290` still requires all four checks. The fetched main tree matched the reviewed head and local main was fast-forwarded before the TLS branch was created.

## Approved contract and implementation

[ADR-0006](../decisions/ADR-0006-tls-origin-verification.md) records the maintainer-approved FR-5 contract. [SafeHttpClient](../../app/Support/SafeHttp/SafeHttpClient.php) records the first-hop metadata after the transport and connected peer checks pass. It carries the observation across subsequent hops and on completed failed probes. The synchronous and pooled paths share hop evaluation. [VerifiedTlsMetadata](../../app/Support/SafeHttp/VerifiedTlsMetadata.php) parses bounded certificate metadata. [CheckBatchPersister](../../app/Support/Monitoring/CheckBatchPersister.php) compares the separately supplied observation time and the expiry in UTC at stored-second precision. This is the sole warning calculation. The default remains 14 days.

A TLS rejection is a normalized failed availability check. This differs from a warning about an approaching expiry on a verified leaf. HTTP status evaluation, outage and recovery transitions, and TLS outbox event keys are preserved. There is no new issuer persistence, dashboard presentation, extra probe, TLS failure alert category, or schema change.

## Extraction and certificate fixtures

| Case | Evidence |
|---|---|
| Trusted, matching leaf | Real cURL loopback test using the fixture CA confirms expiry and issuer on a successful request and earlier expiry for a second trusted leaf. |
| Numeric | Unit fixtures accept integer epoch and decimal epoch strings. Invalid numeric input can fall through to valid text. |
| Textual | Unit and SafeHttp tests cover absolute `M j H:i:s Y GMT/UTC` dates and libcurl day padding. Impossible dates and relative values yield no expiry. |
| PEM fallback | A generated public certificate supplies expiry and issuer when lower-level fields are empty or malformed. The parser bounds PEM to 64 KiB and issuer text to 1 KiB, removes controls, and ignores unsupported or empty data. |
| Missing and malformed | Blank, malformed and missing fields produce no new expiry. Persistence preserves the prior expiry, timestamp and warning marker when there is no usable current expiry. |
| Rejected chain, mismatch, and self-signed | Real loopback cases cover a wrong hostname, a standalone self-signed leaf, and rejection of the test CA chain using system roots. |
| Trusted but expired | The test CA issues a zero-day matching leaf. After its issuer signature and expired interval are verified locally, real cURL returns `TLS_ERROR` without metadata. |
| Unexpected HTTP status | The controlled transport case verifies `HTTP_ERROR` while preserving the configured HTTPS leaf. Persistence exercises an independent expiry warning from a verified leaf on HTTP 503. |

The TLS integration worker generates keys and certificates in temporary loopback storage and removes them on teardown. Only the test transport uses its temporary CA path. System trust configuration and [production transport](../../app/Support/SafeHttp/CurlHttpTransport.php) are unchanged. Certificate information varies by libcurl backend. The supported textual representation and bounded PEM fallback are verified with synthetic metadata, and live certificate extraction is verified in the tested local and CI runtimes. [libcurl certificate info](https://curl.se/libcurl/c/CURLINFO_CERTINFO.html)

## Redirect attribution

| Probe | Certificate observation |
|---|---|
| Direct HTTPS, same-origin redirect, another hostname, or a changed effective port | The initial verified HTTPS leaf. |
| HTTPS followed by HTTP, or a multi-hop mixed-scheme chain | The initial verified HTTPS leaf, even when the terminal response is HTTP. |
| HTTP followed by HTTPS | No origin TLS fields. Persistence also rejects a supplied expiry for an HTTP-origin snapshot. |
| Initial HTTPS has missing info and the destination has a certificate | No new expiry or issuer. The destination cannot supply missing origin metadata. |
| Subsequent certificate rejected | The overall check reports `TLS_ERROR` and retains the already verified origin leaf. |
| Initial certificate rejected | `TLS_ERROR`, with no origin metadata. |

The recording transport asserts the exact request sequence for single and batched checks. Live cURL additionally exercises HTTPS to a different loopback HTTPS origin, a rejected destination leaf, and HTTP to HTTPS.

## UTC and renewal

The tests exercise one second below, equal to, and one second above both 14-day and three-day windows, with offset timestamps and distinct fractional seconds. They cover repeated expiry, changed expiry inside the window, rearm on expiry outside it, a missing observation between renewals and a new issuer with identical expiry. They verify the existing event-key shape and deduplication, URL resets, and rejection of a stale snapshot after URL change. Missing or malformed expiry cannot trigger an alert using a stored expiry.

## Security and scope boundaries

SEC-01 through SEC-16, existing real cURL transport tests, lifecycle tests and notification regressions remain the verification baseline. Existing per-hop validation, address pinning, connected-peer checks, proxy restrictions, body limits and timeout handling are retained. The synchronous system DNS call still cannot be preempted mid-call. Monotonic checks retain their existing enforcement at resolver and scheduling boundaries.

Stored metadata from installations upgraded from the terminal-hop implementation can persist until a usable initial HTTPS observation or URL reset. Those historical fields do not generate a warning from stale data. Warnings already queued can outlive URL edits or renewal. P04-03 owns stale-notice treatment, the effective SMTP timeout bound, supported mailers, provider error/retry decisions and the SMTP acknowledgement gap. Existing retention, Phase 02 variance, opt-in dispatch and accessibility release gate are preserved.

## Verification

| Local check | Result |
|---|---|
| Focused metadata, SafeHttp security and integration, incident and notification suite | 221 tests passed, 890 assertions. Windows PHP 8.4.28 with cURL 8.23.0 and OpenSSL 3.0.22. |
| Composer strict validation, locked platform check and locked audit | Passed. No known security advisories reported. |
| Pint | Passed. |
| Locked npm install and production build | Passed with zero reported vulnerabilities and 61 Vite modules. npm retained the existing `esbuild@0.28.2` postinstall policy notice. |
| Chromium browser suite | 17 tests passed. |

The complete local PHP suite passed **377 tests and 2,541 assertions**. The focused and complete runs used `--do-not-cache-result`. Git whitespace checks passed. Exact-head CI evidence is recorded on the draft PR.
