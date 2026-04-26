# Assessment AudFact — Pipeline de Auditoría v1.1

**Fecha:** 2026-04-25 · **Actualizado:** 2026-04-26 (F9 nuevo, corrección causa raíz F1, evidencia de 6 runs en vivo)
**Caso de referencia:** T38250701547 (POSITIVA · cliente NitSec 2426)
**Alcance:** Diagnóstico técnico, operativo y arquitectónico del pipeline de auditoría documental + hoja de ruta de mejora en 5 fases. Esta versión integra el análisis de **reglas de negocio diferenciadas por cliente** y la heterogeneidad documental observada entre EPS/ARL.
**Estado:** Documento vivo. Las fases referenciadas son orientativas; cada una debe planearse en detalle antes de ejecutarse.

---

## 1. Resumen ejecutivo

DISCOLMETS opera el pipeline de auditoría documental para validar **internamente** que sus funcionarios cumplen el procedimiento de entrega de medicamentos. La fuente de verdad (FDV) es el registro transaccional interno, y los soportes son los PDFs adjuntos por el funcionario al momento de la entrega.

El pipeline implementado (Redis Streams + 5 workers + Gemini para extracción) es conceptualmente correcto pero **produce falsos positivos sistemáticos**, **no clasifica los escenarios reales de falla operativa**, y **no captura las reglas de negocio diferenciadas por cliente** (cada EPS/ARL tiene sus propias políticas de tolerancia, entregas parciales y documentos obligatorios), lo que sobrecarga al auditor humano y diluye el valor del sistema.

### Las 5 categorías de falla operativa que el pipeline debe detectar

| ID | Escenario | Descripción |
|----|-----------|-------------|
| E1 | Falsificación de entrega | Funcionario registra entrega no realizada; sube documentos ficticios o reciclados |
| E2 | Cruce de adjuntos | Funcionario sube soportes de la dispensación equivocada |
| E3 | Tipeo errado en BD | Funcionario hizo la entrega pero tipeó mal en el sistema |
| E4 | Soporte incompleto | Funcionario entregó pero no pidió firma o falta documento |
| E5 | Calidad documental | Documentos ilegibles, recortados, mal escaneados |

### Veredicto

- **El modelo conceptual del pipeline (FDV vs documentos) es válido** dada la decisión de operar solo con datos internos.
- **La implementación actual genera falsos positivos** que estimamos en un 30% de auditorías cayendo a `manual_review` innecesariamente.
- **Los hallazgos no están clasificados por escenario** (E1-E5), por lo que cada auditor humano debe diagnosticar manualmente el tipo de falla.
- **El config es ciego a la heterogeneidad de clientes**: no hay forma de declarar tolerancias (POSITIVA permite +5 unidades por factor de empaque), entregas parciales, documentos condicionalmente obligatorios, ni reglas temporales por cliente.
- **Solo 2 de 6 clientes consultados están operativos** en el sistema (POSITIVA configurado, SANITAS configurado pero sin afinar, NUEVA EPS / SALUD TOTAL / SURAMERICANA / FAMISANAR sin audit-config).
- **No hay loop de aprendizaje**: el sistema cometerá los mismos errores en 6 meses que hoy.

### Priorización propuesta

| Fase | Objetivo | Esfuerzo | Impacto |
|------|----------|----------|---------|
| FASE 0 | Tiritas críticas (F1, F2, F3, F6, F9) | ~3 días | Reducir falsos positivos -15% + garantizar reproducibilidad |
| FASE 1 | Falsos positivos sistémicos (F4, F5, F8, D7, D8) | ~1 semana | Reducir falsos positivos -50% acumulado |
| **FASE 1.5** | **Motor de reglas de negocio por cliente (D11-D15)** | **~3 semanas** | **Tolerancias, entregas parciales, documentos obligatorios condicionales** |
| FASE 2 | Clasificación de escenarios (D1, D2, D10) | ~2 semanas | Auditor humano tiene clasificación inicial |
| FASE 3 | Inteligencia operativa (D3, D4, D5, D6) | ~1 mes | Sistema con memoria + detección de E1 (falsificación) |
| FASE 4 | Modelo de config explícito + versionado (D9) | ~3 semanas | Reproducibilidad y auditabilidad regulatoria |

---

## 2. Encuadre del negocio

### 2.1 Qué es la fuente de verdad (FDV)

El endpoint `GET /dispensation/{DisDetNro}` devuelve el registro transaccional que el funcionario de DISCOLMETS tipeó en el sistema interno al momento de realizar la entrega. Para el golden case T38250701547:

```json
{
  "FacSec": "87723098",
  "NumeroFactura": "T38250701547",
  "NombrePaciente": "GARCIA ABSALON ",
  "DocumentoPaciente": "12132213",
  "NumeroAutorizacion": "46338218",
  "FechaEntrega": "2025-07-29",
  "items": [
    { "CodigoArticulo": "IM01273", "Lote": "02041804-25", "CantidadEntregada": "20", ... },
    { "CodigoArticulo": "IM01273", "Lote": "02041806-25", "CantidadEntregada": "30", ... }
  ]
}
```

Esta FDV **es la verdad operativa de DISCOLMETS** porque la generó vuestro proceso interno: un funcionario humano (`MARIA JOSE POLANIA FLOREZ`, visible en el documento DISPENSA del golden case) registró el acto de entrega.

### 2.2 Qué se está auditando

El pipeline responde una sola pregunta de negocio:

> **¿Nuestro funcionario realizó correctamente esta entrega y los soportes que adjuntó respaldan lo que registró en la transacción, según las reglas del cliente al que se factura?**

Esto implica triangular cuatro fuentes:
1. **FDV** (lo que el funcionario tipeó al sistema).
2. **Documentos adjuntos** (los PDFs que el funcionario subió como soporte).
3. **Audit-config del cliente** (qué documentos y campos validar).
4. **Reglas de negocio del cliente** (tolerancias, entregas parciales, obligatoriedad documental, plazos).

### 2.3 Qué NO se está auditando (alcance interno)

DISCOLMETS no tiene integraciones con sistemas externos. Por lo tanto, **fuera del alcance**:

- Validación de afiliación del paciente en RUAF/ADRES.
- Validación de tarjeta profesional del médico en RETHUS.
- Validación de habilitación de la IPS en REPS.
- Validación de productos en INVIMA.
- Detección de fraude externo del paciente o del prestador.

El sistema audita el **cumplimiento del proceso interno**, no la veracidad externa de los datos.

### 2.4 Las 5 categorías de falla operativa (E1-E5)

| Escenario | Síntoma técnico | Acción requerida |
|-----------|----------------|------------------|
| **E1 — Falsificación** | Múltiples campos mismatch + firma genérica + datos demasiado limpios | Investigación de RRHH, posible despido |
| **E2 — Cruce de adjuntos** | Múltiples campos mismatch pero coherentes entre documentos | Devolver al funcionario para corregir adjuntos |
| **E3 — Tipeo en BD** | 1 campo mismatch en 1 documento | Corregir en BD, capacitación |
| **E4 — Soporte incompleto** | Firma ausente, demás campos coinciden | Devolver para completar firma |
| **E5 — Calidad documental** | `document_quality !== legible` | Pedir reescaneo |

Cada escenario requiere acciones operativas radicalmente distintas. El pipeline actual no los diferencia: produce listas de hallazgos por campo, dejando al auditor humano la clasificación.

---

## 3. Heterogeneidad por cliente — Reglas de negocio diferenciadas

DISCOLMETS atiende ~22 clientes (EPS, ARL, fiduciarias, aseguradoras). **Cada cliente es un negocio distinto** con su propio contrato, políticas operativas, documentos obligatorios y tolerancias. Lo que para POSITIVA (ARL) es válido (entregar +5 unidades por factor de empaque) puede ser causal de rechazo para SANITAS (EPS contributivo). Esta dimensión no está representada hoy en el pipeline.

### 3.1 Documentos heterogéneos por cliente — datos reales

Comparación obtenida de `GET /clients/{NitSec}/documents` para varios clientes:

| Cliente | NitSec | Documentos requeridos | Total |
|---------|--------|----------------------|-------|
| POSITIVA (ARL) | 2426 | DISPENSA · AUTORIZACION · FORMULA MEDICA | 3 |
| SANITAS (EPS contrib.) | 1165 | ACTA DE ENTREGA · FORMULA MEDICA · VALIDADOR DE DERECHOS · AUTORIZACION DE SERVICIOS | 4 |
| NUEVA EPS | 2624 | ACTA DE ENTREGA · AUTORIZACION · FORMULA MEDICA · VALIDADOR DE DERECHOS | 4 |
| SALUD TOTAL | 1169 | ACTA DE ENTREGA · AUTORIZACION (×2) · FORMULA MEDICA · VALIDACION DE DERECHOS | 5 |

**Observaciones críticas:**

