# ADR-0005: Phase 03 functional acceptance and accessibility verification deferral

**Decision:** Maintainer-authorized on 2026-11-28 by @ayjed
**Effectiveness:** Pending merge of the approval documentation into main
**Release gate:** Open, owned by @ayjed

## Context

The maintainer accepted Phase 03 functional, security, database, performance and automated browser evidence. The final audit was squash-merged through PR #14 at `df37a2ac0afe8ac93137fc2cc2c87eb269d2b7e0`. The authorized review head was `df50285e962400fc5a2540b74507203569756a8a`. The audit distinguishes executed tests from remaining manual evidence. [PR #14](https://github.com/ayjed/SitePulse/pull/14) · [Verified CI](https://github.com/ayjed/SitePulse/actions/runs/46160837252) · [Final audit](../audits/PHASE_03_FINAL_ACCEPTANCE.md)

## Options considered

1. Hold Phase 03 functional closeout until every remaining accessibility check is complete.
2. Accept the verified functional scope after this record merges and make the remaining accessibility verification a mandatory Phase 05 public-release gate.

## Decision

Adopt option 2. Until this documentation merges, Phase 03 remains IN REVIEW. Upon merge, Phase 03 functional delivery is conditionally approved under the maintainer's recorded decision. Phase 04 requires separate start authorization.

This amendment changes the order of verification. FR-7 and its full WCAG 2.1 AA requirement remain in force. The decision establishes no conformance and waives no known defect. Existing accessibility implementation, automated regressions and historical evidence are retained. [FR-7](../REQUIREMENTS.md#37-status-dashboard-fr-7) · [WCAG 2.1 conformance](https://www.w3.org/TR/WCAG21/#conformance-reqs)

## Exact deferred scope

1. Complete independent manual keyboard navigation.
2. Real screen-reader verification.
3. Screen-reader chart and table navigation.
4. Dynamic status and validation announcements.
5. Remaining applicable non-text contrast checks.
6. Forced-colors verification.
7. Complete native browser zoom and reflow checks.
8. WCAG text-spacing verification.
9. Remaining applicable WCAG 2.1 A/AA success criteria.
10. Additional browser and assistive-technology combinations where necessary.

## Consequences and evidence

The maintainer owns closure of [the mandatory Phase 05 gate](../phases/PHASE_05_RELEASE.md#mandatory-accessibility-release-gate) and must review the evidence against the release candidate before approving public release. Delegate testing as needed and retain explicit maintainer sign-off. Any failure or missing applicable evidence keeps the gate open. Repair verified defects, retest the affected criteria and flows, and rerun the automated regression suite before closure. Preserved evidence includes the Chromium matrix, targeted Firefox runs, native zoom observations and the prior audit's benchmark and accessibility limitations. [P03-04 evidence](../audits/P03_04_ACCESSIBILITY.md) · [Test procedure](../TEST_PLAN.md#phase-05-accessibility-release-verification)
