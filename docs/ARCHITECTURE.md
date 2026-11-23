# System Architecture (Proposed)

**Status:** PROPOSED (Lead Architect Discovery Complete)  
**Date:** 2026-11-23  

---

## 1. High-Level Architecture

SitePulse is structured as a modular, monolithic Laravel application optimized for low operational complexity, high security, and minimal resource utilization.

```mermaid
flowchart TD
    subgraph Hosting Environment
        Cron[System Cron: * * * * *] -->|Triggers| ConsoleRunner[php artisan schedule:run]
        ConsoleRunner --> CheckCmd[sitepulse:check-monitors]
        ConsoleRunner --> PruneCmd[sitepulse:prune-checks daily]
        
        subgraph Monitoring Engine
            CheckCmd --> Selector[Monitor Selector: Due Checks]
            Selector --> Pool[Bounded Worker Pool: Concurrency 5-10]
            Pool --> SafeClient[SafeHttpClient Service]
            SafeClient -->|1. Scheme & Port Check| Guard1[Protocol Guard]
            Guard1 -->|2. DNS Pre-Resolution| DNSResolver[DNS Resolver]
            DNSResolver -->|3. CIDR Validation| IPFilter[Private/Reserved IP Filter]
            IPFilter -->|4. Pinned IP Request| Transport[cURL Transport CURLOPT_RESOLVE]
            Transport -->|5. Hop-by-Hop Check| RedirectMgr[Manual Redirect Validator]
        end
        
        subgraph Core Logic & Storage
            Transport --> CheckLogger[Check Result Logger]
            CheckLogger --> StateMachine[Incident State Machine]
            StateMachine -->|State Transition| Notifier[Notification Dispatcher]
            Notifier --> Mailer[Mail Transport SMTP/API]
            
            CheckLogger --> DB[(SQLite WAL / MySQL)]
            StateMachine --> DB
            PruneCmd --> DB
        end
        
        subgraph Web Dashboard
            Owner[Site Owner Browser] -->|HTTPS Session| Auth[Auth & Rate Limiting]
            Auth --> Dashboard[Blade / Tailwind Dashboard]
            Dashboard -->|Read Queries| DB
        end
    end

    Transport -.->|Outbound HTTP/S| ExternalSites[Monitored External Websites]
    Mailer -.->|Outbound Alert| OwnerMail[Owner Email Inbox]
```

---

## 2. Core Subsystems & Components

### 2.1 Scheduler & CLI Command Engine
- **Trigger:** System cron invokes `php artisan schedule:run` every minute.
- **Check Command:** `sitepulse:check-monitors`:
  - Uses `withoutOverlapping(10)` to guarantee that a slow run never triggers concurrent overlapping executions.
  - Queries active monitors where `last_checked_at IS NULL` OR `last_checked_at <= NOW() - check_interval_minutes`.
  - Dispatches checks in bounded concurrent batches (5–10 requests) using asynchronous cURL multi-handles (`Http::pool()` or custom cURL multi wrapper).

### 2.2 SafeHttpClient Service (SSRF Protection Engine)
The high-risk boundary of SitePulse is performing HTTP requests against user-provided URLs. The `SafeHttpClient` isolates and enforces:
1. **URL & Scheme Sanitization:** Strict allowlist of `http` and `https`. Rejects credentials in URLs (`http://user:pass@host`).
2. **Port Allowlist:** Permits standard web ports (80, 443, 8080, 8443). Blocks all internal database, management, and mail ports.
3. **Pre-Flight DNS Resolution:** Resolves target hostname into all associated IPv4 and IPv6 addresses.
4. **IP Filtering:** Checks every resolved IP against banned CIDRs (loopback, RFC 1918 private, link-local, cloud metadata, multicast, broadcast).
5. **DNS Rebinding Prevention:** Pins the cURL connection to the validated IP address using `CURLOPT_RESOLVE`, ensuring the socket connects strictly to the pre-verified IP.
6. **Hop-by-Hop Redirect Validation:** Disables automatic cURL redirect following. Traverses 3xx redirects manually up to 3 hops, re-executing DNS and IP validation on each target URL.
7. **Resource Caps:** Enforces a 5s connection timeout, 10s total request timeout, and truncates response body reading at 500 KB to prevent memory exhaustion or gzip bombs.

