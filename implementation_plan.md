# SPC-001: Normalización `AudDispCampo` — Especificación Determinística

> **Versión**: 1.0 · **Estado**: Pendiente aprobación · **Archivos afectados**: 11

---

## 1. Objetivo

Separar los metadatos intrínsecos de cada campo auditable (inmutables, universales) de la configuración por cliente (toggles, orden, overrides) mediante una tabla catálogo SQL normalizada. Esto elimina la fragilidad del round-trip `UI → API → BD` donde el `DELETE + INSERT` actual puede destruir `CodigoCampo` y `TipoDato`.

---

## 2. Modelo de Datos

### 2.1 [NEW] Tabla `Discolnet.dbo.AudDispCampoCatalogo`

**Propósito**: Fuente de verdad para metadatos intrínsecos de campos auditables.

```sql
CREATE TABLE Discolnet.dbo.AudDispCampoCatalogo (
    CampoNombre    VARCHAR(100)  NOT NULL,
    CodigoCampo    VARCHAR(50)   NOT NULL,
    TipoCampo      CHAR(1)       NOT NULL,
    TipoDato       VARCHAR(50)       NULL,
    Descripcion    VARCHAR(500)      NULL,
    Severidad      VARCHAR(10)   NOT NULL  CONSTRAINT DF_Catalogo_Sev DEFAULT 'alta',
    EsVisual       BIT           NOT NULL  CONSTRAINT DF_Catalogo_Vis DEFAULT 0,
    CONSTRAINT PK_AudDispCampoCatalogo PRIMARY KEY (CampoNombre),
    CONSTRAINT UQ_Catalogo_Codigo      UNIQUE (CodigoCampo),
    CONSTRAINT CK_Catalogo_TipoCampo   CHECK (TipoCampo IN ('E','S','B','V')),
    CONSTRAINT CK_Catalogo_Severidad   CHECK (Severidad IN ('alta','media','baja')),
    CONSTRAINT CK_Catalogo_VisualNull  CHECK (
        (EsVisual = 1 AND TipoCampo = 'V' AND TipoDato IS NULL)
        OR (EsVisual = 0 AND TipoCampo <> 'V' AND TipoDato IS NOT NULL)
    )
);
```

**Restricciones formales**:
- `CampoNombre`: PK, `VARCHAR(100)`, regex `^[A-Za-z0-9_.\-]{1,100}$`
- `CodigoCampo`: UNIQUE, NOT NULL — nunca puede ser NULL en catálogo
- `TipoCampo`: `E`=Exacto, `S`=Semántico, `B`=Negocio, `V`=Visual
- `TipoDato`: NOT NULL para no-visuales; NULL para visuales. Valores válidos: enum `AuditFieldValueType` (`text`, `date`, `quantity`, `money`, `identity_doc_type`, `identity_doc_number`, `code`, `trace_token`, `person_name`, `institution_name`, `article_name`)
- `EsVisual`: `1` si y solo si `TipoCampo = 'V'`

### 2.2 Seed Data (28 filas)

```sql
INSERT INTO Discolnet.dbo.AudDispCampoCatalogo
    (CampoNombre, CodigoCampo, TipoCampo, TipoDato, Descripcion, Severidad, EsVisual)
VALUES
    ('Cliente',                'CLI',  'S', 'institution_name',  NULL, 'alta', 0),
    ('NITCliente',             'CLN',  'E', 'text',              NULL, 'alta', 0),
    ('IPS',                    'IPS',  'S', 'institution_name',  NULL, 'alta', 0),
    ('NombrePaciente',         'PAC',  'S', 'person_name',       NULL, 'alta', 0),
    ('TipoDocumentoPaciente',  'TDP',  'E', 'identity_doc_type', NULL, 'alta', 0),
    ('DocumentoPaciente',      'DOP',  'E', 'identity_doc_number', NULL, 'alta', 0),
    ('Medico',                 'MED',  'S', 'person_name',       NULL, 'alta', 0),
    ('NumeroFactura',          'FACN', 'E', 'text',              NULL, 'alta', 0),
    ('CodigoDiagnostico',      'DX',   'E', 'code',              NULL, 'alta', 0),
    ('FechaEntrega',           'FEN',  'E', 'date',              NULL, 'alta', 0),
    ('FechaFormula',           'FFO',  'E', 'date',              NULL, 'alta', 0),
    ('FechaAutorizacion',      'FAU',  'E', 'date',              NULL, 'alta', 0),
    ('NumeroAutorizacion',     'AUT',  'E', 'text',              NULL, 'alta', 0),
    ('CodigoArticulo',         'ART',  'E', 'code',              NULL, 'alta', 0),
    ('CodigoProducto',         'PRD',  'E', 'code',              NULL, 'alta', 0),
    ('NombreArticulo',         'NAM',  'S', 'article_name',      NULL, 'alta', 0),
    ('Laboratorio',            'LAB',  'S', 'text',              NULL, 'alta', 0),
    ('CUM',                    'CUM',  'E', 'text',              NULL, 'alta', 0),
    ('Lote',                   'LOT',  'E', 'trace_token',       NULL, 'alta', 0),
    ('FechaVencimiento',       'VEN',  'E', 'date',              NULL, 'alta', 0),
    ('CantidadEntregada',      'CEN',  'B', 'quantity',          NULL, 'alta', 0),
    ('CantidadPrescrita',      'CPR',  'B', 'quantity',          NULL, 'alta', 0),
    ('VlrCobrado',             'VLR',  'E', 'text',              NULL, 'alta', 0),
    ('Mipres',                 'MIP',  'E', 'text',              NULL, 'alta', 0),
    ('NITDiscolmets',          'DIS',  'E', 'text',              NULL, 'alta', 0),
    ('FirmaActaEntrega',       'FIR',  'V', NULL, 'Verificar que el acta o soporte de entrega tenga firma de recibido.', 'alta', 1),
    ('VigenciaEntrega',        'VIG',  'V', NULL, 'Verificar que el documento indique la vigencia o plazo de entrega autorizado; extraer dias y fecha base si estan visibles.', 'alta', 1),
    ('FirmaPrescriptor',       'FPRE', 'V', NULL, 'Verificar que la formula medica tenga firma del prescriptor.', 'alta', 1);
```

