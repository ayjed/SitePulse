# SitePulse

> Planning-stage project: a lightweight, self-hosted website uptime monitoring tool for PHP developers and small website owners.

**Status:** Phase 00 — Discovery and feasibility. No application implementation has been approved yet.

## Intended outcomes
- Monitor website availability and HTTP response time.
- Record uptime and downtime history.
- Provide a usable, accessible status dashboard.
- Check TLS certificate expiry and optionally alert by email.
- Offer a clear installation path for compatible PHP hosting with cron support, **only after testing compatibility**.

## Start here
1. Read [PROJECT_RULES.md](PROJECT_RULES.md).
2. Review [SITEPULSE_PHASE_TRACKER.md](SITEPULSE_PHASE_TRACKER.md).
3. Complete [Phase 00](docs/phases/PHASE_00_DISCOVERY.md) and record decisions.
4. Use [AGENTS.md](AGENTS.md) when working with Antigravity or Codex.

## Proposed stack (not locked)
PHP 8.3+, Laravel, Blade + Livewire + Tailwind, SQLite initially, MySQL compatibility, cron, Pest or PHPUnit. Architecture and hosting constraints must be validated in Phase 00.

## Important
Stars and sponsorships are not success criteria for early engineering milestones. The priority is safe, useful, installable software.
