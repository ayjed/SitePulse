# ADR-0006: Attribute TLS metadata to the configured HTTPS origin

**Status:** ACCEPTED by the maintainer for P04-02
**Date:** 2026-11-28

## Context

The Phase 02 engine already validates certificate chains and hostnames and inspects leaf metadata. The P04-01 audit found that it extracted certificate metadata from the terminal hop while persistence gated it on the configured URL scheme. It also found a fixed warning flag in the HTTP result beside a configurable threshold in persistence. [P04-01 reconciliation](../audits/P04_01_REQUIREMENTS_RECONCILIATION.md)

## Decision

- Attribute metadata only to the verified leaf on the initial configured HTTPS request, including its hostname and effective port. Redirect destinations remain independently validated. Their certificate metadata cannot replace or fill an origin observation. An HTTP origin has no TLS observation. Reuse the initial request and existing redirect path.
- Keep one warning threshold in persistence. Normalize observation and expiry to UTC whole seconds and warn at `expiry <= checkedAtUTC + configuredWarningDays`. The default is 14 days. Transport results carry metadata without calculating warnings.
- Strictly reject expired, untrusted, wrong-host and other invalid TLS connections using the existing normalized TLS failure classification. Those checks affect the existing availability state machine. A near-expiry warning from a verified leaf is independent of the HTTP status. There is no separate certificate-failure event.
- Capture issuer transiently. Use bounded certificate metadata and PEM fallback from the already verified leaf. Missing or malformed expiry preserves the last successfully observed expiry and timestamp. It neither emits a new warning nor clears a warning cycle.
- Retain expiry and cycle identity. Changed verified expiry within the window may produce a new unique event. A later verified expiry outside the window rearms future eligibility. Identical expiries remain one identity per cycle. URL changes retain their existing reset and revision fencing.

## Consequences

A destination TLS failure can fail the overall check while the already verified origin observation remains available. Existing observations made before the change can remain as historical fields until a usable origin observation or a URL reset. There is no new storage or dashboard field for issuer, no additional network probe, and no change to trust, destination checks, allowed redirects, or cancellation. Mail delivery policy and stale queued warning reconciliation remain P04-03 decisions.

[Verification matrix](../audits/P04_02_TLS_VERIFICATION.md) · [Safe HTTP transport](ADR-0002-safe-http-client.md) · [libcurl certificate info](https://curl.se/libcurl/c/CURLINFO_CERTINFO.html)