### 2.3 [MODIFY] Tabla `Discolnet.dbo.AudDispCampo`

> [!CAUTION]
> **Descubierto en auditoría SDD**: La PK actual es `PK_AudDispCampo (FacNitSec, NitMedDocId, CampoNombre, TipoCampo)` — CLUSTERED. `TipoCampo` está en la PK. No se puede hacer `DROP COLUMN TipoCampo` sin primero eliminar y recrear la PK.

**PK actual** `[CONFIRMADO]`:
```
PK_AudDispCampo CLUSTERED (FacNitSec, NitMedDocId, CampoNombre, TipoCampo)
```

**PK nueva** (sin `TipoCampo`):
```
PK_AudDispCampo CLUSTERED (FacNitSec, NitMedDocId, CampoNombre)
```

**Secuencia DDL** (orden estricto):

```sql
-- Paso 1: Agregar FK al catálogo
ALTER TABLE Discolnet.dbo.AudDispCampo
    ADD CONSTRAINT FK_AudDispCampo_Catalogo
    FOREIGN KEY (CampoNombre)
    REFERENCES Discolnet.dbo.AudDispCampoCatalogo(CampoNombre);

-- Paso 2: Eliminar PK existente (incluye TipoCampo)
ALTER TABLE Discolnet.dbo.AudDispCampo
    DROP CONSTRAINT PK_AudDispCampo;

-- Paso 3: Recrear PK sin TipoCampo
ALTER TABLE Discolnet.dbo.AudDispCampo
    ADD CONSTRAINT PK_AudDispCampo
    PRIMARY KEY CLUSTERED (FacNitSec, NitMedDocId, CampoNombre);

-- Paso 4: Eliminar columnas redundantes
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN TipoCampo;
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN TipoDato;
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN CodigoCampo;
```

**Prerequisito del Paso 3**: La nueva PK `(FacNitSec, NitMedDocId, CampoNombre)` debe ser única. Verificación obligatoria:
```sql
SELECT FacNitSec, NitMedDocId, CampoNombre, COUNT(*) AS cnt
FROM Discolnet.dbo.AudDispCampo
GROUP BY FacNitSec, NitMedDocId, CampoNombre
HAVING COUNT(*) > 1;
-- Resultado esperado: 0 filas. Si hay filas → hay duplicados que deben resolverse antes.
```

**Esquema resultante de `AudDispCampo`**:

| Columna | Tipo | NULL | Descripción |
|---|---|---|---|
| `FacNitSec` | varchar | NO | FK → AudDisp.FacNitSec |
| `NitMedDocId` | int | NO | FK → NitDocumentos.NitMedDocId |
| `CampoNombre` | varchar(100) | NO | FK → AudDispCampoCatalogo.CampoNombre |
| `Activo` | bit | NO | Toggle on/off |
| `Orden` | int | NO | Orden de procesamiento |
| `DescripcionOverride` | varchar | SÍ | Override de Descripcion del catálogo |
| `SeveridadOverride` | varchar | SÍ | Override de Severidad del catálogo |

### 2.4 Prerequisito: Data Fix antes de FK

Antes de agregar la FK, todos los `CampoNombre` existentes en `AudDispCampo` deben existir en el catálogo. Verificación:

```sql
SELECT DISTINCT ac.CampoNombre
FROM Discolnet.dbo.AudDispCampo ac
WHERE NOT EXISTS (
    SELECT 1 FROM Discolnet.dbo.AudDispCampoCatalogo cat
    WHERE cat.CampoNombre = ac.CampoNombre
);
-- Resultado esperado: 0 filas. Si hay filas, agregarlas al seed antes de crear la FK.
```

---

## 3. Contratos de API

### 3.1 [NEW] `GET /audit/field-catalog`

**Ruta**: `app/Routes/web.php` — nueva línea después de la línea 15.
**Controlador**: `AuditConfigController::catalog`

**Request**: Sin parámetros.

**Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Catálogo de campos auditables",
  "data": [
    {
      "campoNombre": "NombrePaciente",
      "codigoCampo": "PAC",
      "tipoCampo": "S",
      "tipoDato": "person_name",
      "descripcion": null,
      "severidad": "alta",
      "esVisual": false
    }
  ]
}
```

**Errores posibles**: Solo HTTP 500 (fallo de BD).

### 3.2 [MODIFY] `GET /clients/{clientId}/audit-config`

**Contrato de respuesta ANTES** (por campo de datos):
```json
{ "campoNombre": "X", "tipoCampo": "E", "tipoDato": "text", "orden": 1, "severity": "alta", "codigoCampo": "XX" }
```

**Contrato de respuesta DESPUÉS** (sin cambios en la estructura JSON):
```json
{ "campoNombre": "X", "tipoCampo": "E", "tipoDato": "text", "orden": 1, "severity": "alta", "codigoCampo": "XX" }
```

> Los valores de `tipoCampo`, `tipoDato` y `codigoCampo` ahora vienen del JOIN con el catálogo, no de columnas de `AudDispCampo`. El contrato de salida es **idéntico** — el frontend y el pipeline no ven diferencia.

### 3.3 [MODIFY] `POST /clients/{clientId}/audit-config`

**Contrato de entrada ANTES** (por campo):
```json
{ "docId": 1, "campoNombre": "X", "tipoCampo": "E", "tipoDato": "text", "orden": 1, "description": null, "severity": "alta", "codigoCampo": "XX" }
```

**Contrato de entrada DESPUÉS** (simplificado):
```json
{ "docId": 1, "campoNombre": "X", "orden": 1, "description": null, "severity": "alta" }
```

**Campos eliminados del payload**: `tipoCampo`, `tipoDato`, `codigoCampo`. Si la UI los envía, se **ignoran silenciosamente** (backward compatible).

**Validación por campo**:

| Campo | Regla | Error si falla |
|---|---|---|
| `docId` | requerido, `is_numeric`, `> 0` | `'docId' requerido y numérico.` |
| `campoNombre` | requerido, string, regex `^[A-Za-z0-9_.\-]{1,100}$`, no en `EXCLUDED_FIELDS`, **debe existir en catálogo** | `'campoNombre' no existe en el catálogo de campos.` |
| `orden` | int, default 0 | — |
| `description` | string nullable, trim | — |
| `severity` | `ALTA\|MEDIA\|BAJA` (case-insensitive), default `ALTA` | silencioso (fallback a ALTA) |

**Nueva validación**: `campoNombre` se valida contra el catálogo SQL. Si no existe → error 422.

**Response** (sin cambios): `{ "success": true, "data": { "fieldCount": N } }`

---

## 4. Cambios por Archivo

### 4.1 [MODIFY] `app/Routes/web.php` (1 línea)

Agregar después de línea 15:
```php
$router->get('/audit/field-catalog', 'AuditConfigController', 'catalog');
```

### 4.2 [MODIFY] `app/Models/AuditConfigModel.php`

**Método `getConfig()` — Query ANTES** (líneas 35-52):
```sql
SELECT nd.NitMedDocId AS docId, nd.NitMedDocNom AS docNombre,
       ac.CampoNombre, ac.TipoCampo, ac.TipoDato, ac.Orden,
       ac.DescripcionOverride, ac.SeveridadOverride, ac.CodigoCampo
FROM Discolnet.dbo.AudDispCampo ac ...
```

**Método `getConfig()` — Query DESPUÉS**:
```sql
SELECT nd.NitMedDocId AS docId, nd.NitMedDocNom AS docNombre,
       cat.CampoNombre, cat.TipoCampo, cat.TipoDato,
       cat.CodigoCampo, cat.EsVisual,
       cat.Descripcion AS DescripcionDefault,
       cat.Severidad   AS SeveridadDefault,
       ac.Orden, ac.DescripcionOverride, ac.SeveridadOverride
FROM Discolnet.dbo.AudDispCampo ac WITH (NOLOCK)
INNER JOIN Discolnet.dbo.AudDispCampoCatalogo cat WITH (NOLOCK)
    ON cat.CampoNombre = ac.CampoNombre
INNER JOIN NitDocumentos nd WITH (NOLOCK)
    ON nd.NitSec = ac.FacNitSec AND nd.NitMedDocId = ac.NitMedDocId
WHERE ac.FacNitSec = :nitSec AND ac.Activo = 1 AND nd.NitMedDocOpc = 'N'
ORDER BY nd.NitMedDocId ASC, cat.EsVisual ASC, ac.Orden ASC
```

**Mapeo de filas** — lógica de agrupación (reemplaza líneas 64-95):
- Condición de visual: `$row['EsVisual']` (antes: `$row['TipoCampo'] !== 'V'`)
- `tipoDato`: `$row['TipoDato']` (del catálogo, nunca NULL para no-visuales)
- `codigoCampo`: `$row['CodigoCampo']` (del catálogo, nunca NULL)
- `severity` para fields: `$row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'media'`
- `severity` para visual checks: `$row['SeveridadOverride'] ?? $row['SeveridadDefault'] ?? 'alta'`
- `description` para visual checks: `$row['DescripcionOverride'] ?? $row['DescripcionDefault'] ?? ''`

**Método `replaceFields()` — INSERT ANTES** (líneas 220-227):
```sql
INSERT INTO ... (FacNitSec, NitMedDocId, CampoNombre, TipoCampo, TipoDato, Activo, Orden,
                 DescripcionOverride, SeveridadOverride, CodigoCampo)
VALUES (:nitSec, :docId, :campoNombre, :tipoCampo, :tipoDato, 1, :orden,
        :description, :severity, :codigoCampo)
```

**Método `replaceFields()` — INSERT DESPUÉS**:
```sql
INSERT INTO Discolnet.dbo.AudDispCampo
    (FacNitSec, NitMedDocId, CampoNombre, Activo, Orden,
     DescripcionOverride, SeveridadOverride)
VALUES
    (:nitSec, :docId, :campoNombre, 1, :orden,
     :description, :severity)
