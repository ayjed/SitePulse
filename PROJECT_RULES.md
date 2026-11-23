# Project Rules

1. Planning first: Phase 00 must be reviewed before application scaffolding.
2. One phase at a time. Do not implement unapproved future phases.
3. Small, reviewable changes; separate branches/PRs for substantial features.
4. No fabricated tests, screenshots, benchmarks, uptime, stars, or hosting claims.
5. Never commit credentials, customer data, personal information, or `.env` secrets.
6. A monitored URL is untrusted input. Threat-model SSRF and DNS/redirect bypasses before building the HTTP checker.
7. Default to least privilege, conservative timeouts, and bounded concurrency.
8. Every feature requires tests and documentation as part of its completion criteria.
9. AI tools must explain key design choices and exact files changed. Review before merging.
10. Keep tracker, phase file, and ADRs synchronized with meaningful changes.
