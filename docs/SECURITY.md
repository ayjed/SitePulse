# Security Notes and Threat Model

**Status:** IN REVIEW (Phase 00 Architecture Revision)  
**Date:** 2026-11-23  

---

## 1. High-Risk Security Boundary

The primary security vulnerability inherent to any website uptime monitoring application is **Server-Side Request Forgery (SSRF)**. 
Because the application accepts user-specified URLs and executes automated outbound network requests from the host server, an attacker who gains access to the application (or exploits URL validation flaws) could coerce the server into:
1. Scanning internal networks and probing local ports.
2. Interacting with local loopback services (Redis on `6379`, MySQL on `3306`, Docker on `2375`, local administrative panels).
3. Querying cloud infrastructure metadata endpoints (`http://169.254.169.254/latest/meta-data/`) to exfiltrate IAM roles, private keys, or instance tokens.
4. Bypassing network boundaries via DNS rebinding or redirect chaining.
5. Leaking requests through or attacking host-configured proxy servers.
6. Launching Denial-of-Service attacks against internal or external infrastructure via unbounded responses or connection exhaustion.

---

## 2. Threat Vector Analysis & Mitigation Matrix

| Threat Vector | Description | Severity | Mitigation Strategy |
|---|---|---|---|
| **Direct Loopback / Private IP Access** | User sets monitor to `http://127.0.0.1`, `http://localhost`, `http://[::1]`, or `http://192.168.1.1`. | Critical | Reject URL if host resolves to loopback, private, link-local, multicast, or reserved CIDRs. |
| **Cloud Metadata Exfiltration** | Target points to `http://169.254.169.254/` (AWS/GCP/DigitalOcean metadata) or `metadata.google.internal`. | Critical | Explicitly block `169.254.0.0/16`, link-local ranges, and cloud metadata hostnames. |
| **Alternate IP Representations** | Target uses hex (`0x7f000001`), octal (`017700000001`), decimal integer (`2130706433`), or IPv4-mapped IPv6 (`::ffff:127.0.0.1`). | High | Canonicalize and parse IP addresses using standard PHP network functions (`inet_pton`, `filter_var`) before evaluation. |
| **DNS Rebinding (TOCTOU)** | Attacker's domain resolves to public IP during validation, but returns `127.0.0.1` when cURL establishes the TCP socket. | Critical | Resolve DNS prior to connection; pin the validated IP to the socket connection via cURL's `CURLOPT_RESOLVE` flag. |
| **Redirect to Internal Network** | Public URL `https://example.com/check` returns a 302 redirect to `http://127.0.0.1:8000/admin`. | Critical | Disable automatic cURL redirects (`CURLOPT_FOLLOWLOCATION = false`). Follow redirects manually in PHP code (max 3 hops), re-validating the full URL, DNS, and IP address at each hop. |
| **Host Proxy Variable Exploitation** | Host environment sets `http_proxy` / `ALL_PROXY`, routing requests through an internal corporate proxy and bypassing local checks. | High | Explicitly set `CURLOPT_PROXY => ''` on monitoring handles to ignore environment proxies. |
| **Internal Port Scanning** | Target uses non-standard ports (e.g., `http://example.com:22` or `http://example.com:6379`). | High | Enforce port allowlist (80, 443, 8080, 8443 by default). Explicitly reject known sensitive services. |
| **Protocol Smuggling** | Target specifies `file://`, `gopher://`, `dict://`, `ftp://`, or `php://`. | Critical | Strict protocol allowlist: only `http` and `https` allowed. |
| **Denial of Service (Slowloris / Stream)** | Target returns an infinite stream or takes minutes to respond, exhausting worker processes. | High | Enforce strict timeouts: 5s connection timeout, 10s total transfer timeout, low-speed abort limits. |
| **Zip Bomb / Memory Exhaustion** | Target returns a heavily compressed payload that expands to gigabytes in memory. | High | Cap response body buffering at 500 KB; abort connection once header/status evaluation is complete. |

---

## 3. Comprehensive SSRF Defense Specification

### 3.1 Disallowed IP Ranges (CIDR Blocklist)
The `SafeHttpClient` must validate all resolved IP addresses (IPv4 and IPv6) against the following CIDRs prior to establishing any network connection:

