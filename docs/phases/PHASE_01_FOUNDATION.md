# Phase 01 — Foundation

**Status:** IN PROGRESS  
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
- [x] Set up automated formatting checks (`pint`) and GitHub Actions CI workflow (`.github/workflows/ci.yml`; remote run pending push).
- [ ] Build initial owner authentication and CLI provisioner (`sitepulse:create-owner`, deferred until Codex remediation review).
- [x] Document installation and startup commands (`README.md`, `docs/HOSTING_REQUIREMENTS.md`).

## Exit criteria
- [x] Clean install works with documented tool versions locally (PHP 8.4.28 host / WSL or Linux CI needed on Windows Smart App Control sandboxes).
- [ ] CI passes on a fresh checkout (`.github/workflows/ci.yml` authored; awaiting GitHub Actions execution after branch push).
- [x] No credentials or predictable seeded login accounts included in repository.
- [ ] Maintainer approves evidence and review.

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
  - Existing non-empty `APP_KEY` values are detected and preserved across setup retries (`artisan key:generate --force --ansi` runs only when missing/empty).
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
- [ ] Remote GitHub Actions execution of `.github/workflows/ci.yml` verifying PHP `8.3`, PHP `8.4`, SQLite WAL, and MySQL `8.0` migrations on Linux runners.
- [ ] Codex independent re-review of remediation branch `fix/phase-01-foundation-remediation`.
- [ ] Owner authentication migration and `sitepulse:create-owner` CLI command (held until remediation review).
- [ ] Maintainer `@ayjed` explicit review and approval of Phase 01.

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
   - Replaced passwordless `root` with `MYSQL_RANDOM_ROOT_PASSWORD: 'yes'` and a dedicated disposable non-root account (`MYSQL_USER: sitepulse_ci`, scoped to `sitepulse_test`), masked `CI_MYSQL_PASSWORD` via `::add-mask::`, used container env references inside `--health-cmd`, and configured loopback-only port binding (`127.0.0.1:33306:3306`).