```

Binds eliminados: `:tipoCampo`, `:tipoDato`, `:codigoCampo` y todo su manejo de NULL.

**[NEW] Método `getCatalog(): array`**:
```php
public function getCatalog(): array
{
    $sql = "SELECT CampoNombre, CodigoCampo, TipoCampo, TipoDato,
                   Descripcion, Severidad, EsVisual
            FROM Discolnet.dbo.AudDispCampoCatalogo WITH (NOLOCK)
            ORDER BY EsVisual ASC, CampoNombre ASC";
    $stmt = $this->readDb->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

**[NEW] Método `catalogFieldExists(string $campoNombre): bool`**:
```php
public function catalogFieldExists(string $campoNombre): bool
{
    $sql = "SELECT 1 FROM Discolnet.dbo.AudDispCampoCatalogo WITH (NOLOCK)
            WHERE CampoNombre = :campo";
    $stmt = $this->readDb->prepare($sql);
    $stmt->bindParam(':campo', $campoNombre, \PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
}
```

### 4.3 [MODIFY] `app/Controllers/AuditConfigController.php`

**[NEW] Método `catalog()`**:
```php
public function catalog(): void
{
    $rows = $this->model->getCatalog();
    $catalog = array_map(fn(array $row) => [
        'campoNombre' => $row['CampoNombre'],
        'codigoCampo' => $row['CodigoCampo'],
        'tipoCampo'   => $row['TipoCampo'],
        'tipoDato'    => $row['TipoDato'] !== null ? strtolower(trim($row['TipoDato'])) : null,
        'descripcion' => $row['Descripcion'],
        'severidad'   => $row['Severidad'],
        'esVisual'    => (bool) $row['EsVisual'],
    ], $rows);
    Response::success($catalog, 'Catálogo de campos auditables');
}
```

**Método `sanitizeFields()` — CAMBIOS**:
1. **Eliminar** llamadas a: `sanitizeTipoCampo()`, `sanitizeTipoDato()`, `sanitizeCodigoCampo()`
2. **Agregar** validación contra catálogo: `if (!$this->model->catalogFieldExists($campoNombre)) throw ...`
3. **Eliminar** del array `$sanitized`: claves `tipoCampo`, `tipoDato`, `codigoCampo`

**Métodos a ELIMINAR** (código muerto post-normalización):
- `sanitizeTipoCampo()` (líneas 205-214)
- `sanitizeTipoDato()` (líneas 216-238)
- `sanitizeCodigoCampo()` (líneas 183-203)
- `typeCombinationError()` (líneas 240-250)

**Import a ELIMINAR**: `use App\Services\Audit\AuditFieldValueType;` (línea 8) — ya no se usa.

### 4.4 Pipeline — Impacto CERO funcional

El pipeline (`DocumentAuditOrchestrator`, `DocumentPolicyEngine`, `VisualCheckEvaluator`, `DeliveryValidityEvaluator`) consume los datos a través de `AuditDataService::getAuditConfig()` → `AuditConfigModel::getConfig()`. El contrato de salida de `getConfig()` **no cambia** — los arrays siguen teniendo las mismas claves (`tipoCampo`, `tipoDato`, `codigoCampo`, `severity`, etc.). La fuente de esos valores pasa de columnas en `AudDispCampo` a JOIN con `AudDispCampoCatalogo`, pero el pipeline no lo sabe.

**Verificación**: Los campos que el pipeline valida explícitamente:
- `$field['tipoDato']` en `normalizeSchemaFields()` línea 402 → seguirá siendo string no-vacío (viene del catálogo)
- `$field['codigoCampo']` en `DocumentPolicyEngine` línea 261 → seguirá siendo string o null (viene del catálogo, nunca NULL por constraint)
- `$check['codigoCampo']` en `VisualCheckEvaluator` línea 95 → ídem

### 4.5 [MODIFY] Frontend — 4 archivos

> [!IMPORTANT]
> El frontend pasa de ser **editor de metadatos** (tipoCampo, tipoDato seleccionables) a **visor de metadatos** (badges readonly del catálogo). Los controles editables que permanecen: toggle on/off, orden, severidad override, descripción override.

#### 4.5.1 `frontend/lib/api/endpoints.ts` — [MODIFY] L10

**Agregar** después de línea 10 (`auditConfig`):
```typescript
fieldCatalog: () => "/audit/field-catalog",
```

#### 4.5.2 `frontend/lib/schemas/domain.ts` — [MODIFY] L70, L81+

**Sin cambios** en `AuditConfigFieldSchema` (L70-81) ni `AuditVisualCheckSchema` (L83-90). `[CONFIRMADO]` — el contrato GET no cambia, los schemas siguen parseando la misma respuesta.

**Agregar** después de `AuditVisualCheckSchema` (después de L90):
```typescript
export const FieldCatalogItemSchema = z.object({
  campoNombre: z.string(),
  codigoCampo: z.string(),
  tipoCampo: z.string(),
  tipoDato: z.string().nullable(),
  descripcion: z.string().nullable(),
  severidad: z.string(),
  esVisual: z.boolean(),
});
export type FieldCatalogItem = z.infer<typeof FieldCatalogItemSchema>;
export const FieldCatalogSchema = z.array(FieldCatalogItemSchema);
```

#### 4.5.3 `frontend/lib/api/audfact.ts` — [MODIFY] L142-155

**ANTES** (L142-155):
```typescript
export type AuditConfigPayload = {
  systemPrompt?: string | null;
  fields: Array<{
    docId: number;
    campoNombre: string;
    tipoCampo: string;
    tipoDato?: string | null;
    enabled: boolean;
    description?: string | null;
    severity?: string | null;
    orden: number;
    codigoCampo?: string | null;
  }>;
};
```

**DESPUÉS**:
```typescript
export type AuditConfigPayload = {
  systemPrompt?: string | null;
  fields: Array<{
    docId: number;
    campoNombre: string;
    enabled: boolean;
    description?: string | null;
    severity?: string | null;
    orden: number;
  }>;
};
```

**Campos eliminados del tipo**: `tipoCampo`, `tipoDato`, `codigoCampo`. `[CONFIRMADO]` — el backend los ignora silenciosamente (§3.3).

**Agregar** función (después de `saveAuditConfig`):
```typescript
import { FieldCatalogSchema } from "@/lib/schemas/domain";

export function getFieldCatalog() {
  return requestJson(endpoints.fieldCatalog(), FieldCatalogSchema);
}
```

#### 4.5.4 `frontend/components/audit/audit-config-editor.tsx` — [MODIFY] 12 cambios

##### ELIMINACIONES (código muerto post-normalización)

| # | Líneas | Elemento | Motivo |
|---|---|---|---|
| 1 | 54-59 | `type VisualCheckOption` | Reemplazado por `FieldCatalogItem` del catálogo `[CONFIRMADO]` |
| 2 | 61 | `type TipoCampoValue` | Solo usada por `tipoDatoOptions`/`isTipoDatoAllowed` `[CONFIRMADO]` |
| 3 | 63-67 | `type TipoDatoOption` | Solo usada por `tipoDatoOptions` `[CONFIRMADO]` |
| 4 | 77-97 | `const visualCheckOptions` | Hardcodeo → viene del catálogo `[CONFIRMADO]` |
| 5 | 99-111 | `const tipoDatoOptions` | Hardcodeo → viene del catálogo `[CONFIRMADO]` |
| 6 | 124-127 | `function normalizeTipoCampo()` | Solo usada por `tipoDatoOptionsFor` `[CONFIRMADO]` |
| 7 | 129-131 | `function isTipoDatoAllowed()` | Solo usada por `fieldValidationError` y `<Select>` de tipoCampo `[CONFIRMADO]` |
| 8 | 134-138 | `function tipoDatoOptionsFor()` | Solo usada por `FieldRow` L873 `[CONFIRMADO]` |

##### MODIFICACIONES

**9. `fieldValidationError()` (L141-148) — simplificar:**

**ANTES**:
```typescript
function fieldValidationError(field: FieldToggle): string | null {
  if (!field.enabled || field.tipoCampo === "V") return null;
  if (!field.tipoDato) return "Define el tipo de dato.";
  if (!isTipoDatoAllowed(field.tipoCampo, field.tipoDato)) {
    return "Combinación tipo/comparación inválida.";
  }
  return null;
}
```

**DESPUÉS**:
```typescript
function fieldValidationError(field: FieldToggle): string | null {
  if (!field.enabled || field.tipoCampo === "V") return null;
  if (!field.campoNombre.trim()) return "Define el nombre del campo.";
  return null;
}
```
`[CONFIRMADO]` — la validación de `tipoDato` y combinación tipo/dato ya no corresponde al frontend; el catálogo garantiza consistencia.

**10. `buildPayload()` (L343-369) — simplificar:**

**ANTES**:
```typescript
fields.push({
  docId: doc.docId,
  campoNombre: f.campoNombre,
  tipoCampo: f.tipoCampo,
  tipoDato: f.tipoCampo === "V" ? null : (f.tipoDato ?? null),
  enabled: true,
  description: f.descripcionOverride ?? null,
  severity: f.severityOverride ?? null,
  orden: f.orden,
  codigoCampo: f.codigoCampo ?? null,
});
```

**DESPUÉS**:
```typescript
fields.push({
  docId: doc.docId,
  campoNombre: f.campoNombre,
  enabled: true,
  description: f.descripcionOverride ?? null,
  severity: f.severityOverride ?? null,
  orden: f.orden,
});
```

**11. `FieldRow` — Selects → Badges readonly (L927-971):**

**ANTES** (L930-970): grid de 3 columnas con `<Select>` para Tipo, `<Select>` para Dato, `<Select>` para Severidad.

**DESPUÉS**: grid de 3 columnas donde:
- **Columna 1 (Tipo)**: Badge readonly que muestra `tipoCampo` como texto ("Exacto", "Semántico", "Negocio")
- **Columna 2 (Dato)**: Badge readonly que muestra `tipoDato` como label legible (mapeo inline: `text`→"Texto", `date`→"Fecha", `quantity`→"Cantidad", `person_name`→"Persona", `institution_name`→"Institución", `code`→"Código", `trace_token`→"Trazabilidad", `identity_doc_type`→"Tipo doc.", `identity_doc_number`→"Documento", `money`→"Dinero", `article_name`→"Artículo")
- **Columna 3 (Severidad)**: `<Select>` — **sin cambios** (sigue siendo editable)

```tsx
{/* Columna 1: Tipo — readonly badge */}
<div className="space-y-1">
  <span className="text-[9px] font-bold uppercase tracking-widest text-slate-600">Tipo</span>
  <div className="flex h-8 items-center rounded-lg bg-background/50 px-2.5 text-[11px] text-slate-400">
    {field.tipoCampo === "S" ? "Semántico" : field.tipoCampo === "B" ? "Negocio" : "Exacto"}
  </div>
</div>

{/* Columna 2: Dato — readonly badge */}
<div className="space-y-1">
  <span className="text-[9px] font-bold uppercase tracking-widest text-slate-600">Dato</span>
  <div className="flex h-8 items-center rounded-lg bg-background/50 px-2.5 text-[11px] text-slate-400">
    {TIPO_DATO_LABELS[field.tipoDato ?? ""] ?? field.tipoDato}
  </div>
</div>
```

**Constante de mapeo** (agregar al bloque de constantes, reemplaza `tipoDatoOptions`):
```typescript
const TIPO_DATO_LABELS: Record<string, string> = {
  text: "Texto", date: "Fecha", quantity: "Cantidad", money: "Dinero",
  identity_doc_type: "Tipo doc.", identity_doc_number: "Documento",
  code: "Código", trace_token: "Trazabilidad", person_name: "Persona",
  institution_name: "Institución", article_name: "Artículo",
};
```

**12. `toggleVisualCheckOption()` (L278-325) — catálogo en lugar de hardcodeo:**

**ANTES**: Usa `option: VisualCheckOption` con label/description hardcodeado.
**DESPUÉS**: Usa `option: FieldCatalogItem` del catálogo. Los campos `descripcion` y `severidad` vienen del catálogo. `codigoCampo` se propaga desde el catálogo al state.

```typescript
const toggleVisualCheckOption = (docName: string, option: FieldCatalogItem) => {
  // ... misma lógica de toggle (buscar existente, crear nuevo)
  // Diferencia: option.descripcion en lugar de option.description
  //             option.severidad en lugar de option.severity
  //             option.codigoCampo se incluye en FieldToggle
};
```

##### Eliminación de `validationError` en L872-873

**ANTES**:
```typescript
const validationError = fieldValidationError(field);
const allowedTipoDatoOptions = tipoDatoOptionsFor(field.tipoCampo);
```

**DESPUÉS**:
```typescript
const validationError = fieldValidationError(field);
// allowedTipoDatoOptions eliminado — tipoDato ya no es seleccionable
```

##### Impacto visual (comportamiento del usuario)

| Elemento UI | Antes | Después | ¿Regresión? |
|---|---|---|---|
| Toggle on/off por campo | ✅ Editable | ✅ Editable | NO |
| Orden de campos | ✅ Editable | ✅ Editable | NO |
| Severidad override | ✅ `<Select>` | ✅ `<Select>` | NO |
| Descripción override | ✅ `<Input>` | ✅ `<Input>` | NO |
| System prompt | ✅ `<Textarea>` | ✅ `<Textarea>` | NO |
| Tipo de comparación (tipoCampo) | ✅ `<Select>` editable | ⛔ Badge readonly | **Corrección** — es intrínseco |
| Tipo de dato (tipoDato) | ✅ `<Select>` editable | ⛔ Badge readonly | **Corrección** — es intrínseco |
| Código de campo (codigoCampo) | 🔇 Invisible | ⛔ Badge readonly (header) | **Mejora** — visible para trazabilidad |
| Checks visuales disponibles | Hardcodeado (3 opciones) | Del catálogo (dinámico) | **Mejora** — extensible |
| Validación tipo/dato | ⚠️ Frontend validaba combinación | ✅ Catálogo garantiza consistencia | **Mejora** — single source of truth |

---

## 5. Preservación del Cliente 2426 — Protocolo de Integridad

> [!CAUTION]
> El cliente 2426 es el **único cliente validado y funcional en producción**. Cualquier pérdida de configuración de este cliente es un **bloqueador total**. Esta sección define el snapshot de referencia, las queries de verificación pre/post, y los criterios de aserción obligatorios.

### 5.1 Snapshot de Referencia (Estado Actual — 2026-06-04)

**Header (`AudDisp`)**:
- `FacNitSec`: `2426`
- `SystemPrompt`: `"Código Producto" y "Código Artículo" corresponden a conceptos diferentes...`
- `Activo`: `true`

**Config (`AudDispCampo`): 35 filas activas en 3 documentos**:

| Doc (NitMedDocId) | DocNombre | CampoNombre | TipoCampo | TipoDato | CodigoCampo | Orden | DescripcionOverride | SeveridadOverride |
|---|---|---|---|---|---|---|---|---|
| 1 | DISPENSA | Cliente | S | institution_name | CLI | 1 | null | alta |
| 1 | DISPENSA | NITCliente | E | text | CLN | 2 | null | alta |
| 1 | DISPENSA | IPS | S | institution_name | IPS | 3 | null | alta |
| 1 | DISPENSA | NombrePaciente | S | person_name | PAC | 4 | null | alta |
| 1 | DISPENSA | TipoDocumentoPaciente | E | identity_doc_type | TDP | 5 | null | alta |
| 1 | DISPENSA | DocumentoPaciente | E | identity_doc_number | DOP | 6 | null | alta |
| 1 | DISPENSA | Medico | S | person_name | MED | 8 | null | alta |
| 1 | DISPENSA | NumeroFactura | E | text | FACN | 10 | null | alta |
| 1 | DISPENSA | FechaEntrega | E | date | FEN | 12 | null | alta |
| 1 | DISPENSA | FechaFormula | E | date | FFO | 13 | null | alta |
| 1 | DISPENSA | FechaAutorizacion | E | date | FAU | 14 | null | alta |
| 1 | DISPENSA | NumeroAutorizacion | E | text | AUT | 15 | null | alta |
| 1 | DISPENSA | CodigoProducto | E | code | PRD | 18 | null | alta |
| 1 | DISPENSA | CodigoDiagnostico | E | code | DX | 19 | null | alta |
| 1 | DISPENSA | Lote | E | trace_token | LOT | 22 | null | alta |
| 1 | DISPENSA | CantidadEntregada | B | quantity | CEN | 24 | null | alta |
| 1 | DISPENSA | CantidadPrescrita | B | quantity | CPR | 25 | null | alta |
| 1 | DISPENSA | FirmaActaEntrega | V | null | FIR | 29 | "Firma o sello de recibido del paciente/acudiente" | alta |
| 2 | AUTORIZACION | NumeroAutorizacion | E | text | AUT | 1 | null | alta |
| 2 | AUTORIZACION | NombrePaciente | S | person_name | PAC | 3 | null | alta |
| 2 | AUTORIZACION | TipoDocumentoPaciente | E | identity_doc_type | TDP | 4 | null | alta |
| 2 | AUTORIZACION | DocumentoPaciente | E | identity_doc_number | DOP | 5 | null | alta |
| 2 | AUTORIZACION | FechaAutorizacion | E | date | FAU | 6 | null | alta |
| 2 | AUTORIZACION | CodigoDiagnostico | E | code | DX | 8 | null | alta |
| 2 | AUTORIZACION | CodigoProducto | E | code | PRD | 9 | null | alta |
| 2 | AUTORIZACION | CantidadEntregada | B | quantity | CEN | 10 | null | alta |
| 2 | AUTORIZACION | NITDiscolmets | E | text | DIS | 10 | null | alta |
| 2 | AUTORIZACION | VigenciaEntrega | V | null | null | 9 | "Extrae la vigencia o plazo visible para entrega/reclamación." | alta |
| 3 | FORMULA MEDICA | NombrePaciente | S | person_name | PAC | 2 | null | alta |
| 3 | FORMULA MEDICA | TipoDocumentoPaciente | E | identity_doc_type | TDP | 3 | null | alta |
| 3 | FORMULA MEDICA | DocumentoPaciente | E | identity_doc_number | DOP | 4 | null | alta |
| 3 | FORMULA MEDICA | Medico | S | person_name | MED | 5 | null | alta |
| 3 | FORMULA MEDICA | CodigoDiagnostico | E | code | DX | 8 | null | alta |
| 3 | FORMULA MEDICA | FechaFormula | E | date | FFO | 9 | null | alta |
| 3 | FORMULA MEDICA | FirmaPrescriptor | V | null | null | 13 | "Firma del médico/profesional que prescribe" | alta |

### 5.2 Query de Verificación PRE-Migración (Obligatoria)

Ejecutar **antes** de cualquier cambio DDL. El resultado debe coincidir exactamente con §5.1.

```sql
-- QUERY PRE-001: Conteo exacto
SELECT COUNT(*) AS total FROM Discolnet.dbo.AudDispCampo WHERE FacNitSec = '2426' AND Activo = 1;
-- Resultado esperado: 35

-- QUERY PRE-002: Hash funcional (campos × documentos)
SELECT NitMedDocId, CampoNombre, Orden, DescripcionOverride, SeveridadOverride
FROM Discolnet.dbo.AudDispCampo
WHERE FacNitSec = '2426' AND Activo = 1
ORDER BY NitMedDocId, Orden;
-- Resultado esperado: 35 filas idénticas al snapshot §5.1

-- QUERY PRE-003: Header intacto
SELECT FacNitSec, Activo, SystemPrompt FROM Discolnet.dbo.AudDisp WHERE FacNitSec = '2426';
-- Resultado esperado: 1 fila, Activo=1, SystemPrompt con contenido de Código Producto/Artículo
```

### 5.3 Query de Verificación POST-Migración (Obligatoria)

Ejecutar **después** de completar todos los pasos DDL + deploy. Garantiza que la configuración del cliente 2426 sobrevivió la normalización.

```sql
-- QUERY POST-001: Conteo exacto (no cambió)
SELECT COUNT(*) AS total FROM Discolnet.dbo.AudDispCampo WHERE FacNitSec = '2426' AND Activo = 1;
-- Resultado esperado: 35 (idéntico a PRE-001)

-- QUERY POST-002: Datos funcionales preservados
SELECT NitMedDocId, CampoNombre, Orden, DescripcionOverride, SeveridadOverride
FROM Discolnet.dbo.AudDispCampo
WHERE FacNitSec = '2426' AND Activo = 1
ORDER BY NitMedDocId, Orden;
-- Resultado esperado: 35 filas idénticas a PRE-002

-- QUERY POST-003: JOIN con catálogo resuelve metadatos correctamente
SELECT ac.CampoNombre, cat.TipoCampo, cat.TipoDato, cat.CodigoCampo,
       ac.NitMedDocId, ac.Orden, ac.DescripcionOverride, ac.SeveridadOverride
FROM Discolnet.dbo.AudDispCampo ac
INNER JOIN Discolnet.dbo.AudDispCampoCatalogo cat ON cat.CampoNombre = ac.CampoNombre
WHERE ac.FacNitSec = '2426' AND ac.Activo = 1
ORDER BY ac.NitMedDocId, ac.Orden;
-- Resultado esperado: 35 filas. Cada fila tiene TipoCampo/TipoDato/CodigoCampo resueltos
-- desde el catálogo. Valores idénticos al snapshot §5.1.

-- QUERY POST-004: Verificar que no hay campos huérfanos (FK integridad)
SELECT ac.CampoNombre FROM Discolnet.dbo.AudDispCampo ac
WHERE NOT EXISTS (SELECT 1 FROM Discolnet.dbo.AudDispCampoCatalogo cat WHERE cat.CampoNombre = ac.CampoNombre);
-- Resultado esperado: 0 filas

-- QUERY POST-005: Columnas eliminadas no existen
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'AudDispCampo' AND TABLE_SCHEMA = 'dbo'
  AND COLUMN_NAME IN ('TipoCampo', 'TipoDato', 'CodigoCampo');
-- Resultado esperado: 0 filas
```

### 5.4 Aserción API POST-Migración

Ejecutar `GET /clients/2426/audit-config` después del deploy y comparar:

| Aserción | Criterio |
|---|---|
| HTTP Status | 200 |
| `data.nitSec` | `"2426"` |
| `data.activo` | `true` |
| `data.systemPrompt` | contiene `"Código Producto"` |
| `data.documents` | 3 keys: `DISPENSA`, `AUTORIZACION`, `FORMULA MEDICA` |
| `data.documents.DISPENSA.fields` | 16 campos (excluye `FirmaActaEntrega` que es visual) |
| `data.documents.DISPENSA.visualChecks` | 1 check: `FirmaActaEntrega` |
| `data.documents.AUTORIZACION.fields` | 8 campos |
| `data.documents.AUTORIZACION.visualChecks` | 1 check: `VigenciaEntrega` |
| `data.documents["FORMULA MEDICA"].fields` | 5 campos |
| `data.documents["FORMULA MEDICA"].visualChecks` | 1 check: `FirmaPrescriptor` |
| Todo campo no-visual tiene `tipoDato` no null | `true` (antes fallaba para cliente 1165) |
| Todo campo tiene `codigoCampo` string no null | `true` (antes `VigenciaEntrega` y `FirmaPrescriptor` tenían null) |

> [!IMPORTANT]
> **Nota sobre `VigenciaEntrega` y `FirmaPrescriptor`**: En el snapshot actual tienen `CodigoCampo = null`. Post-migración tendrán `CodigoCampo = 'VIG'` y `CodigoCampo = 'FPRE'` respectivamente (del catálogo). Esto es una **mejora**, no una regresión — el pipeline ya manejaba null gracefully, y ahora tendrá el código correcto para la trazabilidad.

---

## 6. Secuencia de Ejecución (Migración)

### Paso 0: Verificación PRE (§5.2)
Ejecutar queries PRE-001, PRE-002, PRE-003. **Guardar resultados** como baseline.

### Paso 1: Crear catálogo + seed
```sql
CREATE TABLE Discolnet.dbo.AudDispCampoCatalogo (...); -- DDL exacto de §2.1
INSERT INTO Discolnet.dbo.AudDispCampoCatalogo VALUES (...); -- 28 filas de §2.2
```

### Paso 2: Validar cobertura
```sql
SELECT DISTINCT ac.CampoNombre FROM Discolnet.dbo.AudDispCampo ac
WHERE NOT EXISTS (SELECT 1 FROM Discolnet.dbo.AudDispCampoCatalogo cat WHERE cat.CampoNombre = ac.CampoNombre);
```
**Si retorna filas**: STOP. Agregar campos faltantes al catálogo antes de continuar.

### Paso 3: Agregar FK
```sql
ALTER TABLE Discolnet.dbo.AudDispCampo
    ADD CONSTRAINT FK_AudDispCampo_Catalogo FOREIGN KEY (CampoNombre)
    REFERENCES Discolnet.dbo.AudDispCampoCatalogo(CampoNombre);
```

### Paso 4: Eliminar columnas redundantes
```sql
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN TipoCampo;
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN TipoDato;
ALTER TABLE Discolnet.dbo.AudDispCampo DROP COLUMN CodigoCampo;
```

### Paso 5: Verificación POST-SQL (§5.3)
Ejecutar queries POST-001 a POST-005. Comparar POST-001/002 con PRE-001/002.

### Paso 6: Deploy backend
Código PHP: modelo + controlador + ruta.

### Paso 7: Deploy frontend
Código TypeScript.

### Paso 8: Verificación POST-API (§5.4)
Ejecutar `GET /clients/2426/audit-config` y verificar aserciones.

> [!CAUTION]
> **Rollback**: Si paso 4 falla → pasos 1-3 son inocuos (DROP FK, DROP TABLE catálogo). Si deploy falla post-paso-4 → rollback requiere re-agregar columnas con datos reconstruidos desde catálogo:
> ```sql
> ALTER TABLE Discolnet.dbo.AudDispCampo ADD TipoCampo CHAR(1), TipoDato VARCHAR(50), CodigoCampo VARCHAR(50);
> UPDATE ac SET ac.TipoCampo = cat.TipoCampo, ac.TipoDato = cat.TipoDato, ac.CodigoCampo = cat.CodigoCampo
> FROM Discolnet.dbo.AudDispCampo ac INNER JOIN Discolnet.dbo.AudDispCampoCatalogo cat ON cat.CampoNombre = ac.CampoNombre;
> ```

---

## 7. Casos Límite y Manejo de Errores

| Caso | Comportamiento esperado |
|---|---|
| UI envía `campoNombre` que no existe en catálogo | HTTP 422: `"'X' no existe en el catálogo de campos."` |
| UI envía `tipoCampo`/`tipoDato`/`codigoCampo` en payload POST | **Se ignoran** (backward compatible, no error) |
| Cliente sin configuración (GET) | HTTP 404 (sin cambios) |
| Catálogo vacío (GET /audit/field-catalog) | HTTP 200 con `data: []` |
| `AudDispCampo.CampoNombre` referencia campo eliminado del catálogo | FK impide DELETE en catálogo → error 500 SQL |
| Campo visual en catálogo con `TipoDato` no-null | CHECK constraint lo impide en INSERT/UPDATE |
| Cliente 2426 pierde filas post-migración | **Bloqueador**. POST-001 ≠ PRE-001 → rollback inmediato |
| Campo nuevo no existente en catálogo enviado desde UI | HTTP 422 (FK + validación server-side) |

---

## 8. Tests

### 8.1 PHPUnit — Nuevos
- `AuditConfigModel::getCatalog()` retorna 28 filas con keys esperadas
- `AuditConfigModel::catalogFieldExists('NombrePaciente')` → `true`
- `AuditConfigModel::catalogFieldExists('CampoInexistente')` → `false`
- `AuditConfigController::catalog()` retorna JSON con estructura correcta
- `sanitizeFields()` con `campoNombre` inexistente → HTTP 422

### 8.2 PHPUnit — Modificados
- `DocumentPolicyEngineTest`: mocks de `getConfig()` siguen inyectando `tipoDato` y `codigoCampo` → sin cambios funcionales
- `RulesEvaluationWorkerTest`: ídem
- Tests de `sanitizeFields()`: payload sin `tipoCampo`/`tipoDato` debe pasar

### 8.3 Frontend
- `npx tsc --noEmit`: compilación sin errores
- `buildPayload()` no incluye `tipoCampo`, `tipoDato`, `codigoCampo` en output

---

## 9. Criterios de Aceptación

### Infraestructura SQL
- [ ] Tabla `AudDispCampoCatalogo` creada con 28 filas y constraints CHECK/UNIQUE/PK
- [ ] `AudDispCampo` no contiene columnas `TipoCampo`, `TipoDato`, `CodigoCampo`
- [ ] FK activa entre `AudDispCampo.CampoNombre` → `AudDispCampoCatalogo.CampoNombre`

### Preservación Cliente 2426
- [ ] POST-001 = PRE-001 (35 filas)
- [ ] POST-002 = PRE-002 (datos funcionales idénticos)
- [ ] POST-003 resuelve todos los metadatos vía JOIN (0 nulls en TipoDato para no-visuales)
- [ ] POST-004 = 0 filas (sin huérfanos)
- [ ] POST-005 = 0 filas (columnas eliminadas)
- [ ] `GET /clients/2426/audit-config` retorna 3 documentos con conteos correctos

### API
- [ ] `GET /clients/{id}/audit-config` retorna `tipoDato`, `tipoCampo`, `codigoCampo` del catálogo
- [ ] `POST /clients/{id}/audit-config` acepta payload sin `tipoCampo`/`tipoDato`/`codigoCampo`
- [ ] `POST /clients/{id}/audit-config` rechaza `campoNombre` inexistente con HTTP 422
- [ ] `GET /audit/field-catalog` retorna catálogo completo

### Código
- [ ] Pipeline de auditoría pasa todos los tests sin regresión
- [ ] Frontend compila sin errores TypeScript
- [ ] No queda código muerto: `sanitizeTipoCampo`, `sanitizeTipoDato`, `sanitizeCodigoCampo`, `typeCombinationError`, `visualCheckOptions`, `tipoDatoOptions`, import `AuditFieldValueType` en controller
