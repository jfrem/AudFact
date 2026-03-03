# Smoke Tests de Routing (Manual)

Objetivo: validar rapidamente que `audit-skill-router` selecciona el skill correcto y mantiene contrato de salida unificado.

## Como ejecutar

1. Envia el prompt de prueba.
2. Verifica skill(s) seleccionados contra "Ruta esperada".
3. Verifica salida minima:
   - `## Findings`
   - `## Domain Scoring`
   - `## Global Result`
   - `## Missing Evidence`
   - `## Structured Output`
4. Marca `PASS` si cumple todo; si no, `FAIL` con causa.

## Casos (5)

### Caso 1 - Dominio unico (arquitectura)

- Prompt:
  - `Revisa la arquitectura del backend: acoplamiento, limites de modulos y cuellos de botella.`
- Ruta esperada:
  - `architecture-assessment`
- Criterio de aprobacion:
  - Solo 1 dominio seleccionado.

### Caso 2 - Dominio unico (seguridad)

- Prompt:
  - `Haz una auditoria de seguridad para release readiness, con foco en secretos y vulnerabilidades.`
- Ruta esperada:
  - `security-assessment`
- Criterio de aprobacion:
  - Solo 1 dominio seleccionado.

### Caso 3 - Dos dominios explicitos

- Prompt:
  - `Necesito arquitectura y calidad de codigo antes de empezar un refactor grande.`
- Ruta esperada:
  - `architecture-assessment` + `code-quality-assessment`
- Criterio de aprobacion:
  - Solo esos 2 dominios, sin seguridad ni gobernanza.

### Caso 4 - Full audit explicito

- Prompt:
  - `Realiza una auditoria completa end-to-end con score global y clasificacion A/B/C/D.`
- Ruta esperada:
  - `architecture-assessment` + `code-quality-assessment` + `security-assessment` + `technical-governance-assessment`
- Criterio de aprobacion:
  - 4 dominios en orden de orquestacion oficial.

### Caso 5 - Gobernanza tecnica

- Prompt:
  - `Evalua nuestra madurez tecnica: ownership, politica de code review, proceso de incidentes y alineacion de roadmap.`
- Ruta esperada:
  - `technical-governance-assessment`
- Criterio de aprobacion:
  - Solo 1 dominio seleccionado.

## Plantilla de resultado

| Caso | Esperado | Obtenido | Estado | Notas |
| --- | --- | --- | --- | --- |
| 1 | architecture-assessment |  |  |  |
| 2 | security-assessment |  |  |  |
| 3 | architecture-assessment + code-quality-assessment |  |  |  |
| 4 | architecture-assessment + code-quality-assessment + security-assessment + technical-governance-assessment |  |  |  |
| 5 | technical-governance-assessment |  |  |  |