1. **Mismo concepto, distintos nombres:** "DISPENSA" (POSITIVA) ≡ "ACTA DE ENTREGA" (resto). El pipeline trata estos como tipos de documento distintos: el matching por `nombre_documento` no normaliza el sinónimo, lo que rompe cualquier reutilización de schema entre clientes.
2. **Variantes ortográficas:** `"VALIDADOR DE DERECHOS"` (1165, 2624) vs `"VALIDACION DE DERECHOS"` (1169). Distinta cadena, mismo documento. El match exacto falla.
3. **Documentos duplicados en config:** SALUD TOTAL tiene `"AUTORIZACION"` registrada **dos veces** (`docId 2` y `docId 5`). El sistema actual no detecta duplicados — sobreescribe o duplica registros silenciosamente.
4. **Volumen variable:** POSITIVA = 3 docs · SANITAS, NUEVA EPS = 4 docs · SALUD TOTAL = 5 docs. El pipeline no parametriza un mínimo/máximo de adjuntos esperados.

### 3.2 Estado real del audit-config: solo 1 cliente bien configurado

`GET /clients/{NitSec}/audit-config` retorna lo siguiente:

| Cliente | NitSec | Estado audit-config | Riesgo operativo |
|---------|--------|---------------------|-------------------|
| POSITIVA | 2426 | ✅ Configurado (3 docs, visualChecks definidos) | Funciona, con falsos positivos por F1-F8 |
| SANITAS | 1165 | ⚠️ Configurado pero **sin afinar** (los 4 docs comparten 37 campos idénticos, `visualChecks: []`) | Audita sin distinción documental; firmas no se validan |
| NUEVA EPS | 2624 | ❌ Sin configuración | `POST /audit/single` falla en orchestrator |
| SALUD TOTAL | 1169 | ❌ Sin configuración | `POST /audit/single` falla en orchestrator |
| SURAMERICANA | 1045 | ❌ Sin configuración | `POST /audit/single` falla en orchestrator |
| FAMISANAR | 1163 | ❌ Sin configuración | `POST /audit/single` falla en orchestrator |

**Implicación operativa:** En la práctica el pipeline **solo audita POSITIVA con calidad razonable**. Los demás clientes están técnicamente desplegados pero operativamente inertes. SANITAS audita pero sin distinción por documento (todos los campos en todos los docs) y sin validación de firmas — efectivamente, SANITAS pasa todas sus auditorías sin detectar E4 (soporte incompleto).

### 3.3 Las reglas de negocio que el pipeline ignora — ejemplos reales

Esta sección documenta las **categorías de reglas** que existen en la operación de DISCOLMETS pero que no están representadas en el modelo del audit-config actual.

#### A. Tolerancias cuantitativas — Factor de empaque

**POSITIVA** permite entregar hasta **5 unidades por encima** de lo autorizado cuando el producto se vende en empaques cerrados. Ejemplo: autorizadas 18 gasas, el lote viene en cajas de 5 → se entregan 20. La diferencia (+2) es legítima.

El pipeline actual evalúa esto con `BUSINESS` comparando `parseNumber(20) === parseNumber(18)` → `VALOR_DISTINTO` → severidad ALTA → `manual_review`. Cada dispensación de POSITIVA con factor de empaque genera un falso positivo.

Otros clientes pueden tener:
- **Tolerancia 0** (entrega exacta requerida — algunos contratos privados).
- **Tolerancia +N%** (porcentual — algunas EPS permiten ±5% del autorizado).
- **Tolerancia condicionada por categoría** (ARL puede tolerar más en insumos que en medicamentos controlados).
- **Tolerancia condicionada por presentación** (envases de líquido pueden tener variación distinta a sólidos).

#### B. Entregas parciales

El golden case T38250701547 dice **"Entrega 3 de 3"**. POSITIVA permite que una autorización se cumpla en N entregas hasta completar el total. Auditarlo correctamente requiere:

1. **Validar que la suma acumulada** (entrega 1 + entrega 2 + entrega 3) ≤ total autorizado (con tolerancia de A).
2. **Validar que las cantidades de la entrega actual** ≤ saldo restante.
3. **Validar que las entregas previas no fueron rechazadas** (si la 1 fue rechazada, la 3 no debería existir sin reautorización).
4. **Validar consistencia de fechas** entre parciales.

Otros clientes:
- **No permiten parciales** (entrega total única — algunos contratos hospitalarios).
- **Permiten N parciales máximo** (e.g., NUEVA EPS — máximo 3 parciales por autorización).
- **Tienen plazos entre parciales** (e.g., mínimo 25 días entre dispensaciones de medicamento crónico).

El pipeline actual **audita cada dispensación en aislamiento**: no consulta el histórico de entregas previas asociadas a la misma autorización.

#### C. Documentos obligatorios condicionales

El config actual lista "estos N documentos son requeridos". No tiene lógica condicional. En la operación real, la obligatoriedad depende de variables:

| Condición | Documento adicional requerido |
|-----------|------------------------------|
| Producto es **MIPRES** (alto costo) | "PRESCRIPCION MIPRES" con ID válido |
| Producto es **NO-POS / PBS** | "ACTA CTC" (Comité Técnico-Científico) |
| Dispensación por **orden judicial** | "COPIA DE FALLO DE TUTELA" |
| **Accidente de trabajo** (ARL) | "FURAT" (formato único de reporte) |
| Paciente **menor de 18** o **mayor de 70** | "DOCUMENTO DEL ACUDIENTE" |
| **Sustitución terapéutica** | "NOTA TÉCNICA DE SUSTITUCIÓN" |
| **Reposición** por pérdida o robo | "DENUNCIA POLICIAL" + "CARTA DE RESPONSABILIDAD" |

#### D. Reglas temporales

Distintas EPS/ARL imponen ventanas distintas:

- **Plazo máximo entre `FechaFormula` y `FechaEntrega`** (e.g., NUEVA EPS: 30 días para medicamentos POS, 90 para crónicos; POSITIVA ARL: variable según calificación de origen).
- **Vigencia de la autorización** (`FechaAutorizacion + N días >= FechaEntrega`). Algunas autorizaciones vencen en 7 días, otras en 90.
- **Plazo para reclamación contable** (si se factura después de N días de la entrega, el cliente rechaza el pago).
- **Restricciones por tipo de medicamento controlado** (psiquiátricos: dispensación máxima cada 30 días).
- **Coherencia cronológica obligatoria:** `FechaFormula <= FechaAutorizacion <= FechaEntrega`.

El pipeline no valida ninguna ventana temporal hoy.

#### E. Tipo de servicio y cobertura

Cada cliente cubre un subset de servicios:

| Tipo | Reglas asociadas |
|------|------------------|
| **POS / PBS básico** | Cobertura estándar Resolución 5269 |
| **NO-POS / PBS-no-cubierto** | Requiere CTC + autorización especial |
| **MIPRES** (alto costo) | Requiere ID MIPRES válido + prescripción en plataforma |
| **ARL accidente de trabajo** | Requiere FURAT + diagnóstico ocupacional + nexo causal calificado |
| **ARL enfermedad laboral** | Requiere FUREP + calificación junta médica |
| **SOAT** | Requiere placa de vehículo + póliza vigente |
| **Pólizas privadas** | Pueden tener exclusiones de productos específicos |
| **Magisterio / Régimen especial** | Reglas distintas a EPS contributivo |

El config no tiene noción del "tipo de servicio". Todo se audita como si fuera POS.

#### F. Reglas de Vlr Cobrado / copago

- **Régimen contributivo Nivel 1:** copago según resolución MINSA (% del IBC).
- **Régimen subsidiado:** copago = 0 en mayoría de productos.
- **ARL:** copago = 0 siempre.
- **SOAT:** copago = 0 dentro de cobertura.
- **Pólizas privadas:** copago variable según condicionado.
- **Régimen especial (FFAA, Ecopetrol, Magisterio):** copago = 0.

El pipeline solo compara `VlrCobrado` campo a campo (FDV vs documento). No valida que el valor sea **coherente con el régimen del paciente y el contrato del cliente**.

#### G. Sustitución terapéutica

Cuando el producto autorizado no está disponible, algunos clientes permiten sustituir por equivalente. Reglas típicas:

- **Mismo principio activo, distinta marca** → permitido en POS sin trámite adicional.
- **Distinto principio activo (terapéutico equivalente)** → requiere autorización médica explícita.
- **Concentración distinta** → puede requerir ajuste de cantidad para mantener dosis equivalente.

El pipeline no detecta sustituciones (no compara producto autorizado vs producto entregado en ese marco) ni valida si la sustitución es legítima por contrato.

### 3.4 Implicaciones para el modelo de datos

El audit-config actual (`AudDisp` + `AudDispCampo`) es insuficiente. Falta una jerarquía:

```
ClienteAuditConfig (NitSec)
├── Documentos requeridos (obligatorio/opcional, condición de obligatoriedad)
│   └── Campos por documento (header/item, validateAs)
├── Tolerancias por campo (porcentaje, absoluto, factor empaque)
├── Reglas de entregas parciales (permitido, max parciales, plazo entre)
├── Reglas temporales (plazo formula→entrega, vigencia autorización)
├── Tipo de servicio y reglas asociadas (POS/NO-POS/MIPRES/ARL/SOAT)
├── Reglas de copago (matriz régimen × tipo de producto)
└── Reglas de sustitución (permitida/no, escalamiento requerido)
```

