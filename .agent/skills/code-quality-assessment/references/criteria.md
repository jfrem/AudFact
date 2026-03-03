# Code Quality Criteria

Use this checklist for maintainability and technical debt assessments.

## Scope

- Evaluate readability, complexity, duplication, dead code, and testability.
- Prioritize modules identified as business-critical.

## Checklist

- Naming and conventions are clear and consistent.
- Functions/classes are appropriately sized and cohesive.
- Complexity hotspots are identified and justified.
- Duplication is minimized or documented when intentional.
- Dead code is identified and removal opportunities are listed.
- Logging/traceability supports troubleshooting in critical paths.
- Coverage for critical modules is present or explicitly missing.
- Refactor opportunities are grouped by effort and impact.

## Scoring

- Use deterministic domain scoring only from `../_shared/references/deterministic-scoring.md`.
- Domain score is computed from the 8 fixed controls with allowed values `0`, `0.5`, `1`.
- Apply code-quality cap rule when control 7 is `0` (tests on critical flows absent).
- Any module-level score is diagnostic only and does not override or replace the deterministic domain/global score.

## Severity guidance

- Critical: Code condition creates immediate operational or delivery risk.
- High: Debt concentration in critical modules with high change cost.
- Medium: Debt is material but manageable in short term.
- Low: Preventive cleanup and consistency improvements.
