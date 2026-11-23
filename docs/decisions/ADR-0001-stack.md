# ADR-0001: Application Stack, Runtime, and Hosting Constraints

**Status:** PROPOSED (Phase 00 Architecture Revision)  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse is an open-source, self-hosted website uptime and response-time monitoring application. The system must be straightforward for PHP developers and small site owners to self-host, reliable over months of unattended operation, and secure by default against network-probing vulnerabilities.

Following Phase 00 review feedback, the foundational application framework baseline is updated from Laravel 11 to **Laravel 13 on PHP 8.3+**. We must formally verify the compatibility of the framework, frontend libraries, database engines, testing harnesses, and runtime constraints before Phase 01 scaffolding begins.

## Stack Compatibility Verification

| Component | Target Version | Compatibility & Verification Status |
|---|---|---|
| **PHP Runtime** | **PHP 8.3+ / 8.4** | Baseline runtime. Provides typed class constants, dynamic class constant fetch, `json_validate()`, and modern memory/performance enhancements. |
| **Framework Core** | **Laravel 13.x** | Requires PHP 8.3+. Standardizes on lean application structure, modern routing, integrated task scheduling (`Schedule`), Eloquent ORM, and first-class mail notifications. |
| **Frontend UI** | **Blade + Tailwind CSS (v3/v4) + Livewire 3.x** | Server-rendered Blade components styled with Tailwind CSS via Vite. Livewire 3 provides reactive dashboard updates without requiring an external SPA build or Node daemon in production. |
| **Testing Harness** | **Pest 3.x / PHPUnit 11.x** | Pest 3 is designed for PHP 8.3+ and Laravel 13. Provides expressive, readable unit, integration, and security test suites. |
| **Default Database** | **SQLite 3.35.0+** | Zero-configuration single-file database. Configured with Write-Ahead Logging (`WAL`) mode and busy timeout for safe concurrent CLI writes and web reads. |
| **Alternate Database** | **MySQL 8.0+ / MariaDB 10.5+** | Fully supported via standard Laravel Eloquent migrations with zero code divergence. |
| **Task Scheduling** | **Laravel Console Scheduler** | Driven by standard system cron: `* * * * * php artisan schedule:run`. |

## Options Considered

### Option 1: Minimalist Custom PHP / Micro-framework (Slim 4 / Symfony Micro)
- **Advantages:** Lower disk footprint (~15 MB vs ~60 MB).
- **Disadvantages:** Slower velocity; custom-built authentication, CSRF, migration, and CLI scheduling engines present high security and maintenance overhead.

### Option 2: Full-Stack Laravel 13.x + Blade / Tailwind CSS + SQLite / MySQL (Selected)
- **Advantages:**
  - Modern PHP 8.3+ idioms, strict typing, and rock-solid security defaults (bcrypt/argon2id, CSRF tokens, secure session cookies, rate-limiting).
  - Out-of-the-box CLI scheduling with overlap prevention (`withoutOverlapping`).
  - Active ecosystem with standardized test tooling (Pest 3).
  - Familiar operational paradigm for target PHP developers.
- **Disadvantages:**
  - Disk footprint of `vendor/` is ~60–80 MB.
  - Baseline memory per CLI invocation is ~35–45 MB (well within standard 128 MB–256 MB PHP limits, but requires bounded concurrency on constrained hosts).

## Decision
Adopt **Laravel 13.x on PHP 8.3+**:
1. **Runtime:** PHP 8.3+ with required extensions: `curl`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `xml`, `bcmath`, `filter`, `ctype`, `tokenizer`, `session`.
2. **Framework:** Laravel 13.x.
3. **Frontend:** Server-rendered Blade components styled with Tailwind CSS; Livewire 3 for dynamic dashboard metrics.
4. **Testing Suite:** Pest 3.x (PHPUnit 11 foundation).
5. **Database Driver:** SQLite as zero-configuration default; MySQL 8.0+ / MariaDB 10.5+ fully supported.
6. **Task Scheduling:** Single system cron invoking `php artisan schedule:run` once per minute.
7. **Minimum Hosting Target:** VPS (1 vCPU, 1 GB RAM) or modern CLI-enabled shared hosting with cron access (documented in `docs/HOSTING_REQUIREMENTS.md`).

## Consequences
- Modern, clean codebase for Phase 01 scaffolding.
- Consistent architecture avoiding bespoke framework layers.
- Minimum hosting requirements are strictly documented: SSH/CLI access and a 1-minute cron entry are required. Users without CLI access cannot reliably operate SitePulse v1.

## Required Evidence & Acceptance Criteria
- Phase 01 clean scaffold must execute on PHP 8.3+ with automated Pest test pass in CI.
- Measure baseline CLI memory footprint and verify execution under 45 MB.
