# AudFact — Contexto de Negocio

> **Lectura OBLIGATORIA** antes de cualquier implementación que involucre reglas de negocio,
> validaciones, pipeline de auditoría o lógica de dominio.
>
> Este documento explica **por qué** existe AudFact, **qué problema resuelve** y **qué reglas
> del dominio** deben respetarse en cada decisión técnica.

---

## 1. Quiénes Somos

**Discolmets** es un gestor farmacéutico con presencia en casi todo el territorio nacional colombiano. Su actividad principal es **dispensar medicamentos e insumos médicos** a la población afiliada de las Entidades Promotoras de Salud (EPS).

- **Rol en la cadena de salud**: Discolmets actúa como operador logístico y farmacéutico entre el médico que prescribe y el paciente que recibe.
- **Modelo de negocio**: Discolmets dispensa y luego **factura a la EPS** correspondiente. La EPS paga solo si la documentación soporte es correcta y completa.
- **Riesgo principal**: Si la documentación tiene errores, inconsistencias o está incompleta, la EPS **glosa** (objeta) la factura y Discolmets no cobra.

---

## 2. El Problema que Resuelve AudFact

Cada dispensación genera un expediente documental (fórmula médica, autorización, acta de entrega). Antes de radicar la factura ante la EPS, Discolmets debe verificar que:

1. **Lo dispensado sea lo formulado** — el medicamento entregado coincide con lo que el médico prescribió.
2. **Lo formulado esté autorizado** (si aplica) — la EPS aprobó la entrega mediante una autorización válida y vigente.
3. **Lo autorizado sea lo entregado** — la cadena Fórmula → Autorización → Acta de Entrega es consistente.
4. **No haya fraude ni alteración** — los documentos son auténticos, no están manipulados.
5. **No haya errores humanos** — datos del paciente, diagnóstico, cantidades y fechas son correctos.
6. **Los documentos correspondan a la entrega** — no se adjuntaron documentos de otro paciente o dispensación.
7. **La entrega se haya realizado en tiempo** — dentro de la vigencia de la autorización.
8. **Los documentos estén vigentes** — fórmulas no vencidas, autorizaciones no expiradas.

**Sin AudFact**: auditores humanos revisan manualmente miles de expedientes por mes. Es lento, costoso y propenso a errores.

**Con AudFact**: Google Gemini (IA multimodal) extrae datos de los documentos escaneados y el motor de reglas PHP los compara automáticamente contra la base de datos de dispensación (fuente de verdad). **Resultado**: reducción de glosas en la radicación de facturas.

---

## 3. Actores del Dominio

| Actor | Rol | Relación con AudFact |
|---|---|---|
| **Discolmets** | Gestor farmacéutico. Dispensa y factura | Operador del sistema. Usuario interno |
| **EPS** (Cliente) | Entidad Promotora de Salud. Contrata la dispensación y paga facturas | Destinatario de la factura. 22 EPS activas actualmente |
| **Paciente** | Persona que recibe los medicamentos | Sujeto de la dispensación. Sus datos son protegidos (Habeas Data) |
| **Médico / Prescriptor** | Profesional de salud que emite la fórmula médica | Su firma y datos aparecen en la fórmula. Se validan en auditoría |
| **IPS** | Institución Prestadora de Salud donde se prescribe | Aparece en los documentos como origen de la prescripción |
| **Auditor interno** | Personal de Discolmets que revisa hallazgos | Usuario principal del dashboard. Decide sobre casos `manual_review` |

---

## 4. Cadena de Dispensación

El flujo de negocio sigue esta secuencia obligatoria:

```
Médico prescribe          Paciente recibe         Discolmets cobra
      │                         │                        │
      ▼                         ▼                        ▼
┌──────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────┐
│ FÓRMULA  │──▶│ AUTORIZACIÓN │──▶│    ACTA DE    │──▶│ FACTURA  │
│  MÉDICA  │   │   (si aplica)│   │   ENTREGA    │   │  A EPS   │
└──────────┘   └──────────────┘   └──────────────┘   └──────────┘
    ORD/OPF        AUT/PDE            ANE/CRC           FacNro
```

