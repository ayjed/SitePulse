# ADR-0002: Safe HTTP Client and SSRF Protection Architecture

**Status:** ACCEPTED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse performs automated HTTP/HTTPS health checks against user-configured URLs. Because URLs originate from user input, this introduces a high-risk security boundary: **Server-Side Request Forgery (SSRF)**.

Without defense-in-depth protections, an attacker or compromised user account could coerce the server into scanning internal networks, querying cloud metadata (`169.254.169.254`), or interacting with local services. Standard HTTP clients (vanilla Guzzle or Laravel's `Http` facade) do not provide full protection against these attack vectors out of the box.

---

## Comprehensive Security Design Specifications

The `SafeHttpClient` service encapsulates all outbound network requests and enforces the following security controls:

### 1. Strict Canonical URL & Port Validation
- **Protocol:** Strictly lowercase `http://` or `https://`. Reject all other schemes (`file://`, `gopher://`, `dict://`, `ftp://`, `php://`, `data://`).
- **No User Credentials:** Reject URLs containing userinfo (`http://user:pass@host/`).
- **Port Allowlist:** Standard ports `80`, `443`, `8080`, and `8443` permitted by default.
- **Sensitive Port Denylist:** Explicitly block sensitive ports even if custom ports are configured: 21 (FTP), 22 (SSH), 23 (Telnet), 25 (SMTP), 53 (DNS), 110 (POP3), 143 (IMAP), 389 (LDAP), 445 (SMB), 636 (LDAPS), 1433 (MSSQL), 1521 (Oracle), 2049 (NFS), 2375/2376 (Docker), 3306 (MySQL), 5432 (PostgreSQL), 6379 (Redis), 8000, 9200 (Elasticsearch), 11211 (Memcached), 27017 (MongoDB).

### 2. Rejection of Ambiguous & Nonstandard IP Representations
- Rather than relying on `filter_var()` or `inet_pton()` to normalize nonstandard numeric formats, the validation engine **strictly rejects ambiguous IPv4 representations**:
  - IPv4 addresses must strictly match 4 decimal octets separated by dots (`^([0-9]{1,3}\.){3}[0-9]{1,3}$`), where each octet is 0–255.
  - **No leading zeros allowed** (e.g. `0127.0.0.1`, `127.000.000.001`, `010.0.0.1` are strictly rejected as invalid to prevent octal interpretation ambiguities across C-based resolvers).
  - Hexadecimal (`0x7f000001`), octal (`017700000001`), dword integer (`2130706433`), and mixed radix notations are strictly rejected at URL validation time.

### 3. IPv4 & IPv6 Range Restrictions (Comprehensive CIDR Denylist)
All resolved IP addresses are evaluated against:
- **IPv4 Denylist:** `0.0.0.0/8`, `10.0.0.0/8` (Private), `100.64.0.0/10` (CGNAT), `127.0.0.0/8` (Loopback), `169.254.0.0/16` (Link-local/APIPA), `172.16.0.0/12` (Private), `192.0.0.0/24`, `192.0.2.0/24` (TEST-NET-1), `192.88.99.0/24`, `192.168.0.0/16` (Private), `198.18.0.0/15`, `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), `224.0.0.0/4` (Multicast), `240.0.0.0/4` (Reserved), `255.255.255.255/32` (Broadcast).
- **IPv6 Denylist:** `::/128`, `::1/128`, `::ffff:0:0/96` (IPv4-mapped IPv6 evaluated against IPv4 denylist), `100::/64`, `2001:db8::/32`, `fc00::/7` (ULA), `fe80::/10` (Link-local), `ff00::/8` (Multicast).
- **Cloud Metadata Endpoints:** Explicitly reject `169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`, and Alibaba `100.100.100.200`.

### 4. DNS Pre-Resolution & Pinning (`CURLOPT_RESOLVE`)
- Resolve target host to all associated `A` and `AAAA` records via `dns_get_record()`.
- If **any** returned IP address belongs to a prohibited CIDR, abort the check immediately with `SSRF_BLOCKED`.
- Pin the connection socket using cURL's `CURLOPT_RESOLVE`:
  ```php
  $rule = "{$host}:{$port}:{$validatedIp}";
  curl_setopt($ch, CURLOPT_RESOLVE, [$rule]);
  ```
  This guarantees that the socket connects strictly to the pre-verified IP, defeating DNS rebinding (TOCTOU).

### 5. Connected Peer IP Verification (`CURLINFO_PRIMARY_IP`)
- Even with socket pinning, the actual connected peer IP is verified post-connection:
  ```php
  $connectedIp = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
  if ($this->isIpBlocked($connectedIp) || $connectedIp !== $validatedIp) {
      throw new BlockedDestinationException("Connected socket peer violates security policy.");
  }
  ```
  If the connected peer does not match the pinned validated IP or is within a restricted range, the response is discarded immediately.

### 6. Isolation from Environment Proxy Variables
- In shared and enterprise hosting environments, system variables (`http_proxy`, `https_proxy`, `all_proxy`, `HTTP_PROXY`, `ALL_PROXY`) might be present.
- By default, cURL inherits these variables, which could route requests through an internal proxy and bypass local IP checks.
- **Design Rule:** Explicitly disable environment proxy inheritance on health check handles:
  ```php
  curl_setopt($ch, CURLOPT_PROXY, '');
  curl_setopt($ch, CURLOPT_NOPROXY, '*');
  ```
  Unless an egress proxy is explicitly configured in SitePulse's `.env`.

### 7. Manual HTTP Redirect Traversal
- Disable automatic cURL redirection: `CURLOPT_FOLLOWLOCATION = false`.
- Inspect 3xx status codes in application code:
  - Extract `Location` header.
  - Parse and normalize destination URL.
  - Enforce maximum redirect count: **3 hops max**.
  - Track visited URLs in memory to detect redirect loops.
  - Subject each hop URL to the identical validation pipeline (canonical URL, port, DNS resolution, IP CIDR validation, IP pinning, connected peer check).

### 8. TLS Certificate Validation & Inspection
- Enforce strict peer verification: `CURLOPT_SSL_VERIFYPEER = true` and `CURLOPT_SSL_VERIFYHOST = 2`.
- Capture peer certificate metadata (`CURLINFO_CERTINFO`) to record expiration date (`validTo_time_t`) and issuer.
- Decouple certificate expiry alerts ($\le 14$ days remaining) from HTTP availability checks.

### 9. Timeouts & Resource Caps
- Connection Timeout: `CURLOPT_CONNECTTIMEOUT = 5` seconds.
- Total Request Timeout: `CURLOPT_TIMEOUT = 10` seconds (ceiling: 15 seconds).
- Low Speed Limit: Abort if transfer speed falls below 1024 bytes/sec for 5 consecutive seconds.
- Response Body Cap: Buffer at most 500 KB; abort connection once headers and initial bytes are received.

---

## Security Test Scenarios & Acceptance Criteria

| ID | Test Scenario | Input / Setup | Expected Outcome | Acceptance Criteria |
|---|---|---|---|---|
| **SEC-01** | Direct IPv4 Loopback | `http://127.0.0.1/` | Request blocked before socket open | Throws `BlockedDestinationException`, 0 outbound packets |
| **SEC-02** | Direct IPv6 Loopback | `http://[::1]/` | Request blocked before socket open | Throws `BlockedDestinationException`, 0 outbound packets |
| **SEC-03** | RFC 1918 Private IP | `http://192.168.1.1/`, `http://10.0.0.1/` | Request blocked | Throws `BlockedDestinationException` |
| **SEC-04** | Cloud Metadata IP | `http://169.254.169.254/latest/` | Request blocked | Throws `BlockedDestinationException` |
| **SEC-05** | Ambiguous IP Literals (Hex/Octal/Dword) | `http://2130706433/`, `http://0x7f000001/` | Validation rejection | URL rejected during canonical validation |
| **SEC-06** | Ambiguous IP (Leading Zeros) | `http://0127.0.0.1/`, `http://127.000.000.001/` | Validation rejection | URL rejected due to leading zeros |
| **SEC-07** | DNS Resolving to Private IP | `http://rebinding.test/` resolving to `10.1.2.3` | DNS check fails | Throws `BlockedDestinationException` |
| **SEC-08** | Dual-homed Split DNS | DNS returns `198.51.100.1` AND `127.0.0.1` | Check fails if any IP is restricted | Entire destination rejected |
| **SEC-09** | Redirect to Private Network | Public URL 302-redirects to `http://127.0.0.1/` | Hop 1 blocked | Hop 1 intercepts `Location`, validates, aborts |
| **SEC-10** | Redirect Loop | Target cycles between `/a` and `/b` | Max redirects exceeded | Aborts after 3 hops with `MAX_REDIRECTS_EXCEEDED` |
| **SEC-11** | Sensitive Internal Port | `http://example.com:22/`, `http://example.com:6379/` | Port rejected | Validation fails, 0 outbound packets |
| **SEC-12** | Non-HTTP Protocol | `file:///etc/passwd`, `gopher://...` | Scheme rejected | Validation fails |
| **SEC-13** | Host Proxy Override | Host has `http_proxy=internal-proxy:8080` | Proxy ignored | Request does not traverse internal proxy |
| **SEC-14** | Connected Peer IP Mismatch | Socket connects to IP differing from pinned IP | Request aborted | Discards response, records security violation |
| **SEC-15** | Slowloris / Timeout | Server delays response past 10s | Connection cleanly aborted | Check recorded as `TIMEOUT`, worker remains alive |
| **SEC-16** | Giant / Stream Response | Server streams infinite zeroes | Body buffer capped at 500 KB | Transfer aborted, memory usage does not spike |

---

## Decision & Status
Adopt this comprehensive architecture. The `SafeHttpClient` service class will be implemented in Phase 02 with 100% test coverage against these scenarios before any live network probing is permitted.
