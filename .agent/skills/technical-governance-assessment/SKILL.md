---
name: technical-governance-assessment
description: Assesses engineering governance maturity across ownership, code review policy, incident processes, technical debt management, and roadmap alignment. Use when users ask for governance review, engineering maturity assessment, delivery process health, or organizational technical risk analysis.
---

# Technical Governance Assessment

## Instructions

Assess technical governance and maturity with process evidence.

Collect inputs first:

- Team/repository scope and organizational context.
- Existing engineering process docs (if any).
- Critical systems and incident exposure level.

Use core criteria in [references/criteria.md](references/criteria.md).
Use quality checks in [references/evaluations.md](references/evaluations.md).
Use output shape in [assets/report-template.md](assets/report-template.md).
Use global weighting rules in [../_shared/references/global-scoring-weights.md](../_shared/references/global-scoring-weights.md).
Use finding ID rules in [../_shared/references/finding-id-convention.md](../_shared/references/finding-id-convention.md).
Use deterministic scoring rules in [../_shared/references/deterministic-scoring.md](../_shared/references/deterministic-scoring.md).
Use severity rules in [../_shared/references/severity-matrix.md](../_shared/references/severity-matrix.md).
Use business-priority rules in [../_shared/references/business-risk-matrix.md](../_shared/references/business-risk-matrix.md).
Use evidence validation rules in [../_shared/references/evidence-validation.md](../_shared/references/evidence-validation.md).

Evaluate at minimum:

1. Ownership is explicit for critical components.
2. Code review is enforced on critical paths.
3. Branch and release governance are enforced.
4. CI quality gates are enforced before merge/release.
5. Incident response process and SLA are defined.
6. Postmortem practice exists for severe incidents.
7. Technical debt register is maintained.
8. Technical roadmap aligns with product priorities.

Classify maturity:

- Compute numeric governance score first using the 8 fixed governance controls from `deterministic-scoring.md`.
- Allowed control values: `0`, `0.5`, `1`.
- Compute raw score with the deterministic formula.
- If controls 2 and 4 are both `0`, cap score at `2.0`.
- Derive maturity from numeric score:
  - High: 4.0-5.0
  - Medium: 3.0-3.9
  - Low: 2.0-2.9
  - Absent: 0.0-1.9

## Required Output Contract

Return Markdown with these exact H2 headings, in this order:

1. `## Scope And Assumptions`
2. `## Findings`
3. `## Domain Scoring`
4. `## Global Result`
5. `## Maturity Result`
6. `## 30-60-90 Plan`
7. `## Missing Evidence`
8. `## Structured Output`

In `## Findings`, include a table with columns:
`ID | Severity | Component | Evidence | Evidence Command | Technical Risk | Business Impact | Priority | Recommendation | Action Type | Owner | ETA`
Use `GOV-001`, `GOV-002`, ... for finding IDs.
Assign severity only using `severity-matrix.md`.
Set action priority using `business-risk-matrix.md`.
Validate evidence and apply downgrade/discard rules using `evidence-validation.md`.

In `## Domain Scoring`, include a table with columns:
`Domain | Raw Score (0-5) | Weight | Weighted Score | Cap Applied | Rationale | Confidence`
In `Rationale`, include control trace as `pass/partial/fail`.
Use `Confidence` values `High|Medium|Low` from `deterministic-scoring.md`.
Build `## Missing Evidence` before final score calculation.

In `## Global Result`, include:
- `Global score: <number>`
- `Classification: A|B|C|D`
- `Ceiling rule applied: Yes|No`
Apply classification rules from [../_shared/references/classification-rubric.md](../_shared/references/classification-rubric.md).
For multi-domain outputs, apply weights from [../_shared/references/global-scoring-weights.md](../_shared/references/global-scoring-weights.md).

In `## Structured Output`, include one JSON block with: `domain_scores`, `global_score`, `classification`, `ceiling_rule_applied`, `confidence`, `caps_triggered`, `findings` (with `id`, `severity`, `component`, `evidence`, `evidence_command`, `priority`).

In `## Maturity Result`, include:
- `Maturity level: High|Medium|Low|Absent`
- `Score omitted: No`
- `Justification: <short text>`

## Examples

Input example:
- "Assess our governance maturity and incident readiness."

Output snippet example:
- Governance findings with ownership/process evidence.
- Maturity level with concise justification.
- 30/60/90 improvements with assigned owners.



