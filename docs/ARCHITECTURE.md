# System Architecture (Proposed)

**Status:** PROPOSED (Phase 00 Final Technical Corrections)  
**Date:** 2026-11-23  

---

## 1. High-Level Architecture

SitePulse is structured as a modular, monolithic Laravel 13 application running on PHP 8.3+, optimized for low operational complexity, high security, and minimal resource utilization.

```mermaid
flowchart TD
    subgraph Hosting Environment
        Cron[System Cron: * * * * *] -->|Triggers| ConsoleRunner[php artisan schedule:run]
        ConsoleRunner --> CheckCmd[sitepulse:check-monitors]
        ConsoleRunner --> PruneCmd[sitepulse:prune-checks daily]
        
        subgraph Monitoring Engine
            CheckCmd --> Mutex[Schedule Mutex withoutOverlapping 10m TTL]
            Mutex --> Timer[Monotonic Deadline hrtime 45s/50s]
            Timer --> Selector[Monitor Selector: Due Checks ORDER BY CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END, last_checked_at ASC]
            Selector --> Pool[Bounded Worker Pool: Concurrency 5]
            Pool --> SafeClient[SafeHttpClient Service]
            SafeClient -->|1. Canonical URL & Scheme| Guard1[Canonical URL Guard]
            Guard1 -->|2. Proxy Isolation| ProxyGuard[CURLOPT_PROXY Empty & NOPROXY]
            ProxyGuard -->|3. Ambiguous IP Rejection| IPFormat[Strict Decimal IP Guard No Leading Zeros]
            IPFormat -->|4. DNS Pre-Resolution| DNSResolver[DNS Resolver]
            DNSResolver -->|5. CIDR Denylist| IPFilter[Private/Reserved IP Filter]
            IPFilter -->|6. Pinned Socket| Transport[cURL Transport CURLOPT_RESOLVE]
            Transport -->|7. Connected Peer Verification| PeerCheck[CURLINFO_PRIMARY_IP Check]
            PeerCheck -->|8. Hop-by-Hop Check| RedirectMgr[Manual Redirect Validator max 3 hops]
            PeerCheck -->|9. TLS Verification| TLSGuard[Peer & Cert Expiry Inspector]
        end
        
        subgraph Core Logic & Storage
            Transport --> BatchBuffer[Batch Result Buffer]
            BatchBuffer -->|Atomic DB Transaction| CheckLogger[Check Result Logger]
            CheckLogger --> StateMachine[Incident State Machine]
            StateMachine -->|State Transition| Notifier[Notification Dispatcher]
            Notifier --> Mailer[Mail Transport SMTP/API 5s Timeout]
            
            CheckLogger --> DB[(SQLite WAL / MySQL)]
            StateMachine --> DB
            PruneCmd --> DB
        end
        
        subgraph Web Dashboard
            Owner[Site Owner Browser] -->|HTTPS Session| Auth[Auth & Rate Limiting]
            Auth --> Dashboard[Blade / Livewire 4 / Tailwind Dashboard]
            Dashboard -->|Read Queries| DB
        end
    end

    Transport -.->|Outbound HTTP/S| ExternalSites[Monitored External Websites]
    Mailer -.->|Outbound Alert| OwnerMail[Owner Email Inbox]
```

---

## 2. Core Subsystems & Components

### 2.1 Scheduler, Concurrency, and Deadline Lifecycle
- **Scheduler Entrypoint:** Invoked every 1 minute via system cron (`php artisan schedule:run`).
- **Command:** `sitepulse:check-monitors`.
- **Locking & Mutex Lifecycle (`withoutOverlapping`):**
  - Configured with `withoutOverlapping(10)`.
  - **Important Clarification:** The parameter `10` sets the **cache lock expiration TTL (10 minutes)**, which prevents permanent deadlocks if a process is abruptly terminated (`SIGKILL`). It does **not** enforce a process execution runtime limit.
  - If a previous execution is still running when cron fires, the new invocation detects the active lock, logs an informational notice, and exits cleanly with exit code `0`.
- **Monotonic Execution Deadline:**
  - Wall-clock time can drift or jump; elapsed budget is measured from the start of `MonitorCheckRunner::run()` via high-resolution monotonic time (`hrtime(true)`).
  - Target intake deadline: **45 seconds** from run entry (shared across lock check, monitor selection, synchronous DNS/HTTP admission, and incremental batch commits).
  - Hard HTTP cancellation ceiling: **50 seconds** from run entry.
  - Before issuing batches (and before admitting each queued monitor in `SafeHttpClient::checkBatch()`), the engine verifies remaining whole-run monotonic budget:
    ```php
    if ($this->nowNs() >= $softDeadlineNs) {
        // Cease admitting new monitors; finish/cancel active handles up to $hardDeadlineNs
    }
    ```
  - **Scope Boundary of Monotonic Deadlines:** PHP synchronous operations — database queries (`Monitor::dueForCheck()`), database commits (`CheckBatchPersister::persistBatch()`), and blocking C stub-resolver DNS lookups (`dns_get_record()`) — cannot be preempted mid-call by `hrtime(true)`. Total runtime can therefore exceed 50 seconds if a final blocking DB or OS DNS call stalls near cutoff.
