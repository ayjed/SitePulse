# ADR-0002: Safe HTTP Client and SSRF Protection Architecture

**Status:** PROPOSED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse performs automated HTTP/HTTPS health checks against user-configured URLs. Because URLs originate from user input, this introduces a high-risk security boundary: Server-Side Request Forgery (SSRF).

Without robust defenses, an attacker or compromised user account could target:
- Cloud provider instance metadata services (e.g., `http://169.254.169.254/`).
- Loopback interfaces (`http://127.0.0.1/`, `http://[::1]/`).
- Private internal network hosts (e.g., `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).
- Internal administrative ports (e.g., Redis `6379`, MySQL `3306`, SSH `22`, Docker daemon `2375`).
- DNS rebinding attacks where a domain resolves to a public IP during validation, but returns a private IP when the HTTP client establishes the socket connection.
- Sneaky redirect loops or redirect transitions (e.g., initial URL is public, but 302 redirects to `http://127.0.0.1/admin`).

Standard HTTP clients (such as vanilla Guzzle or Laravel's `Http` facade) follow redirects automatically and do not inspect the resolved IP addresses against private network blocks by default.

## Options Considered

1. **Option A: Regex / String-based URL Filter before standard Guzzle request**
   - *Pros:* Simple to implement.
   - *Cons:* Ineffective against DNS rebinding, alternate IP encodings (hex, octal, dword), IPv6 embeddings, and HTTP redirects to private addresses. Unacceptable security posture.

2. **Option B: Dedicated `SafeHttpClient` Service with Multi-Layer Protection (Selected)**
   - *Pros:*
     - Validates scheme against a strict allowlist (`http`, `https`).
     - Enforces port allowlist (default 80, 443, 8080, 8443).
     - Resolves DNS prior to connection and validates all A/AAAA records against comprehensive private/reserved CIDRs.
     - Pins the target IP using cURL's `CURLOPT_RESOLVE` to prevent DNS rebinding Time-of-Check to Time-of-Use (TOCTOU) exploits.
     - Disables automatic cURL redirect following (`CURLOPT_FOLLOWLOCATION = false`); follows redirects manually in application code (up to 3 hops), subjecting every intermediate URL to identical DNS and IP verification.
     - Enforces strict transfer timeouts (5s connect, 10s total) and caps response body buffering at 500 KB to avoid denial-of-service via unbounded streaming or memory exhaustion.
   - *Cons:*
     - Requires custom cURL transport wrapper rather than a one-liner `Http::get()`.
     - Adds minor DNS resolution latency per check.

## Decision
Adopt **Option B: Dedicated `SafeHttpClient` Service**. 

The implementation will reside in an isolated service class (e.g., `App\Services\Monitoring\SafeHttpClient`) with zero reliance on naive regex filtering. All outbound monitoring requests in Phase 02 and beyond MUST route through this component.

## Consequences
- Every outbound monitor check has deterministic security validation.
- Negative SSRF test suite can be executed against mock DNS and socket fakes without needing live external networks.
- Users cannot monitor internal private services by default. (If internal self-monitoring is desired in a future phase, it must require an explicit, hardened `.env` override).

## Evidence & Verification
- Unit and integration tests in Phase 02 must execute against the test cases defined in `docs/SECURITY.md`, specifically:
  - `127.0.0.1`, `localhost`, `[::1]`, `169.254.169.254`
  - Alternate representations (`0x7f.1`, `2130706433`, octal)
  - Split-horizon and dual-homed DNS answers
  - Public URLs redirecting to internal IPs
