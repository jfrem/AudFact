# Report Template

## Scope And Assumptions

- Repository:
- Revision:
- Scope:
- Assumptions:

## Findings

Use ID format `GOV-001`, `GOV-002`, ... from [../../_shared/references/finding-id-convention.md](../../_shared/references/finding-id-convention.md).
Use severity from [../../_shared/references/severity-matrix.md](../../_shared/references/severity-matrix.md).
Set execution priority with [../../_shared/references/business-risk-matrix.md](../../_shared/references/business-risk-matrix.md).
Evidence format is required as `path:line` (Critical/High should include at least two references).
Evidence command is required as reproducible read-only command per finding.

| ID | Severity | Component | Evidence | Evidence Command | Technical Risk | Business Impact | Priority | Recommendation | Action Type | Owner | ETA |
|----|----------|-----------|----------|------------------|----------------|-----------------|----------|----------------|-------------|-------|-----|

## Domain Scoring

| Domain | Raw Score (0-5) | Weight | Weighted Score | Cap Applied | Rationale | Confidence |
|--------|------------------|--------|----------------|-------------|-----------|------------|
| Technical Governance | | 100% (single-domain) | | Yes/No | | High/Medium/Low |

## Global Result

- Global score:
- Classification: A/B/C/D
- Ceiling rule applied: Yes/No
- Multi-domain weight profile (if applicable): Core 4 from [../../_shared/references/global-scoring-weights.md](../../_shared/references/global-scoring-weights.md)

## Maturity Result

- Maturity level: High/Medium/Low/Absent
- Score omitted: No
- Justification:

## 30-60-90 Plan

| Horizon | Action | Owner | Success Metric |
|---------|--------|-------|----------------|
| 30 days | | | |
| 60 days | | | |
| 90 days | | | |

## Missing Evidence

- Evidence gap:
- Collection method:
- Impact on confidence:

## Structured Output

```json
{
  "domain_scores": [
    {
      "domain": "Technical Governance",
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
      "id": "GOV-001",
      "domain": "Technical Governance",
      "severity": "Critical|High|Medium|Low",
      "component": "",
      "evidence": [],
      "evidence_command": "",
      "priority": "P0|P1|P2|P3"
    }
  ]
}
```





