---
name: architecture-assessment
description: Reviews software architecture for modularity, coupling, error handling, extensibility, and scalability. Use when users ask for architecture review, design assessment, system decomposition, coupling analysis, technical bottleneck analysis, or architectural remediation planning.
---

# Architecture Assessment

## Instructions

Assess architecture quality using repository evidence.

Collect inputs first:

- Repository path and target branch/commit.
- Scope (full system or selected modules/services).
- Business-critical flows and scalability constraints.

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

1. Module boundaries and single-responsibility clarity.
2. Coupling level and dependency direction.
3. Circular dependencies and boundary violations.
4. Separation of domain logic from infrastructure.
5. Error-handling consistency and failure isolation.
6. State management risks (global state, hidden side effects).
7. Extensibility impact of adding one realistic feature.
8. Structural performance/scalability bottlenecks.

Scoring:

- Score only with the 8 fixed architecture controls from `deterministic-scoring.md`.
- Allowed control values: `0`, `0.5`, `1`.
- Compute raw score with the deterministic formula.
- If critical architectural risk is unmitigated, cap score at `2.0`.

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
Use `ARCH-001`, `ARCH-002`, ... for finding IDs.
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

## Examples

Input example:
- "Review this backend architecture before splitting into services."

Output snippet example:
- One Critical finding with concrete module/file evidence.
- Architecture domain score with cap-rule note when needed.
- 30/60/90 actions with named owners.



