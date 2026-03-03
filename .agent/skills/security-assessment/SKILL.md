---
name: security-assessment
description: Audits software security posture across dependencies, secrets handling, authentication/authorization, input validation, and secure operations. Use when users ask for security review, vulnerability assessment, release readiness, or security remediation planning.
---

# Security Assessment

## Instructions

Perform a security audit grounded in repository and configuration evidence.

Collect inputs first:

- Repository path and target branch/commit.
- System exposure context (public API, internal service, sensitive data).
- Compliance or policy constraints if provided.

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

1. Sensitive endpoints enforce authn/authz.
2. Secrets are not exposed in operational paths.
3. Dependency vulnerability management exists.
4. Input validation and sanitization are robust.
5. Sensitive data handling and logging are safe.
6. Security controls exist in release pipeline.
7. External integrations use secure transport/config.
8. Environment hardening prevents insecure defaults in non-local environments.

Scoring:

- Score only with the 8 fixed security controls from `deterministic-scoring.md`.
- Allowed control values: `0`, `0.5`, `1`.
- Compute raw score with the deterministic formula.
- If any unmitigated critical vulnerability exists, cap score at `1.0`.

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
Use `SEC-001`, `SEC-002`, ... for finding IDs.
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
- "Run a release-readiness security audit for this repo."

Output snippet example:
- Vulnerabilities table with severity and evidence by component.
- Security score with cap-rule note when critical issues exist.
- 30/60/90 remediation plan with owners.