Hoy todo el config se reduce a `documents → fields[] + visualChecks[]`. Una sola dimensión de un problema multidimensional.

---

## 4. Arquitectura del pipeline (estado actual)

### 4.1 Flujo de eventos

```
POST /audit/single
   ↓ AuditController::single()
   ↓
[audit.inbox] ──── audit_created
   ↓ DocumentAuditOrchestrator
   ↓
[audit.documents] ──── document_registered (×N)
   ↓ DocumentExtractionWorker (Gemini Function Calling)
   ↓
[audit.documents] ──── document_extracted
   ↓ DocumentNormalizer (workers de normalización)
   ↓
[audit.documents] ──── document_normalized
   ↓ DocumentPolicyEngine (workers de políticas)
   ↓ (al completar todos los docs)
[audit.results] ──── rules_evaluated
   ↓ AuditResultAggregator
   ↓ Persistencia SQL (AudDispEst, AdjuntosDispensacion)
[audit.results] ──── audit_completed | audit_failed
```

### 4.2 Mapa de componentes

| Componente | Archivo | Responsabilidad |
|-----------|---------|-----------------|
| HTTP API | [app/Controllers/AuditController.php](app/Controllers/AuditController.php) | Endpoints `/audit/single`, `/audit/async`, `/audit/results`, `/audit/jobs/{id}` |
| Eventos | [app/Services/Audit/Events/AuditEvent.php](app/Services/Audit/Events/AuditEvent.php) | Definición + serialización |
| Publicación | [app/Services/Audit/Events/AuditEventPublisher.php](app/Services/Audit/Events/AuditEventPublisher.php) | XADD a Redis Streams |
| Estado | [app/Services/Audit/Events/AuditStateStore.php](app/Services/Audit/Events/AuditStateStore.php) | Estado en Redis con scripts Lua atómicos |
| Extracción | [app/Services/Audit/Events/DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) | Llamada a Gemini + Function Calling |
| Normalización | [app/Services/Audit/Events/DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php) | Aliases, tipos, items, visual checks |
| Políticas | [app/Services/Audit/Events/DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) | EXACT / SEMANTIC / VISUAL / BUSINESS |
| Reglas | [app/Services/Audit/Events/AuditFindingRules.php](app/Services/Audit/Events/AuditFindingRules.php) | Severidad, prioridad, métricas |
| Agregación | [app/Services/Audit/Events/AuditResultAggregator.php](app/Services/Audit/Events/AuditResultAggregator.php) | Estado final + persistencia SQL |
| Catálogo | [app/Services/Audit/FieldClassifier.php](app/Services/Audit/FieldClassifier.php) | 43 campos con tipo, severidad, doc autoritativo |
| Config | [app/Models/AuditConfigModel.php](app/Models/AuditConfigModel.php) | AudDisp + AudDispCampo |

### 4.3 Workers ejecutables

| Bin | Stream | Grupo |
|-----|--------|-------|
| [bin/audit-orchestrator-worker.php](bin/audit-orchestrator-worker.php) | audit.inbox | orchestrator |
| [bin/audit-extraction-worker.php](bin/audit-extraction-worker.php) | audit.documents | extractors |
| [bin/audit-normalizer-worker.php](bin/audit-normalizer-worker.php) | audit.documents | normalizers |
| [bin/audit-policy-worker.php](bin/audit-policy-worker.php) | audit.documents | policy |
| [bin/audit-aggregator-worker.php](bin/audit-aggregator-worker.php) | audit.results | aggregator |

---

## 5. Análisis del Golden Case T38250701547

### 5.1 Documentos procesados

Se dispone de **2 ejecuciones completas** del pipeline nuevo realizadas el 2026-04-26 (13:51 UTC y 14:11 UTC). Cada run genera 3 archivos `success_*.json` en `responseIA/`:

| Documento | Run 1 (13:51–13:53 UTC) | Run 2 (14:11–14:12 UTC) | Items | Visual Checks (configurados) | Tokens Run 1 |
|-----------|-------------------------|-------------------------|-------|------------------------------|--------------|
| DISPENSA | `_135152054563_79d4bffd.json` | `_141111855082_4338785f.json` | 2 (gasas) | FirmaActaEntrega | 1 424 + 3 531 + 540 = **5 495** |
| AUTORIZACION | `_135206895824_c6bf37fe.json` | `_141127677249_52031974.json` | 0 (ausente) | (ninguno configurado) | 1 003 + 1 097 + 225 = **2 325** |
| FORMULA MEDICA | `_135300261936_e189ce53.json` | `_141217561342_cbc6e94f.json` | 4 órdenes | FirmaPrescriptor | 1 083 + 4 985 + 1 516 = **7 584** |
| **Total auditoría** | | | | | **15 404 tokens** |

Modelo: `gemini-3.1-pro-preview` · Sin cache hits. Extended thinking ~62% del consumo total.

**Schema nuevo confirmado:** El pipeline nuevo usa `fields` como objeto/diccionario `{campo: valor}` (no array de `{campo, valor}`). La clave `items` puede estar ausente (AUTORIZACION no la retorna — no incluye ni array vacío). La clave `visual_checks` usa `detalle` en lugar de `descripcion`.

**Determinismo observado:** AUTORIZACION y FORMULA MEDICA son determinísticas (token counts y valores idénticos entre runs). **DISPENSA no es determinística** (Run 1: 5 495 tokens; Run 2: 4 325 tokens — se añade/omite campo `Tipo: "POS"` y varía `severidad` en visual_checks). Ver F9.

### 5.2 Inspección visual (validada por humano + Gemini)

**DISPENSA** muestra:
- Cabecera: T38250701547, DISCOLMETS SAS, paciente GARCIA ABSALON CC 12132213.
- Médico: carlos esneider murcia rojas. Autorización: 46338218.
- Entrega: **3 de 3** (tercera entrega parcial — caso de regla B de sección 3.3).
- 2 ítems de gasas estériles (lotes distintos, 20 + 30 unidades — caso de regla A: factor de empaque).
- **Firma manuscrita de Absalon Garcia + cédula 12132213** visible. ✅

**AUTORIZACION** muestra:
- Anexo Técnico N°1 — POSITIVA. Autorización 46338218 del 27/07/2025.
- Servicio autorizado: Cureband premium gasa antiadherente (caso de regla G: sustitución).
- Imagen oscura, sección de "persona que autoriza" sin firma/sello visible claramente.

**FORMULA MEDICA** muestra:
- IPS Centro de Diagnóstico Ocupacional. Fecha 2025-05-20.
- Autorización **45082636** (distinta a la dispensación — caso de regla E: tipo de servicio ARL).
- Tipo de caso: ACCIDENTE DE TRABAJO. ARL: POSITIVA.
- 4 órdenes médicas (Ciclobenzaprina + lista de insumos + Clorhexidina + Mometasona).
- **Firma del médico Carlos Murcia** visible. ✅

### 5.3 Comparación FDV vs Extracción Gemini (campos clave — Run 1, 2026-04-26)

| Campo | FDV | DISPENSA (Gemini) | AUTORIZACION (Gemini) | FORMULA MEDICA (Gemini) |
|-------|-----|-------------------|-----------------------|-------------------------|
| NumeroAutorizacion | `46338218` | `46338218` ✅ | `46338218` ✅ | `45082636` ⚠️ (F5 — autorización de consulta, diferente por diseño ARL) |
| NombrePaciente | `"GARCIA ABSALON "` (trailing space) | `GARCIA ABSALON` | `ABSALON GARCIA` (orden invertido) | `ABSALON GARCIA` |
| DocumentoPaciente | `12132213` | `12132213` ✅ | `12132213` ✅ | `12132213` ✅ |
| NombreArticulo (item) | `GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5` | `20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5-- -INV:2018DM-0018580` ⚠️ (F4) | `Cureband premium gasa antiadherente estéril- 7.5cm x 7.5cm- sobre CAJA 18 unds` ⚠️ | `"null"` (string) ⚠️ (F2) |
| FechaFormula | `2025-05-20` | `20/05/2025` (formato distinto) | — | `2025-05-20` ✅ |
| IPS | `"ips centro de diagnostico ocupacional"` (lowercase FDV) | `IPS CENTRO DE DIAGNOSTICO OCUPACIONAL` | — | `I.P.S. CENTRO DE DIAGNOSTICO OCUPACIONAL` |
| CantidadPrescrita (item) | `20` / `30` | `20` ✅ / `30` ✅ | — | `"null"` (string, F2) |
| CUM (item) | código real | `0-0` ⚠️ (F7) | — | — |
| FirmaActaEntrega | presente (DISPENSA) | solo en `visual_checks`: presente=true ✅ | visual_checks halluc. F1 | — |
| visual_checks (AUTORIZACION) | [] (vacío — sin checks configurados) | — | `"Firma de quien autoriza" false` + `"Sello" false` ❌ (F1 — hallucination) | — |