### 4.1 Prescripción (Fórmula Médica)
- El médico prescribe medicamentos al paciente.
- Genera la **Fórmula Médica** con: paciente, diagnóstico (CIE), medicamentos, cantidades, firma del prescriptor.
- Alias POS: `ORD` · Alias MIPRES: `OPF`

### 4.2 Autorización (si aplica)
- La EPS autoriza la dispensación mediante un número de autorización.
- Contiene: paciente, diagnóstico, medicamento autorizado, vigencia.
- **Regla de vigencia**: la entrega debe realizarse dentro de los días de vigencia desde la fecha de autorización (generalmente 60 días, configurable por EPS).
- Alias POS: `AUT` · Alias MIPRES: `PDE`

### 4.3 Dispensación y Entrega (Acta de Entrega)
- Discolmets entrega los medicamentos al paciente o su acudiente.
- El paciente **firma el acta de entrega** como constancia de recepción.
- Contiene: todos los datos del expediente + firma + fecha de entrega + cantidades + lotes.
- Alias POS: `ANE` (Dispensa) · Alias MIPRES: `CRC` (Acta de Entrega)

### 4.4 Facturación
- Discolmets agrupa dispensaciones en facturas y las radica ante la EPS.
- **AudFact audita ANTES de la radicación** para detectar y corregir problemas que generarían glosa.

---

## 5. Tipos de Servicio: POS vs MIPRES

El sistema audita actualmente dos tipos de servicio. Cada uno tiene documentación y reglas distintas:

### POS (Plan Obligatorio de Salud)
- Medicamentos incluidos en el plan básico de cobertura.
- **Documentos típicos**: Fórmula (`ORD`), Autorización (`AUT`), Dispensa (`ANE`).
- **Campos clave**: `CUM` (Código Único de Medicamento) presente, `Mipres` vacío.
- IDs de trazabilidad Mipres: todos en `"0"`.

### MIPRES (Mi Prescripción)
- Medicamentos o dispositivos **NO incluidos** en el plan básico, prescritos vía el sistema electrónico del Ministerio de Salud.
- **Documentos típicos**: Fórmula (`OPF`), Autorización (`PDE`), Acta de Entrega (`CRC`), y posibles adicionales: Validador de Derechos (`PDE`), Testigo a Ruego (`FDE`).
- **Campos clave**: `Mipres` con número de prescripción (ej: `20251022157002502904`), `CUM` puede estar vacío (dispositivos médicos).
- IDs de trazabilidad Mipres completos: `IdPrincipal`, `IdDirec`, `IdProg`, `IdEntr`, `IdRepEnt`, `IdFact`.
- Puede incluir **Régimen** del paciente (Contributivo / Subsidiado).

### Diferencias documentales

| Aspecto | POS | MIPRES |
|---|---|---|
| Alias Acta de Entrega | `ANE` ("DISPENSA") | `CRC` ("ACTA DE ENTREGA") |
| Alias Autorización | `AUT` | `PDE` |
| Alias Fórmula | `ORD` | `OPF` |
| Documentos adicionales | — | Validador de Derechos, Testigo a Ruego |
| CUM | Siempre presente | Puede estar vacío (dispositivos) |
| Número Mipres | Vacío | Obligatorio |
| IDs trazabilidad Mipres | Todos `"0"` | Todos con valor real |

---

## 6. Los 22 Clientes (EPS)

Discolmets atiende actualmente 22 clientes configurados en el sistema. La lista incluye EPS, aseguradoras y entidades territoriales. Algunos manejan ambos servicios (POS + MIPRES), otros solo uno.

Cada cliente define:
- **Catálogo de documentos obligatorios** — qué documentos se exigen por dispensación (endpoint `/clients/{id}/documents`).
- **Configuración de auditoría** — qué campos auditar, con qué tipo de comparación y qué severidad (endpoint `/clients/{id}/audit-config`).

