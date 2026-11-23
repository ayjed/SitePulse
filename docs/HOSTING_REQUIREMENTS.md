# SitePulse Hosting Feasibility & Environment Specification

**Status:** PROPOSED (Phase 00 Final Technical Corrections)  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

---

## 1. Hosting Feasibility Overview

SitePulse is designed to be lightweight, autonomous, and self-hosted on standard PHP infrastructure. However, **SitePulse does not claim universal compatibility with all shared hosting providers**. 

The application requires specific operational primitives that budget or legacy shared hosts frequently lack or restrict:
- A regular system cron capable of running every 1 minute.
- PHP CLI (Command Line Interface) access with sufficient execution time budget.
- Unrestricted outbound HTTP/HTTPS sockets to arbitrary public internet destinations.
- Write permissions for SQLite sidecar files (`-wal` and `-shm`) or an external MySQL database.

Shared hosting compatibility is an **engineering target** that requires empirical deployment testing on real target hosts before public release claims.

---

## 2. Supported Hosting Tiers

| Tier | Environment Type | Compatibility Status | Operational Notes |
|---|---|---|---|
| **Tier 1 (Recommended)** | Linux VPS (Hetzner, DigitalOcean, Linode) or Docker container | **Fully Supported** | Complete control over cron, PHP CLI, memory limits, and outgoing network traffic. 1 vCPU and 1 GB RAM is ample. |
| **Tier 2 (Supported with Prerequisites)** | Modern shared hosting (cPanel / CloudLinux with SSH/shell access) | **Target: Supported with Caveats** | Requires user crontab access (1-minute interval), PHP 8.3+ CLI binary, unblocked outbound cURL, and SQLite WAL write permissions. Concurrency is bounded to 5 workers. Must be verified via deployment tests. |
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

### 3.2 Composer & Package Dependencies
- **Composer Version:** Composer 2.7+ on the host (or deployment via pre-packaged release zip with pre-built `vendor/` directory).
- **Dependency Baseline:**
  - `laravel/framework`: `^13.0`
  - `pestphp/pest`: `^4.0` (with compatible `phpunit/phpunit: ^11.0 || ^12.0`, subject to Composer resolution)
  - `livewire/livewire`: `^4.2` (subject to Composer resolution; Blade + Tailwind polling as zero-dependency fallback)

### 3.3 Command Line Interface (CLI) Access
- **Terminal Access:** SSH shell access (or cPanel Terminal) is required for initial setup:
  - Running database migrations: `php artisan migrate --force`.
  - Provisioning initial site owner: `php artisan sitepulse:create-owner`.
  - Diagnostic and status checks.

### 3.4 Cron & Task Scheduling Requirements
- **Frequency:** System scheduler must be triggered **every 1 minute**:
  ```bash
  * * * * * php /path/to/sitepulse/artisan schedule:run >> /dev/null 2>&1
  ```
- **Execution Budget:** The internal check engine enforces a monotonic deadline of 45s (50s hard ceiling) to ensure execution clears cleanly before the next cron tick.
- **Locking:** `withoutOverlapping(10)` enforces a 10-minute cache lock TTL to prevent deadlocks after abrupt worker termination.

### 3.5 Database Requirements
- **SQLite (Default):** SQLite 3.35.0+ with WAL mode (`PRAGMA journal_mode = WAL;`). Filesystem write permissions required in `database/` for `-wal` and `-shm` temporary files.
- **MySQL / MariaDB:** MySQL 8.0+ or MariaDB 10.5+ / 10.11+ LTS with InnoDB and `utf8mb4_unicode_ci`. Requires CI integration testing.

### 3.6 Outbound Network Traffic (Egress)
- Outbound TCP traffic to ports **80** (HTTP) and **443** (HTTPS) must be unrestricted to arbitrary public IP addresses. Hosts enforcing outbound firewalls or mandatory proxies are not supported.

### 3.7 Email Delivery Requirements
- Outbound transactional email via SMTP (port 587/465) or API drivers (Mailgun, Postmark, Resend, Amazon SES). PHP `mail()` is discouraged.

---

## 4. Known Hosting Limitations & Measurable Targets

1. **CloudLinux LVE Limits:** Concurrency bounded to 5 requests to keep CLI memory usage below the 45 MB target and avoid LVE process kills.
2. **Execution Timeouts:** Enforced monotonic deadline prevents runaway execution on hosts with strict CLI execution limits.
3. **Database Concurrency:** SQLite WAL reduces file contention, but heavy write loads may still experience contention; batch writes are implemented to reduce disk operations and will be benchmarked in Phase 02.
