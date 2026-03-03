# Architecture Criteria

Use this checklist for architecture reviews.

## Scope

- Assess structural quality, sustainability, and scalability.
- Focus on modules with high business impact first.

## Checklist

- Module responsibilities are clear and cohesive.
- Domain logic is separated from infrastructure concerns.
- Contracts between modules/services are explicit and stable.
- Circular dependencies are absent.
- Coupling is controlled and dependency direction is intentional.
- Error handling is consistent and failure boundaries are clear.
- Uncontrolled global state is avoided.
- Extensibility is acceptable for planned feature growth.
- Structural bottlenecks (CPU, memory, I/O, connection limits) are identified.
- Caching/scaling strategies are evaluated when relevant.

## Scoring

- Score: 0 to 5.
- Critical unmitigated architecture issue caps score at 2.

## Severity guidance

- Critical: Active risk to availability/data integrity/security from architecture.
- High: Fragility in critical modules or strong coupling blocking evolution.
- Medium: Moderate debt without immediate production impact.
- Low: Preventive or cosmetic architectural improvements.
