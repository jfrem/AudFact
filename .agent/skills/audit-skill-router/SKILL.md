---
name: audit-skill-router
description: "Routes software-audit requests to the most appropriate specialized skill: architecture-assessment, code-quality-assessment, security-assessment, or technical-governance-assessment. Use when users request an audit but scope is broad, ambiguous, or mixed across domains."
---

# Audit Skill Router

## Instructions

Classify the user request and orchestrate the minimal skill set while preserving one unified audit objective.

Use routing rules in [references/routing-rules.md](references/routing-rules.md).
Use orchestration contract in [../_shared/references/orchestration-contract.md](../_shared/references/orchestration-contract.md).
Use global score/classification rules in [../_shared/references/global-scoring-weights.md](../_shared/references/global-scoring-weights.md) and [../_shared/references/classification-rubric.md](../_shared/references/classification-rubric.md).
Use finding ID rules in [../_shared/references/finding-id-convention.md](../_shared/references/finding-id-convention.md).
Use deterministic scoring rules in [../_shared/references/deterministic-scoring.md](../_shared/references/deterministic-scoring.md).
Use severity rules in [../_shared/references/severity-matrix.md](../_shared/references/severity-matrix.md).
Use business-priority rules in [../_shared/references/business-risk-matrix.md](../_shared/references/business-risk-matrix.md).
Use evidence validation rules in [../_shared/references/evidence-validation.md](../_shared/references/evidence-validation.md).

Routing procedure (activation precision first):

1. Identify primary intent from user wording.
2. Choose one skill when scope is single-domain.
3. Choose multiple skills when the user explicitly asks cross-domain, or when three or more domains are strongly signaled in an audit-oriented request.
4. If intent is explicitly full-scope (for example: "full audit", "end-to-end audit", "complete assessment"), choose all four domain skills in this order: `architecture-assessment`, `code-quality-assessment`, `security-assessment`, `technical-governance-assessment`.
5. In the first response, state selected skill(s) and why in one line.

Execution procedure (global objective preservation):

1. Run only selected domain skill(s). Do not load non-selected domain references.
2. For each selected domain, gather findings and one domain score (0-5) plus cap rationale.
3. Normalize findings into one consolidated list with unique IDs per domain prefix.
4. Produce one single consolidated response that follows [../_shared/references/orchestration-contract.md](../_shared/references/orchestration-contract.md).
5. In cross-domain audits, compute weighted global score with Core 4 profile.
6. If a selected domain is missing evidence, keep the domain in the consolidated report and explicitly lower confidence.
7. Keep per-domain details concise and prioritize cross-domain risk interaction in the final risk narrative.
8. Do not override numeric scores with subjective adjustments.
9. If a domain cap rule is triggered, record the trigger explicitly in `Cap Applied`.
10. Deduplicate cross-domain overlaps by `Component + Evidence`, and resolve severity conflicts using evidence strength rules from the orchestration contract.
11. Build `## Missing Evidence` before computing final domain/global scores.

Output behavior:

- Keep routing explanation under 2 sentences.
- Preserve selected domain contracts internally, but return one unified final audit report.
- Avoid duplicating large domain sections when one consolidated section can represent them.
- In single-domain audits, keep global score equal to domain score (weight 100%).
- Keep one-decimal rounding for raw/global score and two-decimal rounding for weighted score.
- Always include `## Structured Output` JSON block for machine consumption.

## Examples

Input:
- "Check security and release readiness of this repo."

Route:
- `security-assessment`

Input:
- "I need architecture and code quality risks before refactor."

Route:
- `architecture-assessment` + `code-quality-assessment`

Input:
- "Do a full technical audit with scoring and plan."

Route:
- `architecture-assessment` + `code-quality-assessment` + `security-assessment` + `technical-governance-assessment`
