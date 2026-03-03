# Skills de Auditoria Tecnica

Este directorio contiene un sistema de skills para auditorias tecnicas con scoring determinista, evidencia obligatoria y salida estructurada.

## Skills disponibles

| Skill | Uso principal |
| --- | --- |
| `audit-skill-router` | Enrutamiento de auditorias amplias, ambiguas o multi-dominio. |
| `architecture-assessment` | Arquitectura: modulos, acoplamiento, limites, escalabilidad. |
| `code-quality-assessment` | Calidad y mantenibilidad: complejidad, duplicacion, deuda, testabilidad. |
| `security-assessment` | Seguridad: auth/authz, secretos, vulnerabilidades, hardening, release controls. |
| `technical-governance-assessment` | Gobernanza tecnica: ownership, revisiones, incidentes, deuda, roadmap. |

## Regla de entrada recomendada

1. Si la solicitud es de auditoria completa o ambigua, usa `audit-skill-router`.
2. Si el usuario pide un solo dominio, usa solo el skill de ese dominio.
3. Si el usuario pide dos dominios concretos, usa solo esos dos.
4. Si pide "full audit", "complete assessment", "global score" o "A/B/C/D", enruta a los cuatro dominios.

## Orden de orquestacion (full audit)

1. `architecture-assessment`
2. `code-quality-assessment`
3. `security-assessment`
4. `technical-governance-assessment`

## Salida esperada

- Reporte unificado (no reportes aislados por dominio).
- Scoring por dominio (0-5) con caps aplicados cuando corresponda.
- Global score ponderado y clasificacion `A|B|C|D`.
- Lista de findings con IDs, severidad, evidencia y prioridad.
- Seccion `## Structured Output` con JSON para consumo de maquina.

## Referencias compartidas clave

- `./_shared/references/deterministic-scoring.md`
- `./_shared/references/global-scoring-weights.md`
- `./_shared/references/classification-rubric.md`
- `./_shared/references/severity-matrix.md`
- `./_shared/references/business-risk-matrix.md`
- `./_shared/references/evidence-validation.md`
- `./_shared/references/orchestration-contract.md`

## Uso rapido (prompts)

- `Haz una auditoria completa del repo con score global y clasificacion A/B/C/D.`
- `Revisa arquitectura y calidad de codigo antes de un refactor.`
- `Necesito una auditoria de seguridad para readiness de release.`
- `Evalua la madurez de gobernanza tecnica del equipo.`

