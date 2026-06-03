# Glosario de Dominio — AudFact

> Términos del negocio usados en el código y la base de datos. Referencia para que cualquier agente entienda el contexto del proyecto.
> **Contexto completo de negocio**: ver [`BUSINESS.md`](../BUSINESS.md) en la raíz del repositorio para entender el dominio, la cadena de dispensación, las reglas de auditoría y el modelo POS/MIPRES.

## Entidades principales

| Término | Significado | Tabla/Vista en BD | Campo clave |
|---|---|---|---|
| **Factura** | Documento de cobro emitido por la farmacia a la EPS | `dbo.factura` | `FacSec`, `FacNro` |
| **Dispensa / Dispensación** | Acto de entregar medicamentos a un paciente bajo una fórmula médica. Una factura puede tener múltiples dispensaciones | `vw_discolnet_dispensas` | `Dispensa` (= `DisDetNro`), `facsecF` (= `Factura.FacSec`) |
| **Cliente / EPS** | Entidad Promotora de Salud que contrata los servicios. Es el "cliente" del sistema | `Clientes`, `NIT` | `NitSec`, `NitCom` |
| **Paciente** | Persona que recibe los medicamentos dispensados | (dentro de la dispensa) | `Paciente_doc`, `Paciente_doct` |
| **Attachment / Adjunto** | Documento digitalizado asociado a una dispensa (fórmula médica, autorización, acta de entrega) | Modelo `AttachmentsModel` | `attachmentId` |
| **Auditoría IA** | Proceso automatizado donde Google Gemini analiza una factura y sus documentos adjuntos para detectar inconsistencias, fraude o errores administrativos | `AudDispEst` | `EstAud` |

## Identificadores

| Campo | Significado | Ejemplo |
|---|---|---|
| `FacSec` | Llave canónica de auditoría; equivale a `Factura.FacSec`, `vw_discolnet_dispensas.facsecF` y `AudDispEst.FacSec` | `89549114` |
| `FacNro` | En `AudDispEst`, almacena el `DisDetNro` auditado para búsqueda operativa | `D19251100113` |
| `FacNitSec` | ID del cliente/EPS asociado a la factura | `1165` |
| `DisDetNro` | Número del detalle de dispensación (= `Dispensa`) | `D19251100113` |
| `facsecF` | Campo de la FDV que debe mapearse como `FacSec` canónico | `89549114` |
| `facsec` | Identificador legacy/de agrupación en la FDV; no usar como llave de auditoría | `DIS26-...` |
| `NitSec` | ID secuencial del NIT en el sistema | `1165` |
| `NitCom` | Número de NIT comercial de la EPS | `ENTIDAD PROMOTORA DE SALUD SANITAS S.A.S.` |
| `DisId` | ID de la dispensación vinculada a la factura | `89549114` |

## Términos médicos y regulatorios

| Término | Significado |
|---|---|
| **NIT** | Número de Identificación Tributaria (Colombia) |
| **IPS** | Institución Prestadora de Salud |
| **CUM** | Código Único de Medicamento (registro INVIMA Colombia) |
| **CIE** | Clasificación Internacional de Enfermedades (código diagnóstico) |
| **Mipres** | Sistema de prescripción electrónica del Ministerio de Salud de Colombia |
| **Copago** | Valor que paga el paciente directamente |
| **Autorización** | Número aprobado por la EPS para la dispensación |
| **Acta de Entrega** | Documento firmado por el paciente al recibir medicamentos (obligatorio) |
| **Fórmula Médica** | Prescripción del médico que autoriza la entrega de medicamentos |
| **Lote** | Identificador del lote de fabricación del medicamento |

## Pipeline de auditoría

| Término | Significado |
|---|---|
| **Auditoría batch** | Proceso que analiza múltiples facturas en una sola solicitud |
| **DocumentAuditOrchestrator** | Worker que orquesta la resolución de FDV, adjuntos y construcción del contrato Gemini para extracción |
| **extraction_contract** | Contrato dinámico por documento con function calls según necesidad: `extract_fields`, `extract_items` y `detect_visual_checks` solo cuando aplican; `assess_document_quality` siempre |
| **DocumentPolicyEngine** | Motor determinista PHP que evalúa discrepancias, severidades y emite hallazgos contra rules del audit-config |
| **EstAud** | Campo en `AudDispEst` que almacena el estado de la auditoría |