---

## 6. Debilidades técnicas — F1 a F9

### F1 — Visual checks alucinados en AUTORIZACION (CRÍTICO)

**Hallazgo:** El audit-config de AUTORIZACION tiene `visualChecks: []` (vacío). El prompt enviado a Gemini no incluye sección "Checks visuales esperados:" **pero el schema de Function Calling define `visual_checks` como campo `required`**. Al ser obligatorio, Gemini inventa checks propios basados en su conocimiento general de documentos médicos:

```json
[
  {"check": "Firma de quien autoriza", "presente": false, "severidad": "alta"},
  {"check": "Sello", "presente": false, "severidad": "baja"}
]
```

**Evidencia:** Confirmado en **2 runs independientes** del 2026-04-26 con resultados bitwise idénticos — no es ruido aleatorio, es un comportamiento **sistemático y determinístico** del modelo dado el prompt+schema actual.

**Impacto:** Estos checks espurios pasan por el `DocumentNormalizer` sin filtrarse, llegan al `DocumentPolicyEngine` y generan hallazgos falsos positivos con severidad ALTA → `manual_review` automático.

**Causa raíz (corregida):** El problema no es el normalizer — el normalizer solo ve lo que Gemini ya devolvió. La causa raíz está en `DocumentExtractionWorker`:
1. `buildFunctionSchema()` incluye `visual_checks` en `required[]` incluso cuando `configuredChecks` está vacío.
2. La instrucción del sistema dice "Para verificaciones visuales usa `presente=false` cuando el elemento no sea visible" — esta frase activa la generación de checks aunque no se hayan pedido.

**Archivos afectados:**
- [app/Services/Audit/Events/DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) — causa raíz (FASE 0, P0).
- [app/Services/Audit/Events/DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php) método `normalizeVisualChecks` (~L165) — defensa secundaria.

**Fix en `DocumentExtractionWorker`:** Si `count(configuredChecks) === 0`:
1. Eliminar `visual_checks` de `required[]` en el schema de Function Calling.
2. Omitir la instrucción "Para verificaciones visuales…" del system prompt.
3. Omitir la sección "Checks visuales esperados:" del user prompt.

**Fix defensivo en `DocumentNormalizer`:** Independientemente del prompt, filtrar `visual_checks` para retener solo checks cuyo `check` esté en `configuredChecks`. Loguear descartes en `normalizationLog`.

### F2 — `"null"` como string en lugar de JSON null (ALTO)

**Hallazgo:** En FORMULA MEDICA, Gemini devuelve campos como `"CantidadPrescrita": "null"`, `"NombreArticulo": "null"`, `"CodigoDiagnostico": "null"` (string literal en lugar de JSON null). También ocurre en items — item 2 y item 4 de FORMULA MEDICA tienen varios campos como `"null"`.

**Evidencia:** Confirmado en **2 runs independientes** del 2026-04-26. El schema object-based (nuevo) **no resuelve este bug** — Gemini sigue retornando strings `"null"` cuando no puede leer el valor. El schema no tiene `enum` ni `pattern` que prohíban la cadena `"null"`.

**Impacto:** El `DocumentNormalizer.normalizeFields` convierte `""` (empty string) a `null`, pero no convierte `"null"` (string). El valor `"null"` llega al `DocumentPolicyEngine` y produce `VALOR_DISTINTO` cuando debería ser `NO_ENCONTRADO`. La lógica BUSINESS de suma de cantidades falla con `parseNumber("null")`.

**Archivo afectado:** [app/Services/Audit/Events/DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php) método `normalizeFields` (línea ~68). También aplicar a la normalización de items.

**Fix:** En `normalizeFields` y en la normalización de cada campo de items:
```php
if (is_string($value) && strtolower(trim($value)) === 'null') {
    $value = null;
    $normalizationLog[] = ['field' => $key, 'transformation' => 'null_string_to_null'];
}
```

### F3 — `FirmaActaEntrega` en `fields` y `visualChecks` simultáneamente (ALTO)

**Hallazgo:** El audit-config de DISPENSA define `FirmaActaEntrega` tanto en `fields` (campo de texto) como en `visualChecks` (verificación visual). El PolicyEngine evalúa ambos:
- En `fields`: como Gemini no extrae texto para `FirmaActaEntrega`, resultado = `NO_ENCONTRADO`.
- En `visualChecks`: resultado = `COINCIDE` (firma presente).

**Impacto:** Hallazgo duplicado y contradictorio en la misma auditoría.

**Archivos afectados:**
- audit-config (POSITIVA y otros clientes con configuración similar).
- [app/Services/Audit/Events/DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) método `evaluateField` (línea ~377).

**Fix:** En `evaluateField`, antes de evaluar un campo de `fields`, verificar si su nombre normalizado coincide con un check configurado en `visualChecks`. Si coincide, omitir la evaluación de campo (la verificación visual ya cubre el chequeo).

### F4 — `NombreArticulo`: FDV limpio vs documento con código + referencia (ALTO)

**Hallazgo:** La FDV tiene el nombre limpio del producto (`GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5`, 45 caracteres). El documento DISPENSA imprime el código + nombre + referencia de inventario en una sola celda (`20012566-23 - GASA ESTERIL PRECORTADA NO TEJIDA 3X3 PQTE*5-- -INV:2018DM-0018580`, 82 caracteres). Con `similar_text()`:

```
Similitud ≈ (2 × 45) / (45 + 82) ≈ 0.71
Threshold NombreArticulo = 0.82 → FALLA SISTEMÁTICA
```

**Impacto:** **100% de las dispensaciones** de este cliente (y otros similares) tienen este patrón. Cada una genera `VALOR_DISTINTO` con severidad ALTA → `manual_review` garantizado aunque el producto sea correcto.

**Archivo afectado:** [app/Services/Audit/Events/DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) método `evaluateSemanticField` (línea ~441).

**Fix:** Antes de `similar_text`, verificar si el nombre limpio de la FDV está contenido en el valor extraído del documento (tokenizado, case-insensitive). Si la FDV es subcadena tokenizada del documento → `COINCIDE`. Caer a `similar_text` solo si no hay contención.

### F5 — `NumeroAutorizacion` en FORMULA MEDICA siempre distinto (ALTO)

**Hallazgo:** En accidentes laborales (ARL), la fórmula médica se prescribe en una consulta autorizada con un número (e.g. `45082636` del 20/05/2025). Posteriormente, la dispensación requiere otra autorización (`46338218` del 27/07/2025). Son dos números distintos por diseño del proceso.

**Impacto:** El audit-config de FORMULA MEDICA incluye `NumeroAutorizacion` como campo a comparar contra la FDV. Como el FDV trae la autorización de la dispensación, **la comparación falla 100% de las veces** para casos de ARL.

**Archivos afectados:**
- audit-config (renombrar el campo en FORMULA MEDICA).
- [app/Services/Audit/FieldClassifier.php](app/Services/Audit/FieldClassifier.php) (mapear el alias).

**Fix:** Renombrar el campo en FORMULA MEDICA a `NumeroAutorizacionConsulta` (campo distinto, no se compara contra el `NumeroAutorizacion` del FDV — se compara contra el `NumeroAutorizacionConsulta` si existe en FDV, o se omite). Para clientes con dispensaciones POS regulares, el campo no se renombra.

### F6 — Trailing space en FDV (`"GARCIA ABSALON "`) (MEDIO)

**Hallazgo:** El endpoint `/dispensation/{id}` retorna `"NombrePaciente": "GARCIA ABSALON "` (con espacio al final). Si el normalizer aplica `trim()` solo al valor extraído del documento pero no al de la FDV, la comparación EXACT falla por el espacio.

**Archivo afectado:** [app/Services/Audit/Events/DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) método `resolveSourceTruthValue` (línea ~295).

**Fix:** Aplicar `trim()` a los valores de FDV antes de comparar.

### F7 — CUM en documento `"0-0"` vs FDV código real (LATENTE)

**Hallazgo:** El documento DISPENSA imprime `CUM:0-0` en la descripción del artículo (placeholder de DISCOLMETS cuando no hay CUM disponible al imprimir). La FDV tiene el CUM real (`20012566-23`).

**Estado actual:** `CUM` está en `PRODUCT_HINT_FIELDS` (se omite del PolicyEngine). No genera hallazgo hoy.

**Riesgo:** Si alguien activa el campo en el config, fallará 100% de las veces. Documentar como riesgo conocido.

### F8 — Match de documentos por nombre exacto (HANG RISK)

**Hallazgo:** El `DocumentAuditOrchestrator` matchea attachments con audit-config usando el `nombre_documento` exacto. Casos reales que rompen el match:
- `"FORMULA_MEDICA"` vs `"FORMULA MEDICA"` (guión bajo vs espacio).
- `"VALIDADOR DE DERECHOS"` (cliente 1165, 2624) vs `"VALIDACION DE DERECHOS"` (cliente 1169).
- `"DISPENSA"` (cliente 2426) vs `"ACTA DE ENTREGA"` (resto de clientes) — **mismo concepto, distintos nombres**.

