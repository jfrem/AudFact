# Glosario de Dominio — AudFact

> Términos del negocio usados en el código y la base de datos. Referencia para que cualquier agente entienda el contexto del proyecto.

## Entidades principales

| Término | Significado | Tabla/Vista en BD | Campo clave |
|---|---|---|---|
| **Factura** | Documento de cobro emitido por la farmacia a la EPS | `dbo.factura` | `FacSec`, `FacNro` |
| **Dispensa / Dispensación** | Acto de entregar medicamentos a un paciente bajo una fórmula médica. Una factura puede tener múltiples dispensaciones | `vw_discolnet_dispensas` | `Dispensa` (= `DisDetNro`) |
| **Cliente / EPS** | Entidad Promotora de Salud que contrata los servicios. Es el "cliente" del sistema | `Clientes`, `NIT` | `NitSec`, `NitCom` |
| **Paciente** | Persona que recibe los medicamentos dispensados | (dentro de la dispensa) | `Paciente_doc`, `Paciente_doct` |
| **Attachment / Adjunto** | Documento digitalizado asociado a una dispensa (fórmula médica, autorización, acta de entrega) | Modelo `AttachmentsModel` | `attachmentId` |
| **Auditoría IA** | Proceso automatizado donde Google Gemini analiza una factura y sus documentos adjuntos para detectar inconsistencias, fraude o errores administrativos | `AudDispEst` | `EstAud` |

## Identificadores

| Campo | Significado | Ejemplo |
|---|---|---|
| `FacSec` | ID secuencial interno de la factura | `89549114` |
| `FacNro` | Número de factura visible | `D19251100113` |
| `FacNitSec` | ID del cliente/EPS asociado a la factura | `1165` |
| `DisDetNro` | Número del detalle de dispensación (= `Dispensa`) | `D19251100113` |
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
| **GeminiAuditService** | Servicio PHP que orquesta la comunicación con Google Gemini API |
| **AuditPromptBuilder** | Clase que construye los prompts y schemas para la API de Gemini |
| **JsonResponseParser** | Parsea las respuestas JSON de Gemini (pueden venir malformadas) |
| **JsonRepairHelper** | Intenta reparar JSON truncado o malformado de Gemini |
| **EstAud** | Campo en `AudDispEst` que almacena el estado de la auditoría |
