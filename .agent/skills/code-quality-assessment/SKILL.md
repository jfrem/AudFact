---
name: code-quality-assessment
description: Assesses code quality and maintainability, including readability, complexity, duplication, dead code, testability, and technical debt concentration. Use when users ask for code quality review, maintainability scoring, refactoring priorities, or technical debt assessment.
---

# Code Quality Assessment

## Instructions

Evaluate maintainability with evidence from source code and tests.

Collect inputs first:

- Repository path and branch/commit.
- Priority modules and critical business paths.
- Existing coding standards and test constraints.

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

1. Naming and conventions are consistent.
2. Complexity hotspots are limited and managed.
3. Duplication is controlled.
4. Dead code is controlled.
5. Cohesion and separation of concerns are acceptable.
6. Critical flows are testable.
7. Automated tests exist for critical flows.
8. Debt hotspots are tracked and prioritized.

Scoring:

- Score only with the 8 fixed code-quality controls from `deterministic-scoring.md`.
- Allowed control values: `0`, `0.5`, `1`.
- Compute raw score with the deterministic formula.
- If tests on critical flows are absent (control 7 = `0`), cap score at `2.0`.

## Required Output Contract

Return Markdown with these exact H2 headings, in this order:

1. `## Scope And Assumptions`
2. `## Findings`
3. `## Domain Scoring`
4. `## Global Result`
5. `## 30-60-90 Plan`
6. `## Missing Evidence`
7. `## Structured Output`

In `## Findings`, include a table with columns:
`ID | Severity | Component | Evidence | Evidence Command | Technical Risk | Business Impact | Priority | Recommendation | Action Type | Owner | ETA`
Use `QUAL-001`, `QUAL-002`, ... for finding IDs.
Assign severity only using `severity-matrix.md`.
Set action priority using `business-risk-matrix.md`.
Validate evidence and apply downgrade/discard rules using `evidence-validation.md`.

In `## Domain Scoring`, include a table with columns:
`Domain | Raw Score (0-5) | Weight | Weighted Score | Cap Applied | Rationale | Confidence`
In `Rationale`, include control trace as `pass/partial/fail`.
Use `Confidence` values `High|Medium|Low` from `deterministic-scoring.md`.
Build `## Missing Evidence` before final score calculation.

In `## Domain Scoring`, include an additional module table with columns:
`Module | Criticality | Raw Score (0-5) | Weight | Weighted Contribution | Key Rationale`
Treat this module table as diagnostic context only; final domain/global scoring must remain deterministic per `deterministic-scoring.md`.

In `## Global Result`, include:
- `Global score: <number>`
- `Classification: A|B|C|D`
- `Ceiling rule applied: Yes|No`
Apply classification rules from [../_shared/references/classification-rubric.md](../_shared/references/classification-rubric.md).
For multi-domain outputs, apply weights from [../_shared/references/global-scoring-weights.md](../_shared/references/global-scoring-weights.md).

In `## Structured Output`, include one JSON block with: `domain_scores`, `global_score`, `classification`, `ceiling_rule_applied`, `confidence`, `caps_triggered`, `findings` (with `id`, `severity`, `component`, `evidence`, `evidence_command`, `priority`).

## Examples

Input example:
- "Assess code quality and rank refactors for this API service."

Output snippet example:
- Findings table with complexity and duplication hotspots.
- Module-level and weighted global score.
- 30/60/90 plan with explicit owners.