**Impacto:** La auditoría queda en `PROCESSING` indefinidamente porque `docs_evaluated` nunca alcanza `docs_total`. TTL de 24h en Redis la pierde. No hay alerta. Adicionalmente, no hay forma de reusar schemas equivalentes entre clientes.

**Fix:** Normalizar el nombre del documento (lowercase + colapsar espacios y guiones bajos) antes del match. Considerar tabla de sinónimos `DocumentoSinonimo` para mapear `"DISPENSA"` ↔ `"ACTA DE ENTREGA"`. Loguear el match en el estado de la auditoría para observabilidad.

### F9 — No-determinismo de extracción (CRÍTICO — NUEVO 2026-04-26)

**Hallazgo:** El mismo documento DISPENSA del golden case procesado en dos runs separadas (13:51 UTC y 14:11 UTC) produce **resultados distintos**:

| Aspecto | Run 1 (13:51 UTC) | Run 2 (14:11 UTC) |
|---------|-------------------|-------------------|
| `fields.Tipo` | `"POS"` | *ausente* |
| `visual_checks[0].severidad` | *ausente* | `"info"` |
| `thoughtsTokenCount` | 3 531 | 2 366 |
| `totalTokenCount` | 5 495 | 4 325 |

AUTORIZACION y FORMULA MEDICA son determinísticas (token counts idénticos entre runs). Solo DISPENSA varía.

**Causa raíz:** El `thinkingBudget` en `generationConfig` no está fijado a un valor constante — varía entre llamadas según la carga del modelo o el seed de generación. Con thinking variable, Gemini puede incluir o excluir campos opcionales como `Tipo` dependiendo de cuánto "piense" sobre el documento.

**Impacto:** Dos auditores que re-ejecuten el mismo caso pueden obtener **decisiones distintas**. El campo `Tipo: "POS"` presente en Run 1 pero ausente en Run 2 puede compararse contra la FDV y generar un finding en Run 1 que no existe en Run 2. Esto invalida la reproducibilidad del sistema, que es un requisito de cualquier sistema de auditoría con validez operativa.

**Archivo afectado:** [app/Services/Audit/Events/DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) — método que construye `generationConfig`.

**Fix:** Fijar `thinkingBudget` a un valor constante (e.g., `2048` tokens) y añadir `temperature: 0` explícito en `generationConfig`. Esto no elimina toda variabilidad de Gemini, pero la reduce al mínimo técnicamente posible con la API actual.

**Hallazgo operativo adicional:** El payload de AUTORIZACION no incluye la clave `items` (ni siquiera como array vacío). El código en `DocumentNormalizer` y `DocumentPolicyEngine` debe usar `$args['items'] ?? []` — no asumir que la clave siempre existe.

---

## 7. Debilidades de modelo y capacidad — D1 a D15

Los siguientes son problemas estructurales más profundos. No son bugs sino **brechas de capacidad** entre lo que el pipeline hace y lo que el negocio necesita. Las debilidades **D11-D15** corresponden específicamente a la heterogeneidad por cliente descrita en sección 3.

### D1 — No clasifica escenarios E1-E5

El pipeline produce listas de hallazgos por campo. El auditor humano debe **diagnosticar manualmente** si está ante una falsificación (E1), un cruce de adjuntos (E2), un typo (E3), un soporte incompleto (E4) o un problema de calidad (E5). Cada uno requiere acción operativa distinta.

**Lo que falta:** Un `PatternClassifier` que mapee combinaciones de hallazgos a escenarios E1-E5.

### D2 — Severidad por campo aislado, sin combinatoria

`AuditFindingRules.findingPriority` asigna severidad campo a campo. Pero el riesgo real depende de la **combinación**:
- 1 campo discrepante → probable E3 (typo).
- 5 campos discrepantes en el mismo documento → probable E2 (cruce).
- 5 campos discrepantes con datos del paciente coherentes entre documentos pero distintos a la FDV → probable E1 (falsificación).

El pipeline trata estas combinaciones igual, sumando severidades sin entender el patrón.

### D3 — Validación de firma trivial

`FirmaActaEntrega: presente=true` es un binario. Para auditar al funcionario contra E1 (falsificación) o E4 (soporte incompleto), debería verificar:
- ¿Hay número de cédula manuscrito junto a la firma? (en el golden case sí: `12132213`).
- ¿La cédula manuscrita coincide con la del paciente?
- ¿La firma cae en zona designada del documento?
- ¿La firma varía entre dispensaciones del mismo paciente? (firma idéntica = sospecha).
- ¿La firma varía entre pacientes del mismo funcionario? (todos firman parecido = sospecha).

### D4 — Sin contexto histórico funcionario/paciente

Cada auditoría se ejecuta en un vacío. El pipeline no sabe:
- Tasa histórica de `manual_review` del funcionario que registró la entrega.
- Patrón temporal del funcionario (¿hace todas las entregas a las 5pm?).
- Histórico de dispensaciones del paciente (cantidades anómalas, productos recurrentes).
- Cantidades acumuladas en entregas parciales (caso "3 de 3" del golden case).

Esta información **está en la BD interna de DISCOLMETS** y es accesible sin integraciones externas. Hoy no se usa.

### D5 — Sin loop de aprendizaje del auditor

Cuando un auditor humano resuelve un `manual_review`, esa decisión:
- No actualiza thresholds dinámicamente.
- No alimenta una lista de excepciones aprobadas (e.g., "este paciente firma siempre de esta forma → OK").
- No marca al funcionario en cuestión.
- No genera fixtures de regresión.

Después de 6 meses operando, el sistema cometerá los mismos errores que el primer día.

### D6 — Documentos como datos, no como evidencia

Hay riqueza forense en los PDFs que se desperdicia:
- **Metadatos del PDF**: software de creación, fecha, autor, modificaciones.
- **Análisis de capas**: ¿texto encima de imagen? ¿coordenadas coherentes con el plantilla?
- **Coherencia tipográfica**: ¿toda la DISPENSA usa la misma fuente?
- **Códigos de barras**: la DISPENSA del golden case tiene un código de barras que no se decodifica para validar `T38250701547`.

Para detectar E1 (falsificación), la evidencia forense es crítica.

### D7 — Decisión por documento, no por item

Una DISPENSA con 5 items es tratada como un todo. Si el item 3 tiene cantidad equivocada, todo el documento falla. Pero la realidad operativa es:
- Item 1 puede aprobarse.
- Item 3 va a revisión.
- Item 5 se rechaza por lote vencido.

El pipeline no produce decisiones a nivel ítem.

### D8 — Calidad de imagen no afecta confianza

Si Gemini reporta `document_quality: parcialmente_legible`, el pipeline registra el hecho pero sigue aplicando reglas como si el documento fuera legible. Una "firma presente" en un documento parcialmente legible debería tener confianza reducida, no la misma que en uno legible.

### D9 — Header fields e item fields mezclados en config

El audit-config trata todos los campos por igual:
```json
"fields": ["NombrePaciente", "Lote", "CantidadEntregada", "CodigoArticulo", ...]
```

`NombrePaciente` es header (un único valor) y `Lote` es por item (múltiples valores). El pipeline tiene lógica implícita en código para resolver esto, pero el config no lo declara. Debería ser:

```json
{
  "headerFields": ["NombrePaciente", "DocumentoPaciente", "Medico"],
  "itemFields": [
    {"name": "CantidadEntregada", "validateAs": "sum"},
    {"name": "Lote", "validateAs": "set"},
    {"name": "CodigoArticulo", "validateAs": "set"}
  ]
}
```

### D10 — Estados terminales muy gruesos

Hoy una auditoría termina en uno de: `completed`, `manual_review`, `error`, `failed`. El auditor humano necesita más granularidad para enrutar correctamente:
- `manual_review_funcionario` (patrón sugiere problema con el funcionario).
- `manual_review_documento` (sugerencia de pedir mejor calidad de soporte).
- `auto_aprobado_con_observacion` (pasa pero queda nota).
- `rechazado_devolver_funcionario` (no pasa, devolver para corrección).

Esta granularidad cambiaría la experiencia operativa: el panel del auditor tendría colas distintas con prioridades distintas.

### D11 — No hay motor de tolerancias por cliente (CRÍTICO)

`CantidadEntregada` se evalúa con tipo `BUSINESS` que solo verifica suma + entrega parcial relativa. **No tiene noción de:**
- Factor de empaque por producto (caja de 5, caja de 10, caja de 50).
- Tolerancia configurable por cliente (POSITIVA `+5 abs`, otra cliente `+10%`, otra `0`).
- Tolerancia diferenciada por tipo de producto (medicamento controlado estricto vs insumo flexible).

**Impacto:** Cada dispensación de POSITIVA con factor de empaque genera un falso positivo. **Sección 3.3.A** documentó la regla de negocio explícita.

