# SitePulse

> A lightweight, self-hosted website uptime and response-time monitoring application built on Laravel 13.

**Status:** Phase 03 — functional acceptance documentation in review. Full WCAG 2.1 AA verification remains an open public-release gate. See the [phase tracker](SITEPULSE_PHASE_TRACKER.md).

## Intended outcomes
- Monitor website availability and HTTP response time with strict SSRF defenses.
- Record uptime and downtime history (30-day default raw retention, permanent incident logs).
- Provide a usable, accessible status dashboard (Blade + Livewire 4 + Tailwind CSS).
- Check TLS certificate expiry and alert by email on state transitions.
- Offer a documented, tested installation path for compatible PHP hosting with cron support.

## Approved technology stack
- **PHP:** PHP 8.3+ (8.3 and 8.4 target runtimes; lockfile stays on Symfony 7.4 LTS for PHP 8.3+ compatibility)
- **Framework:** Laravel 13 (`^13.0`)
- **UI:** Server-rendered Blade + Tailwind CSS v4 (`@tailwindcss/vite`) + Livewire 4 (`^4.2`)
- **Database:** SQLite default (WAL mode and 5000 ms busy timeout configured in `config/database.php`); MySQL 8.0+ / MariaDB 10.5+ compatibility target
- **Testing & Linting:** Pest 4 (`^4.0`) with PHPUnit 12 (`^12.5`) and Laravel Pint
- **Frontend Build Toolchain:** Vite 7 (`^7.0.7`, requires Node.js `^20.19.0 || >=22.12.0` and npm)

## Local development setup

### Prerequisites
- PHP 8.3+ with required extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite`, `session`, `tokenizer`, `xml`, `zip`
- Composer 2.7+
- Node.js `^20.19.0 || >=22.12.0` and npm

> **Windows note:** Official `windows.php.net` PHP ZIP builds are unsigned. On Windows 11 systems enforcing Smart App Control (`VerifiedAndReputableDesktop`), loading unsigned extension DLLs (such as `php_openssl.dll` and `php_fileinfo.dll`) may be blocked in restricted or offline sandboxes. Do not disable Windows security protections; use WSL2 Ubuntu, a Linux Docker container, or a trusted code-signed runtime.

### Repeatable installation & verification commands
```bash
git clone https://github.com/ayjed/SitePulse.git
cd SitePulse
composer run setup
```

`composer run setup` runs:
1. `composer install --no-interaction`
2. Idempotent environment initialization via `App\Support\EnvironmentSetup::prepare()` (targets regular non-symlink `.env` files with link count `<= 1`; inspects default `bootstrap/cache/config.php`, process `APP_CONFIG_CACHE`, `.env` `APP_CONFIG_CACHE`, and active `.env.<APP_ENV>` caches fail-closed, rejecting unsafe/traversal/symlinked/ambiguous cache paths and refusing mutation when an existing cache or active `.env.<APP_ENV>` conflicts — arbitrary un-cleared cached configuration is rejected rather than automatically deleted; obtains a candidate `APP_KEY` via `artisan key:generate --show --no-ansi` when missing or empty; stages `.env.tmp.<24-hex>` covered by `.gitignore`'s `.env.*` rule; neutralizes inherited `APP_KEY`/`APP_PREVIOUS_KEYS`/`APP_CIPHER` overrides during bound Laravel verification and proves with SHA-256 key comparison that `encrypter` uses the exact intended key; verifies any post-publication rollback bytes match on disk and preserves a `.env.recovery.<24-hex>` copy on denied rollback, rather than claiming unconditional atomic rollback across unsupported filesystems; applies `0600` mode bits on Unix-like systems while Windows relies on directory NTFS ACLs; and creates `database/database.sqlite` when `DB_CONNECTION=sqlite`)
3. `php artisan migrate --force`
4. `npm ci`
5. `npm run build`

Start the local web server and run verification checks:
```bash
php artisan serve
php artisan test
php vendor/bin/pint --test
composer audit
```

### Provisioning the single site owner
Database seeding (`php artisan db:seed`) intentionally creates **zero** default or predictable user accounts. Provision the initial administrator strictly from the CLI:
```bash
php artisan sitepulse:create-owner
```
Or supply the password via standard input without passing credentials in CLI arguments or process lists:
```bash
php artisan sitepulse:create-owner --name="Site Owner" --email="owner@example.com" --password-stdin
```

Authentication details:
- Public self-registration is disabled (`GET /login` and `POST /login` authenticate the provisioned owner; `POST /logout` terminates the session; `GET /dashboard` serves the authenticated Phase 01 placeholder).
- Passwords require at least 12 characters with mixed case, numbers, and symbols (<= 72 bytes when using `bcrypt`, `BCRYPT_ROUNDS=12`).
- `POST /login` enforces CSRF protection and a 5-attempt-per-minute IP rate limit (including malformed payloads), regenerates the session ID upon sign-in, and invalidates sessions / rotates CSRF tokens on sign-out.

### Production configuration notes
Before deploying to production, configure `.env` with:
- `APP_ENV=production` and `APP_DEBUG=false`
- `APP_URL=https://your-domain.example` over TLS/HTTPS
- `SESSION_SECURE_COOKIE=true`
- `MAIL_MAILER=smtp` (or a supported transactional API driver), valid credentials/TLS, `MAIL_FROM_ADDRESS`, and `MAIL_TIMEOUT=5` (`config/mail.php` defaults SMTP timeout to `5` seconds per ADR-0004)

See [HOSTING_REQUIREMENTS.md](docs/HOSTING_REQUIREMENTS.md) and [SECURITY.md](docs/SECURITY.md) for full details.

## Planning & governance documents
1. [PROJECT_RULES.md](PROJECT_RULES.md)
2. [SITEPULSE_PHASE_TRACKER.md](SITEPULSE_PHASE_TRACKER.md)
3. [Phase 01 Foundation](docs/phases/PHASE_01_FOUNDATION.md)
4. [AGENTS.md](AGENTS.md)
