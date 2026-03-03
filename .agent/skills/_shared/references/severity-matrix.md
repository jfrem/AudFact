# Severity Matrix (Deterministic)

Use this matrix for all findings. Do not assign severity by intuition.
Use [business-risk-matrix.md](business-risk-matrix.md) to derive execution priority after severity is set.
Use [evidence-validation.md](evidence-validation.md) to validate evidence before final severity.

## Required evidence rule

- Every finding must include at least one `path:line` evidence reference.
- Every finding must include a reproducible `Evidence Command`.
- `Critical` and `High` findings must include at least two independent evidence references.
- If evidence is insufficient, downgrade one level.

## Severity decision order

Apply the first rule that matches:

1. `Critical`
- Unauthenticated access to critical data or privileged operations.
- Active credential/secret exposure (private key, API key, token) in operational files.
- Direct compromise risk for confidentiality/integrity/availability in production path.

2. `High`
- Security control exists but is incomplete on critical flows (for example auth missing on sensitive endpoints).
- Unsafe transport/configuration in sensitive integrations (for example disabled encryption/trust-all certs).
- Delivery/process gap likely to permit high-impact regressions (for example no release gating for critical paths).

3. `Medium`
- Material debt or control gaps without immediate exploit/incident path.
- Inconsistent governance or quality controls with moderate operational impact.

4. `Low`
- Preventive, hygiene, or cosmetic improvements with low short-term impact.

## Tie-breaker

- If uncertain between two levels, choose the lower level unless there is evidence of production-path impact.