### 2.3 Incident State Machine
Maintains state determinism and prevents alert flapping:
- **States:** `OPERATIONAL`, `PENDING_DOWN`, `DOWN`, `PENDING_RECOVERY`.
- **Transitions:**
  - $N = 2$ consecutive failures required to transition to `DOWN` and open an `Incident`.
  - $M = 2$ consecutive successes required to transition to `OPERATIONAL` and resolve an `Incident`.
- **Alert Generation:** Dispatched exclusively when a transition creates or closes an incident.

### 2.4 Notification Subsystem
- Dispatches transactional alert emails on state transitions.
- Records all sent alerts in `incident_notifications` with unique composite keys `(incident_id, type)` ensuring exact-once notification semantics.
- Outbound SMTP connections are bounded by a 5-second socket timeout so slow or failing mail servers do not disrupt ongoing monitor runs.

### 2.5 Data Pruning Subsystem
- Daily scheduled task `sitepulse:prune-checks` purges records in `check_results` older than `SITEPULSE_HISTORY_RETENTION_DAYS` (default: 30 days).
- High-level `incidents` records are preserved permanently for long-term SLA/uptime reporting.

---

## 3. Data Model & Database Schema (Draft)

```mermaid
erDiagram
    USERS ||--o{ MONITORS : owns
    MONITORS ||--o{ CHECK_RESULTS : records
    MONITORS ||--o{ INCIDENTS : generates
    INCIDENTS ||--o{ INCIDENT_NOTIFICATIONS : dispatches

    USERS {
        bigint id PK
        string name
        string email UK
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
        string status "operational | pending_down | down | pending_recovery | paused"
        int consecutive_failures
        int consecutive_successes
        datetime last_checked_at
        int last_status_code
        int last_latency_ms
        datetime tls_expires_at
        datetime tls_last_checked_at
        datetime created_at
        datetime updated_at
    }

    CHECK_RESULTS {
        bigint id PK
        bigint monitor_id FK
        string status "success | failure"
        int status_code
        int latency_ms
        string failure_reason "timeout | dns_error | ssrf_blocked | http_error | tls_error"
        text error_message
        datetime checked_at
    }

    INCIDENTS {
        bigint id PK
        bigint monitor_id FK
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
        bigint incident_id FK
        string type "down | recovery | tls_warning"
        string recipient_email
        datetime sent_at
        boolean is_success
        text error_message
    }
```

---

## 4. Deployment Constraints & Resource Budgets

### Operating Budgets
- **PHP Memory Limit:** Configured for standard $\le 128\text{ MB}$ environments. CLI execution footprint target: $\le 45\text{ MB}$.
- **Execution Time Budget:** 50 monitors checked in $\le 25\text{ seconds}$ via bounded concurrent worker pool (concurrency = 5).
- **Disk Storage:** Under SQLite with 30-day retention, 10 monitors checked every minute produce $\approx 430\text{K}$ rows/month, consuming $\approx 40\text{–}50\text{ MB}$ of disk space.

### SQLite Concurrency Settings
To avoid `SQLITE_BUSY` errors between CLI writes and Web reads:
```sql
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = NORMAL;
```
Standard Eloquent migrations will remain fully compatible with MySQL 8.0+ and MariaDB.

---

## 5. Architectural Decision Records (ADRs)
- [ADR-0001: Application Stack and Hosting Constraints](decisions/ADR-0001-stack.md)
- [ADR-0002: Safe HTTP Client and SSRF Protection](decisions/ADR-0002-safe-http-client.md)
- [ADR-0003: Database Engine, Concurrency, and Data Retention](decisions/ADR-0003-data-retention-and-storage.md)
- [ADR-0004: Incident State Machine and Notification Strategy](decisions/ADR-0004-incident-state-and-alerts.md)