**Lo que falta:**
- Tabla `AudDispTolerancia (NitSec, CampoNombre, TipoProducto, TipoTolerancia, Valor)` parametrizable.
- Motor `ToleranceEvaluator` consumido por `DocumentPolicyEngine.evaluateBusinessField` antes de generar `VALOR_DISTINTO`.

### D12 — No hay motor de obligatoriedad documental por cliente (ALTO)

El orchestrator registra "los documentos del config" pero **no diferencia entre obligatorio/opcional ni evalúa condiciones** (ej: MIPRES requiere documento adicional).

**Impacto:** Si un cliente requiere "ACTA CTC" solo cuando el producto es NO-POS, el pipeline no puede expresar esa regla. Si una dispensación MIPRES no trae el documento MIPRES, el pipeline no detecta la falta — solo detecta que los documentos presentes "coinciden" con la FDV.

**Lo que falta:**
- Marca `obligatorio: bool` por documento en el config.
- Reglas condicionales (`requiredIf: producto.tipo === 'MIPRES'`) declarativas.
- Validación pre-extracción en orchestrator: si documento obligatorio falta → `audit_failed` con causa específica.

### D13 — Reglas temporales no parametrizadas (ALTO)

No hay validación de:
- Plazo máximo entre `FechaFormula` y `FechaEntrega`.
- Vigencia de la autorización (`FechaAutorizacion + N días >= FechaEntrega`).
- Coherencia cronológica (`FechaFormula <= FechaAutorizacion <= FechaEntrega`).
- Plazos por tipo de medicamento (controlados con plazo más corto).

**Impacto:** Una dispensación con prescripción de hace 6 meses pasa la auditoría sin alerta.

**Lo que falta:** Componente `TemporalRulesEvaluator` consumido por `DocumentPolicyEngine`. Configuración por cliente: `{maxDiasFormulaAEntrega: 30, vigenciaAutorizacionDias: 90, exigirCoherenciaCronologica: true}`.

### D14 — Productos sin clasificación de tipo de servicio (MEDIO)

No hay metadato sobre si el producto es POS, NO-POS, MIPRES, ARL-cubierto, etc. **Sin esta clasificación no se pueden aplicar reglas específicas** (D12 no puede activar `requiredIf MIPRES` si nadie sabe que el producto es MIPRES).

**Impacto:** Todas las dispensaciones se auditan como si fueran POS. Las dispensaciones especiales (NO-POS, MIPRES, ARL) no reciben las validaciones específicas que les corresponden.

**Lo que falta:** Tabla `ProductoCobertura (NitSec, CodigoProducto, TipoServicio, RequiereMipres, RequiereCtc)` o derivación desde un catálogo INVIMA local. Consumido por el orchestrator para decidir qué documentos exigir y qué reglas aplicar.

### D15 — Sustitución terapéutica no soportada (MEDIO)

Si la autorización dice "Cureband premium gasa antiadherente" y la entrega dice "Gasa estéril", el pipeline marca `VALOR_DISTINTO` aunque sean **terapéuticamente equivalentes** y la sustitución sea legítima (caso del golden case T38250701547).

**Impacto:** Toda sustitución legítima genera `manual_review` con severidad ALTA.

**Lo que falta:** Tabla `EquivalenciaTerapeutica (CodigoProductoOriginal, CodigoProductoSustituto, NitSec, RequiereAutorizacion)` o regla de sustitución por cliente.

---

## 8. Hoja de ruta de implementación

### FASE 0 — Tiritas críticas (P0, ~3 días)

**Objetivo:** Detener la sangre. Reducir falsos positivos triviales que el pipeline produce hoy.

| Tarea | Archivo | Cambio |
|-------|---------|--------|
| F1 — Causa raíz: schema sin `required` para visual_checks vacíos | [DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) `buildFunctionSchema` | Si `count(configuredChecks) === 0`: 1) eliminar `visual_checks` de `required[]`, 2) omitir la propiedad o fijar `maxItems: 0`. |
| F1 — Causa raíz: omitir instrucción visual cuando no hay checks | [DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) `buildUserPrompt` / system prompt | Solo incluir "Para verificaciones visuales…" y "Checks visuales esperados:" cuando `count(configuredChecks) > 0`. |
| F1 — Defensa: filtrar checks no configurados en normalizer | [DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php) `normalizeVisualChecks` (~L165) | Retener solo checks cuyo `check` esté en `configuredChecks`. Loguear descartes. |
| F2 — Normalizar `"null"` string en fields e items | [DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php) `normalizeFields` (~L68) | `if (is_string($v) && strtolower(trim($v)) === 'null') $v = null;`. Aplicar también a cada campo de items. Log en `normalizationLog`. |
| F3 — Resolver firma duplicada | [DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) `evaluateField` (~L377) | Excluir campos cuyo nombre normalizado coincida con un visualCheck configurado. |
| F6 — Trim FDV antes de comparar | [DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) `resolveSourceTruthValue` (~L295) | Aplicar `trim()` también a valores de FDV. |
| F9 — Fijar thinkingBudget | [DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) `buildGenerationConfig` (o equivalente) | Establecer `thinkingBudget: 2048` (constante) y `temperature: 0` en `generationConfig`. |
| F9 — Manejo graceful de `items` ausente | [DocumentNormalizer.php](app/Services/Audit/Events/DocumentNormalizer.php), [DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) | Usar `$args['items'] ?? []` en toda la cadena — nunca asumir que la clave existe. |
| Tests asociados | `tests/Services/Audit/Events/DocumentNormalizerTest.php`, `DocumentPolicyEngineTest.php`, nuevo `DocumentExtractionWorkerTest.php` | Casos: visual_checks alucinados cuando `configuredChecks=[]`, `"null"` string en fields y en items, trailing space en FDV, payload sin clave `items`. |

**Verificación FASE 0:**
1. Re-ejecutar el golden case **3 veces** y comparar resultados — los 3 deben ser idénticos (F9 resuelto).
2. AUTORIZACION en `responseIA/` ya no contiene `"Firma de quien autoriza"` ni `"Sello"` (F1 resuelto).
3. FORMULA MEDICA: `fields.CantidadPrescrita`, `fields.NombreArticulo`, `fields.CodigoDiagnostico` son `null` JSON, no `"null"` string (F2 resuelto).
4. Los 3 runs producen el mismo `totalTokenCount` para DISPENSA (señal de thinkingBudget fijo).

### FASE 1 — Falsos positivos sistémicos (P1, ~1 semana)

**Objetivo:** Eliminar los patrones de fallo que afectan al 100% de las dispensaciones de ciertos clientes.

| Tarea | Archivo | Cambio |
|-------|---------|--------|
| F4 — NombreArticulo: contención + tokens | [DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) `evaluateSemanticField` (~L441) | Si docValue contiene fdvValue como subcadena tokenizada → COINCIDE. `similar_text` solo como fallback. |
| F5 — NumeroAutorizacion contextual | audit-config + [FieldClassifier.php](app/Services/Audit/FieldClassifier.php) | Renombrar `NumeroAutorizacion` en FORMULA MEDICA a `NumeroAutorizacionConsulta` para clientes ARL. |
| F8 — Normalizar match de documentos + tabla de sinónimos | `DocumentAuditOrchestrator` + nueva tabla `DocumentoSinonimo` | Comparar `nombre_documento` con normalización (lowercase + colapsar espacios/guiones). Mapear sinónimos (`DISPENSA` ↔ `ACTA DE ENTREGA`). Log de match. |
| D8 — Confianza por document_quality | [DocumentPolicyEngine.php](app/Services/Audit/Events/DocumentPolicyEngine.php) `evaluateField` (~L377) | Si `documentQuality !== 'legible'`: COINCIDE → COINCIDE_BAJA_CONFIANZA, VALOR_DISTINTO → NO_CONCLUYENTE. |
| D7 — Decisión por item | [AuditResultAggregator.php](app/Services/Audit/Events/AuditResultAggregator.php) `aggregate` (~L40) | Generar `item_decisions` paralelo a `document_decisions`. |

**Verificación FASE 1:** Golden case pasa con 0 falsos positivos. Decisión por item funcional.

### FASE 1.5 — Motor de reglas de negocio por cliente (P0/P1, ~3 semanas)

**Objetivo:** Habilitar políticas diferenciadas por cliente para tolerancias, entregas parciales, documentos obligatorios y reglas temporales. Sin esto, el pipeline solo opera correctamente para POSITIVA en su modalidad estándar.

