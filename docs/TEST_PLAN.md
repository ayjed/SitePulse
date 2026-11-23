# Test Plan & Quality Assurance Strategy

**Status:** PROPOSED (Phase 00 Architecture Revision)  
**Date:** 2026-11-23  

---

## 1. Test Levels & Strategy

SitePulse employs an automated test strategy using **Pest 3.x** (built on PHPUnit 11) to ensure deterministic behavior across health checks, state transitions, notifications, and security boundaries.

```
       ▲
      / \     E2E / Browser Smoke (Dashboard, Accessibility, Mobile)
     /---\    
    /     \   Integration (Migrations, Scheduler, Batch DB, Mailer)
   /-------\  
  /         \ Security Suite (SSRF, DNS Rebinding, Proxy Isolation, Timeouts)
 /-----------\
/             \ Unit (URL Parser, CIDR Matching, State Machine, Deduplication)
```

---

## 2. Test Suites & Coverage Requirements

### 2.1 Security & Network Protection Suite (Phase 02 & Phase 04 Gate)
Automated tests executed against mock sockets and DNS fakes (no live public egress required):

| ID | Test Scenario | Acceptance Criteria |
|---|---|---|
| **SEC-01** | Loopback IPv4 (`127.0.0.1`, `127.0.0.2`) | Throws `BlockedDestinationException`, 0 network packets |
| **SEC-02** | Loopback IPv6 (`[::1]`) | Throws `BlockedDestinationException`, 0 network packets |
| **SEC-03** | RFC 1918 Private Subnets (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) | Throws `BlockedDestinationException` |
| **SEC-04** | Cloud Metadata Endpoints (`169.254.169.254`, `metadata.google.internal`) | Throws `BlockedDestinationException` |
| **SEC-05** | Obfuscated / Alternate IP Literals (Dword, Hex, Octal, IPv4-mapped IPv6) | Rejected at input validation or parsed and blocked |
| **SEC-06** | DNS Resolving to Private IP | Blocked upon DNS resolution check |
| **SEC-07** | Dual-Homed / Split DNS (Public IP + Private IP in response) | Entire request aborted if any IP is restricted |
| **SEC-08** | Redirect to Private Destination | Traversal intercepted at Hop 1, aborts before connection |
| **SEC-09** | Circular Redirect Loop | Aborts cleanly after 3 hops with `MAX_REDIRECTS_EXCEEDED` |
| **SEC-10** | Sensitive Internal Ports (SSH 22, Redis 6379, MySQL 3306, etc.) | Validation fails, 0 network packets |
| **SEC-11** | Forbidden Protocols (`file://`, `gopher://`, `ftp://`) | Validation fails, only `http` and `https` allowed |
| **SEC-12** | Environment Proxy Variable Override (`http_proxy`, `ALL_PROXY`) | System proxy ignored, direct safe socket opened |
| **SEC-13** | Slowloris / Hung Socket (Response delay > 10s) | Terminated by `CURLOPT_TIMEOUT`, logged as `TIMEOUT` |
| **SEC-14** | Giant / Unbounded Response Body (> 500 KB) | Buffer aborted at 500 KB cap, memory usage stable |

### 2.2 Scheduler & Concurrency Suite (Phase 02 Gate)
- **SCHED-01 (Overlap Prevention):** When `sitepulse:check-monitors` is running, a concurrent invocation must exit immediately with code `0`.
- **SCHED-02 (Worker Pool Concurrency):** Verify that 15 due monitors are processed in concurrent batches of 5 without blocking.
- **SCHED-03 (Execution Budget):** Verify that if command runtime reaches 45 seconds, the worker loop stops picking up new monitors and exits cleanly.
- **SCHED-04 (Interruption Recovery):** Simulate worker termination mid-cycle; verify that uncompleted monitors retain their due status and are prioritized on the next run (`ORDER BY last_checked_at ASC NULLS FIRST`).
- **SCHED-05 (Batch Database Commit):** Verify that check results are committed in batch transactions (`DB::transaction`) to minimize SQLite write operations.

### 2.3 Incident State Machine & Notification Suite (Phase 02 & Phase 04 Gate)
- **STATE-01 (Flapping Suppression):** Alternating success $\to$ failure $\to$ success results in 0 state changes and 0 notifications.
- **STATE-02 (Outage Confirmation):** $N = 2$ consecutive failures transitions monitor from `OPERATIONAL` to `DOWN`, creates an open `Incident`, and dispatches exactly 1 alert email.
- **STATE-03 (Prolonged Outage Deduplication):** Checks 3, 4, 5 failing while in `DOWN` state trigger 0 additional emails.
- **STATE-04 (Recovery Confirmation):** $M = 2$ consecutive successes transitions monitor from `DOWN` to `OPERATIONAL`, closes the `Incident`, calculates duration, and dispatches exactly 1 recovery email.
- **STATE-05 (Idempotency Record):** Verify unique constraint on `incident_notifications(incident_id, type)`.

### 2.4 Browser Smoke & Accessibility Suite (Phase 03 Gate)
- **UI-01 (Monitor CRUD):** Add, view, edit, pause, resume, delete monitor via web dashboard.
- **UI-02 (Keyboard Accessibility):** Verify full keyboard tab navigation across all interactive controls.
- **UI-03 (WCAG 2.1 AA Contrast):** Status badges must use high-contrast colors and distinct shape/text labels (not color alone).
- **UI-04 (Mobile Responsive):** Dashboard layout renders cleanly on viewport width $\le 375\text{px}$.

---

## 3. Evidence Protocol

| Date | Commit / Branch | Test Command | Result | Evidence Notes |
|---|---|---|---|---|
| TBD | TBD | `pest --testsuite=Unit` | NOT RUN | Phase 00 planning stage |
| TBD | TBD | `pest --testsuite=Security` | NOT RUN | Phase 00 planning stage |
| TBD | TBD | `pest --testsuite=Integration` | NOT RUN | Phase 00 planning stage |

> **Rule:** Never mark a test passed without having executed the command and verified the output.
