# Hallazgos de Auditoría Conocidos — AudFact

> Referencia rápida de problemas documentados.

| ID | Severidad | Descripción | Estado |
|---|---|---|---|
| C01 | 🔴 Crítico | `exit()` en `Response.php` y `Controller.php` | ✅ Resuelto (2026-02-22) |
| C02 | 🔴 Crítico | Rate limiting basado en archivo (no escala) | ✅ Resuelto (2026-02-22) |
| C03 | 🔴 Crítico | Auditoría secuencial sin timeout ni límite | ✅ Resuelto (2026-02-22) |
| C04 | 🔴 Crítico | BLOBs base64 sin límite de memoria | ✅ Resuelto (2026-02-22) |
| C05 | 🔴 Crítico | Webhook MCP sin autenticación | ✅ Resuelto (2026-02-22) |
| INFRA-REDIS-01 | 🔴 Crítico | Consumer group creado con `'$'` podía dejar invisible un evento ya publicado y congelar la auditoría en `pending` | ✅ Resuelto (2026-04-24) |
| INFRA-REDIS-02 | 🔴 Crítico | `NOGROUP` se absorbía en `XREADGROUP`, permitiendo loops silenciosos sin consumo real del stream | ✅ Resuelto (2026-04-24) |
| REF-01 | 🟡 Importante | `/audit/results` mezclaba resumen, detalle, raw/debug y legacy | ✅ Resuelto (2026-05-20) |
| REF-02 | 🟡 Importante | Frontend consumía estructuras internas en vez de contrato público estable | ✅ Resuelto (2026-05-20) |
| REF-03 | 🟡 Importante | Página de detalle buscaba localmente en listados paginados | ✅ Resuelto (2026-05-20) |
| REF-04 | 🟡 Importante | `AuditAggregationWorker` contenía lógica funcional de decisión | ✅ Resuelto (2026-05-20) |
| REF-05 | 🟡 Importante | Schemas legacy ocultaban estados reales como `NO_CONCLUYENTE` y `OMITIDO` | ✅ Resuelto (2026-05-20) |

Al implementar fixes, referenciar estos IDs en el commit message y actualizar esta tabla (ver protocolo de documentación arriba).
