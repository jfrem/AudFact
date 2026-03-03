# Technical Governance Criteria

Use this checklist for engineering governance maturity assessments.

## Scope

- Evaluate process reliability for sustained software quality.
- Focus on ownership, review controls, incidents, debt governance, and roadmap discipline.

## Checklist

- Ownership by module/system is explicit.
- Incident escalation path and roles are documented.
- Code review is required before merge for relevant repos.
- Definition of Done exists and is applied.
- Branching/release policy is documented and followed.
- Incident response SLA exists and is actionable.
- Postmortem practice exists for severe incidents.
- Technical debt is tracked and included in planning cycles.
- Technical roadmap aligns with product/business priorities.

## Maturity classification

- Compute maturity from deterministic numeric score (8 fixed controls), not from subjective interpretation.
- Use score bands:
- High: 4.0-5.0
- Medium: 3.0-3.9
- Low: 2.0-2.9
- Absent: 0.0-1.9

## Severity guidance

- Critical: Governance gap creates immediate operational or compliance risk.
- High: Process weakness in critical delivery or incident response paths.
- Medium: Inconsistent controls with moderate impact.
- Low: Preventive governance improvements.
