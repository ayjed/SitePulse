# Test Plan (Draft)

## Test levels
- Unit: validation, monitor scheduling, state transitions, alert deduplication.
- Integration: database migrations, HTTP transport fakes, scheduled command, notification transport.
- Security: SSRF bypass and permission tests listed in `SECURITY.md`.
- Browser smoke: add monitor, view status, pause/resume, mobile layout.
- Deployment smoke: tested PHP host + cron + outgoing HTTPS + email settings.

## Evidence template
| Date | Branch/commit | Test command | Result | Notes |
|---|---|---|---|---|
| TBD | TBD | TBD | NOT RUN | Planning stage |

Never mark a test passed without having executed it.
