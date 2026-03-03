# Finding ID Convention

Use this format for all findings:

`<PREFIX>-<NNN>`

Where:
- `PREFIX` identifies the domain.
- `NNN` is a 3-digit sequential number starting at `001`.

## Domain Prefixes

- Architecture: `ARCH` (example: `ARCH-001`)
- Code Quality: `QUAL` (example: `QUAL-001`)
- Security: `SEC` (example: `SEC-001`)
- Technical Governance: `GOV` (example: `GOV-001`)

## Rules

- IDs must be unique within a report.
- Keep IDs stable during revisions when the same finding remains open.
- Do not reuse closed IDs for different findings.
- Preserve domain prefix in mixed-domain reports.
