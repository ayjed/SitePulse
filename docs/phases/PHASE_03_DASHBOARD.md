# Phase 03 — Dashboard

**Status:** IN PROGRESS (P03-01 authorized by the maintainer)

## Current task

P03-01 covers the owner-only monitor status overview, including current stored status, last check, latency, and manual refresh. Phase 02 closeout was merged via PR #9 at `77070bc5702d976a6b5c1537a98d8e8de4a896e5`. Its local result remains 283 tests and 1,898 assertions. The PR #9 CI jobs each recorded 283 tests and 1,904 assertions.

The P03-01 overview reads 15 monitors per page in ID order and reauthorizes each Livewire update. The initial PHP 8.4 / SQLite run passed with 294 tests and 2,008 assertions. Desktop and mobile previews and keyboard refresh were checked. Maintainer review and the Phase 03 exit criteria are pending.

### P03-01 merge review

The final review found that array and null Livewire pagination arguments raised a type error. Laravel's permissive decoder also accepted invalid characters, and the overview accepted extra cursor fields. The overview now accepts only canonical, size-limited Laravel cursors with a positive integer ID and a boolean direction. Invalid inputs return to the first owner-scoped page. The ten added regression cases cover encoding, structure, size, argument types, and owner isolation.

After the correction, the complete Windows PHP 8.4 / SQLite suite passed with 304 tests and 2,058 assertions. The overview tests passed with 21 tests and 160 assertions. Git whitespace checks, Composer strict validation, lockfile platform requirements and audit, Pint, `npm ci`, and the production build passed.

On Windows with npm 11.20.0, `npm ci` and `npm install-scripts ls` report that the transitive `esbuild@0.28.2` postinstall hook has no `allowScripts` policy entry. The lockfile supplies the platform binary via `@esbuild/win32-x64@0.28.2`. The clean install reported zero vulnerabilities, and the Vite production build completed. Dependency versions and script policy were left unchanged. [npm script policy](https://docs.npmjs.com/cli/v11/commands/npm-ci/#strict-allow-scripts) · [esbuild binary installation](https://esbuild.github.io/getting-started/#additional-npm-flags)

Desktop and mobile overview captures use synthetic preview data and are preserved locally in the existing Git-ignored `storage/app/private/` directory as `dashboard-desktop.jpg` and `dashboard-mobile.jpg`.

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
