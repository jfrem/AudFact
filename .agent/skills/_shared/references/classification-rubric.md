# Global Classification Rubric

Use this rubric for any output that includes `Classification: A|B|C|D`.
Use global weights from [global-scoring-weights.md](global-scoring-weights.md) when computing cross-domain score.
Use finding IDs from [finding-id-convention.md](finding-id-convention.md).
Use deterministic scoring protocol from [deterministic-scoring.md](deterministic-scoring.md).
Use severity assignment from [severity-matrix.md](severity-matrix.md).

## Score bands

- A: 4.0-5.0
- B: 3.0-3.9
- C: 2.0-2.9
- D: 0.0-1.9

## Ceiling rule

- If any selected domain has an active cap rule applied, classification cannot exceed `C`.
- If two or more selected domains have active cap rules applied, classification cannot exceed `D`.

## Notes

- Apply one-decimal rounding unless the user requests a different precision.
- State explicitly whether the ceiling rule was applied.
- Always provide scoring trace in rationale: controls passed/partial/failed and cap trigger (if any).
