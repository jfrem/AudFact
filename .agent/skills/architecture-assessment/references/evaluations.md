# Evaluations

## Eval 1: Modularity and coupling review

Input:
- "Evaluate architecture coupling and module boundaries."

Pass criteria:
- Identifies concrete coupling hotspots with file/module evidence.
- Distinguishes structural issues from code-style issues.
- Provides architecture score (0-5) with rationale.

## Eval 2: Scalability readiness

Input:
- "Can this architecture support 10x traffic?"

Pass criteria:
- Points to structural bottlenecks (I/O, shared state, sync dependencies).
- Includes risk level and targeted remediation actions.
- Separates confirmed evidence from assumptions.
