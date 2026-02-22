# Decisiones de Arquitectura (ADR)

## Qué es un ADR

Un **Architecture Decision Record** documenta una decisión técnica significativa con su contexto y consecuencias. Sirve para que cualquier agente o desarrollador futuro entienda el **por qué** detrás de una decisión, no solo el **qué**.

## Cuándo crear un ADR

- Cambio de tecnología o framework (ej: agregar Redis, migrar a PHPUnit)
- Decisión de diseño que afecta múltiples componentes (ej: refactorizar rate limiting)
- Trade-offs importantes (ej: base de datos de archivos vs. APCu para rate limiting)
- Rechazo de una alternativa (documentar por qué NO se eligió)

## Template de ADR

Almacenar en `plans/adr/` con el formato `ADR-NNN-titulo.md`:

```markdown
# ADR-NNN: [Título de la Decisión]

**Fecha**: YYYY-MM-DD
**Estado**: Propuesto | Aceptado | Rechazado | Obsoleto
**Hallazgo relacionado**: [ID si aplica, ej: C02]

## Contexto
[Qué problema o necesidad motivó esta decisión]

## Decisión
[Qué se decidió hacer]

## Alternativas consideradas

### Alternativa A: [nombre]
- Pros: ...
- Contras: ...

### Alternativa B: [nombre]
- Pros: ...
- Contras: ...

## Consecuencias
- [Impacto positivo]
- [Impacto negativo o trade-off]
- [Acciones de seguimiento]
```

## ADRs existentes (implícitos, por documentar)

| Decisión | Contexto | Estado |
|---|---|---|
| PHP MVC custom en lugar de Laravel/Symfony | Proyecto legacy con requerimientos específicos de SQL Server | Aceptado (implícito) |
| SQL Server como BD (no MySQL/PostgreSQL) | Integración con sistema existente Discolnet | Aceptado (implícito) |
| Google Gemini API para auditoría IA | Capacidad multimodal necesaria para analizar documentos escaneados | Aceptado (implícito) |
| Docker solo para desarrollo local | Infraestructura de producción actual no soporta contenedores | Aceptado |