#### IPv4 Denylist:
- `0.0.0.0/8` (Current network / default route)
- `10.0.0.0/8` (Private network RFC 1918)
- `100.64.0.0/10` (Carrier-grade NAT RFC 6598)
- `127.0.0.0/8` (Loopback RFC 1122)
- `169.254.0.0/16` (Link-local RFC 3927, includes cloud metadata)
- `172.16.0.0/12` (Private network RFC 1918)
- `192.0.0.0/24` (IETF protocol assignments RFC 6890)
- `192.0.2.0/24` (TEST-NET-1 RFC 5737)
- `192.88.99.0/24` (6to4 Relay Anycast RFC 7526)
- `192.168.0.0/16` (Private network RFC 1918)
- `198.18.0.0/15` (Network benchmark testing RFC 2544)
- `198.51.100.0/24` (TEST-NET-2 RFC 5737)
- `203.0.113.0/24` (TEST-NET-3 RFC 5737)
- `224.0.0.0/4` (Multicast RFC 5771)
- `240.0.0.0/4` (Reserved for future use RFC 1112)
- `255.255.255.255/32` (Broadcast)

#### IPv6 Denylist:
- `::/128` (Unspecified)
- `::1/128` (Loopback)
- `::ffff:0:0/96` (IPv4-mapped IPv6 — extract embedded IPv4 and validate against IPv4 denylist)
- `100::/64` (Discard-only prefix RFC 6666)
- `2001:db8::/32` (Documentation prefix RFC 3849)
- `fc00::/7` (Unique Local Address RFC 4193)
- `fe80::/10` (Link-local unicast RFC 4291)
- `ff00::/8` (Multicast RFC 4291)

### 3.2 DNS Pinning Implementation (`CURLOPT_RESOLVE`)
To eliminate the window for DNS rebinding attacks:
```php
// 1. Resolve host to IP addresses
$ips = dns_get_record($host, DNS_A + DNS_AAAA);

// 2. Validate all returned IPs against blocked CIDRs
foreach ($ips as $record) {
    $ip = $record['ip'] ?? $record['ipv6'] ?? null;
    if ($this->isIpBlocked($ip)) {
        throw new BlockedDestinationException("Resolved IP is in a restricted network.");
    }
}

// 3. Pin the connection to the validated IP
$chosenIp = $ips[0]['ip'] ?? $ips[0]['ipv6'];
$curlResolveRule = "{$host}:{$port}:{$chosenIp}";
curl_setopt($ch, CURLOPT_RESOLVE, [$curlResolveRule]);
```

### 3.3 Proxy Environment Variable Isolation
To ensure requests are not unexpectedly forwarded through local or corporate proxies defined in the operating system environment:
```php
curl_setopt($ch, CURLOPT_PROXY, '');
```

### 3.4 Manual Redirect Execution
Automatic redirection (`CURLOPT_FOLLOWLOCATION = true`) is prohibited.
Redirects are followed step-by-step:
1. Check response status code for 301, 302, 303, 307, 308.
2. Read `Location` response header.
3. Parse and resolve relative redirect URLs against the base URL.
4. Increment redirect counter (max 3 hops).
5. Pass the redirect target URL back into step 1 of full safety validation.

---

## 4. Application Security & Web Hardening

### 4.1 Authentication & Session Protection
- Single-owner admin model with passwords hashed via `bcrypt` (work factor 12) or `argon2id`.
- Login rate-limiting enforced via Laravel's `RateLimiter`: max 5 attempts per minute per IP.
- Session cookies configured with `HttpOnly`, `SameSite=Lax` (or `Strict`), and `Secure` (when HTTPS is present).
- CSRF token validation on all state-changing routes (POST/PUT/DELETE).

### 4.2 Error Handling & Information Disclosure
- Outbound network errors (e.g., DNS failures, connection timeouts) are logged internally with technical details.
- User-facing UI displays categorized, sanitized failure reasons (`DNS Failure`, `Timeout`, `Connection Refused`, `Blocked Destination`) without exposing internal server stack traces or internal IP topologies.

---

## 5. Security Test Matrix and Acceptance Criteria

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
