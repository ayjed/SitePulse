# Product Requirements Document

**Status:** IN REVIEW (Phase 00 Architecture Revision)  
**Date:** 2026-11-23  
**Target Milestone:** v1.0.0 MVP  

---

## 1. Problem Statement
Small site owners, solo PHP developers, and boutique agencies need actionable uptime and latency monitoring for their sites and client portfolios. Existing solutions either require deploying complex, resource-heavy observability stacks (Prometheus, Grafana, Datadog) or incur high recurring SaaS fees with aggressive monitor limits.

SitePulse provides a self-hosted, lightweight, and trustworthy uptime monitor that installs on compatible PHP infrastructure (documented in [docs/HOSTING_REQUIREMENTS.md](HOSTING_REQUIREMENTS.md)), runs autonomously via cron, alerts only when genuine outages occur, and enforces strict security controls to prevent internal network abuse.

---

## 2. Target Personas & Use Cases

### Personas
1. **Solo PHP / Web Freelancer:** Maintains 5–25 client websites on VPS or modern shared hosting; needs reliable availability history and immediate outage alerts before clients notice.
2. **Small Agency Technical Lead:** Needs an internal dashboard to observe uptime and SSL certificate expiration across client production domains.
3. **Indie Developer / Maker:** Self-hosts personal side projects; wants zero-cost, low-maintenance monitoring with SQLite storage.

### Concrete Use Cases
- **Use Case 1 (Availability & Outage Alerting):** An e-commerce client site begins throwing 500 errors. SitePulse marks the check as failed, confirms the outage on the second consecutive failure, opens an incident record, and dispatches a single email alert to the site owner. When the site recovers, SitePulse verifies recovery over two consecutive successful checks, closes the incident, and sends a recovery email with downtime duration.
- **Use Case 2 (TLS Certificate Expiration Warning):** A client's Let's Encrypt certificate fails to auto-renew due to an HTTP-01 challenge issue. SitePulse observes that the certificate expires in 14 days and notifies the administrator before browsers start displaying security warnings.
- **Use Case 3 (Maintenance & Pause):** A developer is deploying a major upgrade. They log into SitePulse, pause the monitor with a single click, perform the deployment, and resume monitoring when verification completes, preventing false incident logs.

---

## 3. Scope & Feature Specifications (v1 MVP)

### 3.1 Account & Access Control (FR-1)
- **Single-Tenant Admin Model:** Designed for a single authenticated site owner.
- **Secure Authentication:** Standard session-based login with strong password hashing (bcrypt/argon2id), CSRF protection, and rate-limited login attempts (max 5 attempts per minute).
- **Initial Setup:** Owner account provisioned via CLI artisan command (`php artisan sitepulse:create-owner`) or a first-run web installation screen.

### 3.2 Monitor Management (FR-2)
- **CRUD Operations:** Create, Read, Update, Delete monitors.
- **State Control:** Pause and resume active monitoring.
- **Configurable Attributes:**
  - Friendly display name (e.g., "Client Store Production").
  - Target URL (strictly `http://` or `https://`).
  - Check interval: selectable options (1, 5, 10, 15, 30, 60 minutes).
  - Expected HTTP response status code range (default: `200–299`; customizable for intentional 301/401 checks).
  - Request timeout: configurable between 2 and 15 seconds (default: 10 seconds).
  - Active toggle (boolean).

### 3.3 HTTP Health Check Engine (FR-3)
- **Outbound Check Execution:** Executes periodically via cron.
- **User-Agent:** Identifies explicitly as `SitePulse/1.0 (+https://github.com/.../sitepulse)`.
- **Metrics Collected:** HTTP status code, total response time (latency in milliseconds), timestamp, failure classification (`TIMEOUT`, `DNS_FAILURE`, `CONNECTION_REFUSED`, `HTTP_ERROR`, `SSRF_BLOCKED`, `TLS_ERROR`), and sanitized error message.
- **Security & Network Guardrails:** Strictly routed through `SafeHttpClient` with DNS pre-validation, private IP blocking, socket IP pinning, proxy variable isolation, and hop-by-hop redirect inspection (see `docs/SECURITY.md`).