- **Cancellation of Outstanding HTTP Handles:**
  - While processing a batch via `curl_multi`, if the hard deadline (50s from run start) is reached:
    - Active easy handles in the multi pool are removed (`curl_multi_remove_handle`) and closed (`curl_close`).
    - Cancelled handles are aborted to leave the process ready for exit before the next cron tick.
- **Handling of Partially Completed Batches:**
  - Checks within a batch that completed successfully or failed with confirmed network errors before cutoff are committed atomically (`DB::transaction`) guarded by an optimistic `monitors.revision` compare-and-swap check (`id` + `revision` + active/non-paused state) to discard same-second stale probes.
  - In-flight checks that were cancelled due to deadline expiration are **not** marked as failed (preventing false outage alerts). Their `last_checked_at` and status counters are left untouched, keeping them due for the next cron cycle.
- **Recovery from Forced Termination:**
  - If an execution is terminated abruptly (`SIGKILL`, host process manager):
    - Unchecked or interrupted monitors retain their older `last_checked_at` timestamp.
    - On the subsequent run, due monitors are selected using a cross-database portable strategy:
      ```sql
      ORDER BY (CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END) ASC, last_checked_at ASC
      ```
      This ANSI SQL construct runs identically on both SQLite and MySQL/MariaDB (avoiding MySQL syntax errors with `NULLS FIRST`), ensuring that never-checked monitors (`NULL`) and longest-interrupted monitors are prioritized first.
    - State transitions only occur on committed, revision-verified check results, preventing ghost outage states.
- **Worker Pool Concurrency:** Bounded pool of **5 concurrent requests** (configurable up to 10 via `SITEPULSE_MAX_CONCURRENCY`).
- **Per-Request Timeouts:** Connect/TLS: 5s max; Total Transfer: 10s default (min 2s, max 15s); Body buffer cap: 500 KB (`512000` bytes). *(Implementation limitation: pre-flight DNS lookups execute synchronously per concurrency slot via `NativeDnsResolver` using PHP `dns_get_record()`, which has no portable per-call socket timeout parameter without external PECL/async DNS libraries or thread-unsafe `putenv('RES_OPTIONS')`. Monotonic deadlines are enforced before lookup and between combined-query vs split `DNS_A` / `DNS_AAAA` fallbacks so dual-homed IPv4+IPv6 SSRF validation is never weakened.)*
- **Monitoring History Write Strategy:** Worker results are buffered in memory and committed in a single atomic database transaction (`DB::transaction`) per batch of 5–10 checks, designed to reduce SQLite write lock contention. Performance gains will be measured via benchmarks in Phase 02.

### 2.2 SafeHttpClient Service (SSRF Protection Engine)
The `SafeHttpClient` isolates and enforces:
1. **Strict Canonical URL Validation:** Only lowercase `http://` or `https://`. Rejects credentials in URLs (`user:pass@`). Strictly validates FQDN syntax (RFC 1123).
2. **Rejection of Ambiguous IP Formats:** Does not rely on normalization functions for nonstandard IP representations. Strictly rejects leading zeros (e.g. `0127.0.0.1`), hexadecimal (`0x`), octal, single integer/dword (`2130706433`), and mixed radix notations.
3. **Protocol & Port Allowlist:** Standard web ports `80`, `443`, `8080`, `8443`. Explicit denylist blocks sensitive administrative/database ports (21, 22, 25, 3306, 6379, etc.).
4. **Environment Proxy Isolation:** Explicitly sets `CURLOPT_PROXY => ''` and `CURLOPT_NOPROXY => '*'` to ensure host environment variables cannot route traffic through unintended proxies.
5. **Pre-Flight DNS Resolution & Pinning:** Resolves target hostname synchronously per slot via `dns_get_record(DNS_A + DNS_AAAA)` (using `NativeDnsResolver`; no separate c-ares async DNS dependency). Validates all records against private/reserved CIDRs. Pins socket IP using cURL's `CURLOPT_RESOLVE`.
6. **Connected Peer IP Verification:** Immediately inspects `curl_getinfo($ch, CURLINFO_PRIMARY_IP)` post-connection. If the connected peer IP is in a restricted range or does not match the pinned IP, the response is discarded and the check is aborted.
7. **Manual Hop-by-Hop Redirect Validation:** Automatic redirects are disabled (`CURLOPT_FOLLOWLOCATION = false`). Traverses 3xx redirects manually up to 3 hops with loop detection and full re-validation of each hop URL.
8. **TLS Certificate Validation & Inspection:** Verifies peer certificate (`CURLOPT_SSL_VERIFYPEER = true`), extracts certificate expiration date, and flags certificates expiring within $\le 14$ days.

