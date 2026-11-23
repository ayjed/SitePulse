# AI Agent Working Agreement

Applies to Antigravity, Codex, and other coding assistants working on SitePulse.

## Required read order
1. `PROJECT_RULES.md`
2. `SITEPULSE_PHASE_TRACKER.md`
3. current `docs/phases/PHASE_XX_*.md`
4. `docs/REQUIREMENTS.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`
5. relevant `docs/decisions/` records

## Before making edits
- State which phase and acceptance criterion the task serves.
- List files to change, risks, tests, and expected evidence.
- Ask for maintainer approval if scope expands or architecture changes.

## While working
- Keep changes narrow; never modify unrelated files.
- Do not simultaneously edit the same working tree with multiple AI agents.
- Document commands executed and their results honestly.
- For HTTP probing, block unsafe destinations by design; do not treat regex-only URL filtering as sufficient.

## After working
- Summarize changed files, tests actually run, known issues, security implications, and next action.
- Propose tracker/phase updates for review. Do not self-approve a phase.

## First task
Complete Phase 00 design review only. Do not scaffold the application without explicit approval.
