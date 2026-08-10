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
| Docker solo para desarrollo local | Infraestructura de producción actual no soporta contenedores | **Obsoleto** — ver ADR de producción abajo |

## ADR: Producción Docker en LAN con Runner Self-Hosted

**Fecha**: 2026 (fecha exacta por verificar)  
**Estado**: Aceptado  
**Sustituye**: ADR "Docker solo para desarrollo local" (marcado Obsoleto)

### Contexto

Durante el desarrollo del pipeline event-driven (Redis Streams, 27 workers) quedó claro que Docker Compose es la única forma práctica de orquestar el clúster de workers en producción. El servidor LAN (`admon@172.16.0.3`) soporta Docker y Docker Compose.

### Decisión

Productividad LAN usa Docker Compose con imágenes inmutables publicadas en GHCR (`ghcr.io/jfrem/audfact-*`). El deploy se ejecuta automáticamente mediante un runner self-hosted GitHub Actions (`audfact-prod-lan`) que:
1. Descarga las imágenes publicadas por el workflow `publish-images.yml`.
2. Escribe el `.env` desde GitHub Secrets.
3. Ejecuta `docker compose --profile frontend up -d --no-build --remove-orphans`.
4. Elimina el código fuente del host después del deploy exitoso (Zero-Source).

### Consecuencias

- El host de producción solo contiene: `.env`, `docker-compose.yml` y `logs/`.
- El código vive dentro de las imágenes GHCR inmutables.
- Rollback = cambiar `AUDFACT_IMAGE_TAG` al SHA anterior y re-ejecutar el deploy.

## Decisiones de diseño documentadas en `architecture.md`

Ver la tabla **"Decisiones de Diseño"** en [`plans/architecture.md`](./architecture.md#decisiones-de-diseño) para las 12 decisiones técnicas activas con justificación completa (Framework PHP custom, PDO sqlsrv, un turno SQL por job, Gemini Flash, Dual storage, MCP como capa separada, Docker multi-container, Load Balancing least\_conn, PHP-FPM Static Pool, Nginx Bundle Assets, Zero-Source Host, PHP Artifact Purge).

---

## Deuda Técnica Activa

### TODO #3 — AuthMiddleware + JWT para rutas `/audit/*`

**Estado:** ⚠️ PENDIENTE — documentado en [`app/Routes/web.php:32`](../app/Routes/web.php#L32)

**Contexto:** Las rutas de auditoría (`/audit/results`, `/audit/single`, `/audit/async`, etc.) no tienen middleware de autenticación. El router soporta `->middleware('auth')` pero la implementación de `AuthMiddleware` y el sistema JWT no existen aún.

**Referencia en código:**
```php
// TODO: FIX #3 PENDIENTE — Aplicar ->middleware('auth') cuando se implemente AuthMiddleware + JWT
```

**Consecuencias actuales:**
- Cualquier cliente con acceso a la red puede iniciar auditorías (`POST /audit/async`).
- El único mecanismo de protección operativo es la red (Docker LAN) y el rate limiting por IP (Redis).
- El webhook MCP sí tiene autenticación (`X-API-KEY` / `MCP_WEBHOOK_SECRET`).

**Desbloqueante:** Implementar `Core\AuthMiddleware` con validación JWT y actualizar `web.php` para aplicar `->middleware('auth')` a todas las rutas `/audit/*`.

