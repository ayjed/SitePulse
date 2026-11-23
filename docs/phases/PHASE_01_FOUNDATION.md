# Phase 01 — Foundation

**Status:** IN PROGRESS (Blocked on Environment Prerequisites)  
**Gate:** Clean install, reproducible setup, CI and test suite running.

## Scope
- [x] Verify Git repository remote, branch tracking, and history integrity.
- [x] Audit development environment (PHP, Composer, Node, DB engines).
- [x] Design non-destructive scaffolding and merge procedure.
- [ ] Install/configure required environment prerequisites (PHP 8.3+, Composer 2.7+).
- [ ] Scaffold approved Laravel 13 project.
- [ ] Establish local env and `.env.example` (no secrets).
- [ ] Resolve dependencies with Composer (Pest 4, PHPUnit 12, Livewire 4).
- [ ] Build database migrations and initial owner authentication (`sitepulse:create-owner`).
- [ ] Set up automated tests, code formatting, and CI.
- [ ] Document installation and startup commands.

## Exit criteria
- [ ] Clean install works with documented tool versions.
- [ ] CI passes on a fresh checkout.
- [ ] No credentials included in repository.
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
- [ ] Upgrade/configure active PHP CLI to PHP 8.3.x or 8.4.x.
- [ ] Install Composer 2.7+.
- [ ] Execute non-destructive scaffolding.
- [ ] Resolve Pest 4, PHPUnit 12, and Livewire 4 via Composer dry-run.
- [ ] Execute `php artisan test`.
- [ ] Implement owner auth migration and `sitepulse:create-owner` command.
- [ ] Configure GitHub Actions CI workflow.
