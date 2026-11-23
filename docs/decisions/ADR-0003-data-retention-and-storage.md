# ADR-0003: Database Engine, Concurrency, and Data Retention Policy

**Status:** PROPOSED  
**Date:** 2026-11-23  
**Deciders:** Lead Architect, Maintainer (@ayjed)

## Context
SitePulse records periodic check results and latency metrics for each monitor. 
For example:
- 10 monitors checked every 1 minute generate 14,400 check records per day (~432,000 per month).
- 25 monitors checked every 1 minute generate 36,000 records per day (>1,000,000 per month).

The application must run smoothly on low-resource environments (entry-level VPS or shared hosting) without complex administration, while preventing database bloat and file lock contention.

## Options Considered

1. **Option A: MySQL / PostgreSQL as strict requirement**
   - *Pros:* High concurrent write throughput, robust row-level locking.
   - *Cons:* Increases setup friction significantly for small site owners and solo developers; requires managing database users, credentials, and connection pools.

2. **Option B: SQLite as Default with WAL Mode + Full MySQL/PostgreSQL Compatibility via Eloquent (Selected)**
   - *Pros:*
     - Zero-configuration setup: a single SQLite database file allows SitePulse to work out of the box.
     - When Write-Ahead Logging (`PRAGMA journal_mode = WAL;`) and busy timeout (`PRAGMA busy_timeout = 5000;`) are configured, SQLite handles concurrent CLI writes and web dashboard reads smoothly.
     - Laravel's Eloquent migrations provide first-class compatibility with MySQL/MariaDB and PostgreSQL for users who prefer external databases.
   - *Cons:*
     - SQLite file-locking under heavy concurrent writers can raise `SQLITE_BUSY` if WAL mode is not enabled or if writes take too long.

## Decision
1. **Default Database Engine:** Default to SQLite for zero-friction installation.
2. **Concurrency Tuning for SQLite:** Configure SQLite connection with:
   - `journal_mode = WAL`
   - `busy_timeout = 5000` (wait up to 5 seconds for write locks rather than failing immediately)
   - `synchronous = NORMAL`
3. **Database Portability:** Write all migrations and database queries using standard Laravel Schema Builder and Eloquent ORM to maintain 100% compatibility with MySQL 8.0+ / MariaDB.
4. **Data Retention & Automated Pruning:**
   - Raw `check_results` records will have a configurable retention window (default: **30 days**, configurable via `SITEPULSE_HISTORY_RETENTION_DAYS`).
   - A daily scheduled command (`sitepulse:prune-checks`) will delete check records older than the retention threshold in chunks.
   - The `incidents` table (outage history, start time, resolution time, duration, root cause) will be retained indefinitely (or for at least 1 year) to ensure long-term reliability trends remain intact without consuming substantial disk space.

## Consequences
- Fast, zero-maintenance setup for 95% of target users.
- Predictable database storage footprint: 10 monitors with 30-day retention will occupy under 45 MB of disk space.
- Advanced users with existing MySQL/MariaDB instances can simply switch `DB_CONNECTION=mysql` in `.env`.

## Evidence & Verification
- Verify SQLite WAL configuration during Phase 01 scaffolding.
- Integration tests in Phase 02 must verify that `sitepulse:prune-checks` successfully prunes expired check results without removing active or historical incidents.
