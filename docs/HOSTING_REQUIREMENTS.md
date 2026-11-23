# SitePulse Hosting Feasibility & Environment Specification

**Status:** PROPOSED (Phase 00 Deliverable)  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

---

## 1. Hosting Feasibility Overview

SitePulse is designed to be lightweight, autonomous, and self-hosted on standard PHP infrastructure. However, **SitePulse does not claim universal compatibility with all shared hosting providers**. 

The application requires specific operational primitives that budget or legacy shared hosts frequently lack or severely restrict:
- A regular system cron capable of running every 1 minute.
- PHP CLI (Command Line Interface) access with sufficient execution time budget.
- Unrestricted outbound HTTP/HTTPS sockets to arbitrary public internet destinations.
- Write permissions for SQLite sidecar files (`-wal` and `-shm`) or an external MySQL database.

---

## 2. Supported Hosting Tiers

| Tier | Environment Type | Compatibility Status | Operational Notes |
|---|---|---|---|
| **Tier 1 (Recommended)** | Linux VPS (Hetzner, DigitalOcean, Linode) or Docker container | **Fully Supported** | Complete control over cron, PHP CLI, memory limits, and outgoing network traffic. 1 vCPU and 1 GB RAM is ample. |
| **Tier 2 (Supported with Prerequisites)** | Modern shared hosting (cPanel / CloudLinux with SSH/shell access) | **Supported with Caveats** | Requires user crontab access (1-minute interval), PHP 8.3+ CLI binary, unblocked outbound cURL, and SQLite WAL write permissions. Concurrency must be bounded (default: 5). |
| **Tier 3 (Unsupported / Incompatible)** | Zero-CLI shared hosts, website builders, serverless platforms without persistent disk | **Incompatible** | Hosts restricting cron to hourly/daily intervals, blocking outgoing HTTP/HTTPS, or lacking CLI terminal access cannot run SitePulse v1. |

---

## 3. Detailed Technical Requirements

### 3.1 PHP Version & Core Extensions
- **PHP Version:** **PHP 8.3.0+** or **PHP 8.4.x**.
- **Required Extensions:**
  - `ext-curl`: Required for outbound HTTP/HTTPS health checks, asynchronous connection pooling (`curl_multi`), and socket IP pinning (`CURLOPT_RESOLVE`).
  - `ext-pdo_sqlite`: Required for the default zero-configuration database.
  - `ext-pdo_mysql`: Required if connecting to an external MySQL or MariaDB instance.
  - `ext-openssl`: Required for HTTPS requests, TLS handshake inspection, and certificate expiration date parsing.
  - `ext-mbstring`: Required for multibyte string operations and UTF-8 handling.
  - `ext-xml` & `ext-dom`: Required by Laravel 13 framework core.
  - `ext-bcmath`: Required for high-precision latency and uptime percentage math.
  - `ext-filter`: Required for IP address validation, CIDR parsing, and sanitization.
  - `ext-ctype`, `ext-tokenizer`, `ext-session`: Standard PHP runtime requirements.

### 3.2 Composer & Package Management
- **Composer Version:** Composer 2.7+ is required on the host for installation and application updates.
- **Alternative Deployment for Hosts Without Composer:** For shared hosts without the Composer binary, users may deploy a pre-packaged release zip containing the pre-built `vendor/` directory. However, PHP CLI access remains mandatory for running database migrations and CLI maintenance.

### 3.3 Command Line Interface (CLI) Access
- **Terminal Access:** SSH shell access (or cPanel Terminal) is required for initial setup:
  - Running database migrations: `php artisan migrate --force`.
  - Provisioning initial site owner: `php artisan sitepulse:create-owner`.
  - Diagnostic and status checks.

### 3.4 Cron & Task Scheduling Requirements
- **Frequency:** The system scheduler must be triggered **every 1 minute**:
  ```bash
  * * * * * php /path/to/sitepulse/artisan schedule:run >> /dev/null 2>&1
  ```
- **Execution Budget:** The cron runner requires a CLI `max_execution_time` of at least 60 seconds (or 0 for unlimited). The internal check engine self-limits execution to 50 seconds to cleanly clear before the subsequent cron cycle.
- **Limitation on Constrained Hosts:** Hosts that restrict crontabs to 5-minute or 15-minute intervals can only check monitors at those respective intervals. Sub-5-minute monitoring is impossible on such hosts.

### 3.5 Database Requirements

#### Option A: SQLite (Default & Recommended for Solo Use)
- **SQLite Version:** SQLite 3.35.0+ (bundled with standard PHP 8.3+ builds).
- **Prerequisites:**
  - Support for Write-Ahead Logging (`PRAGMA journal_mode = WAL;`).
  - Filesystem write permissions in `database/` for SQLite temporary journal files (`database.sqlite-wal` and `database.sqlite-shm`).
  - Network-attached storage (NFS / SMB) should be avoided due to SQLite file locking unreliability over network shares.

#### Option B: MySQL / MariaDB (Optional for Higher Load)
- **Supported Versions:** MySQL 8.0+ or MariaDB 10.5+ / 10.11+ LTS.
- **Configuration:** InnoDB storage engine with `utf8mb4_unicode_ci` collation.

### 3.6 Outbound Network Traffic (Egress)
- **Required Ports:** Outbound TCP traffic to ports **80** (HTTP) and **443** (HTTPS) must be unrestricted to arbitrary public IP addresses.
- **Restricted Environments:** Some low-cost shared hosting platforms restrict outbound cURL via firewalls or enforce egress through an HTTP proxy. SitePulse requires direct or explicitly configured proxy egress.

### 3.7 Email Delivery Requirements
- **Delivery Strategy:** Outbound transactional email for alert notifications.
- **Supported Drivers:**
  - SMTP: TLS over port 587 or SSL over port 465 (e.g., via SendGrid, Mailgun, Amazon SES, or custom SMTP).
  - API Drivers: Mailgun, Postmark, Resend (supported via standard Laravel 13 mail transports).
- **PHP `mail()` Caveat:** While PHP `mail()` can be configured in `.env`, it is strongly discouraged due to spam filtering, lack of authentication, and delivery unreliability on shared hosts.

### 3.8 Filesystem Permissions & Security
- **Directory Write Access:** The web server user (`www-data`, `nginx`, `nobody`) and the CLI cron user must both have read/write access to:
  - `storage/` (application logs, session files, cache locks)
  - `bootstrap/cache/` (framework configuration and route caches)
  - `database/` (when using SQLite)
- **Security Boundaries:** The document root of the web server MUST point strictly to the `public/` directory. Parent directories (containing `.env`, `storage/`, and application code) must not be accessible via the web browser.

---

## 4. Known Hosting Limitations & Failure Modes

1. **CloudLinux LVE Process Limits:** On heavily restricted shared hosting, concurrent worker processes might exceed maximum allowed concurrent processes (EP / Entry Processes) or memory limits. SitePulse mitigates this by restricting default check concurrency to 5 requests.
2. **Execution Timeouts:** If a shared host hard-kills CLI processes after 30 seconds, monitoring cycles with many slow sites could be terminated prematurely. SitePulse's bounded concurrent engine and 10s per-request timeout ensure execution stays within safe limits.
3. **Missing System Crontab:** If a user only has access to "web cron" (triggering an HTTP URL via external ping), SitePulse does not expose an unauthenticated public cron endpoint by default due to security and execution timeout risks. A dedicated secure token-guarded webhook route would be required for such environments.