> La configuración de auditoría es **por cliente**, no global. Esto significa que las reglas pueden variar de una EPS a otra.

---

## 7. Qué Audita el Sistema

### 7.1 Fuente de Verdad (FDV)

La **Fuente de Verdad** es el registro de dispensación almacenado en SQL Server (vista `vw_discolnet_dispensas`). Contiene los datos que el sistema de dispensación (`Discolnet`) registró al momento de la entrega. Contra esta fuente se comparan los datos extraídos de los documentos escaneados.

### 7.2 Tipos de Comparación

Cada campo se audita con un tipo de comparación específico, definido en el `audit-config` del cliente:

| Tipo | Código | Descripción | Ejemplo |
|---|---|---|---|
| **Exacto** | `E` | Debe coincidir carácter a carácter (normalizado) | `DocumentoPaciente`: `"12132213"` vs `"12132213"` |
| **Semántico** | `S` | Similitud textual — Gemini juzga equivalencia | `NombrePaciente`: `"GARCIA ABSALON"` vs `"ABSALON GARCIA"` |
| **Business** | `B` | Lógica de negocio — PHP calcula sumatorias y límites | `CantidadEntregada` ≤ `CantidadAutorizada` / `CantidadPrescrita` |
| **Visual** | `V` | Verificación visual en la imagen del documento | `FirmaActaEntrega`: PRESENTE / AUSENTE |

### 7.3 Configuración Runtime de Campos

El `audit-config` vigente no persiste roles por campo. Cada fila activa de `AudDispCampo`
define si un campo se evalúa mediante `CampoNombre`, `TipoCampo`, `Orden`,
`TipoDato`, `SeveridadOverride` y, para visuales, `DescripcionOverride`. Si un campo
está activo en `fields`, el `DocumentPolicyEngine` lo evalúa; no existe hoy una marca
runtime `INFORMATIVO` para excluirlo de la decisión.

Implicación operativa: un campo como `NombreArticulo` configurado con `TipoCampo = S`
dispara comparación semántica y puede usar `ArticleSemanticMatchJudge` como fallback
Gemini cuando las heurísticas locales no alcanzan el umbral. Ese fallback queda
limitado a campos con `TipoDato = article_name`; nombres de pacientes, IPS u otros
textos semánticos se resuelven con reglas locales determinísticas.

`TipoCampo` define la estrategia de comparación (`E`, `S`, `B`, `V`). `TipoDato`
define cómo se normaliza, extrae y compara el valor. Los tipos vigentes son:
`text`, `date`, `quantity`, `money`, `identity_doc_type`, `identity_doc_number`,
`code`, `trace_token`, `person_name`, `institution_name` y `article_name`.

Para cantidades configuradas con `TipoCampo = B`, Gemini solo extrae valores visibles.
La regla la aplica PHP: suma ítems del documento/FDV y valida el límite documental según
el tipo de soporte. En autorización o fórmula, la cantidad visible funciona como techo
autorizado/prescrito; en dispensa, la cantidad debe reflejar lo efectivamente entregado.

### 7.4 Resultados Posibles por Campo

| Resultado | Significado | Acción |
|---|---|---|
| `COINCIDE` | El valor del documento coincide con la FDV | ✅ Sin hallazgo |
| `VALOR_DISTINTO` | El valor difiere — posible error o fraude | 🔴 Hallazgo reportado |
| `NO_ENCONTRADO` | Gemini no pudo extraer el campo del documento | ⚠️ Hallazgo — documento puede estar incompleto o ilegible |
| `NO_CONCLUYENTE` | Gemini encontró similitud parcial pero no puede confirmar | 🟡 Requiere revisión humana |
| `OMITIDO` | Campo sin valor auditable o no evaluado por condición interna del engine; `omitirSi` no existe en el runtime actual | ➖ No evaluado |

### 7.5 Visual Checks (Verificaciones Visuales)

