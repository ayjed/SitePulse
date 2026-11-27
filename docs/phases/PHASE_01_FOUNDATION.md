# Phase 01 — Foundation

**Status:** APPROVED (`2026-11-27` by `@ayjed`)  
**Gate:** Clean install, reproducible setup, CI and test suite running.

## Scope
- [x] Verify Git repository remote, branch tracking, and history integrity.
- [x] Audit development environment (PHP, Composer, Node, DB engines).
- [x] Design non-destructive scaffolding and merge procedure.
- [x] Install/configure required environment prerequisites (PHP 8.3+, Composer 2.7+).
- [x] Scaffold approved Laravel 13 project.
- [x] Establish local env and `.env.example` (no secrets).
- [x] Resolve dependencies with Composer (Pest 4, PHPUnit 12, Livewire 4).
- [x] Configure SQLite WAL mode (5000ms busy timeout) and execute baseline migrations.
- [x] Verify framework boot and pass initial automated tests (`php artisan test`).
- [x] Remediate PHP 8.3+ lockfile compatibility, unsafe seeding, repeatable setup (`APP_KEY` preservation), and `package-lock.json` (`npm ci` + build).
- [x] Set up automated formatting checks (`pint`) and GitHub Actions CI workflow (`.github/workflows/ci.yml`).
- [x] Implement initial single-owner authentication and CLI provisioner (`sitepulse:create-owner`) (merged via PR #2 at `776333f2320ca52c7d2f7255305fc100f818a9b3`).
- [x] Document installation and startup commands (`README.md`, `docs/HOSTING_REQUIREMENTS.md`).

## Exit criteria
- [x] Clean install works with documented tool versions locally (PHP 8.4.28 host / WSL or Linux CI needed on Windows Smart App Control sandboxes).
- [x] CI passes on a fresh checkout (`.github/workflows/ci.yml` verified green on PR #1 `d3d7ffc`, `08b0212`, PR #2 `46051330107`/`46051323625`, and `main` `776333f` run `46053500009`).
- [x] No credentials or predictable seeded login accounts included in repository.
- [x] Independent security review and merge of owner-authentication PR completed (`https://github.com/ayjed/SitePulse/pull/2` merged at `776333f2320ca52c7d2f7255305fc100f818a9b3`).
- [x] Maintainer approves evidence and review (PR #3 `https://github.com/ayjed/SitePulse/pull/3` merged at `12dbd17a949cd0764ab64cae335fcb9511f70ed1`; Phase 01 explicitly **APPROVED** by `@ayjed` on `2026-11-27`).

---

## Task 01 Execution Log (2026-11-23)

### 1. Commands Executed
- `git status` -> On branch `main`, clean working tree.
- `git remote -v` -> Confirmed `origin` points to `https://github.com/ayjed/SitePulse.git`.
- `git branch -vv` -> `* main 67b38a9 [origin/main] docs: approve SitePulse Phase 00 architecture`.
- `php -v` -> `PHP 8.2.12 (cli) (built: Oct 24 2023 21:15:15) (ZTS Visual C++ 2019 x64)` at `C:\xampp\php\php.exe`.
- `php -m` -> Verified extensions: `curl`, `pdo_sqlite`, `pdo_mysql`, `openssl`, `mbstring`, `bcmath`, `filter`, `xml`.
- `composer -V` -> `CommandNotFoundException` (Composer is not installed on system).
- `node -v` -> `v26.13.0`.
- `npm.cmd -v` -> `11.20.0`.
- `mysql --version` -> `C:\xampp\mysql\bin\mysql.exe Ver 15.1 Distrib 10.4.32-MariaDB`.

### 2. Technical Blockers
1. **PHP Version Incompatibility:**
   - Active CLI version is **PHP 8.2.12**.
   - Approved technology decision ([ADR-0001](../decisions/ADR-0001-stack.md)) mandates **PHP 8.3+** for **Laravel 13**.
   - Scaffolding or installing Laravel 13 on PHP 8.2 will fail dependency constraint checks.
2. **Missing Composer Package Manager:**
   - Composer is not installed on the system (neither `composer` nor `composer.phar` is available in PATH).
   - Composer 2.7+ is mandatory to resolve packages and scaffold the application.

### 3. Non-Destructive Scaffolding Plan
To scaffold Laravel 13 without destroying existing planning documentation in the active Git repository:
1. Scaffold framework files into an isolated temporary directory.
2. Remove any generated `.git` folder from the temp directory.
3. Merge application directories (`app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `tests/`, `artisan`, `composer.json`, `package.json`, `vite.config.js`) into the repository root.
4. Intelligently merge `.gitignore` to preserve existing rules while adding standard Laravel ignores.
5. Preserve existing `README.md`, `PROJECT_RULES.md`, `AGENTS.md`, `CONTRIBUTING.md`, `SITEPULSE_PHASE_TRACKER.md`, and `docs/`.
6. Run `composer install` and verify boot via `php artisan --version` once prerequisites are met.

### 4. Remaining Foundation Tasks
- [x] Upgrade/configure active PHP CLI to PHP 8.3.x or 8.4.x (PHP 8.4.28 NTS verified).
- [x] Install Composer 2.7+ (Composer 2.11.2 verified).
- [x] Execute non-destructive scaffolding.
- [x] Resolve Pest 4, PHPUnit 12, and Livewire 4 via Composer.
- [x] Execute `php artisan test` (expanded to 29 passing tests / 190 assertions).
- [x] Remediate Codex NO-GO and PASS-WITH-FINDINGS issues on branch `fix/phase-01-foundation-remediation`.
- [ ] Run remote GitHub Actions CI workflow (PHP 8.3/8.4 + SQLite + MySQL 8.0) and verify green check.
- [ ] Implement owner auth migration and `sitepulse:create-owner` command (blocked until remediation review).

---

## Task 02 Execution Log (2026-11-24)

### 1. Environment Verification
- Active PHP: `C:\php84\php.exe` -> `PHP 8.4.28 (cli) (built: Feb 10 2026 15:45:00) (NTS Visual C++ 2022 x64)`
- Active Composer: `C:\ProgramData\ComposerSetup\bin\composer.bat` -> `Composer version 2.11.2 2026-02-13 14:15:28`
- PHP Extensions: `bcmath`, `curl`, `dom`, `fileinfo`, `filter`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `xml`, `zip`.
- Packagist connectivity: OK.
- GitHub API connectivity: OK.

### 2. Scaffolding & Merging
- Non-destructive scaffold prepared and merged into repository root.
- Preserved existing documentation: `README.md`, `PROJECT_RULES.md`, `AGENTS.md`, `CONTRIBUTING.md`, `SITEPULSE_PHASE_TRACKER.md`, `docs/`, `.github/`.
- Updated `.gitignore` to preserve existing rules while adding standard Laravel ignore paths (`vendor/`, `.env`, `database/database.sqlite`, etc.).
- Created `.env.example` (`DB_CONNECTION=sqlite`) and local `.env`, while `config/database.php` supplies SQLite defaults (`DB_JOURNAL_MODE=wal`, `DB_BUSY_TIMEOUT=5000`, `DB_SYNCHRONOUS=normal`).

### 3. Composer Resolution & Package Lock
- Executed `composer install --no-interaction`.
- Resolved and locked dependencies:
  - `laravel/framework`: `v13.43.0`
  - `livewire/livewire`: `v4.4.9`
  - `pestphp/pest`: `v4.7.8`
  - `phpunit/phpunit`: `12.5.33`
  - `guzzlehttp/guzzle`: `8.6.0`
  - `nunomaduro/collision`: `v8.9.6`
- Autoloader and package discovery completed without error.

### 4. Database Setup & Migrations
- Created initial `database/database.sqlite`.
- Generated application key via `php artisan key:generate`.
- Executed initial framework migrations (`php artisan migrate --force`):
  - `0001_01_01_000000_create_users_table` (DONE)
  - `0001_01_01_000001_create_cache_table` (DONE)
  - `0001_01_01_000002_create_jobs_table` (DONE)
- SQLite Pragmas verified via PDO/Tinker:
  - `PRAGMA journal_mode`: `wal`
  - `PRAGMA busy_timeout`: `5000`

### 5. Application Boot & Test Suite Execution
- `php artisan --version` -> `Laravel Framework 13.43.0`
- `php artisan about` -> verified environment: `local`, drivers: `sqlite`, `database`, Livewire `v4.4.9`.
- `php artisan test` / `.\vendor\bin\pest` executed:
  - `Tests\Unit\ExampleTest` -> PASS (that true is true)
  - `Tests\Feature\ExampleTest` -> PASS (the application returns a successful response)
  - Result: 2 passed (2 assertions), 0 failures.

---

## Task 03 — Foundation Remediation Log (2026-11-24)

**Branch:** `fix/phase-01-foundation-remediation`  
**Trigger:** Codex assessment `FAIL / NO-GO` for owner authentication.

### 1. Summary of Codex Findings Addressed
1. Windows Application Control blocked `php_openssl.dll` and `php_fileinfo.dll` during Codex's test execution.
2. `composer.lock` resolved `symfony/* v8.1.x` (`php >= 8.4.1`), violating the approved PHP 8.3+ baseline.
3. `database/seeders/DatabaseSeeder.php` seeded a predictable `'Test User'` (`test@example.com`) using `UserFactory`'s hardcoded `'password'`.
4. `composer.json` setup scripts (`setup`, `post-create-project-cmd`) invoked `php artisan key:generate` unconditionally, risking overwriting an existing `APP_KEY` on setup retries.
5. `package-lock.json` was missing and `composer setup` used `npm install` rather than `npm ci`.
6. No GitHub Actions CI workflow existed to validate PHP 8.3/8.4, extensions, advisory audit, Pint, Pest, frontend build, SQLite WAL, and MySQL compatibility.
7. Documentation lacked explicit mapping of SQLite defaults vs overrides and mandatory production hardening values (`APP_DEBUG`, HTTPS, `SESSION_SECURE_COOKIE`, `MAIL_MAILER`).

### 2. Task 1 — PHP Runtime & Environment-Specific Blocker Diagnosis
- **Host Commands Executed:**
  - `where php` -> `C:\php84\php.exe`, `C:\xampp\php\php.exe`
  - `php -v` -> `PHP 8.4.28 (cli) (built: Nov 18 2026 00:57:45) (NTS Visual C++ 2022 x64)`
  - `php --ini` -> `C:\php84\php.ini`
  - `php -m` -> verified `bcmath`, `curl`, `dom`, `fileinfo`, `filter`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `xml`, `zip` loaded on unsandboxed host
  - `php artisan test` -> passes on unsandboxed host
- **Forensic Root Cause (Environment Restriction, NOT Application Bug):**
  - Registry `HKLM:\SYSTEM\CurrentControlSet\Control\CI\Policy` has `VerifiedAndReputablePolicyState = 1` (**Windows 11 Smart App Control** enforced under policy `{0283ac0f-fff1-49ae-ada1-8a933130cad6}` / `VerifiedAndReputableDesktop`).
  - `C:\php84\php.exe`, `C:\php84\ext\php_openssl.dll`, and `C:\php84\ext\php_fileinfo.dll` (official `windows.php.net` ZIP distribution) are **unsigned** (`Authenticode Status = NotSigned`) and carry `ZoneId=3` (`ReferrerUrl=C:\Users\DELL\Downloads\php-8.4.28-nts-Win32-vs17-x64.zip`).
  - `Microsoft-Windows-CodeIntegrity/Operational` Event IDs `3077`, `3033`, and `3118` at `2026-11-24 22:48:27` (`21:48:27Z`) confirm Windows Smart App Control denied `php_openssl.dll` and `php_fileinfo.dll` with status `0xc0e90002` (`Requested Signing Level: 2`, `Validated Signing Level: 1`, `DefenderCloudCallRequested=true`, `DefenderMadeCloudCall=false`, `EADefenderTrustCached=false`).
  - **Security Compliance:** Windows Smart App Control protections were **not** disabled or weakened.
  - **Recommended Trusted Runtimes:** WSL2 Ubuntu (`ubuntu` + signed distro/Ondrej PHP packages; WSL not currently installed on this workstation), Linux Docker containers (`php:8.3-cli` / `php:8.4-cli`; Docker not currently installed on this workstation), enterprise/code-signed Windows PHP runtimes if running natively under Smart App Control, and GitHub Actions `ubuntu-latest` CI.

### 3. Task 2 — Actual Minimum PHP Compatibility (`PHP 8.3+` Retained)
- **Root Cause:** `laravel/framework` `^13.0` supports `symfony/*: ^7.4.0 || ^8.0.0`, `livewire/livewire` `^4.2` supports `^6.0|^7.0|^8.0`, and `pestphp/pest` `^4.0` supports `symfony/process: ^7.4.13|^8.1.0`. However, `symfony/*` `8.0.0+` raised its own minimum to `php >= 8.4` (`8.1.x` requires `>= 8.4.1`). Initial resolution on PHP 8.4 selected Symfony `8.1.x`, locking out PHP 8.3.
- **Supported Resolution Applied:** Added explicit `"conflict"` rules in `composer.json` for `symfony/* >= 8.0` and re-locked `composer.lock` to Laravel 13's supported **Symfony 7.4 LTS** branch (`v7.4.17`–`v7.4.23`, `php >= 8.2`), keeping:
  - `php`: `^8.3` (ADR-0001 preserved without raising PHP requirement)
  - `laravel/framework`: `v13.44.1` (`^13.0`)
  - `livewire/livewire`: `v4.4.10` (`^4.2`)
  - `pestphp/pest`: `v4.7.8` (`^4.0`)
  - `phpunit/phpunit`: `12.5.33` (`^12.5`)
  - `laravel/pint`: `v1.32.3` (`^1.27`)
- Verified programmatically across all packages in `composer.lock`: **0 packages exclude PHP 8.3**.
- Verified `composer validate --strict` and `composer audit` (0 security vulnerability advisories).

### 4. Tasks 3 & 4 — Safe Seeding & Idempotent Setup
- Cleared default user creation in `database/seeders/DatabaseSeeder.php`; `php artisan db:seed --force` now inserts 0 users.
- Replaced hardcoded `'password'` in `database/factories/UserFactory.php` with `Hash::make(Str::random(32))` while preserving factory test ergonomics and explicit password overrides.
- Created `app/Support/EnvironmentSetup.php` (`EnvironmentSetup::prepare()`) and updated `composer.json` `setup` and `post-create-project-cmd` scripts so:
  - `.env` is copied from `.env.example` only if missing.
  - Existing valid `APP_KEY` values are preserved and verified via SHA-256 key comparison against Laravel's loaded `encrypter->getKey()`; when missing or empty, `EnvironmentSetup::prepare()` verifies no conflicting `.env.<APP_ENV>` or active configuration cache (`bootstrap/cache/config.php`, process `APP_CONFIG_CACHE`, `.env` `APP_CONFIG_CACHE`, or active variant `APP_CONFIG_CACHE`) overrides `.env`, obtains a key candidate via `artisan key:generate --show --no-ansi`, stages `.env.tmp.<24-hex>` (`0600` on Unix; Windows relies on NTFS ACLs), verifies candidate encryption bound to the staged file before publishing `.env`, and verifies any post-publication rollback (preserving `.env.recovery.<24-hex>` if restoration is denied). Note setup fails closed rather than clearing arbitrary cached configuration automatically.
  - `database/database.sqlite` is created only when `DB_CONNECTION=sqlite` (excluding `:memory:` and MySQL).

### 5. Task 5 — Frontend Lockfile & Supported Node Versions
- Generated and tracked `package-lock.json` (88 npm packages, 0 audit vulnerabilities).
- Updated `composer.json` `setup` script to prefer `npm ci`.
- Verified `npm ci` and `npm run build` (`vite v7.3.8`, `61 modules transformed`, assets written to `public/build/`).
- **Supported Frontend Toolchain:** Vite `7.3.8` and `laravel-vite-plugin v2.1.0` require **Node.js `^20.19.0 || >=22.12.0`**. Verified locally on Node `v26.13.0` and npm `11.20.0`; CI targets Node `22` LTS.

### 6. SQLite Defaults vs Overrides & Production Security Requirements
- **SQLite Defaults vs Environment Overrides:**

| Layer | `DB_CONNECTION` | `DB_DATABASE` | `DB_JOURNAL_MODE` | `DB_BUSY_TIMEOUT` | `DB_SYNCHRONOUS` |
|---|---|---|---|---|---|
| `config/database.php` | `sqlite` (default) | `database/database.sqlite` | `wal` (default) | `5000` (default) | `normal` (default) |
| `.env.example` | `sqlite` | commented (inherits `config/database.php`) | not set (inherits `wal`) | not set (inherits `5000`) | not set (inherits `normal`) |
| `phpunit.xml` | `sqlite` | `:memory:` (override) | N/A in-memory | N/A in-memory | N/A in-memory |

- **Production Security Requirements (Local `.env.example` vs Mandatory Production Values):**

| Variable | Local `.env.example` | Mandatory Production Setting | Risk if Misconfigured |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Unsafe environment behaviors / lax guards |
| `APP_DEBUG` | `true` | `false` | Leaks stack traces, env values, internals |
| `APP_URL` | `http://localhost` | `https://your-domain.example` | Cleartext transport & broken TLS URLs |
| `SESSION_SECURE_COOKIE` | `false` | `true` | Session cookie exposed over unencrypted HTTP |
| `MAIL_MAILER` | `log` | `smtp` or transactional API driver | Alert emails silently logged, never delivered |
| `MAIL_TIMEOUT` | `5` (`config/mail.php` default `5`) | `<= 5` seconds (per ADR-0004) | Slow SMTP socket stalls cron/check loop |

### 7. Outstanding Phase 01 Acceptance Criteria
- [x] Remote GitHub Actions execution of `.github/workflows/ci.yml` verifying PHP `8.3`, PHP `8.4`, SQLite WAL, and MySQL `8.0` migrations on Linux runners (verified on PR #1 merge `d3d7ffc`, PR #2 `46051330107`/`46051323625`, and `main` `776333f` run `46053500009`).
- [x] Codex independent re-review & merge of PR #1 (`d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` merged after Symfony 7.4 LTS patch `c615775`).
- [x] Independent security review and merge of the Task 10 owner-authentication PR (`https://github.com/ayjed/SitePulse/pull/2`, merged at `776333f2320ca52c7d2f7255305fc100f818a9b3`).
- [x] Maintainer `@ayjed` explicit review and approval of Phase 01 final acceptance evidence (`APPROVED` on `2026-11-27` following PR #3 merge at `12dbd17a949cd0764ab64cae335fcb9511f70ed1`).

---

## Task 04 — Targeted Remediation Corrections Log (2026-11-24)

Triggered by Codex `PASS WITH FINDINGS` audit on `adda0bb`:

1. **`APP_KEY` Parsing, Generation & Verification (`app/Support/EnvironmentSetup.php`):**
   - Replaced custom regex parsing with `Dotenv\Dotenv::parse()`.
   - Normalized malformed empty placeholders (`APP_KEY=""`, `APP_KEY=''`, `export APP_KEY=`, `APP_KEY =`, commented/missing line) to `APP_KEY=` before running `artisan key:generate --force`.
   - Added `Encrypter`-based key validation and fresh-subprocess `verifyApplicationEncryption()` initializing Laravel's real `encrypter` service and verifying an encrypt/decrypt round-trip without logging keys.
   - Invalid existing non-empty keys or malformed `.env` syntax fail with nonzero exit codes and leave `.env` untouched.
   - Replaced stubbed tests with genuine disposable Laravel workspace integration tests calling real `artisan key:generate` and `EncryptionServiceProvider`.
2. **Shell-Free Windows Subprocess Handling (`app/Support/EnvironmentSetup.php`):**
   - Replaced shell-escaped `proc_open()` strings with argument arrays (`[PHP_BINARY, $artisanPath, ...]`), preventing `cmd.exe` `!` and space parsing failures. Tested across paths containing spaces, `!`, and ordinary Windows directory names.
3. **SQLite Creation Failure Propagation (`app/Support/EnvironmentSetup.php`):**
   - Checked `mkdir()` and `touch()` results plus non-directory/directory/writability collisions, throwing `RuntimeException` and returning nonzero exit codes from `EnvironmentSetup::prepare()`.
4. **SQLite Documentation & Mail Timeout Alignment:**
   - Removed inaccurate statements claiming `.env.example` contains `DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, and `DB_SYNCHRONOUS` (defaults live in `config/database.php`).
   - Wired `config/mail.php` SMTP `timeout` to `max(1, (int) (env('MAIL_TIMEOUT') ?: 5))` and documented `MAIL_TIMEOUT=5` alongside setup instructions in `README.md` and `docs/HOSTING_REQUIREMENTS.md`.
5. **Composer `config.platform.php` vs `"conflict"` Evaluation:**
   - Empirically tested `config.platform.php: "8.3.0"` on PHP `8.2.12`: `composer install --dry-run` succeeded (`RC=0`), bypassing the `php: ^8.3` platform gate. With `"conflict"` on `symfony/* >= 8.0`, PHP `8.2.12` failed cleanly (`RC=2`) while PHP `8.3+` locks cleanly to Symfony 7.4 LTS (`0` excluded packages). Retained `"conflict"` declarations and kept PHP `8.3` / `8.4` in CI.
6. **CI Hardening (`.github/workflows/ci.yml`):**
   - Pinned all actions (`actions/checkout`, `shivammathur/setup-php`, `actions/cache`, `actions/setup-node`) to immutable 40-character commit SHAs.
   - Disabled git token persistence (`persist-credentials: false`), avoided persisting DB passwords in `.env`, and verified idempotent `APP_KEY` preservation via SHA-256 hash comparison inside PHP.

---

## Task 05 — Final Security Corrections & Remote CI Preparation Log (2026-11-24)

Triggered by Codex `PASS WITH FINDINGS` audit on `e0aa9f1`:

1. **Dotenv-Aware Top-Level Editing & Atomic `.env` Writes (`app/Support/EnvironmentSetup.php`):**
   - Replaced flat regex line matching with Dotenv-aware chunk grouping mirroring `Dotenv\Parser\Lines` + `Dotenv\Parser\EntryParser`. Lines such as `APP_KEY=part-of-note` inside double-quoted multiline variables are never matched or altered; only top-level `APP_KEY` assignments (or appended EOF entries) are updated.
   - Added `verifyProposedEnvPreservesUnrelatedVariables()` pre-write invariant checking `Dotenv::parse($proposed)` preserves every unrelated variable identically.
   - Replaced in-place writes with `writeFileAtomically()` (same-directory `tempnam` + `fflush`/`fclose` + permissions preservation + `rename()`, cleaning temp artifacts on failure so failed runs leave `.env` byte-for-byte unchanged).
2. **Error Secret-Leak Prevention (`app/Support/EnvironmentSetup.php`):**
   - Removed raw Dotenv exception message forwarding (`$e->getMessage()`) and suppressed raw subprocess stderr/stdout leakage during `EnvironmentSetup::prepare()`, emitting safe static diagnostics instead.
   - Added synthetic secret-canary tests verifying zero leakage across `stdout`, `stderr`, and `storage/logs/*.log` for malformed `MAIL_PASSWORD`, unterminated multiline values, invalid `APP_KEY`, missing `artisan`, and read-only `.env`.
3. **Hardened MySQL GitHub Actions CI (`.github/workflows/ci.yml`):**
   - Historical PR #1 runs (`pull_request` run `45505787002`, `push` run `45505776723` on commit `c67086960bf0ee28efb8b5508b093bb7d75db9f8`) ran `29 passed (190 assertions)` across PHP `8.3` / `8.4` and `MySQL 8.0`, but Codex identified container credential exposure in `services.mysql` initialization and `MYSQL_RANDOM_ROOT_PASSWORD` cleanup logs.

### Task 06 — PR #1 Mandatory Security Corrections (2026-11-25)

Following Codex's `NO-GO FOR MERGE` review on PR #1 (`https://github.com/ayjed/SitePulse/pull/1`, commit `c67086960bf0ee28efb8b5508b093bb7d75db9f8`), applied targeted fixes without altering Phase 01 scope:

1. **Failure-preserving staged `.env` verification (`app/Support/EnvironmentSetup.php`):**
   - Replaced pre-verification mutation with `artisan key:generate --show --no-ansi` candidate generation, exclusive `.env.tmp.<24-hex>` staging, `verifyStagedApplicationEncryption()` against an isolated `APP_CONFIG_CACHE` probe path, explicit pre-publish check for `bootstrap/cache/config.php` (`APP_CONFIG_CACHE`), and atomic `rename()` with rollback restoration if live `verifyApplicationEncryption()` fails.
   - Failed setups when `bootstrap/cache/config.php` is cached leave both `.env` and `bootstrap/cache/config.php` byte-for-byte unchanged and clean up staged temporary files.

2. **Git-ignored exclusive temporary `.env` creation on Windows & Unix (`app/Support/EnvironmentSetup.php`):**
   - Replaced `tempnam()` (which on Windows truncates prefixes to `.enXXXX.tmp` outside `.gitignore`'s `.env.*` rule) with `createExclusiveTempEnvFile()`, opening `.env.tmp.<24-hex>` exclusively via `fopen(..., 'xb')` (`0600` on Unix-like systems; Windows relies on parent NTFS ACLs).
   - Cleaned up deterministically on failure in `finally`; any file abandoned by an abrupt interruption matches `.gitignore`'s `.env.*` rule. Verified with `git check-ignore -v` and `git add -n .` dry-run tests.

3. **Zero-leakage MySQL 8.0 CI workflow (`.github/workflows/ci.yml`):**
   - Removed `services.mysql` and `CI_MYSQL_PASSWORD`; generated disposable `openssl rand -hex 24` root and `sitepulse_ci` passwords inside the `mysql-compatibility` job, registered `::add-mask::` before creating a `0600` `--env-file` (removed immediately and on `trap EXIT`), bound `mysql:8.0` to `127.0.0.1:33306:3306`, omitted `docker logs` dumps on cleanup (`docker rm -f`), and retained `permissions: contents: read` plus 40-char action SHA pins.
   - Commit `2e44c397c55064d7c7195fd40073a1ff83ef1407` passed `pull_request` run `45510794534` and `push` run `45510786337` cleanly for MySQL logging, before Codex identified the two remaining `APP_KEY` correctness defects addressed in Task 07.

### Task 07 — Final `APP_KEY` Correctness & Rollback Remediation (2026-11-25)

Following Codex's `NO-GO` review on commit `2e44c397c55064d7c7195fd40073a1ff83ef1407`, simplified the `EnvironmentSetup` lifecycle and eliminated both remaining correctness defects:

1. **Truthful verified rollback & protected recovery copy (`app/Support/EnvironmentSetup.php`):**
   - Eliminated the redundant post-publication encryption verification subprocess on normal setup runs (full bound Laravel verification executes pre-publication on staged `.env.tmp.<24-hex>`).
   - Updated post-publication rollback (`rollbackPublishedTarget()`) to explicitly verify disk bytes match original `.env` (or verified removal if newly seeded from `.env.example`) before reporting restoration; when rollback is denied or fails, setup returns nonzero, reports the remaining modified state accurately without leaking secrets, and saves an exclusive `.env.recovery.<24-hex>` copy (covered by `.gitignore`'s `.env.*` rule, falling back to system temp if the directory is unwritable).

2. **Exact target `.env` binding & SHA-256 effective-key verification (`app/Support/EnvironmentSetup.php`):**
   - Bound staged verification explicitly via `$app->loadEnvironmentFrom(...)` with inherited `APP_KEY`, `APP_PREVIOUS_KEYS`, `APP_CIPHER`, `APP_ENV`, and `APP_CONFIG_CACHE` neutralized, passing only SHA-256 key digests over STDIN to prove `$app->environmentFile()`, `config('app.cipher')`, `config('app.key')`, and `encrypter->getKey()` match the candidate key without printing secrets.
   - Refused setup before modifying `.env` when `APP_ENV` resolves an existing environment variant (`.env.testing`, `.env.local`, etc.) or `bootstrap/cache/config.php` overrides `.env`, and verified existing `.env` keys match Laravel's effective loaded `encrypter->getKey()` under active variant/cached-config rules.

3. **Filesystem safety & cross-platform permission accuracy:**
   - Rejected symlinked destinations (`is_link()`), non-regular destination/source files (`lstat` mode `0100000`), and hard-linked destinations (`nlink > 1`) before reads, staging, publication, or rollback.
   - Documented supported filesystem assumptions accurately: POSIX `0600` (or masked `0666`) mode bits apply on Unix-like systems, whereas Windows `chmod()` only toggles the DOS read-only bit and directory NTFS ACLs govern access; atomic same-directory rename is not guaranteed across unsupported filesystems or conflicting file locks.
   - Expanded regression coverage to `38 passed (317 assertions)` locally and `38 passed (320 assertions)` across GitHub Actions runs `45514246143` (`pull_request`) and `45514235856` (`push`) on commit `1feac7eefba4eec82e6cc821f4e95d5c271f7d0a`.

### Task 08 — Custom Configuration Cache Verification Remediation (2026-11-25)

Following Codex's `FAIL` audit on commit `1feac7eefba4eec82e6cc821f4e95d5c271f7d0a` (`pull_request` run `45514246143` / `push` run `45514235856` passed `38 passed (320 assertions)`, while Codex reproduced `.env`-declared `APP_CONFIG_CACHE=bootstrap/cache/audit-custom.php` bypassing `bootstrap/cache/config.php` detection):

1. **Root cause:**
   - In Laravel 13, `LoadEnvironmentVariables` initially checks `APP_CONFIG_CACHE` before `.env` loads, then loads `.env`, and `LoadConfiguration` subsequently calls `$app->getCachedConfigPath()` (`normalizeCachePath('APP_CONFIG_CACHE', 'cache/config.php')`), loading a custom cache path declared inside `.env`. Previously `resolveCachedConfigPath()` only inspected process `APP_CONFIG_CACHE` and `bootstrap/cache/config.php`, missing `.env`-declared custom cache files.
2. **Fail-closed cache detection & path validation (`app/Support/EnvironmentSetup.php`):**
   - Added `inspectConfigurationCaches()`, `resolveEnvFileCachePath()`, `validateAndResolveCachePath()`, and `assertSafeCacheFileCandidate()` checking process `APP_CONFIG_CACHE` (including explicit empty values), `.env` `APP_CONFIG_CACHE`, active `.env.<APP_ENV>` `APP_CONFIG_CACHE`, and default `bootstrap/cache/config.php` against Laravel's path resolution rules.
   - Fails closed before touching `.env` or any cache when any path is empty/whitespace/non-string, uses stream wrappers (`phar://`, etc.) or UNC/drive prefixes, traverses outside `$basePath` via `..` or symlinks, is a non-regular file/symlink, or conflicts across cache targets.
   - Refuses setup cleanly without modifying `.env` or deleting/overwriting existing caches when generating a new `APP_KEY` while an active cache exists, or when an existing valid `.env` key differs from Laravel's effective loaded `encrypter->getKey()`.
   - Setup does not attempt to operate safely by mutating keys under un-cleared arbitrary cached configuration; operators must run `php artisan config:clear` before rotating or initializing an empty `APP_KEY`.
3. **Regression coverage:**
   - Verified locally (`41 passed (380 assertions)`, Pint `29 files`) including custom `.env` cache paths, process env cache paths, uncached generation with `APP_CONFIG_CACHE` set, matching/mismatched cache keys, unsafe/traversal/symlinked cache paths, and paths containing spaces and `!`.

---

## Task 09 — PR #1 Advisory Remediation & Merge (2026-11-27)

- Following clean independent review on `1603b15abb028a0092385b33efb070adb8932da1`, CI on doc-sync commit `d4aaf37c20561e93364c52673af3973c4730e0c2` failed `composer audit --no-interaction` on 5 newly reported Symfony advisories (`CVE-2026-133743`, `CVE-2026-133751`, `CVE-2026-133755`, `CVE-2026-133746`, `CVE-2026-133754`) and stopped before merging.
- PR #1 patched `symfony/http-kernel` (`v7.4.25`), `symfony/mailer` (`v7.4.24`), and `symfony/routing` (`v7.4.24`) in commit `c6157758d494bfd23f0129fde26e4a3693c982bf` (GitHub Actions runs `46041927707` and `46041923653` passed all jobs) and merged into `main` at `d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b` (`46042368731` passed).

---

## Task 10 — Single-Owner Authentication Recovery & Retrospective Security Review (2026-11-27)

1. **History & Non-Destructive Recovery:**
   - Owner-authentication commit `08b0212637a291adebffa00b46b1d441109950d9` (`20` files) was inadvertently pushed directly to `origin/main` because branch tracking on `feat/phase-01-owner-authentication` pointed to `refs/heads/main`.
   - `08b0212` passed its GitHub Actions matrix (`46044416132`: `PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, and `PHP 8.3 / MySQL 8.0 Compatibility`, `63 passed (555 assertions)`), and was subsequently superseded on `main` by `5b1162b8c6465607b1495df3a82e794f0804c035`.
   - Without force-pushing, resetting, or rewriting `main`, `08b0212` was preserved under tag `backup/task10-08b0212` and re-applied cleanly onto `feat/phase-01-owner-authentication-reviewed` (created `--no-track` from `origin/main` at `5b1162b`) for retrospective draft-PR review. Phase 01 remains `IN PROGRESS` and independent security review remains pending until maintainer review completes.
2. **Owner Provisioning & Authentication Architecture:**
   - Added `is_owner` (boolean default `false`) and nullable unique `owner_slot` (`unsignedTinyInteger`) to `users` (`2026_11_27_000001_add_owner_columns_to_users_table.php`), enforcing single-owner creation at the database constraint level alongside transactional `lockForUpdate()` in `App\Support\OwnerProvisioner`. `User::isOwner()` + mass-assignment exclusions prevent privilege injection.
   - `php artisan sitepulse:create-owner` (`CreateOwnerCommand`) runs in console only, uses non-echoed masked prompts (`$this->secret(..., false)`) or `--password-stdin` (no `--password` CLI flag), requires >= 12 characters with upper/lower/digit/symbol complexity (<= 72 bytes for `bcrypt` at `BCRYPT_ROUNDS=12`, NUL bytes rejected), and suppresses raw exception/credential details in output and logs.
   - `AuthenticatedSessionController` provides `GET/POST /login` (`guest` middleware) and `POST /logout` (`auth` middleware), using `Auth::attemptWhen(..., fn (User $user) => $user->isOwner(), false)`, session ID regeneration on login, session invalidation + CSRF token rotation on logout, generic error responses, `RateLimiter` throttling (5 attempts/min/IP), and `ApplySecurityHeaders` (`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, CSP, and `Cache-Control: no-store`). No public registration routes exist, and `GET /dashboard` enforces `auth` + `can:access-dashboard`.
3. **Retrospective Independent Security Review Findings & Fixes:**
   - **Rate-limit bypass & `email[]` array 500 error (`AuthenticatedSessionController`, `login.blade.php`):** Previously `$request->validate()` ran before `RateLimiter::hit()`, allowing malformed/empty/oversized POST payloads to bypass the 5/min throttle, and flashing non-string `email[]` arrays into `_old_input` caused `e(old('email'))` to throw `TypeError` (HTTP 500) on `GET /login`. Fixed via manual `Validator::make()`, uniform `RateLimiter::hit()` + generic `422`/redirect responses for all failed/malformed inputs, string normalization for `_old_input['email']`, and type-safe `is_string()` fallback in `login.blade.php`.
   - **Empty `SESSION_SECURE_COOKIE=` silent fallback (`config/session.php`):** Previously `env('SESSION_SECURE_COOKIE') ?? (...)` treated empty string `''` from `.env` as falsy instead of falling back to `APP_ENV === 'production'` or `https://` `APP_URL`. Replaced with explicit boolean/string parsing and `filter_var` fallback verified under `config:cache`.
   - **CLI interactive-echo error handling, bounded STDIN & NUL-byte password guard (`CreateOwnerCommand`, `OwnerProvisioner`):** Wrapped interactive prompts in exception-safe handling, bounded `--password-stdin` reads (`8192`-byte limit without silent truncation), and rejected `\0` bytes in `OwnerProvisioner::passwordRules()`.
   - **Public `/login` CLI command disclosure (`login.blade.php`):** Removed the internal `php artisan sitepulse:create-owner` command hint from the unauthenticated `/login` footer.

---

## Task 11 — Phase 01 Final Acceptance Audit (2026-11-27)

1. **Merged Pull Requests & Main Branch Protection:**
   - **PR #1 (`https://github.com/ayjed/SitePulse/pull/1`):** Merged `fix/phase-01-foundation-remediation` (`c6157758d494bfd23f0129fde26e4a3693c982bf`) into `main` at **`d3d7ffcbe8f7e5b1f0a172c5c35357d51770842b`** (`2026-11-27T17:30:24Z`).
   - **PR #2 (`https://github.com/ayjed/SitePulse/pull/2`):** Merged `feat/phase-01-owner-authentication-reviewed` (`f1e48864d74ebeefea8640f61c03e897c50a6ea2`) into `main` at **`776333f2320ca52c7d2f7255305fc100f818a9b3`** (`2026-11-27T18:59:56Z`).
   - **PR #2 CI Evidence:** Runs `46051330107` (`pull_request`) and `46051323625` (`push`) passed all **6/6 jobs** (`PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, `PHP 8.3 / MySQL 8.0 Compatibility`), reporting `65 passed (592 assertions)` per job. Post-merge `main` push CI run `46053500009` on `776333f2320ca52c7d2f7255305fc100f818a9b3` also passed **3/3 jobs** (`65 passed (592 assertions)` per job).
   - **Branch Protection Status (`main`):** Active via GitHub Repository Ruleset `#28256290` (`Protect SitePulse Main`, `branches/main protected: true`), blocking deletion and non-fast-forward force-pushes, requiring Pull Requests, and enforcing required status checks (`PHP 8.3 / SQLite`, `PHP 8.4 / SQLite`, `PHP 8.3 / MySQL 8.0 Compatibility`). Classic branch protection is unset (returns `404` because Repository Rulesets are used); note ruleset parameters currently set `required_approving_review_count: 0` and `strict_required_status_checks_policy: false`.
2. **Independent Code & Environment Verification (`audit/phase-01-final-acceptance` at `776333f`):**
   - `composer validate --strict --no-interaction`: PASS
   - `composer audit --locked --no-interaction`: PASS (`0` security advisories)
   - `composer check-platform-reqs --lock`: PASS (`PHP 8.4.28`, `0` packages in `composer.lock` exclude PHP `8.3`)
   - `php vendor/bin/pint --test`: PASS (`38 files`)
   - `php artisan test`: PASS (`65 passed (586 assertions)` locally on Windows PHP `8.4.28`; `65 passed (592 assertions)` on Linux CI including POSIX permission/symlink checks)
   - `npm ci --no-fund && npm run build`: PASS (`88` packages installed, `0` npm vulnerabilities; `vite v7.3.8`, `61 modules transformed`)
   - Live SQLite check: `driver=sqlite, journal=wal, busy_timeout=5000, synchronous=1, users=0`
   - Tracked-file credential scan (`git ls-files`): `0` forbidden `.env`/`.sqlite`/key files and `0` leaked credential matches; `.env` and `database/database.sqlite` ignored and unmodified.
3. **Remaining Limitations & Status:**
   - Phase 01 was **APPROVED** by maintainer (`@ayjed`) on `2026-11-27` after PR #3 merged at `12dbd17a949cd0764ab64cae335fcb9511f70ed1`; Phase 02 is authorized and **IN PROGRESS**.
   - Windows 11 Smart App Control hosts may still intermittently block unsigned `windows.php.net` extension DLLs (`php_openssl.dll` / `php_fileinfo.dll`) in restricted/offline sandboxes; WSL2, Linux containers, or GitHub Actions `ubuntu-latest` remain the recommended fallback runtimes.
   - Local workstation does not run Docker or native MySQL 8.0 (`XAMPP` runs MariaDB 10.4), so MySQL 8.0 verification relies on GitHub Actions `ubuntu-latest` CI (`PHP 8.3 / MySQL 8.0 Compatibility`).
   - Root `/` continues to render `welcome` in Phase 01; monitor management and the monitoring dashboard remain deferred to Phases 02 and 03.
