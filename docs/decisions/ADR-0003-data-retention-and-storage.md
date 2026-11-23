# ADR-0003: Database Storage Strategy, Concurrency, and Data Retention

**Status:** PROPOSED (Phase 00 Final Technical Corrections)  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse records periodic check results and latency metrics for each monitor. 
For example:
- 10 monitors checked every 1 minute generate 14,400 check records per day (~432,000 per month).
- 25 monitors checked every 1 minute generate 36,000 records per day (>1,000,000 per month).

The application must run reliably on low-resource environments (entry-level VPS or constrained shared hosting) without complex administration, while managing database size and minimizing file lock contention.

## Options Considered

1. **Option A: MySQL / PostgreSQL as strict requirement**
   - *Pros:* High concurrent write throughput, robust row-level locking.
   - *Cons:* Increases setup friction significantly for small site owners and solo developers; requires managing database users, credentials, and connection pools.

2. **Option B: SQLite as Default with WAL Mode + Full MySQL/PostgreSQL Compatibility via Eloquent (Selected)**
   - *Pros:*
     - Zero-configuration setup: a single SQLite database file allows SitePulse to work out of the box.
     - When Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) and busy timeout (`PRAGMA busy_timeout = 5000;`) are configured, SQLite write lock contention is substantially reduced.
     - Laravel 13's Eloquent migrations provide first-class compatibility with MySQL/MariaDB and PostgreSQL for users who prefer external databases.
   - *Cons:*
     - SQLite file-locking under heavy concurrent writers can still raise `SQLITE_BUSY` if transactions take too long or concurrency is unconstrained.

## Decision
1. **Default Database Engine:** Default to SQLite for zero-friction installation.
2. **Concurrency Tuning for SQLite:** Configure SQLite connection with:
   - `journal_mode = WAL`
   - `busy_timeout = 5000` (wait up to 5 seconds for write locks rather than failing immediately)
   - `synchronous = NORMAL`
3. **Batch History Write Strategy (Targeted Optimization):**
   - To reduce disk I/O churn and SQLite write lock contention, check results from the concurrent worker pool are buffered in memory and committed in a single database transaction (`DB::transaction(...)`) per worker batch (5–10 checks).
   - *Note on Performance Claims:* Batch writes are an architectural strategy intended to improve write throughput; exact performance improvements and disk I/O reductions require empirical benchmarks during Phase 02.
4. **Database Portability:** Write all migrations and database queries using standard Laravel Schema Builder and Eloquent ORM to maintain compatibility with MySQL 8.0+ / MariaDB 10.5+. Compatibility requires automated integration testing in CI during Phase 01.
5. **Data Retention & Automated Pruning:**
   - Raw `check_results` records will have a configurable retention window (default: **30 days**, configurable via `SITEPULSE_HISTORY_RETENTION_DAYS`).
   - A daily scheduled command (`sitepulse:prune-checks`) will delete check records older than the retention threshold in chunks.
   - The `incidents` table (outage history, start time, resolution time, duration, root cause) will be retained indefinitely (or for at least 1 year) to ensure long-term reliability trends remain intact without consuming substantial disk space.

## Performance & Portability Targets
- **Storage Target:** Under SQLite with 30-day retention, 10 monitors checked every minute produce $\approx 430\text{K}$ rows/month, with a target storage footprint under 50 MB.
- **Concurrency Target:** SQLite contention between CLI writes and Web reads is *reduced, not eliminated*. Bounded concurrency (5 workers) and short transaction windows are required to prevent lock starvation.
- **Benchmarking Requirement:** Measure SQLite throughput and transaction durations under simulated 50-monitor loads in Phase 02.

## Evidence & Verification Plan
- Phase 01: Verify SQLite WAL configuration and execute automated migrations in CI.
- Phase 02: Implement and benchmark batch-write transactions against sequential writes; verify `sitepulse:prune-checks` deletes expired check results without removing active incidents.