| Tarea | Componente nuevo / archivo | Cambio |
|-------|---------------------------|--------|
| D11 — Tolerancias por cliente | nueva tabla `AudDispTolerancia` + `app/Services/Audit/Events/ToleranceEvaluator.php` (nuevo) | Schema: `(NitSec, CampoNombre, TipoProducto, TipoTolerancia [ABS/PCT/FACTOR_EMPAQUE], Valor)`. Consumido por `DocumentPolicyEngine.evaluateBusinessField` antes de generar `VALOR_DISTINTO`. POSITIVA: tolerancia ABS +5 en `CantidadEntregada`. |
| D12 — Obligatoriedad documental + condicionales | extensión de `AudDispCampo` o nueva tabla `AudDispDocRequerido` con flags `obligatorio` y `requiredIfRule` (JSON DSL) | Orchestrator valida pre-extracción. Si documento obligatorio falta → `audit_failed` con `causa: documento_obligatorio_faltante`. |
| D13 — Reglas temporales | nueva tabla `AudDispReglasTemporales` + `app/Services/Audit/Events/TemporalRulesEvaluator.php` (nuevo) | Schema: `(NitSec, MaxDiasFormulaAEntrega, VigenciaAutorizacionDias, ExigirCoherenciaCronologica)`. Hallazgos con severidad configurada. |
| D14 — Clasificación de productos por tipo de servicio | nueva tabla `ProductoCobertura` o derivación desde catálogo existente | `(NitSec, CodigoProducto, TipoServicio [POS/NO-POS/MIPRES/ARL/SOAT])`. Consumido por orchestrator para activar reglas condicionales de D12. |
| Histórico de entregas parciales | nuevo método `DispensationModel::previousPartialDeliveries(disDetNro)` consumido por `AuditResultAggregator` | Para entregas parciales, validar saldo acumulado contra autorización total (con tolerancia D11). |
| D15 — Sustitución terapéutica | nueva tabla `EquivalenciaTerapeutica` + lógica en `DocumentPolicyEngine.evaluateSemanticField` | Si docValue es sustituto autorizado de fdvValue → `COINCIDE_POR_SUSTITUCION`. |
| Endpoint de gestión de reglas | nuevo `app/Controllers/AuditRulesController.php` + UI mínima | `GET/POST /clients/{id}/rules` para administrar tolerancias, plazos y obligatoriedades sin desplegar código. |
| Tests | `tests/Services/Audit/Events/ToleranceEvaluatorTest.php`, `TemporalRulesEvaluatorTest.php` | Cobertura por cliente: POSITIVA (factor empaque +5), NUEVA EPS (tolerancia 0), SANITAS (parciales prohibidas), SALUD TOTAL (plazo 30 días). |

**Verificación FASE 1.5:**
- Golden case T38250701547: la entrega de 20 + 30 unidades pasa sin alarma porque la suma (50) está dentro de la tolerancia + factor de empaque sobre lo autorizado.
- Auditoría sintética con producto MIPRES sin documento MIPRES → `audit_failed` con causa explícita.
- Auditoría sintética con `FechaEntrega - FechaFormula > 30 días` para NUEVA EPS → hallazgo temporal, severidad configurada.

### FASE 2 — Clasificación de escenarios (P1, ~2 semanas)

**Objetivo:** Que el pipeline le diga al auditor humano qué tipo de falla está viendo.

| Tarea | Archivo | Función |
|-------|---------|---------|
| D1 — PatternClassifier | `app/Services/Audit/Events/PatternClassifier.php` (nuevo) | Recibe findings + document_decisions. Retorna E1/E2/E3/E4/E5/none. |
| D2 — Severidad combinatoria | [AuditFindingRules.php](app/Services/Audit/Events/AuditFindingRules.php) `findingPriority` (~L40) | Método `combinatorialSeverity(array $findings)`. |
| D10 — Estados granulares | [AuditStateStore.php](app/Services/Audit/Events/AuditStateStore.php) + [AuditResultAggregator.php](app/Services/Audit/Events/AuditResultAggregator.php) `resolveFinalStatus` (~L191) | Estados: `manual_review_funcionario`, `manual_review_documento`, `auto_aprobado_con_observacion`, `rechazado_devolver_funcionario`. |
| Tests | `tests/Services/Audit/Events/PatternClassifierTest.php` (nuevo) | Fixtures de findings → escenario esperado. |

**Reglas iniciales del PatternClassifier:**

```
SI document_quality !== 'legible' EN cualquier doc → E5
SI única discrepancia es FirmaActaEntrega ausente → E4
SI documento obligatorio condicional falta (D12) → E4
SI todos los datos del paciente coinciden entre 3 docs pero NO con FDV → E2 (probable cruce)
SI exactamente 1 campo discrepa en 1 documento (severidad ALTA/MEDIA) → E3 (probable typo)
SI ≥3 campos discrepan + firma genérica + datos demasiado limpios → E1 (sospecha falsificación)
EN OTRO CASO → none / manual_review estándar
```

**Verificación FASE 2:** Suite de fixtures sintéticos cubriendo E1-E5 + casos ambiguos.

### FASE 3 — Inteligencia operativa (P2, ~1 mes)

**Objetivo:** Dar memoria al sistema. Habilitar detección de patrones temporales y validación enriquecida.

| Tarea | Archivo nuevo / componente | Función |
|-------|---------------------------|---------|
| D4 — Histórico funcionario | `app/Models/FuncionarioMetricsModel.php` | Tabla agregada por funcionario: total_dispensaciones, tasa_manual_review, tasa_falsificacion_sospechada. Recalcular en cada `audit_completed`. |
| D4 — Histórico paciente | `app/Models/PacienteDispensacionesModel.php` | Vista de últimas N dispensaciones por DocumentoPaciente. Detección de anomalías cuantitativas. |
| D5 — Feedback del auditor | `app/Models/AuditFeedbackModel.php` + `POST /audit/feedback/{auditId}` | Persistir decisión humana sobre `manual_review`. Loop de aprendizaje. |
| D3 — Firma enriquecida | [DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) `buildUserPrompt` (~L148) | Pedir a Gemini: ¿cédula manuscrita junto a firma?, ¿coincide con paciente?, ¿posición?, ¿tipo de firma? |
| D6 — Forense PDF | [DocumentExtractionWorker.php](app/Services/Audit/Events/DocumentExtractionWorker.php) `handle` (~L73) | Pre-Gemini: extraer metadata del PDF (Producer, Author, CreationDate). Flag forense si Producer es Photoshop/manipulado. |

**Verificación FASE 3:** Después de 100 auditorías, los modelos histórico de funcionario/paciente deben estar poblados y el aggregator debe usarlos para enriquecer decisiones.

### FASE 4 — Modelo estructural de config + versionado (P3, ~3 semanas)

**Objetivo:** Auditabilidad y reproducibilidad regulatoria.

| Tarea | Componente | Función |
|-------|-----------|---------|
| D9 — Schema explícito | [AuditConfigModel.php](app/Models/AuditConfigModel.php) `getConfig` (~L46) + tabla `AudDispCampo` | Separar `headerFields` y `itemFields[{name, validateAs}]`. Migración de datos existente. |
| Versionado de config | nueva tabla `AudDispVersion` + [AuditConfigModel.php](app/Models/AuditConfigModel.php) | Cada cambio genera versión. Auditorías referencian versión usada. |
| Versionado de hallazgo | [AuditEvent.php](app/Services/Audit/Events/AuditEvent.php) payload + tabla `AudDispEst` | Persistir versiones de FieldClassifier, DocumentNormalizer, PolicyEngine usados. |

**Verificación FASE 4:** Cambios en audit-config generan nueva versión sin romper auditorías en curso. Una auditoría de hace 6 meses puede reproducirse exactamente con las versiones que tenía.

---

## 9. Verificación y métricas

### 9.1 Verificación end-to-end por fase

**FASE 0 — Tiritas:**
1. Tests unitarios:
   ```powershell
   docker compose exec php-fpm php vendor/bin/phpunit tests/Services/Audit/Events/DocumentNormalizerTest.php
   docker compose exec php-fpm php vendor/bin/phpunit tests/Services/Audit/Events/DocumentPolicyEngineTest.php
   ```
2. Re-ejecutar golden case:
   ```powershell
   Invoke-RestMethod -Uri "http://localhost:8080/audit/single" -Method POST `
     -ContentType "application/json" -Body '{"DisDetNro":"T38250701547"}' `
     | ConvertTo-Json -Depth 20
   ```
3. Verificar en `responseIA/` que AUTORIZACION ya no genera findings de `"Firma de quien autoriza"` / `"Sello"`.

**FASE 1 — Falsos positivos sistémicos:**
- Golden case T38250701547 termina con 0 hallazgos (los 2 items se aprueban independientemente).
- Auditorías de varios clientes (POSITIVA, SANITAS) ejecutadas y comparadas — tasa de `manual_review` baja del baseline.

**FASE 1.5 — Motor de reglas de negocio:**
- Golden case T38250701547: la entrega 20+30 unidades pasa porque está dentro de tolerancia + factor de empaque (regla A de POSITIVA).
- Caso sintético MIPRES sin documento MIPRES → `audit_failed` con causa explícita.
- Caso sintético con `FechaEntrega - FechaFormula > maxDias` → hallazgo temporal con severidad configurada.
- Onboarding de NUEVA EPS / SALUD TOTAL: con audit-config + reglas, ejecutar auditoría real y validar manualmente.

