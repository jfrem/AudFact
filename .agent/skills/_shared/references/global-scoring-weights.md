# Global Scoring Weights

Use these weights to compute a single global score in cross-domain audits.

## Profile A: Core 4 Domains (current skills)

Use this profile when the audit covers:
- Architecture
- Code Quality
- Security
- Technical Governance

| Domain | Weight |
|--------|--------|
| Architecture | 30% |
| Code Quality | 25% |
| Security | 30% |
| Technical Governance | 15% |

## Profile B: Extended 6 Domains (when Testing and Operations are included)

Use this profile only when all six domains are explicitly assessed:
- Architecture
- Code Quality
- Security
- Testing
- CI/CD and Observability
- Technical Governance

| Domain | Weight |
|--------|--------|
| Architecture | 20% |
| Code Quality | 15% |
| Security | 20% |
| Testing | 15% |
| CI/CD and Observability | 15% |
| Technical Governance | 15% |

Status in this repository:
- Profile B is reserved for future extension.
- Current production routing supports Core 4 only.

## Rules

- For single-domain audits, keep domain weight at 100%.
- For multi-domain audits, use one profile only.
- If a required domain is missing evidence, state it in `## Missing Evidence` and lower confidence.
