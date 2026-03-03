# Deterministic Scoring Protocol

Use this protocol for all audit scoring to minimize agent variance.
Use [evidence-validation.md](evidence-validation.md) before scoring.

## Scoring model

- Domain score range: `0.0` to `5.0`.
- Each domain has `8 controls`.
- Control values allowed: `0` (fail), `0.5` (partial), `1` (pass).
- Raw domain score formula:
`raw_score = round((sum(control_values) / 8) * 5, 1)`
- Weighted score formula:
`weighted_score = round(raw_score * weight, 2)` where `weight` is decimal (for example 30% = 0.30).
- Global score formula:
`global_score = round(sum(weighted_scores), 1)`

Do not apply manual score adjustments outside cap rules below.

## Confidence model

- `High`: 0-1 material evidence gaps.
- `Medium`: 2-3 material evidence gaps.
- `Low`: 4+ material evidence gaps.

## Domain controls (fixed)

### Architecture controls

1. Clear module boundaries and responsibilities.
2. Controlled dependency direction and low coupling.
3. No critical boundary violations/circular dependencies.
4. Domain logic separated from infrastructure.
5. Consistent error boundaries and failure isolation.
6. State management avoids hidden global side effects.
7. Extensibility for one realistic feature change.
8. Explicit structural performance/scalability strategy.

### Code Quality controls

1. Naming and conventions are consistent.
2. Complexity hotspots are limited/managed.
3. Duplication is controlled.
4. Dead code is controlled.
5. Cohesion/separation of concerns is acceptable.
6. Critical flows are testable.
7. Automated tests exist for critical flows.
8. Debt hotspots are tracked and prioritized.

### Security controls

1. Sensitive endpoints enforce authn/authz.
2. Secrets are not exposed in operational paths.
3. Dependency vulnerability management exists.
4. Input validation/sanitization is robust.
5. Sensitive data handling/logging is safe.
6. Security controls exist in release pipeline.
7. External integrations use secure transport/config.
8. Environment hardening prevents insecure defaults in non-local environments.

### Technical Governance controls

1. Ownership is explicit for critical components.
2. Code review is enforced on critical paths.
3. Branch/release governance is enforced.
4. CI quality gates are enforced before merge/release.
5. Incident response process and SLA are defined.
6. Postmortem practice exists for severe incidents.
7. Technical debt register is maintained.
8. Technical roadmap aligns with product priorities.

## Cap rules (deterministic)

- Architecture: if an unmitigated `Critical` architecture finding exists, `domain_score <= 2.0`.
- Code Quality: if control 7 (tests on critical flows) is `0`, `domain_score <= 2.0`.
- Security: if any unmitigated `Critical` security finding exists, `domain_score <= 1.0`.
- Technical Governance: if controls 2 and 4 are both `0`, `domain_score <= 2.0`.

## Missing evidence rule

- Score unknown controls as `0.5` (partial), not pass/fail.
- List each unknown as a material evidence gap in `## Missing Evidence`.
- Build `## Missing Evidence` before domain/global score calculation.
