# Phase 03 — Dashboard

**Status:** CONDITIONALLY APPROVED for functional delivery, effective through PR #15 under ADR-0005

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

Local verification on Windows PHP 8.4 / SQLite passed with 316 tests and 2,174 assertions, including 12 history tests and 116 assertions. Git whitespace checks, Composer strict validation, lockfile platform requirements and audit, Pint, `npm ci`, and the production build passed. PR #11 was squash-merged at `91169b7a6f26981df49eb48f90838424c097f372` after the three required CI jobs passed with 316 tests and 2,180 assertions each. [P03-02 CI](https://github.com/ayjed/SitePulse/actions/runs/46150396892)

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

## P03-03 — Monitor Detail and Visualization

The existing authorized monitor route now shows current status and configuration, the last recorded check, 24-hour, 7-day and 30-day observed-check uptime, recent latency, and paginated check and incident history. Existing edit, pause, resume and delete actions use their original routes. The layout follows the existing Tailwind palette and status badges in both themes.

The component uses one locked UTC reference time for all four history reads. Manual refresh advances it and resets both pages. Check time and incident start time are bounded by that reference. Current monitor state and incident resolution fields reflect the latest persisted records. Uptime shows completed-check sample counts, a separate “No data” state, and a distinct `0%` result. A non-perfect value that rounds to 100% displays `>99.99%`. History continues to be associated with the monitor after a URL edit.

The native SVG plots up to 60 check samples oldest first, with horizontal spacing proportional to UTC elapsed time. Only measured latency produces a point. The chart uses milliseconds, includes its actual recorded range, and offers a keyboard-expandable data table with every sample, including missing and zero values. It does not connect points across missing data. Incident history distinguishes ongoing time at the reference timestamp from recorded final duration.

Livewire signs the model identity and keeps its ID, reference, filters and cursor state locked. The existing policy authorizes the monitor before reads or component actions. Each history page displays at most 25 rows. Cursor signatures bind the token to monitor ID, UTC reference, history type and incident filter. The server checks size, type, encoding, structure, timestamp, ID and direction before accepting a cursor. A filter change clears the incident cursor, and an invalid token lands on the first page in its own history. Responses use private/no-store directives. Blade escapes names and recorded incident causes, and the check history does not include raw error messages.

Browser smoke verification used a disposable SQLite fixture and the built stylesheet, with preview-only light and dark media overrides. At approximately 1440, 768 and 375 pixels, the page had no horizontal overflow. Keyboard activation retained visible focus after Refresh history, expanded the latency table, and recovered from a synthetic query error. Check pagination and the Open incident filter were also exercised. Synthetic screenshots are Git-ignored under `storage/app/private/monitor-detail-*.jpg`. Formal WCAG assessment is pending.

Local PHP 8.4 / SQLite verification passed with 334 tests and 2,332 assertions, including 18 monitor detail tests and 158 assertions. Git whitespace checks, Composer strict validation, lockfile platform requirements and audit, Pint, `npm ci`, and the production build passed. The protected update, cursor, data accuracy, and bounded read tests use the same suite in the existing SQLite and MySQL 8.0 CI matrix.

### Combined detail request benchmark

The measurements below were recorded at the original P03-03 review head `f42be77d750346bf781ff3684669401b7b11ebd7`. A loopback PHP 8.4.28 development server used an isolated SQLite 3.54.1 WAL database with 432,000 synthetic checks across ten monitored endpoints and 320 incidents. A synthetic owner used the real session-authenticated detail route and sent a signed Livewire refresh update. Each operation had one warm-up and 25 measured requests. The PHP timer covered Laravel bootstrap, middleware, queries and response rendering. The SQL timer combined observed statement times. Uncompressed response bytes include the complete HTML or Livewire JSON body.

| Operation | SQL statements | Uptime aggregates | SQL p50 (ms) | Server p50 (ms) | Server p95 (ms) | Response bytes |
|---|---:|---:|---:|---:|---:|---:|
| authenticated HTML GET | 10 | 1 | 83.34 | 430.086 | 455.790 | 105,735 |
| Livewire refresh POST | 11 | 1 | 83.70 | 429.387 | 448.084 | 105,098 |