**FASE 2 — Clasificación:**
- Fixtures sintéticos cubren E1-E5. `PatternClassifier` etiqueta cada uno correctamente.
- El estado final de cada auditoría incluye el escenario detectado.

**FASE 3 — Inteligencia:**
- Después de 100 auditorías, dashboards con tasa de `manual_review` por funcionario y por paciente.
- Una auditoría con un paciente que tiene historial de cantidades altas no genera anomalía cuantitativa.

**FASE 4 — Versionado:**
- Cambio de config en POSITIVA genera versión v2. Auditorías nuevas usan v2; auditorías en curso completan con v1.
- Reproducción de una auditoría de v1 desde sus eventos congelados produce el mismo resultado.

### 9.2 Métricas de éxito

| Métrica | Baseline (estimado) | Post-FASE 0 | Post-FASE 1 | Post-FASE 1.5 | Post-FASE 3 |
|---------|--------------------|--------------|--------------|---------------|--------------|
| Tasa de `manual_review` POSITIVA | ~30% | ~25% | <15% | <10% | <8% |
| Clientes operativos | 1 (POSITIVA bien) | 1 | 1 | **6+** (con onboarding) | 10+ |
| Tiempo medio revisión humana / caso | ~5-10 min | igual | -30% (reducción FP) | -50% (reglas captadas) | -60% (con histórico) |
| Costo Gemini / auditoría (tokens) | ~15 400 | igual | -10% | igual | -25% (cache + budget tuneado) |
| Casos E1 detectados explícitamente | 0 (no diferenciado) | 0 | 0 | 0 | >baseline |
| Auditorías colgadas (sin completar tras 1h) | desconocido | igual | -100% (F8 fix) | -100% | igual |
| Documentos obligatorios faltantes detectados | 0 (no validado) | 0 | 0 | **detectados explícitamente** | igual |

### 9.3 Telemetría requerida (a implementar transversalmente)

- **Per-audit:** versión de pipeline, tokens consumidos, duración por etapa, hallazgos por documento, escenario detectado, **regla de negocio aplicada** (factor empaque, tolerancia, etc.).
- **Per-funcionario:** auditorías procesadas, tasa de `manual_review`, distribución de escenarios.
- **Per-cliente:** tasa de `manual_review`, costo medio Gemini, tipo de hallazgo más frecuente, **uso de tolerancias activas**.

---

## 10. Restricciones, supuestos y exclusiones

### 10.1 Restricciones de alcance

- **Solo auditoría interna.** No se proponen integraciones con RUAF, INVIMA, RETHUS, REPS u otros sistemas externos. La FDV interna es el ancla de verdad.
- **No re-arquitectura del pipeline event-driven.** El modelo Redis Streams + 5 workers se mantiene. Las mejoras son sobre los componentes existentes.

### 10.2 Supuestos

- El audit-config por cliente refleja correctamente las reglas de negocio (validación humana asumida).
- La FDV en BD es trustworthy a nivel transaccional (los funcionarios registran honestamente, salvo casos E1).
- Gemini 3.x con Function Calling **no es determinístico por defecto** (confirmado F9 en runs 2026-04-26). La fijación de `thinkingBudget` y `temperature: 0` (fix F9) reduce —pero no elimina— la variabilidad. Auditorías críticas deben compararse contra la extracción original, no re-ejecutarse.
- El equipo dispone de auditores humanos para el feedback loop de FASE 3.
- Las reglas de negocio por cliente están documentadas en contratos físicos accesibles para parametrizar las tablas de FASE 1.5.

### 10.3 Riesgos no mitigados en este roadmap

- **DLQ congela auditorías:** si un documento falla 3 veces y va a DLQ, su `document_normalized` nunca se publica y la auditoría queda incompleta. Este riesgo existe pero su corrección requiere un componente de reproceso automático del DLQ que no está en este roadmap. Considerar para v1.2.
- **Mensajes en PEL sin reclaim:** crashes de workers dejan mensajes atascados en Pending Entries List sin XAUTOCLAIM. Operación manual necesaria.
- **TTL de Redis (24h):** auditorías que excedan 24h desde su inicio pueden perder estado. No hay fallback a SQL como source of truth durante el procesamiento.
- **Onboarding masivo de clientes:** parametrizar reglas de FASE 1.5 para 22 clientes requiere coordinación con el área comercial / legal para validar tolerancias y plazos contractuales. No es un trabajo solo de ingeniería.

Estos riesgos se documentan aquí pero su tratamiento queda para una iteración posterior de robustez del pipeline.

### 10.4 Reversibilidad

- **FASES 0 y 1**: cambios incrementales en componentes existentes. Reversibles vía `git revert`.
- **FASE 1.5**: tablas nuevas + componentes nuevos. Reversible si se mantienen tablas vacías y los nuevos evaluadores se omiten cuando no hay reglas. Diseñar feature flag por tabla.
- **FASES 2 y 3**: capacidades nuevas. Pueden activarse vía feature flag y desactivarse sin afectar el flujo principal.
- **FASE 4**: cambio estructural del config. Requiere migración de datos. Diseñar plan de rollback antes de ejecutar.

---

## 11. Apéndice — Datos de referencia

### 11.1 Audit-config de POSITIVA (NitSec 2426 = caso de referencia)

```json
{
  "DISPENSA": {
    "docId": 1,
    "fields": ["Cliente", "NITCliente", "IPS", "NombrePaciente", ..., "FirmaActaEntrega"],
    "visualChecks": [{"check": "FirmaActaEntrega", "severity": "CRITICO"}]
  },
  "AUTORIZACION": {
    "docId": 2,
    "fields": ["NumeroAutorizacion", "Cliente", "NombrePaciente", ...],
    "visualChecks": []
  },
  "FORMULA MEDICA": {
    "docId": 3,
    "fields": ["IPS", "NombrePaciente", ..., "NumeroAutorizacion"],
    "visualChecks": [{"check": "FirmaPrescriptor", "severity": "CRITICO"}]
  }
}
```

### 11.2 Comparación de configs entre 4 clientes

```
POSITIVA (2426)        SANITAS (1165)            NUEVA EPS (2624)        SALUD TOTAL (1169)
─────────────────      ───────────────────       ─────────────────────   ──────────────────────
DISPENSA               ACTA DE ENTREGA           ACTA DE ENTREGA          ACTA DE ENTREGA
                                                                          (sin audit-config)
AUTORIZACION           AUTORIZACION DE SERV.     AUTORIZACION             AUTORIZACION
                                                                          AUTORIZACION (¡duplicada!)
FORMULA MEDICA         FORMULA MEDICA            FORMULA MEDICA           FORMULA MEDICA
                       VALIDADOR DE DERECHOS     VALIDADOR DE DERECHOS    VALIDACION DE DERECHOS
                                                                          (typo vs sinónimo)

3 docs                 4 docs (mismos fields     4 docs (sin config)      5 docs (sin config)
visualChecks: 2        para los 4 - default      visualChecks: ?          visualChecks: ?
                       sin afinar)
                       visualChecks: 0
```

### 11.3 Adjuntos retornados por la API (POSITIVA)

```json
[
  {"id_documento": "1", "nombre_documento": "DISPENSA"},
  {"id_documento": "2", "nombre_documento": "AUTORIZACION"},
  {"id_documento": "3", "nombre_documento": "FORMULA MEDICA"}
]
```

### 11.4 Funcionario que registró la entrega del golden case

`MARIA JOSE POLANIA FLOREZ` (visible en el documento DISPENSA del golden case).

### 11.5 Resumen del consumo de tokens Gemini

```
DISPENSA:        prompt 1424  + thinking 3531 + response  540 = 5495 tokens
AUTORIZACION:    prompt 1003  + thinking 1097 + response  225 = 2325 tokens
FORMULA MEDICA:  prompt 1083  + thinking 4985 + response 1516 = 7584 tokens
─────────────────────────────────────────────────────────────────────────
Total auditoría:  3510 prompt + 9613 thinking + 2281 response = 15 404 tokens
```

Modelo: `gemini-3.1-pro-preview`. Sin cache hits. Extended thinking representa ~62% del consumo.

### 11.6 Reglas de negocio mencionadas explícitamente por DISCOLMETS

| Cliente | Regla | Tipo (D11-D15) |
|---------|-------|---------------|
| POSITIVA (2426) | Permite +5 unidades por encima de lo autorizado por factor de empaque | D11 (Tolerancia ABS) |
| POSITIVA (2426) | Permite entregas parciales (caso "3 de 3" del golden case) | D11 + histórico de parciales |

Esta lista es un punto de partida. Durante la implementación de FASE 1.5, se requiere relevamiento exhaustivo con el área operativa de cada uno de los 22 clientes.

---

**Fin del documento.**

Este informe está vinculado al plan en `C:\Users\USER\.claude\plans\c-users-user-desktop-audfact-responseia-enumerated-rose.md` y a los hallazgos críticos referenciados en `plans/audit-findings.md`. Para preguntas de implementación, dirigirse a la sección 8 (Hoja de ruta) y a los archivos específicos referenciados.
