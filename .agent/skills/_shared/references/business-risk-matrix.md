# Business Risk Matrix (Technical x Business)

Use this matrix to convert technical findings into execution priority.
This does not replace severity; it complements it for planning.

## Inputs

- Technical impact: `High|Medium|Low`
- Business impact: `High|Medium|Low`

## Priority matrix

| Technical Impact | Business Impact | Priority | Guidance |
|------------------|-----------------|----------|----------|
| High | High | P0 | Immediate action. Block release if production-critical. |
| High | Medium | P1 | Execute in current cycle with explicit owner. |
| High | Low | P2 | Plan as structural debt reduction. |
| Medium | High | P1 | Prioritize early due to business exposure. |
| Medium | Medium | P2 | Schedule in near-term backlog. |
| Medium | Low | P3 | Address opportunistically. |
| Low | High | P2 | Prioritize when tied to critical flows. |
| Low | Medium | P3 | Preventive improvement. |
| Low | Low | P3 | Hygiene/cosmetic work. |

## Rules

- If severity is `Critical`, minimum priority is `P1`.
- If severity is `High` and business impact is `High`, minimum priority is `P1`.
- For mixed-domain audits, use this matrix to order `## 30-60-90 Integrated Plan`.
- If technical or business impact is unknown, set temporary priority to `P2` and record evidence gap.
