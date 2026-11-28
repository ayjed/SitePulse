# Phase 03 — Dashboard

**Status:** IN PROGRESS (P03-01 merged, P03-02 authorized by the maintainer)

## P03-01 — Authorized Status Overview

P03-01 covers the owner-only monitor status overview, including current stored status, last check, latency, and manual refresh. Phase 02 closeout was merged via PR #9 at `77070bc5702d976a6b5c1537a98d8e8de4a896e5`. Its local result remains 283 tests and 1,898 assertions. The PR #9 CI jobs each recorded 283 tests and 1,904 assertions.

The P03-01 overview reads 15 monitors per page in ID order and reauthorizes each Livewire update. Desktop and mobile previews and keyboard refresh were checked. The maintainer authorized the verified revision, and PR #10 was squash-merged at `dd53fc48928697f993f45c4777d2c3ef93114a25`.

### P03-01 merge review

The final review found that array and null Livewire pagination arguments raised a type error. Laravel's permissive decoder also accepted invalid characters, and the overview accepted extra cursor fields. The overview now accepts only canonical, size-limited Laravel cursors with a positive integer ID and a boolean direction. Invalid inputs return to the first owner-scoped page. The ten added regression cases cover encoding, structure, size, argument types, and owner isolation.

After the correction, the complete Windows PHP 8.4 / SQLite suite passed with 304 tests and 2,058 assertions. The overview tests passed with 21 tests and 160 assertions. Git whitespace checks, Composer strict validation, lockfile platform requirements and audit, Pint, `npm ci`, and the production build passed. Each required CI job passed with 304 tests and 2,064 assertions on the authorized PR head. [PR #10 CI run](https://github.com/ayjed/SitePulse/actions/runs/46148897156)

On Windows with npm 11.20.0, `npm ci` and `npm install-scripts ls` report that the transitive `esbuild@0.28.2` postinstall hook has no `allowScripts` policy entry. The lockfile supplies the platform binary via `@esbuild/win32-x64@0.28.2`. The clean install reported zero vulnerabilities, and the Vite production build completed. Dependency versions and script policy were left unchanged. [npm script policy](https://docs.npmjs.com/cli/v11/commands/npm-ci/#strict-allow-scripts) · [esbuild binary installation](https://esbuild.github.io/getting-started/#additional-npm-flags)

Desktop and mobile overview captures use synthetic preview data and are preserved locally in the existing Git-ignored `storage/app/private/` directory as `dashboard-desktop.jpg` and `dashboard-mobile.jpg`.

## P03-02 — History and Uptime Queries

`MonitorHistory` offers server-side reads for one monitor. Each call checks the current owner, reloads the monitor by ID and owner, applies the existing monitor policy, and only then queries its telemetry relationships. The service uses selected columns and initiates no probes. A successful read uses two SQL statements, one for the owner-scoped lookup and one for telemetry.

- **Uptime.** One UTC `asOf` timestamp, truncated to the database's second precision, defines all windows. A stored outcome counts when `asOf - window < checked_at <= asOf` for the 24-hour, 7-day, and 30-day windows. The numerator counts stored `success` outcomes. The denominator counts stored `success` and `failure` outcomes. The service returns both counts and `successes / samples × 100`, without display rounding. An empty denominator yields `null` for “No data,” while zero successes with samples yields `0.0`. Missing and pruned checks, unobserved paused time, and future timestamps contribute no samples. History belongs to the monitor ID across URL edits.
- **Check and incident pages.** Laravel cursor pagination returns at most 25 rows, ordered by check time or incident start time descending, then ID descending. The query fetches a single extra row to detect another page. Callers pass a cursor returned by the same query with the same monitor, timestamp, and filter. Without a cursor, each method starts at page one independently of the request query string.
- **Latency samples.** The newest 60 recorded check rows are returned with ID, timestamp, and latency. Missing latency stays `null` and measured zero remains `0`.
- **Incidents.** History includes open and resolved incidents with optional status filtering. The service uses stored start, resolution, cause, and final duration. Open incidents have no final duration. History reads use the existing schema and retention behavior.

Local verification on Windows PHP 8.4 / SQLite passed with 316 tests and 2,174 assertions, including 12 history tests and 116 assertions. Git whitespace checks, Composer strict validation, lockfile platform requirements and audit, Pint, `npm ci`, and the production build passed. The dashboard detail UI and Phase 03 exit criteria remain pending.

### Retained-history benchmark

On Windows with PHP 8.4.28, separate temporary databases used SQLite 3.54.1 in WAL mode and MySQL 8.0.46 with InnoDB. Each was migrated and seeded with 432,000 synthetic minute checks across 10 monitors and 1,210 incidents. For each monitor, every 20th check failed and every 15th had missing latency. Each monitor had 120 resolved incidents and one open incident. Test timing used one monitor, the same UTC `asOf`, and 25 warm iterations per operation after collecting planner statistics. Neither database used production data.

Each operation issued two SQL statements. SQL time is the median combined time reported by Laravel for both statements. Service time includes authorization, both statements, and result formatting or hydration. All times are in milliseconds.

| Operation | Results | SQLite SQL p50 | SQLite service p50 | SQLite service p95 | MySQL SQL p50 | MySQL service p50 | MySQL service p95 |
|---|---:|---:|---:|---:|---:|---:|---:|
| Three uptime windows | 3 | 79.83 | 80.744 | 85.856 | 157.73 | 158.729 | 170.600 |
| First check page | 25 | 0.17 | 1.303 | 1.633 | 1.45 | 3.031 | 4.130 |
| Next check page | 25 | 0.21 | 1.545 | 1.945 | 1.54 | 3.330 | 4.461 |
| Previous check page | 25 | 0.22 | 1.563 | 1.762 | 1.26 | 3.084 | 4.262 |
| Check page at 20-day cursor | 25 | 0.20 | 1.473 | 1.773 | 1.43 | 3.069 | 4.007 |
| Latency samples | 60 | 0.18 | 1.856 | 2.551 | 1.41 | 3.510 | 4.285 |
| First incident page | 25 | 0.18 | 1.310 | 1.896 | 1.21 | 2.712 | 3.689 |
| Open incidents | 1 | 0.18 | 0.898 | 1.113 | 1.57 | 2.947 | 3.829 |
| Resolved incidents | 25 | 0.19 | 1.298 | 1.532 | 1.53 | 3.043 | 4.403 |

Both databases returned 95% uptime from 1,368 of 1,440, 9,576 of 10,080, and 41,040 of 43,200 checks for the three windows. The first and next check pages returned 25 disjoint IDs each. Paging backward reproduced page one. SQLite's 20-day page improved from 11.98 ms to 0.20 ms in median SQL time when the query used the cursor timestamp as the index range boundary.

PHP's peak live memory during the measured reads, including the booted application, was 41,456,128 bytes on SQLite and 41,724,056 bytes on MySQL. Reserved PHP memory peaked at 44,040,192 bytes on both runs. MySQL server memory and browser request overhead were outside the measurement.

### Query plans

- **SQLite `EXPLAIN QUERY PLAN`.** Monitor authorization used the integer primary key. The aggregate used `check_results_monitor_checked_idx` for `monitor_id=? AND checked_at>? AND checked_at<?`. Check and latency reads used the same index with monitor equality and the timestamp bound. The previous-page query used both timestamp bounds. Incident reads used `incidents_monitor_started_idx` with monitor equality and an upper timestamp bound. The measured telemetry queries used no temporary sort.
- **MySQL `EXPLAIN`.** The monitor lookup used `PRIMARY` with `const` access. The aggregate, check pages, and latency samples used range access on `check_results_monitor_checked_idx`. Recent check pages and latency used a backward index scan. Unfiltered incident history used `incidents_monitor_started_idx` with a backward index scan. The status filters used `incidents_monitor_status_idx` and a filesort over an estimated one open or 120 resolved rows for the synthetic monitor. MySQL's reported row counts are optimizer estimates. These results support the existing indexes for this workload.

The timings describe an isolated, warm workload. The uptime aggregate scans eligible outcomes for one monitor over the widest time window, so time grows with that monitor's retained sample count. No schema change is needed for the measured workload.

## Scope
- [x] Monitor list and status overview.
- [ ] Check history, incident details and response times.
- [ ] Responsive mobile and desktop layouts.
- [ ] Accessible interaction and empty/error states.
- [ ] Browser smoke tests.

## Exit criteria
- [ ] Status and history are correct for test scenarios.
- [ ] Mobile/keyboard usability checked.
- [ ] Maintainer approves visual and functional review.
