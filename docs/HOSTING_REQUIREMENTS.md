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

### 3.2 Composer, Frontend Build & Package Dependencies
- **Composer Version:** Composer 2.7+ on the host (or deployment via pre-packaged release zip with pre-built `vendor/` directory).
- **Dependency Baseline:**
  - `laravel/framework`: `^13.0` (verified at `v13.44.1`)
  - `pestphp/pest`: `^4.0` with `phpunit/phpunit: ^12.5` (verified at `v4.7.8` / `12.5.33`)
  - `livewire/livewire`: `^4.2` (verified at `v4.4.10`)
  - **PHP 8.3+ Compatibility Guard:** `composer.json` excludes `symfony/* >= 8.0` via `"conflict"` declarations (since Symfony 8.0+ requires PHP `>= 8.4.0`), keeping `composer.lock` on Laravel 13's supported **Symfony 7.4 LTS** branch. Using `config.platform.php` instead of `"conflict"` was evaluated and rejected because `config.platform.php` masks real host PHP `< 8.3` checks during `composer install` (verified empirically on PHP `8.2.12`). Real PHP `8.3` and `8.4` execution is scheduled in GitHub Actions CI.
- **Node.js & Frontend Assets:**
  - Building assets from source (`npm ci && npm run build`) requires **Node.js `^20.19.0 || >=22.12.0`** (CI targets Node `22` LTS; verified locally with Node `v26.13.0` and npm `11.20.0`).
  - Target hosts without Node.js/npm can deploy pre-built `public/build/` assets generated during CI/packaging.

### 3.3 Development Workstation Caveat (Windows Smart App Control)
- Official `windows.php.net` PHP ZIP distributions are unsigned (`Authenticode Status: NotSigned`). On Windows 11 machines enforcing **Smart App Control** (`VerifiedAndReputableDesktop`), loading unsigned extension DLLs (such as `php_openssl.dll` and `php_fileinfo.dll`) can be blocked (`0xc0e90002`) in restricted or offline sandboxes where cloud reputation is unavailable.
- Do **not** disable Windows Smart App Control to work around this. Use **WSL2 Ubuntu**, **Docker Linux containers** (`php:8.3-cli` / `php:8.4-cli`), code-signed runtimes, or **GitHub Actions `ubuntu-latest`** CI runners.

### 3.3 Command Line Interface (CLI) Access
- **Terminal Access:** SSH shell access (or cPanel Terminal) is required for initial setup:
  - Running database migrations: `php artisan migrate --force`.
  - Provisioning initial site owner: `php artisan sitepulse:create-owner`.
  - Diagnostic and status checks.

### 3.4 Cron & Task Scheduling Requirements
- **Deployment Safety Opt-In (`SITEPULSE_SCHEDULED_CHECKS_ENABLED`):** Scheduled execution of `sitepulse:check-monitors` via Laravel's scheduler defaults to **disabled** (`config/sitepulse.php` `scheduled_checks_enabled` reads `SITEPULSE_SCHEDULED_CHECKS_ENABLED=false`). Existing `artisan schedule:run` cron jobs will skip unattended outbound probes while `schedule:list` still shows the task and manual `php artisan sitepulse:check-monitors` CLI runs remain available. Unattended scheduled checks only run once `SITEPULSE_SCHEDULED_CHECKS_ENABLED=true` is explicitly configured (and rebuilt via `php artisan config:cache` if config caching is active).
- **Frequency:** Once authorized and enabled, system scheduler should trigger **every 1 minute**:
  ```bash
  * * * * * php /path/to/sitepulse/artisan schedule:run >> /dev/null 2>&1
  ```
- **Execution Budget:** The internal check engine enforces a whole-run monotonic deadline target of 45s (50s hard handle-cancellation ceiling; subject to non-preemptible synchronous DB and OS stub-resolver DNS boundaries) to clear cleanly before the next cron tick.
- **Locking:** `withoutOverlapping(10)` enforces a 10-minute cache lock TTL alongside `MonitorCheckRunner`'s execution lock to prevent deadlocks after abrupt worker termination.

### 3.5 Database Requirements
- **SQLite (Default):** SQLite 3.35.0+ with WAL mode (`PRAGMA journal_mode = WAL;`). Filesystem write permissions required in `database/` for `-wal` and `-shm` temporary files.
- **MySQL / MariaDB:** MySQL 8.0+ or MariaDB 10.5+ / 10.11+ LTS with InnoDB and `utf8mb4_unicode_ci`. Requires CI integration testing.

### 3.6 Outbound Network Traffic (Egress)
- Outbound TCP traffic to ports **80** (HTTP) and **443** (HTTPS) must be unrestricted to arbitrary public IP addresses. Hosts enforcing outbound firewalls or mandatory proxies are not supported.

### 3.7 Email Delivery Requirements
- Outbound transactional email via SMTP (port 587/465) or API drivers (Mailgun, Postmark, Resend, Amazon SES). PHP `mail()` is discouraged.
- **Production Mail Config:** Local `.env.example` sets `MAIL_MAILER=log` to avoid accidental outbound messages during development; production deployments **must** configure `MAIL_MAILER=smtp` (or a supported API driver), valid TLS/authentication credentials, `MAIL_FROM_ADDRESS`, and keep SMTP timeout bounded (`config/mail.php` defaults `mailers.smtp.timeout` to `MAIL_TIMEOUT=5` seconds, adhering to the `<= 5s` limit in ADR-0004).

### 3.8 Production Environment Hardening & SQLite Overrides
- **SQLite Defaults vs Environment Overrides:** `config/database.php` supplies the default SQLite settings (`journal_mode => env('DB_JOURNAL_MODE', 'wal')`, `busy_timeout => env('DB_BUSY_TIMEOUT', 5000)`, `synchronous => env('DB_SYNCHRONOUS', 'normal')`, and `transaction_mode => 'DEFERRED'`). `.env.example` sets only `DB_CONNECTION=sqlite` and leaves those tuning flags unset unless explicitly overridden in `.env`. `phpunit.xml` overrides `DB_DATABASE=:memory:` for isolated test execution (where SQLite WAL is inactive because `:memory:` databases do not persist WAL files).
- **Mandatory Production Environment Variables:**
  - `APP_ENV=production` and `APP_DEBUG=false` (`.env.example` enables `APP_DEBUG=true` for local debugging only).
  - `APP_URL=https://...` served exclusively over HTTPS.
  - `SESSION_SECURE_COOKIE=true` (`config/session.php` defaults `secure` to `null` / `.env.example` uses `false` for local HTTP `localhost`, which is unsafe over public networks).

---

## 4. Known Hosting Limitations & Measurable Targets

1. **CloudLinux LVE Limits:** Concurrency bounded to 5 requests to keep CLI memory usage below the 45 MB target and avoid LVE process kills.
2. **Execution Timeouts:** Enforced monotonic deadline prevents runaway execution on hosts with strict CLI execution limits.
3. **Database Concurrency:** SQLite WAL reduces file contention, but heavy write loads may still experience contention; batch writes are implemented to reduce disk operations and will be benchmarked in Phase 02.
