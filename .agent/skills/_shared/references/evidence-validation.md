# Evidence Validation Protocol

Use this protocol in all domain and routed audits to keep findings verifiable.

## Evidence requirements

- Every finding must include machine-checkable evidence in `path:line` format.
- Include `Evidence Command` for each finding (for example `rg -n "pattern" src/service.ts`).
- `Critical` and `High` findings require at least two independent evidence references.

## Validation rule

- If a finding has missing or non-verifiable evidence, lower severity by one level.
- If severity is already `Low` and evidence remains non-verifiable, remove the finding and record it under `## Missing Evidence`.

## Pre-scoring gate

- Before computing domain/global scores, produce the `## Missing Evidence` section.
- Mark controls affected by evidence gaps as `0.5` per deterministic scoring.

## Evidence command format

- Command must be reproducible in repository context.
- Prefer read-only commands (`rg`, `Get-Content`, `git show`, `npm ls`).
- Do not use paraphrased evidence without a command and location reference.
