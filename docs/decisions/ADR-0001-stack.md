# ADR-0001: Application Stack, Runtime, and Hosting Constraints

**Status:** PROPOSED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse is an open-source, self-hosted website uptime and response-time monitoring application. The system must be straightforward for PHP developers and small site owners to self-host, reliable over months of unattended operation, and secure by default against network-probing vulnerabilities.

We must decide on the foundational application framework, PHP runtime requirements, UI stack, database drivers, and hosting tier assumptions before Phase 01 scaffolding begins.

## Options Considered

### Option 1: Minimalist Custom PHP / Micro-framework (e.g., Slim 4 + Phinx + Custom Auth)
- **Advantages:**
  - Lower disk footprint (~15 MB vs ~60 MB).
  - Slightly lower baseline memory usage per CLI invocation (~15 MB).
  - Can run on very constrained legacy shared hosting.
- **Disadvantages:**
  - High engineering overhead: authentication, session handling, CSRF protection, database migrations, CLI scheduling, and mail abstractions must be manually assembled and maintained.
  - Significantly higher risk of subtle security flaws in hand-rolled authentication, authorization, and rate-limiting.
  - Slower feature development velocity and less standardized test tooling.

### Option 2: Full-Stack Laravel 11.x + Blade / Tailwind CSS + SQLite / MySQL (Selected)
- **Advantages:**
  - Modern PHP 8.3+ idioms, strict typing, and security defaults (robust session handling, bcrypt/argon2 hashing, CSRF tokens, secure cookie flags).
  - First-class CLI task scheduling (`Schedule`), Eloquent ORM with cross-database migration support, and clean Mail notification components.
  - Standardized, mature testing ecosystem (Pest / PHPUnit) with built-in HTTP and database test helpers.
  - Active community, extensive documentation, and familiar operational model for the primary target persona (PHP developers).
- **Disadvantages:**
  - Higher disk footprint (~55–75 MB in `vendor`).
  - Baseline memory per CLI invocation is ~30–45 MB (well within standard 128 MB–256 MB PHP memory limits, but requires verification on cheap hosts).
  - Requires Composer and PHP CLI access for installation and updates.

## Decision
Adopt **Option 2: Laravel 11.x on PHP 8.3+**:
1. **Runtime:** PHP 8.3+ with extensions: `curl`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `openssl`, `xml`, `bcmath`.
2. **Framework:** Laravel 11.x.
3. **Frontend:** Server-rendered Blade components styled with Tailwind CSS (clean, fast, accessible; Livewire evaluated for reactive dashboard polling in Phase 03 without requiring a separate SPA build).
4. **Testing Suite:** Pest PHP (built on PHPUnit) for readable unit, integration, and security test coverage.
5. **Database Driver:** SQLite as zero-configuration default; MySQL 8.0+ / MariaDB 10.5+ fully supported via standard migrations.
6. **Task Scheduling:** Standard system cron triggering `php artisan schedule:run` once per minute.
7. **Minimum Hosting Target:** 
   - Low-cost VPS (e.g., 1 vCPU, 1 GB RAM on Hetzner, DigitalOcean, Linode), Docker container, or modern shared hosting with PHP 8.3 CLI, cron, and SQLite support.

## Consequences
- Fast, secure foundation for Phase 01 scaffolding.
- Consistent architecture avoiding custom infrastructure code.
- Setup requirements are clearly communicated: SSH/CLI access and a 1-minute cron entry are required. Users without CLI access cannot reliably operate SitePulse v1.

## Required Evidence
- Phase 01 must measure clean scaffold memory footprint, verify SQLite WAL performance, and confirm test suite execution in CI.
