# Routing Rules

## Architecture signals

- architecture
- arquitectura
- design
- diseño
- modularity
- modularidad
- coupling
- acoplamiento
- scalability
- escalabilidad
- bottleneck
- cuello de botella

Route to: `architecture-assessment`

## Code quality signals

- maintainability
- mantenibilidad
- technical debt
- deuda tecnica
- deuda técnica
- code quality
- calidad de codigo
- calidad de código
- refactor priority
- prioridad de refactor
- complexity
- complejidad
- duplication
- duplicacion
- duplicación

Route to: `code-quality-assessment`

## Security signals

- security
- seguridad
- vulnerability
- vulnerabilidad
- auth
- authorization
- autorizacion
- autorización
- secrets
- secretos
- release readiness
- preparacion de release
- preparación de release

Route to: `security-assessment`

## Governance signals

- governance
- gobernanza
- ownership
- ownership claro
- responsables por modulo
- responsables por módulo
- code review policy
- politica de code review
- política de code review
- incident process
- proceso de incidentes
- maturity
- madurez
- roadmap alignment
- alineacion roadmap
- alineación roadmap

Route to: `technical-governance-assessment`

## Broad/full audit signals

- full audit
- auditoria completa
- auditoría completa
- end-to-end audit
- auditoria end-to-end
- auditoría end-to-end
- complete assessment
- evaluacion completa
- evaluación completa
- global score
- score global
- A/B/C/D classification
- clasificacion A/B/C/D
- clasificación A/B/C/D

Route to: `architecture-assessment` + `code-quality-assessment` + `security-assessment` + `technical-governance-assessment`

## Tie-breaker

- If two domains are clearly requested, select both.
- If three or more domains are strongly signaled in an audit-oriented request, select all strongly signaled domains.
- If broad/full-audit signals are present, route to all four domains.
- If signals are weak or conflicting, route to the single strongest-signal domain only.
