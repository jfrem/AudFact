# Hallazgos de Auditoría Conocidos — AudFact

> Referencia rápida de problemas documentados.

| ID | Severidad | Descripción | Estado |
|---|---|---|---|
| C01 | 🔴 Crítico | `exit()` en `Response.php` y `Controller.php` | ✅ Resuelto (2026-02-22) |
| C02 | 🔴 Crítico | Rate limiting basado en archivo (no escala) | ✅ Resuelto (2026-02-22) |
| C03 | 🔴 Crítico | Auditoría secuencial sin timeout ni límite | ✅ Resuelto (2026-02-22) |
| C04 | 🔴 Crítico | BLOBs base64 sin límite de memoria | ✅ Resuelto (2026-02-22) |
| C05 | 🔴 Crítico | Webhook MCP sin autenticación | ✅ Resuelto (2026-02-22) |

Al implementar fixes, referenciar estos IDs en el commit message y actualizar esta tabla (ver protocolo de documentación arriba).
