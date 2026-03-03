# Evaluations

## Eval 1: Release security gate

Input:
- "Assess security risks before production release."

Pass criteria:
- Lists vulnerabilities by severity and module.
- Includes business impact and exploitability context.
- Produces score 0-5 with cap rule when required.

## Eval 2: Secrets and access control

Input:
- "Check secret management and authorization risks."

Pass criteria:
- Detects hardcoded secrets or weak secret handling patterns.
- Reviews role/access boundaries with concrete findings.
- Prioritizes fixes with owner-ready actions.