| Check | Documento | Qué verifica |
|---|---|---|
| `FirmaActaEntrega` | Acta de Entrega / Dispensa | Firma o sello del paciente/acudiente que recibió |
| `FirmaPrescriptor` | Fórmula Médica | Firma del médico que prescribió |
| `VigenciaEntrega` | Autorización | Que la entrega se hizo dentro de los días de vigencia desde la autorización |

### 7.6 Decisión Final por Documento

Cada documento recibe un veredicto:

| Veredicto | Condición |
|---|---|
| `approved: true` | Ningún hallazgo del documento tiene resultado fallido (`VALOR_DISTINTO`, `NO_ENCONTRADO` o `NO_CONCLUYENTE`) |
| `approved: false` | Al menos un hallazgo del documento tiene resultado fallido (`VALOR_DISTINTO`, `NO_ENCONTRADO` o `NO_CONCLUYENTE`) |

### 7.7 Estado Final de la Auditoría

| Estado | Significado |
|---|---|
| `completed` | Todos los documentos aprobados, sin hallazgos |
| `manual_review` | Al menos un documento con hallazgos que requieren revisión humana |
| `error` | Error técnico durante el procesamiento (IA, descarga, etc.) |
| `failed` | Fallo irrecuperable — no se pudo completar la auditoría |

---

## 8. Ejemplo Real: Golden Case (`T38250701547`)

Auditoría POS para Positiva Compañía de Seguros. Paciente: Garcia Absalon. Producto: Gasa estéril.

### Resultado

| Métrica | Valor |
|---|---|
| Estado final | `manual_review` |
| Documentos procesados | 3 (Fórmula, Autorización, Dispensa) |
| Total campos auditados | 36 |
| Coincidencias | 34 |
| Discrepancias | 1 (`CodigoDiagnostico` NO_ENCONTRADO en fórmula) |
| No concluyentes | 1 (`NombreArticulo` en fórmula) |
| Risk Score | 20 |
| Duración | ~14.6 segundos |

### Veredicto por documento

| Documento | Aprobado | Observación |
|---|---|---|
| DISPENSA | ✅ Sí | Todos los campos coinciden |
| AUTORIZACION | ✅ Sí | Cantidad autorizada `100` cubre entrega parcial `50`; datos clave coinciden |
| FORMULA MEDICA | ❌ No | `CodigoDiagnostico` no encontrado y `NombreArticulo` NO_CONCLUYENTE |

### Verificaciones especiales exitosas
- **VigenciaEntrega**: FechaAutorización `2025-07-27` + 60 días = `2025-09-25`. Entrega `2025-07-29` ✅ dentro de vigencia.
- **FirmaActaEntrega**: `PRESENTE` — firma manuscrita visible ✅
- **FirmaPrescriptor**: `PRESENTE` ✅
- **Entrega parcial autorizada**: Entregada `50` ≤ Autorizada `100` ✅
- **Cantidades en dispensa**: Entregada `50` = registrada `50` ✅
- **Trazabilidad**: Lotes `02041804-25` y `02041806-25` coinciden como set completo ✅

### Caso complementario: trazabilidad multi-lote (`X24260100121`)

Este caso valida que la trazabilidad de producto no se compare como un string plano
y que el pipeline post-refactor preserve las métricas de Gemini en el resultado
persistido. El campo `Lote` se clasifica como `TRACE_TOKEN` y se evalúa con
lógica de conjuntos.

| Métrica | Valor |
|---|---|
| Estado final | `manual_review` |
| Documentos procesados | 3 (Fórmula, Autorización, Dispensa) |
| Total campos auditados | 37 |
| Coincidencias | 33 |
| Discrepancias | 1 (`CodigoDiagnostico` NO_ENCONTRADO en `DISPENSA`) |
| No concluyentes | 3 (`NombreArticulo` en fórmula, `IPS` en dispensa, `NombreArticulo` en dispensa) |
| Risk Score | 40 |