### 3.4 Outage Detection & Incident State Machine (FR-4)
- **Flapping Prevention:** Outage confirmation requires **$N = 2$ consecutive failed checks**. Recovery confirmation requires **$M = 2$ consecutive successful checks**.
- **Incident Lifecycle:**
  - `OPERATIONAL`: All recent checks healthy.
  - `PENDING_DOWN`: 1 check failed; awaiting confirmation check.
  - `DOWN`: Confirmed outage; active `Incident` opened; alert sent.
  - `PENDING_RECOVERY`: 1 successful check following an outage; awaiting confirmation.
- **Incident Duration:** Calculated precisely between first confirmed failure timestamp and recovery timestamp.

### 3.5 TLS Certificate Inspection (FR-5)
- **Certificate Verification:** For HTTPS targets, capture certificate expiration date and issuer during health checks.
- **Alert Threshold:** Trigger a warning email when remaining certificate validity is $\le 14$ days (configurable).
- **Graceful Handling:** Handled independently of HTTP availability (e.g., an expired cert is flagged as a TLS incident without crashing the HTTP checker).

### 3.6 Notifications & Alerting (FR-6)
- **Delivery Channel:** Transactional Email (via configured SMTP, Mailgun, Postmark, or Sendmail).
- **State-Transition Trigger:** Emails are dispatched strictly upon state change (`OPERATIONAL` $\to$ `DOWN`, `DOWN` $\to$ `OPERATIONAL`, and `TLS_EXPIRING_SOON`).
- **Idempotency & Deduplication:** Guarded by unique constraint in `incident_notifications` to prevent duplicate emails during prolonged outages.
- **Timeout Protection:** Outbound mail transport limited to 5-second socket timeout to prevent mail delays from blocking health checks.

### 3.7 Status Dashboard (FR-7)
- **Overview Screen:** Clean list of all monitors with current status badges (`Operational`, `Down`, `Degraded`, `Paused`).
- **Detail View:**
  - Calculated uptime percentage over 24 hours, 7 days, and 30 days.
  - Response time latency sparkline/graph for recent checks.
  - Incident history table (start time, resolution time, duration, failure cause).
- **Accessibility:** Full WCAG 2.1 AA compliance, keyboard navigation, clear text indicators alongside color status badges.

### 3.8 Storage & Data Pruning (FR-8)
- **Default Database:** SQLite with Write-Ahead Logging (WAL) enabled; MySQL 8.0+ supported.
- **Batch Persistence:** Check results are committed in batch transactions to prevent lock contention.
- **Automated Retention:** Daily scheduled command (`sitepulse:prune-checks`) purging raw check results older than 30 days. Historical `incidents` records are preserved permanently.

---

## 4. Non-Functional Requirements

| Metric | Target | Verification Method |
|---|---|---|
| **Security (SSRF)** | 100% block rate for private/loopback/cloud metadata IPs | Negative security test suite with mock DNS/sockets |
| **Execution Memory** | $\le 45$ MB per cron check execution | CLI memory profiling during check runs |
| **Check Latency Budget** | 50 monitors checked in $\le 25$ seconds | Asynchronous connection pooling (concurrency 5–10) |
| **Accessibility** | WCAG 2.1 AA compliant | Axe core audit and keyboard navigation test |
| **Storage Growth** | $\le 50$ MB database size for 10 monitors over 30 days | SQLite disk measurement with 30-day retention |
| **Deterministic Behavior** | 0 duplicate notifications during simulated flapping | Incident state machine unit test suite |

---

## 5. Explicit Non-Goals for v1
- Multi-tenancy, team organizations, or public registration.
- Multi-region or distributed probing agents.
- Browser-based synthetic user journey automation (Puppeteer/Playwright).
- Public arbitrary URL proxying or public status pages (v1 dashboard is authenticated owner-only).
- SMS, Slack, Discord, Telegram, or Webhook notifications (deferred to v1.1).
- Sub-minute checking intervals (e.g., 10-second checks).
- Full application performance monitoring (APM) or database query tracing.
