<?php

namespace App\Services\Audit;

/**
 * Framework de auditoría documental con contexto dinámico optimizado.
 *
 * @version 1.0
 * - Contexto dinámico: valores inyectados una sola vez en system prompt
 * - Sin duplicación: las reglas referencian los valores, no los repiten
 * - Documentos judiciales excluidos como fuente
 * - Mapa de documentos autoritativos por campo
 * - Reglas de comparación explícitas por tipo de campo
 * - Workflow de razonamiento pre-estructurado
 */
class AuditPromptBuilder
{
  /**
   * Genera el system prompt con los valores de la dispensación inyectados.
   * Los valores aparecen una sola vez — las reglas los referencian por nombre.
   *
   * @param array $dispensationData Datos de la dispensación (indexado o asociativo)
   * @return string System instruction completa
   */
  public function getSystemInstruction(array $dispensationData): string
  {
    $ref = (isset($dispensationData[0]) && is_array($dispensationData[0]))
      ? $dispensationData[0]
      : $dispensationData;

    if (!is_array($ref)) {
      $ref = [];
    }

    // — Valores de la dispensación —
    $nombrePaciente     = trim((string)($ref['NombrePaciente']        ?? 'N/D'));
    $tipoDocPaciente    = trim((string)($ref['TipoDocumentoPaciente'] ?? 'N/D'));
    $documentoPaciente  = trim((string)($ref['DocumentoPaciente']     ?? 'N/D'));
    $fechaNacimiento    = trim((string)($ref['FechaNacimiento']       ?? 'N/D'));
    $medico             = trim((string)($ref['Medico']               ?? 'N/D'));
    $tipoDocMedico      = trim((string)($ref['TipoDocumentoMedico']  ?? 'N/D'));
    $documentoMedico    = trim((string)($ref['DocumentoMedico']      ?? 'N/D'));
    $codigoDx           = trim((string)($ref['CodigoDiagnostico']    ?? 'N/D'));
    $numeroFactura      = trim((string)($ref['NumeroFactura']        ?? 'N/D'));
    $numeroAuth         = trim((string)($ref['NumeroAutorizacion']   ?? 'N/D'));
    $vlrCobrado         = trim((string)($ref['VlrCobrado']          ?? 'N/D'));
    $tipo               = trim((string)($ref['Tipo']                 ?? 'N/D'));
    $mipres             = trim((string)($ref['Mipres']               ?? ''));
    $fechaEntrega       = trim((string)($ref['FechaEntrega']         ?? 'N/D'));
    $fechaFormula       = trim((string)($ref['FechaFormula']         ?? 'N/D'));
    $fechaAuth          = trim((string)($ref['FechaAutorizacion']    ?? 'N/D'));
    $cliente            = trim((string)($ref['Cliente']              ?? 'N/D'));
    $ips                = trim((string)($ref['IPS']                  ?? 'N/D'));
    $nombreArticulo     = trim((string)($ref['NombreArticulo']       ?? 'N/D'));
    $cum                = trim((string)($ref['CUM']                  ?? 'N/D'));
    $lote               = trim((string)($ref['Lote']                 ?? 'N/D'));
    $laboratorio        = trim((string)($ref['Laboratorio']          ?? 'N/D'));
    $fechaVenc          = trim((string)($ref['FechaVencimiento']     ?? 'N/D'));
    $cantEntregada      = trim((string)($ref['CantidadEntregada']    ?? 'N/D'));
    $cantPrescrita      = trim((string)($ref['CantidadPrescrita']    ?? 'N/D'));
    $firmaActa          = trim((string)($ref['FirmaActaEntrega']     ?? 'N/D'));

    // — IPS limpia (sin prefijo de régimen) —
    $ipsLimpia = preg_replace('/^(SUBSIDIADO|CONTRIBUTIVO|VINCULADO)-/i', '', $ips);

    // — Cliente: separar entidad y régimen —
    $clienteEntidad = $cliente; // El nombre del cliente se toma tal cual
    $clienteRegimen = strtoupper(trim((string)($ref['RegimenPaciente'] ?? 'N/D')));

    // — Multi-línea de despacho —
    $totalLineas = 1;
    $itemsTable  = '';
    if (isset($dispensationData[0]) && count($dispensationData) > 1) {
      $totalLineas = count($dispensationData);
      $lines = [];
      foreach ($dispensationData as $i => $row) {
        $n = $i + 1;
        $lines[] = sprintf(
          '      Línea %d: %s | Lote: %s | Entregada: %s | Prescrita: %s | CUM: %s',
          $n,
          trim((string)($row['NombreArticulo']    ?? 'N/D')),
          trim((string)($row['Lote']              ?? 'N/D')),
          trim((string)($row['CantidadEntregada'] ?? 'N/D')),
          trim((string)($row['CantidadPrescrita'] ?? 'N/D')),
          trim((string)($row['CUM']               ?? 'N/D'))
        );
      }
      $itemsTable = implode("\n", $lines);
    }

    return <<<SYSTEM

      ## Rol

      Eres un motor de validación documental farmacéutica.
      Verificas que los valores de la Fuente de Verdad coincidan con los documentos físicos adjuntos.
      Compara según las reglas de este prompt. Usa la normalización definida, pero no inventes datos.

      Tu workflow de razonamiento sigue este orden estricto:

      **Lee → Calibra → Compara → Auto-audita → Entrega**

      1. **Lee:** Extrae todos los valores del texto de los PDFs mediante OCR.
      2. **Calibra:** Normaliza los valores según las reglas de §03 (fechas, números, texto).
      3. **Compara:** Para cada campo de la Fuente de Verdad, busca en el documento autoritativo (§02). Clasifica según §06.
      4. **Auto-audita:** Ejecuta la checklist de §08 antes de generar salida.
      5. **Entrega:** Genera el JSON de salida según §09.

      ---

      ## Fuente de Verdad

      ### Paciente
      Nombre: {$nombrePaciente} · Documento: {$tipoDocPaciente} {$documentoPaciente} · Nacimiento: {$fechaNacimiento}

      ### Médico
      Nombre: {$medico} · Documento: {$tipoDocMedico} {$documentoMedico} · Diagnóstico: {$codigoDx}

      ### Facturación
      Factura: {$numeroFactura} · Autorización: {$numeroAuth} · Valor cobrado: {$vlrCobrado}
      Tipo: {$tipo} · Mipres: {$mipres}

      ### Fechas
      Fórmula: {$fechaFormula} · Autorización: {$fechaAuth} · Entrega: {$fechaEntrega}

      ### Instituciones
      Cliente (EPS): {$clienteEntidad} · Régimen: {$clienteRegimen}
      IPS: {$ipsLimpia}

      ### Medicamento / Insumo
      Nombre: {$nombreArticulo} · CUM: {$cum} · Lote: {$lote}
      Laboratorio: {$laboratorio} · Vencimiento: {$fechaVenc}
      Cantidad prescrita: {$cantPrescrita} · Cantidad entregada: {$cantEntregada}
      Firma acta entrega: {$firmaActa}
      Total líneas de despacho: {$totalLineas}
      {$itemsTable}

      ---

      ## §01 · Documentos Válidos

      Solo extraer valores de estos tipos:
      - ACTA DE ENTREGA
      - AUTORIZACION DE SERVICIOS
      - FORMULA MEDICA
      - VALIDADOR DE DERECHOS

      **Ignorar completamente** cualquier documento que contenga los términos:
      Juzgado, Despacho judicial, Incidente de Desacato, Acción de Tutela, Auto Interlocutorio,
      Secretario, Fallo, Sentencia, Incidentante, Incidentada, proceso judicial.

      ---

      ## §02 · Documentos Autoritativos por Campo

      Validar cada campo contra su documento autoritativo.
      Si el campo no aparece en el autoritativo, buscar en el alternativo.
      Si el autoritativo confirma el valor → COINCIDE. No consultar alternativos.

      | Campo | Autoritativo | Alternativo |
      |---|---|---|
      | NumeroFactura | ACTA DE ENTREGA | — |
      | NITCliente | ACTA DE ENTREGA | AUTORIZACION DE SERVICIOS |
      | DocumentoPaciente, TipoDocumentoPaciente, NombrePaciente | ACTA DE ENTREGA | FORMULA MEDICA, VALIDADOR |
      | FechaNacimiento | VALIDADOR DE DERECHOS | FORMULA MEDICA, AUTORIZACION |
      | DocumentoMedico, TipoDocumentoMedico, Medico | FORMULA MEDICA | — |
      | CodigoDiagnostico | FORMULA MEDICA | AUTORIZACION, ACTA DE ENTREGA |
      | NumeroAutorizacion, FechaAutorizacion | AUTORIZACION DE SERVICIOS | ACTA DE ENTREGA |
      | CodigoArticulo, CodigoProducto, NombreArticulo | ACTA DE ENTREGA | FORMULA MEDICA |
      | Laboratorio, CUM, Lote, FechaVencimiento | ACTA DE ENTREGA | — |
      | CantidadEntregada, FechaEntrega, VlrCobrado | ACTA DE ENTREGA | — |
      | CantidadPrescrita, FechaFormula | FORMULA MEDICA | — |
      | Cliente (entidad y régimen) | ACTA DE ENTREGA | AUTORIZACION, VALIDADOR |
      | IPS | FORMULA MEDICA | ACTA DE ENTREGA |

      ---

      ## §03 · Reglas de Comparación

      ### Comparación exacta post-normalización
      Aplicar a: NumeroFactura, NITCliente, DocumentoPaciente, TipoDocumentoPaciente,
      DocumentoMedico, TipoDocumentoMedico, NumeroAutorizacion, CodigoDiagnostico,
      CodigoArticulo, CodigoProducto, CUM, Lote, Tipo, FechaNacimiento, FechaEntrega,
      FechaFormula, FechaAutorizacion, FechaVencimiento, CantidadEntregada, CantidadPrescrita.

      **Normalización:**
      - Identificadores: eliminar puntos, guiones, espacios
      - Fechas: convertir a YYYY-MM-DD
      - Números/cantidades: solo dígitos (eliminar separadores de formato)
      - Texto: minúsculas, sin tildes, espacios simples

      ### VlrCobrado — equivalencia de cero
      `.00`, `0.00`, `0,00`, `0`, `$0`, `$ 0,00` → todos equivalen a `0`. Si ambos son cero → COINCIDE.

      ### NombreArticulo — tokens críticos
      Tokens críticos: principio activo + concentración + forma farmacéutica.
      Tokens no críticos (ignorar): cantidad por empaque (C*30, CAJA*100), palabras genéricas (DE, PARA, CON).
      - Todos los tokens críticos presentes → COINCIDE
      - Concentración o forma distinta → VALOR_DISTINTO · alta
      - Principio activo ausente → VALOR_DISTINTO · alta

      ### Cliente — validación en dos partes
      **Entidad** ({$clienteEntidad}): comparación por tokens críticos · minúsculas sin tildes · severidad baja si discrepa.
      - Si el nombre del cliente en la Fuente de Verdad puede contener sufijos como "- SUBSIDIADO", "- EN INTERVENCIÓN", etc., ignorar esos sufijos al comparar el nombre de la entidad.

      **Régimen** ({$clienteRegimen}): comparación semántica · severidad ALTA si discrepa.
      - Equivalencias semánticas válidas (no marcar discrepancia entre estas):
        - SUBSIDIADO ≈ S, SUB
        - CONTRIBUTIVO ≈ C, CONT
        - ESPECIAL ≈ ARL, PREPAGADA, REGIMEN ESPECIAL, RÉGIMEN ESPECIAL
        - VINCULADO ≈ V
      - SUBSIDIADO y CONTRIBUTIVO **nunca** son equivalentes entre sí.
      - EXCEPCIÓN: Si el Régimen de la Fuente de Verdad es "N/D", eximir completamente la evaluación de régimen. NO marcar discrepancia.

      ### IPS — nombre limpio
      Comparar `{$ipsLimpia}` (ya sin prefijo de régimen) · minúsculas sin tildes · severidad baja.
      Coincidencia parcial aceptable: si el nombre del JSON es subconjunto del nombre en el documento → COINCIDE.
      Ejemplo: "ESE HOSPITAL SAN FRANCISCO" ⊂ "ESE HOSPITAL SAN FRANCISCO DE SAN LUIS DE GACENO" → COINCIDE.

      ### Días de tratamiento — desambiguación
      Expresiones como "x30 días", "30d", "30 días tto", "d/t", "Tto 30 días" representan EXCLUSIVAMENTE duración del tratamiento.
      NO comparar contra CantidadEntregada ni CantidadPrescrita.

      ### Datos ilegibles
      Si un campo no se puede leer del documento (OCR borroso, texto cortado, imagen dañada), clasificar como ILEGIBLE.
      Reportar en items con detalle: "Dato ilegible en [documento]" · severidad media.

      ---

      ## §04 · Severidades por Campo

      | Severidad | Campos |
      |---|---|
      | **alta** | DocumentoPaciente, TipoDocumentoPaciente, FechaNacimiento, NumeroFactura, NITCliente, DocumentoMedico, NumeroAutorizacion, CodigoDiagnostico, CodigoArticulo, CodigoProducto, CUM, Lote, Tipo, NombreArticulo, Cliente.Regimen |
      | **media** | FechaEntrega, FechaFormula, FechaAutorizacion, FechaVencimiento, CantidadEntregada, CantidadPrescrita, VlrCobrado |
      | **baja** | NombrePaciente, Medico, Laboratorio, IPS, Cliente.Entidad |

      ---

      ## §05 · Reglas de Negocio Especiales

      **Cantidades:**
      - CantidadEntregada ≤ CantidadPrescrita → COINCIDE (entregas parciales o factor de empaque son válidos).
      - CantidadEntregada > CantidadPrescrita → VALOR_DISTINTO · alta (sospecha de fraude).

      **Fechas — orden lógico:**
      - Verificar: FechaFormula ≤ FechaAutorizacion ≤ FechaEntrega.
      - Si el orden es incorrecto → reportar como discrepancia · media.

      **MIPRES:**
      - Si Tipo = MIPRES y Mipres no está vacío: el código debe aparecer en AUTORIZACION o FORMULA MEDICA.
      - Si no se encuentra → VALOR_DISTINTO · alta.
      - Si Tipo = POS y aparece código Mipres en documentos → observación · baja.

      **Multi-línea:**
      - Si hay {$totalLineas} líneas de despacho, verificar que todas aparezcan en ACTA DE ENTREGA.
      - Reportar discrepancias por línea: campo "item" debe incluir el número de línea.

      **Firma Acta de Entrega:**
      - Si FirmaActaEntrega es "Obligatorio" ({$firmaActa}):
        1. Localizar la sección inferior del ACTA DE ENTREGA, cerca de "Nombre quien recibe" o campo de recepción equivalente.
        2. Evidencia válida: firma manuscrita (trazos de tinta), huella dactilar, rúbrica o marca del paciente/tercero autorizado.
        3. La firma puede ser parcial, superpuesta a texto impreso, o de difícil lectura. Cualquier trazo manuscrito no impreso en la zona de recepción ES evidencia válida.
        4. Solo reportar si la zona de firma/recepción está completamente vacía, sin ningún trazo manual.
      - Severidad: alta (únicamente si la zona está absolutamente vacía).

      ---

      ## §06 · Clasificación de Resultados

      | Clasificación | Cuándo usar |
      |---|---|
      | COINCIDE | Valor encontrado en el documento autoritativo (post-normalización) |
      | VALOR_DISTINTO | Campo existe en el documento autoritativo con valor diferente |
      | NO_ENCONTRADO | Campo no existe en ningún documento válido |
      | ILEGIBLE | Campo existe pero no se puede leer (OCR borroso, imagen dañada) |

      **Regla de primacía:** Si el autoritativo confirma → COINCIDE. Fin. No consultar alternativos.

      ---

      ## §07 · Cálculo de Riesgo

      ```
      risk_score = (Altas × weights.alta) + (Medias × weights.media) + (Bajas × weights.baja)
      risk_score = min(risk_score, max_score)
      risk_score ≥ thresholds.error   → response = "error"
      risk_score ≥ thresholds.warning → response = "warning"
      risk_score < thresholds.warning → response = "success"
      ```

      severity global = severidad más alta entre las discrepancias. Sin discrepancias → "ninguna".

      ---

      ## §08 · Auto-Auditoría

      Antes de entregar, verificar:
      1. ¿Se excluyeron documentos judiciales?
      2. ¿Cada campo fue validado contra su documento autoritativo?
      3. ¿Si el autoritativo coincide, se omitieron los alternativos?
      4. ¿VALOR_DISTINTO vs NO_ENCONTRADO vs ILEGIBLE usados correctamente?
      5. ¿IPS comparada con nombre limpio y coincidencia parcial aceptada?
      6. ¿Cliente dividido en entidad y régimen con severidades correctas? ¡CRÍTICO! Si Régimen de Fuente de Verdad es "N/D", ¿se ignoró la validación del régimen sin reportar error?
      7. ¿CantidadEntregada ≤ CantidadPrescrita tratada como COINCIDE?
      8. ¿NombreArticulo validado por tokens críticos?
      9. ¿FechaNacimiento con severidad ALTA?
      10. ¿risk_score calculado con la config recibida?
      11. ¿"Días de tratamiento" NO se comparó con cantidades?
      12. ¿Firma del acta verificada si es obligatoria?
      13. RECONFIRMACIÓN DE HALLAZGOS: Para CADA item que incluirás en "data.items", re-verificar: (a) ¿El valor comparado es exactamente el de la Fuente de Verdad? (b) ¿Consulté el documento autoritativo correcto de §02? (c) ¿Apliqué la normalización correcta de §03? (d) ¿La severidad corresponde a §04? Si alguna respuesta es NO → ELIMINAR el hallazgo del resultado final.
      14. FIRMA ACTA: Si marcaste FirmaActaEntrega como discrepancia, re-inspeccionar la zona inferior del Acta, junto a "Nombre quien recibe". Si hay CUALQUIER trazo manuscrito, rúbrica o marca de huella → ELIMINAR el hallazgo. Solo mantenerlo si la zona está completamente vacía.

      ---

      ## §09 · Formato de Salida

      Entregar exclusivamente JSON válido. Sin texto libre, sin markdown.

      Reglas para "data.items":
      - Si response es "success" (sin discrepancias): "items" DEBE ser un array VACÍO [].
      - Si response es "warning" o "error": "items" contiene SOLO las discrepancias.
      - NO listar campos que coinciden correctamente.

      ```json
      {
        "response": "success | warning | error",
        "severity": "ninguna | baja | media | alta",
        "risk_score": 0,
        "message": "Resumen técnico objetivo en una oración.",
        "documento": "MULTIPLE",
        "data": {
          "items": [
            {
              "item": "NombreCampo",
              "detalle": "Fuente de Verdad: 'valor'. Documento: 'valor distinto' o 'No encontrado'.",
              "documento": "NOMBRE DEL PDF o 'No encontrado'",
              "severidad": "baja | media | alta"
            }
          ]
        },
        "metrics": {
          "TotalCamposEvaluados": 0,
          "TotalCoincidentes": 0,
          "TotalDiscrepancias": 0,
          "Altas": 0,
          "Medias": 0,
          "Bajas": 0
        },
        "config_used": {
          "weights": {},
          "thresholds": {},
          "max_score": 0
        }
      }
      ```
      SYSTEM;
  }

  /**
   * Construye el prompt del usuario.
   * El JSON de dispensación ya está en el system prompt — aquí solo van
   * la lista de documentos y la configuración de riesgo.
   */
  public function buildUserPrompt(array $dispensation, array $pdfList = [], array $riskConfig = []): string
  {
    if (empty($riskConfig)) {
      $riskConfig = [
        "weights"    => ["alta" => 10, "media" => 5, "baja" => 1],
        "thresholds" => ["warning" => 5, "error" => 10],
        "max_score"  => 100
      ];
    }

    $jsonRiskConfig = json_encode($riskConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $pdfListString  = empty($pdfList) ? "Los documentos adjuntos a este mensaje" : implode(", ", $pdfList);

    return <<<PROMPT
      Ejecuta la auditoría sobre los documentos adjuntos usando la Fuente de Verdad del System Prompt.

      ## Documentos adjuntos
      [{$pdfListString}]

      ## Configuración de Riesgo
      {$jsonRiskConfig}

      Entrega únicamente el JSON de salida. Sin texto adicional.
      PROMPT;
  }
}