The data path issued five monitor lookups, three check queries and one incident query per request. HTTP authentication added one user lookup. The Livewire endpoint added one cache-table query with the default database cache store. PHP reserved up to 31,457,280 bytes during both operations. The measured cost is local server-side timing, with no network or browser rendering measurement. The uptime scan still grows with the number of eligible recorded checks for the monitor. The previous engine-level MySQL and SQLite benchmarks remain applicable to the unchanged MonitorHistory queries.

### P03-03 closeout review

- The original Delete Monitor button submitted a permanent cascade in one action. A closed native disclosure now names the monitor and explains the loss of its check and incident history before exposing a separate submit button. A client-side pending flag suppresses repeat form submission. The existing owner-authorized, CSRF-protected route handles the deletion.
- Three rapid Enter presses on the original refresh control produced at least two Livewire refresh requests in the delayed browser fixture. The shared history-action guard permits one in-flight request across refresh, pagination and filtering. The corrected isolated trace recorded one refresh for three presses while pending, and the browser retained visible focus. Keyboard disclosure open/cancel, check pagination, incident pagination and incident filtering also passed. The confirmed cascade was exercised in a separate database test using synthetic records.
- The monitor detail now labels the recorded reference as the history cutoff. Incident starts use that cutoff. Status and resolution use the latest saved record, and a later resolution is identified on the row. A regression resolves an incident after the locked cutoff and verifies both the later-resolution label and its disappearance after advancing the cutoff.
- The existing signed cursor and locked-state regressions pass. Uptime tests distinguish `<0.01%` and `>99.99%` from exact `0%` and `100%`. The original CI run `46153241238` recorded 334 tests and 2,338 assertions per job before remediation.

The complete local replacement suite passed with 337 tests and 2,360 assertions, including 21 detail tests and 186 assertions. Git whitespace checks, Composer validation, platform requirements and locked audit, Pint, `npm ci`, and the production build passed. The existing `esbuild` script-policy warning remains documented above.

The benchmark and earlier synthetic screenshots describe the original PR head. The closeout browser capture is Git-ignored at `storage/app/private/monitor-detail-delete-confirmation.jpg`. The maintainer authorized replacement head 6ed3a5d20061f141a1d6ab29b13e18b8d40f65c1. CI run 46155308200 recorded 337 tests and 2,366 assertions per job. PR #12 squash-merged at 798ad0276e31de66ce41f22f5321a19a98c96cde.

## P03-04 — Browser Smoke Testing and Accessibility

The [browser and accessibility matrix](../audits/P03_04_ACCESSIBILITY.md) describes fixture isolation, contributor commands, regression coverage and remaining review limits.

PR #13 was squash-merged at `d89846b867effe016d33fc986a45ead88d01c58f`. All four jobs passed on the approved head and resulting main commit. [Authorized run](https://github.com/ayjed/SitePulse/actions/runs/46159162521) · [Main run](https://github.com/ayjed/SitePulse/actions/runs/46159905568)

## P03-05 — Final Acceptance and Evidence

The [final acceptance audit](../audits/PHASE_03_FINAL_ACCEPTANCE.md) maps the requirements to implementation and evidence. It records the incident table correction and distinguishes observed browser and accessibility results from the remaining evidence for FR-7's full WCAG 2.1 AA requirement.

PR #14 was squash-merged at `df37a2ac0afe8ac93137fc2cc2c87eb269d2b7e0`. The maintainer accepted the verified functional, security, database, performance and automated browser evidence on 2026-11-28. [ADR-0005](../decisions/ADR-0005-phase-03-accessibility-release-gate.md) became effective when PR #15 squash-merged at `1034bd6e936aa2a25d49b8ec4f39a19a9c820439`. The full FR-7 verification remains a [mandatory Phase 05 release gate](PHASE_05_RELEASE.md#mandatory-accessibility-release-gate).

## Scope
- [x] Monitor list and status overview.
- [x] Check history, incident details and response times.
- [x] Responsive mobile and desktop layouts at the tested Chromium widths and themes.
- [ ] Accessible interaction and empty/error states.
- [x] Browser smoke tests.

## Exit criteria
- [x] Status and history are correct for test scenarios.
- [ ] Complete the remaining manual accessibility review at the mandatory Phase 05 gate under ADR-0005.
- [x] Record conditional functional approval through the maintainer-authorized PR #15 merge.