### 2.3 Incident State Machine
- **States:** `UNKNOWN`, `OPERATIONAL`, `PENDING_DOWN`, `DOWN`, `PENDING_RECOVERY`, `PAUSED` (`UNKNOWN` prevents newly created, resumed, or URL-changed monitors from reporting `OPERATIONAL` prior to a completed successful probe).
- **Transitions:**
  - $N = 2$ consecutive failures required to transition to `DOWN` and open an `Incident`.
  - $M = 2$ consecutive successes required to transition to `OPERATIONAL` and resolve an `Incident`.
- **Alert Generation:** Dispatched exclusively when a transition creates or closes an incident.

### 2.4 Notification Subsystem
- Dispatches transactional alert emails on state transitions.
- Records all sent alerts in `incident_notifications` with unique composite keys `(incident_id, type)` ensuring exact-once notification semantics.
- Outbound SMTP connections bounded by a 5-second socket timeout.

### 2.5 Data Pruning Subsystem
- Daily scheduled task `sitepulse:prune-checks` purges records in `check_results` older than `SITEPULSE_HISTORY_RETENTION_DAYS` (default: 30 days).
- High-level `incidents` records are preserved permanently.

---

## 3. Data Model & Database Schema (Draft)

```mermaid
erDiagram
    USERS ||--o{ MONITORS : owns
    MONITORS ||--o{ CHECK_RESULTS : records
    MONITORS ||--o{ INCIDENTS : generates
    MONITORS ||--o{ INCIDENT_NOTIFICATIONS : tracks
    INCIDENTS ||--o{ INCIDENT_NOTIFICATIONS : dispatches

    USERS {
        bigint id PK
        string name
        string email UK
        boolean is_owner
        string password
        datetime created_at
        datetime updated_at
    }

    MONITORS {
        bigint id PK
        bigint user_id FK
        string name
        string url
        int check_interval_minutes
        int timeout_seconds
        int expected_status_code_min
        int expected_status_code_max
        boolean is_active
        string status "unknown | operational | pending_down | down | pending_recovery | paused"
        int consecutive_failures
        int consecutive_successes
        datetime last_checked_at
        datetime next_check_at
        int last_status_code
        int last_latency_ms
        datetime tls_expires_at
        datetime tls_last_checked_at
        datetime tls_warning_expires_at
        int tls_warning_cycle
        bigint revision
        datetime created_at
        datetime updated_at
    }

    CHECK_RESULTS {
        bigint id PK
        bigint monitor_id FK
        string status "success | failure"
        int status_code
        int latency_ms
        string failure_reason "timeout | dns_error | ssrf_blocked | http_error | tls_error | deadline_exceeded | max_redirects_exceeded"
        text error_message
        int redirects_followed
        boolean body_truncated
        datetime checked_at
    }

    INCIDENTS {
        bigint id PK
        bigint monitor_id FK
        bigint open_monitor_id UK
        datetime started_at
        datetime resolved_at
        int duration_seconds
        string root_cause
        string status "open | resolved"
        datetime created_at
        datetime updated_at
    }

    INCIDENT_NOTIFICATIONS {
        bigint id PK
        bigint monitor_id FK
        bigint incident_id FK
        string type "down | recovery | tls_warning"
        string event_key UK
        string channel
        string recipient
        string subject
        string delivery_status "pending | sending | sent | failed"
        int attempts
        datetime claimed_at
        datetime next_attempt_at
        datetime sent_at
        boolean is_success
        string error_message
        string error_summary
        int duration_seconds
        datetime tls_expires_at
        string claim_token
        bigint claim_version
        datetime created_at
        datetime updated_at
    }
```

---

## 4. Measurable Engineering Targets

| Metric | Target | Status & Verification Method |
|---|---|---|
| **CLI Memory Footprint** | $\le 45\text{ MB}$ peak usage | Target to be measured via `memory_get_peak_usage(true)` in CI |
| **Check Latency Budget** | 50 monitors checked in $\le 25\text{ seconds}$ | Target via bounded concurrent worker pool (concurrency 5) |
| **Storage Footprint** | $\le 50\text{ MB}$ for 10 monitors over 30 days | Target under SQLite WAL; measured in Phase 02 |
| **SQLite Lock Contention** | Reduced contention between CLI writes & Web reads | Target via WAL mode, busy timeout, and batch writes; requires Phase 02 benchmarks |
| **Database Portability** | 100% schema parity on SQLite and MySQL 8.0+ | Requires automated CI integration testing |
| **Shared Hosting Compatibility** | Viable operation on CLI-enabled shared hosts | Target requiring empirical deployment testing before release |

---

## 5. Architectural Decision Records (ADRs)
- [ADR-0001: Application Stack and Dependency Compatibility](decisions/ADR-0001-stack.md)
- [ADR-0002: Safe HTTP Client and SSRF Protection](decisions/ADR-0002-safe-http-client.md)
- [ADR-0003: Database Storage Strategy, Concurrency, and Data Retention](decisions/ADR-0003-data-retention-and-storage.md)
- [ADR-0004: Incident State Machine and Notification Strategy](decisions/ADR-0004-incident-state-and-alerts.md)
- Environment Specification: [HOSTING_REQUIREMENTS.md](HOSTING_REQUIREMENTS.md)
