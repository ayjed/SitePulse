# ADR-0002: Safe HTTP Client and SSRF Protection Architecture

**Status:** PROPOSED (Phase 00 Architecture Revision)  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse performs automated HTTP/HTTPS health checks against user-configured URLs. Because URLs originate from user input, this introduces a high-risk security boundary: **Server-Side Request Forgery (SSRF)**.

Without comprehensive, defense-in-depth protections, an attacker or compromised user account could coerce the server into:
- Scanning internal networks and probing local ports.
- Interacting with loopback services (Redis `6379`, MySQL `3306`, Docker daemon `2375`, local admin panels).
- Exfiltrating cloud provider instance metadata (`169.254.169.254`, `metadata.google.internal`).
- Exploiting DNS rebinding where a domain resolves to a public IP during validation, but resolves to a private IP during connection establishment.
- Exploiting HTTP redirects to private destinations.
- Leaking requests through or attacking host-configured proxy servers.
- Causing denial of service via Slowloris responses, infinite streams, or gzip bombs.

Standard HTTP clients (vanilla Guzzle or Laravel's `Http` facade) do not provide full protection against these attack vectors out of the box.

---

## Comprehensive Security Design Specifications

The `SafeHttpClient` service encapsulates all outbound network requests and enforces the following 11 security layers:

### 1. Allowed Protocols & Port Restrictions
- **Protocols:** Strictly `http://` and `https://`. Reject all other schemes (`file://`, `gopher://`, `dict://`, `ftp://`, `php://`, `data://`).
- **Default Ports:** Only ports `80`, `443`, `8080`, and `8443` are permitted by default.
- **Sensitive Port Denylist:** Explicitly block sensitive ports even if custom ports are enabled: 21 (FTP), 22 (SSH), 23 (Telnet), 25 (SMTP), 53 (DNS), 110 (POP3), 143 (IMAP), 389 (LDAP), 445 (SMB), 636 (LDAPS), 1433 (MSSQL), 1521 (Oracle), 2049 (NFS), 2375/2376 (Docker), 3306 (MySQL), 5432 (PostgreSQL), 6379 (Redis), 8000, 9200 (Elasticsearch), 11211 (Memcached), 27017 (MongoDB).

### 2. IPv4 & IPv6 Range Restrictions (Comprehensive CIDR Denylist)
All resolved IP addresses are evaluated against the following ranges:
- **IPv4:** `0.0.0.0/8` (Current net), `10.0.0.0/8` (Private), `100.64.0.0/10` (CGNAT), `127.0.0.0/8` (Loopback), `169.254.0.0/16` (Link-local/Cloud metadata), `172.16.0.0/12` (Private), `192.0.0.0/24` (IETF protocol), `192.0.2.0/24` (TEST-NET-1), `192.88.99.0/24` (6to4 Relay), `192.168.0.0/16` (Private), `198.18.0.0/15` (Benchmark), `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), `224.0.0.0/4` (Multicast), `240.0.0.0/4` (Reserved), `255.255.255.255/32` (Broadcast).
- **IPv6:** `::/128` (Unspecified), `::1/128` (Loopback), `::ffff:0:0/96` (IPv4-mapped IPv6), `100::/64` (Discard), `2001:db8::/32` (Doc), `fc00::/7` (Unique Local), `fe80::/10` (Link-local), `ff00::/8` (Multicast).
- **Cloud Metadata Hosts:** Explicitly reject `169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`, and Alibaba metadata `100.100.100.200`.

### 3. IP Literals & Unusual Representations
- URLs containing integer/dword IPs (`http://2130706433/`), hex IPs (`http://0x7f000001/`), octal IPs (`http://017700000001/`), or mixed notation are normalized and parsed using PHP's `filter_var(..., FILTER_VALIDATE_IP)` and `inet_pton()`.
- Non-standard numeric hostnames that cannot be parsed cleanly into a valid public IP or canonical FQDN are rejected at input validation.

### 4. DNS Pre-Resolution & Rebinding Protection (Socket IP Pinning)
- Resolve target host to all associated `A` and `AAAA` records via `dns_get_record()`.
- If **any** returned IP address belongs to a prohibited CIDR, abort the check immediately with `SSRF_BLOCKED`.
- Mitigate DNS Rebinding (TOCTOU) by selecting a validated IP and pinning the connection socket using cURL's `CURLOPT_RESOLVE`:
  ```php
  $rule = "{$host}:{$port}:{$validatedIp}";
  curl_setopt($ch, CURLOPT_RESOLVE, [$rule]);
  ```
  This guarantees that the TCP socket connects strictly to the pre-verified IP, bypassing any secondary DNS lookup by cURL.

### 5. Manual HTTP Redirect Traversal
- Disable automatic cURL redirection: `CURLOPT_FOLLOWLOCATION = false`.
- Inspect 3xx status codes in application code:
  - Extract `Location` header.
  - Parse and normalize destination URL.
  - Enforce maximum redirect count: **3 hops max**.
  - Track visited URLs in memory to detect redirect loops.
  - Subject each hop URL to the identical validation pipeline (scheme, port, DNS resolution, IP CIDR validation, IP pinning).

### 6. Isolation from Environment Proxy Variables
- In many hosting environments, system variables like `http_proxy`, `https_proxy`, `ALL_PROXY`, or `HTTP_PROXY` are set.
- By default, cURL inherits these variables, which could route requests through an internal proxy and bypass local IP checks or leak sensitive headers.
- **Design Rule:** Explicitly disable environment proxy inheritance on health check handles:
  ```php
  curl_setopt($ch, CURLOPT_PROXY, '');
  ```
  Unless a dedicated, trusted monitoring egress proxy is explicitly defined in SitePulse's `.env`.

### 7. TLS Certificate Validation & Inspection
- Enforce strict peer verification: `CURLOPT_SSL_VERIFYPEER = true` and `CURLOPT_SSL_VERIFYHOST = 2`.
- Capture peer certificate metadata (`CURLINFO_CERTINFO`) to record expiration date (`validTo_time_t`) and issuer.
- Decouple certificate expiry alerts ($\le 14$ days remaining) from HTTP availability checks.

### 8. Strict Timeouts & Resource Caps
- Connection Timeout: `CURLOPT_CONNECTTIMEOUT = 5` seconds.
- Total Request Timeout: `CURLOPT_TIMEOUT = 10` seconds (ceiling: 15 seconds).
- Low Speed Limit: Abort if transfer speed falls below 1024 bytes/sec for 5 consecutive seconds (Slowloris protection).
- Response Body Cap: Buffer at most 500 KB; abort connection once headers and initial bytes are received.

---

## Security Test Scenarios & Acceptance Criteria

| ID | Test Scenario | Input / Setup | Expected Outcome | Acceptance Criteria |
|---|---|---|---|---|
| **SEC-01** | Direct IPv4 Loopback | `http://127.0.0.1/` | Request blocked before socket open | Throws `BlockedDestinationException`, 0 outbound packets |
| **SEC-02** | Direct IPv6 Loopback | `http://[::1]/` | Request blocked before socket open | Throws `BlockedDestinationException`, 0 outbound packets |
| **SEC-03** | RFC 1918 Private IP | `http://192.168.1.1/`, `http://10.0.0.1/` | Request blocked | Throws `BlockedDestinationException` |
| **SEC-04** | Cloud Metadata IP | `http://169.254.169.254/latest/` | Request blocked | Throws `BlockedDestinationException` |
| **SEC-05** | Dword / Hex IP Literal | `http://2130706433/`, `http://0x7f000001/` | Input validation rejection | URL rejected during validation or IP normalization |
| **SEC-06** | DNS Resolving to Private IP | `http://rebinding.test/` resolving to `10.1.2.3` | DNS check fails | Throws `BlockedDestinationException` |
| **SEC-07** | Dual-homed Split DNS | DNS returns `198.51.100.1` AND `127.0.0.1` | Check fails if any IP is restricted | Entire destination rejected |
| **SEC-08** | Redirect to Private Network | Public URL 302-redirects to `http://127.0.0.1/` | Hop 1 blocked | Hop 1 intercepts `Location`, validates, aborts |
| **SEC-09** | Redirect Loop | Target cycles between `/a` and `/b` | Max redirects exceeded | Aborts after 3 hops with `MAX_REDIRECTS_EXCEEDED` |
| **SEC-10** | Sensitive Internal Port | `http://example.com:22/`, `http://example.com:6379/` | Port rejected | Validation fails, 0 outbound packets |
| **SEC-11** | Non-HTTP Protocol | `file:///etc/passwd`, `gopher://...` | Scheme rejected | Validation fails |
| **SEC-12** | Host Proxy Override | Host has `http_proxy=internal-proxy:8080` | Proxy ignored | Request does not traverse internal proxy |
| **SEC-13** | Slowloris / Timeout | Server delays response past 10s | Connection cleanly aborted | Check recorded as `TIMEOUT`, worker remains alive |
| **SEC-14** | Giant / Stream Response | Server streams infinite zeroes | Body buffer capped at 500 KB | Transfer aborted, memory usage does not spike |

---

## Decision & Status
Adopt this comprehensive architecture. The `SafeHttpClient` service class will be implemented in Phase 02 with 100% test coverage against these scenarios before any live network probing is permitted.
