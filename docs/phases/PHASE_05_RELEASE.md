# Phase 05 — Packaging and Public Release

**Status:** NOT STARTED

## Scope
- [ ] Verify install on specified PHP hosting environment(s).
- [ ] Publish accurate requirements and installation guide.
- [ ] Add chosen open-source license after review.
- [ ] Create screenshots/demo, contributing guide, issue templates.
- [ ] Configure `.github/FUNDING.yml` only if appropriate.
- [ ] Tag first version and publish release notes.

## Mandatory accessibility release gate

**Owner:** @ayjed
**Status:** OPEN — blocks public release approval

[ADR-0005](../decisions/ADR-0005-phase-03-accessibility-release-gate.md) records the maintainer's amendment and its exact deferred scope. Phase 03 functional acceptance carries forward only after the approval documentation merges. FR-7 remains a release requirement.

Close this gate only when all of the following are recorded:

- [ ] Identify the release candidate commit and review whole pages and complete flows against every applicable WCAG 2.1 A/AA criterion. Document reasons for criteria assessed as inapplicable.
- [ ] Execute the independent keyboard, screen-reader, announcement, contrast, forced-colors, native zoom/reflow and text-spacing checks in the [verification procedure](../TEST_PLAN.md#phase-05-accessibility-release-verification). Record coverage and rationale for the browser and assistive-technology combinations used.
- [ ] Preserve a criterion-by-criterion evidence matrix with reproduction steps, environment versions, actual outcomes and evidence links. Capture representative observations of chart, table and dynamic-announcement behavior with real assistive technology.
- [ ] Fix verified defects. Record the fixes, retest affected criteria and adjacent flows, and rerun the automated browser and axe suite on the candidate. Missing applicable evidence or failed checks keep the gate open.
- [ ] Obtain explicit maintainer approval of the complete evidence on the release candidate and record the decision and commit in the tracker and release evidence.

Public release approval and publication require a closed gate. The existing Phase 05 exit criteria also apply.

## Exit criteria
- [ ] Installation reproduced from fresh checkout.
- [ ] Security/docs/tests reviewed.
- [ ] No exaggerated claims of compatibility or usage.
- [ ] Mandatory accessibility release gate closed on the release candidate.
- [ ] Maintainer explicitly approves public release.