Verificaciones clave:
- **Lote**: FDV `{645B01A, E245513E}` = documento `{645B01A, E245513E}` → `COINCIDE`.
- **Autorización**: aprobada; `CantidadEntregada` `60` coincide con el techo autorizado registrado.
- **Diagnóstico en acta**: el `audit-config` vigente del cliente `2426` activa `CodigoDiagnostico` en `DISPENSA`; Gemini no lo encuentra visible en el acta y el motor lo marca como `NO_ENCONTRADO`.
- **Dispensa**: no aprobada por `CodigoDiagnostico` ausente y revisión semántica de `IPS` y `NombreArticulo`, no por trazabilidad.
- **Métricas Gemini**: 3 llamadas de extracción, 2 llamadas semánticas, 5 llamadas remotas en total, `cache_hit_rate = 0` y tokens totales `20044`.

---

## 9. Reglas de Negocio (Invariantes del Dominio)

Estas reglas **siempre aplican** y no pueden ser modificadas sin aprobación explícita:

### Reglas de consistencia documental
1. **Identidad del paciente**: `DocumentoPaciente` + `TipoDocumentoPaciente` deben coincidir en TODOS los documentos del expediente y contra la FDV (registro de dispensación).
2. **Diagnóstico**: `CodigoDiagnostico` (CIE) debe coincidir entre fórmula, autorización y acta.
3. **Prescriptor**: `Medico` debe coincidir entre fórmula y acta de entrega.
4. **Número de autorización**: `NumeroAutorizacion` debe coincidir entre autorización y acta.

### Reglas de cantidades
5. **CantidadEntregada ≤ techo documental**: No se puede entregar más de lo formulado ni más de lo autorizado. Si hay autorización, `CantidadAutorizada` es techo; si no hay autorización, el techo es `CantidadPrescrita`.
6. **Entrega completa preferible, entrega parcial válida**: `CantidadEntregada = techo` es ideal; `CantidadEntregada < techo` es válida como entrega parcial; `CantidadEntregada > techo` es `VALOR_DISTINTO`.

### Reglas de vigencia y temporalidad
7. **Vigencia de autorización**: La entrega debe ocurrir dentro del plazo de vigencia desde la fecha de autorización (generalmente 60 días, configurable por EPS).
8. **Vigencia de fórmula**: La fórmula médica debe estar vigente al momento de la dispensación.
9. **Orden temporal**: `FechaFormula ≤ FechaAutorizacion ≤ FechaEntrega`.

### Reglas de completitud
10. **Firma del acta de entrega**: Obligatoria. Sin firma no hay constancia de recepción.
11. **Firma del prescriptor**: Obligatoria en la fórmula médica.
12. **Documentos obligatorios**: Según la configuración del cliente, todos los documentos deben estar presentes.

### Reglas de autenticidad
13. **Sin alteración**: Los documentos no deben mostrar señales de manipulación digital o física.
14. **Correspondencia**: Los documentos deben pertenecer a la misma dispensación — no se aceptan documentos de otro paciente o entrega.

---

## 10. Concepto de Glosa

**Glosa** es el rechazo total o parcial de una factura por parte de la EPS debido a inconsistencias en la documentación soporte. Las glosas representan pérdida directa de ingresos para Discolmets.

### Causas comunes de glosa que AudFact detecta

| Causa | Tipo de hallazgo | Severidad |
|---|---|---|
| Acta de entrega sin firma del paciente | Visual — `FirmaActaEntrega: AUSENTE` | Alta |
| Medicamento entregado ≠ medicamento autorizado | Semántico — `NombreArticulo: DISCREPANCIA` | Alta |
| Cantidad entregada > cantidad formulada/autorizada | Business — `CantidadEntregada: DISCREPANCIA` | Alta |
| Autorización vencida al momento de la entrega | Visual — `VigenciaEntrega: DISCREPANCIA` | Alta |
| Documento de otro paciente | Exacto — `DocumentoPaciente: DISCREPANCIA` | Alta |
| Diagnóstico no coincide entre documentos | Exacto — `CodigoDiagnostico: DISCREPANCIA` | Alta |
| Fórmula sin firma del médico | Visual — `FirmaPrescriptor: AUSENTE` | Alta |
| Campo ilegible o no extraíble del escaneo | Exacto — `campo: NO_ENCONTRADO` | Media |

