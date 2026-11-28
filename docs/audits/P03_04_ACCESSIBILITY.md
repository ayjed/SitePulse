# P03-04 Browser smoke and accessibility verification

## Run locally

Use the repository's supported PHP runtime with PDO SQLite, Composer, and Node 22.12 or later. Run these from the repository root with the Vite development server stopped.

```text
composer install
npm ci
npm run build
npm exec -- playwright install chromium
npm run test:browser
```

On Linux runners that also need browser system libraries, use `npm exec -- playwright install --with-deps chromium`. For manual review, run `npm run test:browser -- --debug --grep "keyboard tab order"`. Use the Playwright Inspector to advance to the detail page, then interact with the headed browser. For a local copy of the responsive detail captures, set `SITEPULSE_BROWSER_SCREENSHOTS=1` and run the suite. The captures go to `storage/app/private/browser-evidence/`.

Playwright supplies browser input, navigation, interception and web-first assertions. The axe integration evaluates the `wcag2a`, `wcag2aa`, `wcag21a` and `wcag21aa` rules in Chromium. Both packages are exact-pinned development dependencies. The selected Chromium revision comes from the locked Playwright release.

Each test uses a fresh SQLite database, file session store and generated credentials under a temporary directory. The fixture ignores the project's dotenv directory, disables the DNS and HTTP functions used by the monitor transport, and runs a loopback-only PHP server. Browser requests are restricted to the fixture origin. The suite uses a production Vite build. Cleanup stops PHP and removes the fixture directory. Keep this harness local to development and CI.

CI keeps its existing SQLite and MySQL matrix and runs Chromium separately on PHP 8.4 with SQLite. The job publishes a JSON list of test titles and outcomes for three days. Browser traces, videos, screenshots, raw errors and session data are excluded from the upload.

## Coverage matrix

| Requirement | Browser evidence | Manual review or limitation |
|---|---|---|
| UI-01 Monitor CRUD | Owner creates, views, edits, pauses, resumes and deletes a synthetic monitor. Persisted state is checked. Keyboard cancellation leaves the monitor in place. A held submission returns exactly one delete request. | Native deletion disclosure and form semantics were inspected on Chromium. |
| UI-02 Keyboard | Skip target, detail tab order, focus indicators, recovery, confirmation, chart disclosure, local table scrolling, refresh and pagination. A held history update admits one keyboard/pointer request and restores the server-confirmed filter. | Native Chromium review covered detail presentation at 200% browser zoom. Keyboard traces exercise the real browser document. Address-bar focus in the manual control session limited a separate manual tab-order pass. |
| UI-03 Status contrast and non-color cues | axe scans sign-in, overview with all six states, management table, create and edit forms, and detail with chart table and confirmation open, at all widths and both themes. Validation errors receive a separate scan. The suite checks rendered field boundary contrast for sign-in, monitor setup and the incident filter. | Status text and distinct decorative shapes were inspected in source and in the detail accessibility tree. Native assistive-technology announcements and exhaustive non-text contrast require further manual review. |
| UI-04 Responsive layout | Document reflow at 1440, 768, 375 and 320 CSS px in light and dark mode. Long names, URLs and incident causes, zero and missing latency, and a large latency sample exercise wrapping. An additional 320 px detail run doubles the root text size and checks table cells. | Headed Chromium at native 200% zoom showed the long name wrapping and the upper detail layout remaining readable. The automated 200% test scales the root font size. |

The browser tests also exercise the guest redirect, rejected non-owner login, forbidden access to another user's monitor, CSRF rejection, a stale tab after sign-out, private/no-store Livewire responses, bounded pages, sample counts, empty data, and recovery after a synthetic query failure. An incident resolved after the locked cutoff is labeled as such. The server authorization and data-integrity tests continue to run in the PHP suite.

## Corrected issues

- Login autofocus skipped the first keyboard stop. The skip link now starts the tab order, and failed submissions direct focus to the named error summary.
- The login form and long edit headings could widen the document at 320 CSS px. The password layout can wrap and the edit heading breaks long words. At enlarged text sizes, named table regions keep horizontal scrolling local and keyboard accessible.
- Monitor-overview refresh dropped focus, and native disabled states made history pagination buttons lose focus at page boundaries. Focusable controls announce availability and guard their actions while an update is pending.
- Dark input boundaries and the incident filter needed stronger contrast against adjacent surfaces. The browser regression measures representative field boundaries against the WCAG 3:1 threshold in both schemes.

Local PHP 8.4.28 with SQLite passed 337 tests and 2,360 assertions. Pint, Composer strict validation, locked platform requirements, and locked audit passed. The clean npm install reported zero known vulnerabilities and repeated the existing npm 11 notice about the unapproved `esbuild@0.28.2` postinstall script. The npm policy was preserved and the Vite production build compiled 61 modules.

Chromium 159 passed all 17 browser tests on Windows, including 49 axe scans with zero violations in the selected rules and 24 representative field boundary comparisons at or above 3:1. The review also inspected synthetic 320 px light and 1440 px dark screenshots showing the keyboard focus indicator and the expanded deletion warning. The local evidence directory contains all eight detail captures.

## Reviewer checklist

1. Run the browser suite with `--headed`. Use only Tab, Shift+Tab, Enter, Space and the arrow keys on login, monitor management, forms and history. Check the skip target, named notices, visible focus, and focus order after reading or changing data.
2. Exercise Delete Monitor, then cancel it from the keyboard. Confirm the monitor still exists. For duplicate submissions and overlapping history actions, use the repeatable delayed-request tests.
3. At default zoom and native 200% zoom, read the long monitor name and target, inspect the uptime and compare the chart's summary with its table. At 320 CSS px check that the document reflows and the focused table regions scroll independently.
4. Test light and dark themes. Where assistive technology is available, listen to the field errors and the pending and error states, and navigate the chart and table by headings. Record browser, OS and assistive-technology versions.

The browser matrix covers Chromium. Browser and assistive-technology behavior outside that matrix, and complete WCAG 2.1 AA conformance, remain unverified. [Playwright accessibility testing](https://playwright.dev/docs/accessibility-testing) · [WCAG 2.1](https://www.w3.org/TR/WCAG21/) · [Resize Text](https://www.w3.org/WAI/WCAG21/Understanding/resize-text) · [Reflow](https://www.w3.org/WAI/WCAG21/Understanding/reflow)
