# ADR-0001: Application Stack, Runtime, and Dependency Compatibility

**Status:** ACCEPTED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse is an open-source, self-hosted website uptime and response-time monitoring application. The system must be straightforward for PHP developers and small site owners to self-host, reliable over months of unattended operation, and secure by default against network-probing vulnerabilities.

Following Phase 00 technical reviews, we have established the application framework on **Laravel 13 on PHP 8.3+**. We must record the exact dependency compatibility requirements for the testing framework, frontend libraries, database engines, and runtime targets before Phase 01 scaffolding begins.

## Dependency Compatibility & Verification Requirements

| Component | Target Version | Compatibility Requirements & Evidence |
|---|---|---|
| **PHP Runtime** | **PHP 8.3+ / 8.4** | Baseline runtime. Provides typed class constants, dynamic class constant fetch, `json_validate()`, and modern memory/performance enhancements. |
| **Framework Core** | **Laravel 13.x** | Requires PHP 8.3+. Standardizes on lean application structure, modern routing, integrated task scheduling (`Schedule`), Eloquent ORM, and first-class mail notifications. |
| **Testing Harness** | **Pest 4.x (with PHPUnit 12)** | Pest 4 with PHPUnit 12 is the testing baseline for PHP 8.3+ and Laravel 13 (actual package resolution verified in Phase 01). Powered by PHPUnit 12, Pest 4 provides unified unit, integration, and Playwright-backed browser smoke testing. |
| **Frontend UI** | **Blade + Tailwind CSS + Livewire 4.x** | Server-rendered Blade components styled with Tailwind CSS via Vite. Official compatibility for Laravel 13 requires **Livewire v4** (e.g. `^4.2`, which introduced explicit Laravel 13 support and reactive security hardenings). If Livewire 4 introduces unresolved dependency friction during Phase 01 Composer resolution, server-rendered Blade with standard Alpine.js/fetch polling serves as the zero-dependency fallback. |
| **Default Database** | **SQLite 3.35.0+** | Zero-configuration single-file database. Target configuration uses Write-Ahead Logging (`WAL`) mode and busy timeouts to reduce write contention between CLI checks and web reads. |
| **Alternate Database** | **MySQL 8.0+ / MariaDB 10.5+** | Portable via standard Laravel Eloquent migrations; cross-database compatibility requires automated integration testing in CI during Phase 01. |
| **Task Scheduling** | **Laravel Console Scheduler** | Driven by system cron: `* * * * * php artisan schedule:run`. |

## Options Considered

### Option 1: Minimalist Custom PHP / Micro-framework (Slim 4 / Symfony Micro)
- **Advantages:** Lower disk footprint (~15 MB vs ~60 MB).
- **Disadvantages:** High engineering overhead; custom-built authentication, CSRF, migration, and CLI scheduling engines present high security and maintenance risk.

### Option 2: Full-Stack Laravel 13.x + Blade / Tailwind CSS + Livewire 4 + Pest 4 (Selected)
- **Advantages:**
  - Modern PHP 8.3+ idioms, strict typing, and rock-solid security defaults (bcrypt/argon2id, CSRF tokens, secure session cookies, rate-limiting).
  - Out-of-the-box CLI scheduling with overlap prevention (`withoutOverlapping`).
  - Active ecosystem with standardized test tooling (Pest 4).
  - Familiar operational paradigm for target PHP developers.
- **Disadvantages:**
  - Disk footprint of `vendor/` is ~60–80 MB.
  - Baseline memory per CLI invocation has a target of $\le 45\text{ MB}$, which requires empirical measurement during Phase 01.

## Decision
Adopt **Laravel 13.x on PHP 8.3+**:
1. **Runtime:** PHP 8.3+ with required extensions: `curl`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `xml`, `bcmath`, `filter`, `ctype`, `tokenizer`, `session`.
2. **Framework:** Laravel 13.x.
3. **Frontend:** Server-rendered Blade components styled with Tailwind CSS; Livewire 4 (`^4.2`) evaluated for dynamic metrics with Blade polling fallback.
4. **Testing Suite:** Pest 4.x with PHPUnit 12 (actual package resolution verified in Phase 01).
5. **Database Driver:** SQLite as zero-configuration default; MySQL 8.0+ / MariaDB 10.5+ fully supported via integration tests.
6. **Task Scheduling:** System cron invoking `php artisan schedule:run` once per minute.
7. **Hosting Targets:** Documented in `docs/HOSTING_REQUIREMENTS.md`. Shared hosting compatibility is an engineering target requiring real-world deployment testing.

## Measurable Performance & Portability Targets
- **Memory Footprint:** Target $\le 45\text{ MB}$ peak CLI memory usage during a check cycle (to be measured empirically via `memory_get_peak_usage(true)` in Phase 01 CI).
- **Database Concurrency:** SQLite WAL mode and busy timeouts reduce file lock contention, but do not eliminate it under heavy parallel load.
- **MySQL Compatibility:** Requires automated multi-database CI integration tests before claiming full compatibility.
- **Shared Hosting:** Requires deployment smoke tests on actual target hosts before public release claims.

## Evidence & Verification Plan
- **Phase 01 Dependency Verification (2026-11-24):** Verified actual Composer lock resolution for `laravel/framework` (`v13.44.1`), `livewire/livewire` (`v4.4.10`), `pestphp/pest` (`v4.7.8`), `phpunit/phpunit` (`12.5.33`), and `laravel/pint` (`v1.32.3`). Because `symfony/* >= 8.0` requires PHP `8.4.0+`, `composer.json` explicitly conflicts with `symfony/* >= 8.0` so Composer locks to Laravel 13's supported **Symfony 7.4 LTS** series (`php >= 8.2`), retaining full **PHP 8.3+** baseline compatibility without downgrading Laravel, Livewire, Pest, or PHPUnit.
- **Node.js / Frontend Build Verification (2026-11-24):** Verified `package-lock.json` with `npm ci` and `npm run build` (`vite v7.3.8`, `laravel-vite-plugin v2.1.0`, `@tailwindcss/vite v4.3.5`). Build tooling requires **Node.js `^20.19.0 || >=22.12.0`** (CI runs Node `22` LTS).
- Measure baseline CLI memory footprint and verify execution within memory budgets in CI once monitoring commands are introduced in Phase 02.