---

## 11. Glosario de Negocio

> Para el glosario técnico con mapeo a tablas de BD, ver [`plans/domain-glossary.md`](plans/domain-glossary.md).

| Término | Significado |
|---|---|
| **Dispensación** | Acto de entregar medicamentos a un paciente bajo una fórmula médica |
| **Factura** | Documento de cobro que Discolmets emite a la EPS, agrupando dispensaciones |
| **EPS** | Entidad Promotora de Salud — cliente de Discolmets |
| **IPS** | Institución Prestadora de Salud — donde el médico prescribe |
| **Glosa** | Rechazo de una factura por la EPS debido a errores documentales |
| **Radicación** | Proceso de entregar la factura y sus soportes a la EPS para cobro |
| **POS** | Plan Obligatorio de Salud — medicamentos cubiertos por el plan básico |
| **MIPRES** | Mi Prescripción — sistema electrónico para medicamentos fuera del POS |
| **CUM** | Código Único de Medicamento (registro INVIMA Colombia) |
| **CIE** | Clasificación Internacional de Enfermedades (código diagnóstico) |
| **NIT** | Número de Identificación Tributaria (Colombia) |
| **Fórmula Médica** | Prescripción del médico que autoriza la entrega |
| **Autorización** | Aprobación de la EPS para que se realice la dispensación |
| **Acta de Entrega** | Documento firmado por el paciente al recibir los medicamentos |
| **FDV** | Fuente de Verdad — datos del sistema Discolnet en SQL Server |
| **Copago** | Valor que paga el paciente directamente (si aplica) |
| **Lote** | Identificador del lote de fabricación del medicamento |
| **Testigo a Ruego** | Persona que firma en nombre del paciente cuando este no puede |
| **Validador de Derechos** | Documento que confirma la afiliación activa del paciente a la EPS |
| **audit-config** | Configuración por cliente que define qué campos auditar y con qué reglas |
| **TipoDato** | Tipo explícito del valor auditable; gobierna schema Gemini, normalización y estrategia fina de comparación |
| **Risk Score** | Puntuación numérica de riesgo calculada por el motor de reglas |

---

## 12. Mapa Dominio → Código

Tabla puente entre conceptos de negocio y su implementación técnica:

| Concepto de Negocio | Implementación Técnica | Archivo(s) Clave |
|---|---|---|
| Dispensación | Vista `vw_discolnet_dispensas` | `app/Models/DispensationModel.php` |
| Factura | Campo `FacSec` / `FacNro` | `app/Models/InvoicesModel.php` |
| Documentos del expediente | Tabla `AdjuntosDispensacion` | `app/Models/AttachmentsModel.php` |
| Catálogo de documentos por EPS | Tabla `NitDocumentos` | `app/Models/AttachmentsModel.php` |
| Configuración de auditoría | Tablas `NitDocumentos` + `NitMedDoc*` | Endpoint `/clients/{id}/audit-config` |
| Extracción IA de documentos | Google Gemini API (multimodal) | `app/Services/Audit/Pipeline/DocumentExtractionWorker.php` |
| Comparación exacta / semántica | Motor de reglas PHP | `app/Services/Audit/Pipeline/DocumentPolicyEngine.php` |
| Verificaciones visuales | Gemini detecta + PHP decide | `DocumentPolicyEngine.php` + `RulesEvaluationWorker.php` |
| Resultado de auditoría | Tabla `AudDispEst` | `app/Models/AuditStatusModel.php` |
| Decisión por documento | `affected_documents` en resultado | `AuditAggregationWorker.php` |
| Vigencia de entrega | Cálculo PHP: `FechaAutorizacion + N días` | `RulesEvaluationWorker.php` |
| Glosa (prevención) | Hallazgos `DISCREPANCIA` / `NO_ENCONTRADO` | Dashboard frontend |
| Clientes (EPS) | Tablas `NIT` + `Clientes` | `app/Models/ClientsModel.php` |
