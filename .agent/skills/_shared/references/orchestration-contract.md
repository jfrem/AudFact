# Orchestration Contract (Router Output)

Use this contract when `audit-skill-router` orchestrates one or more domain skills.

Goal:
- Preserve a single audit objective across segmented skills.
- Keep activation precise and context load minimal.
- Produce one consolidated executive-grade output.
- Keep scoring reproducible across agents.
- Keep findings machine-consumable for downstream automation.

## Required H2 headings (exact order)

1. `## Scope And Assumptions`
2. `## Routed Domains`
3. `## Consolidated Findings`
4. `## Domain Scoring`
5. `## Global Result`
6. `## Cross-Domain Risk Narrative`
7. `## 30-60-90 Integrated Plan`
8. `## Missing Evidence`
9. `## Structured Output`

## Required content by section

### `## Routed Domains`

- List selected domain skills.
- One-line reason per domain.
- Explicitly state non-selected domains were intentionally excluded for precision.

### `## Consolidated Findings`

Use one table with columns:
`ID | Domain | Severity | Component | Evidence | Evidence Command | Technical Risk | Business Impact | Priority | Recommendation | Action Type | Owner | ETA`

Rules:
- Enforce ID prefixes per domain from `finding-id-convention.md`.
- Keep IDs unique within the report.
- Sort by severity, then business impact.
- Derive execution priority using [business-risk-matrix.md](business-risk-matrix.md) and reflect it in recommendation/action ordering.
- Validate evidence with [evidence-validation.md](evidence-validation.md).
- Deduplicate cross-domain overlaps by `Component + Evidence`.
- If duplicate findings disagree on severity, keep the higher severity only when evidence volume is equal or stronger; otherwise keep lower severity and record conflict rationale.

### `## Domain Scoring`

Use one table with columns:
`Domain | Raw Score (0-5) | Weight | Weighted Score | Cap Applied | Rationale | Confidence`

Rules:
- Single-domain: `Weight = 100%`.
- Cross-domain (4 domains): use Core 4 profile from `global-scoring-weights.md`.
- If evidence is missing, set `Confidence` accordingly and keep the row.
- Compute raw/global scores only with [deterministic-scoring.md](deterministic-scoring.md).
- In `Rationale`, include control trace `pass/partial/fail` for the 8 domain controls.

### `## Global Result`

Include:
- `Global score: <number>`
- `Classification: A|B|C|D`
- `Ceiling rule applied: Yes|No`
- `Confidence: High|Medium|Low`

Apply classification from `classification-rubric.md`.

### `## Cross-Domain Risk Narrative`

- Describe interactions between domains (for example architecture debt increasing security exposure).
- Keep this section short and decision-oriented.

### `## Consolidated Findings` severity rule

- Assign severity only with [severity-matrix.md](severity-matrix.md).
- If two evidence references are not available for `Critical`/`High`, downgrade severity by one level.
- If evidence remains non-verifiable after downgrade, remove finding and record as evidence gap.

### `## 30-60-90 Integrated Plan`

Use one table with columns:
`Horizon | Action | Domains Impacted | Owner | Success Metric`

Rules:
- Include mixed-domain actions first.
- Label each action as `Quick Win`, `Incremental`, or `Structural`.
- Order actions by priority from [business-risk-matrix.md](business-risk-matrix.md): `P0`, `P1`, `P2`, `P3`.

### `## Missing Evidence`

For each gap include:
- `Evidence gap`
- `Collection method`
- `Impact on confidence`

### `## Structured Output`

Provide one JSON block with this schema:

```json
{
  "domain_scores": [
    {
      "domain": "Architecture|Code Quality|Security|Technical Governance",
      "raw_score": 0.0,
      "weight": 0.0,
      "weighted_score": 0.0,
      "cap_applied": false,
      "confidence": "High|Medium|Low"
    }
  ],
  "global_score": 0.0,
  "classification": "A|B|C|D",
  "ceiling_rule_applied": false,
  "confidence": "High|Medium|Low",
  "caps_triggered": [],
  "findings": [
    {
      "id": "ARCH-001",
      "domain": "Architecture",
      "severity": "Critical|High|Medium|Low",
      "component": "",
      "evidence": [],
      "evidence_command": "",
      "priority": "P0|P1|P2|P3"
    }
  ]
}
```
