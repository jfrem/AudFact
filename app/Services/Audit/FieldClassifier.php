<?php

namespace App\Services\Audit;

/**
 * Clasifica campos de auditoría en categorías de comparación y define
 * sus severidades y documentos autoritativos.
 *
 * Fuente de verdad: documentos autoritativos y severidades de negocio.
 *
 * @version 4.0
 */
class FieldClassifier
{
    /**
     * Tipos de comparación soportados.
     */
    public const TYPE_EXACT = 'exact';
    public const TYPE_SEMANTIC = 'semantic';
    public const TYPE_VISUAL = 'visual';
    public const TYPE_BUSINESS = 'business';

    /**
     * Severidades de discrepancia.
     */
    public const SEVERITY_HIGH = 'alta';
    public const SEVERITY_MEDIUM = 'media';
    public const SEVERITY_LOW = 'baja';

    /**
     * Mapa de campos → tipo de comparación.
     *
     * - exact:    Comparación normalizada de strings (IDs, fechas, números)
     * - semantic: Comparación vía cosine similarity de embeddings
     * - visual:   Verificación visual por Gemini Vision (presencia de firma, sello)
     * - business: Lógica de negocio codificada en PHP (cantidades, reglas MIPRES)
     */
    private const FIELD_TYPES = [
        // ── Campos exactos ──
        'NumeroFactura'       => self::TYPE_EXACT,
        'NumeroFormula'       => self::TYPE_EXACT,
        'Autorizacion'        => self::TYPE_EXACT,
        'TipoIdentificacion'  => self::TYPE_EXACT,
        'NumeroIdentificacion' => self::TYPE_EXACT,
        'FechaFormula'        => self::TYPE_EXACT,
        'FechaAutorizacion'   => self::TYPE_EXACT,
        'FechaEntrega'        => self::TYPE_EXACT,
        'VlrCobrado'          => self::TYPE_EXACT,
        'Mipres'              => self::TYPE_EXACT,
        'IdPrincipal'         => self::TYPE_EXACT,
        'IdDirec'             => self::TYPE_EXACT,
        'IdProg'              => self::TYPE_EXACT,
        'IdEntr'              => self::TYPE_EXACT,
        'IdRepEnt'            => self::TYPE_EXACT,
        'Lote'                => self::TYPE_EXACT,

        // ── Campos semánticos ──
        'NombrePaciente'      => self::TYPE_SEMANTIC,
        'NombreArticulo'      => self::TYPE_SEMANTIC,
        'Medico'              => self::TYPE_SEMANTIC,
        'Laboratorio'         => self::TYPE_SEMANTIC,
        'IPS'                 => self::TYPE_SEMANTIC,
        'Cliente.Entidad'     => self::TYPE_SEMANTIC,

        // ── Campos visuales ──
        'FirmaActaEntrega'    => self::TYPE_VISUAL,
        'SelloRecepcion'      => self::TYPE_VISUAL,

        // ── Campos de negocio ──
        'CantidadEntregada'   => self::TYPE_BUSINESS,
        'CantidadPrescrita'   => self::TYPE_BUSINESS,
        'Cliente.Regimen'     => self::TYPE_BUSINESS,
    ];

    /**
     * Mapa de campos → severidad de discrepancia.
     * Reglas de severidad de negocio.
     */
    private const FIELD_SEVERITIES = [
        // Alta
        'NumeroFactura'        => self::SEVERITY_HIGH,
        'NombrePaciente'       => self::SEVERITY_HIGH,
        'NumeroIdentificacion' => self::SEVERITY_HIGH,
        'TipoIdentificacion'   => self::SEVERITY_HIGH,
        'NombreArticulo'       => self::SEVERITY_HIGH,
        'CantidadEntregada'    => self::SEVERITY_HIGH,
        'CantidadPrescrita'    => self::SEVERITY_HIGH,
        'Autorizacion'         => self::SEVERITY_HIGH,
        'VlrCobrado'           => self::SEVERITY_HIGH,
        'FirmaActaEntrega'     => self::SEVERITY_HIGH,
        'Cliente.Regimen'      => self::SEVERITY_HIGH,

        // Media
        'NumeroFormula'        => self::SEVERITY_MEDIUM,
        'Medico'               => self::SEVERITY_MEDIUM,
        'Laboratorio'          => self::SEVERITY_MEDIUM,
        'FechaFormula'         => self::SEVERITY_MEDIUM,
        'FechaAutorizacion'    => self::SEVERITY_MEDIUM,
        'FechaEntrega'         => self::SEVERITY_MEDIUM,
        'IPS'                  => self::SEVERITY_MEDIUM,
        'Cliente.Entidad'      => self::SEVERITY_MEDIUM,

        // Baja
        'Mipres'               => self::SEVERITY_LOW,
        'IdPrincipal'          => self::SEVERITY_LOW,
        'IdDirec'              => self::SEVERITY_LOW,
        'IdProg'               => self::SEVERITY_LOW,
        'IdEntr'               => self::SEVERITY_LOW,
        'IdRepEnt'             => self::SEVERITY_LOW,
        'Lote'                 => self::SEVERITY_LOW,
        'SelloRecepcion'       => self::SEVERITY_LOW,
    ];

    /**
     * Documento autoritativo primario para cada campo.
     * Define cuál documento adjunto es la fuente más confiable
     * para extraer el valor del campo.
     *
     * Definición de documentos autoritativos.
     */
    private const AUTHORITATIVE_DOCS = [
        'NumeroFactura'        => 'FACTURA',
        'NumeroFormula'        => 'FORMULA_MEDICA',
        'NombrePaciente'       => 'FORMULA_MEDICA',
        'NumeroIdentificacion' => 'FORMULA_MEDICA',
        'TipoIdentificacion'   => 'FORMULA_MEDICA',
        'NombreArticulo'       => 'FORMULA_MEDICA',
        'CantidadPrescrita'    => 'FORMULA_MEDICA',
        'Medico'               => 'FORMULA_MEDICA',
        'FechaFormula'         => 'FORMULA_MEDICA',
        'CantidadEntregada'    => 'ACTA_DE_ENTREGA',
        'FirmaActaEntrega'     => 'ACTA_DE_ENTREGA',
        'FechaEntrega'         => 'ACTA_DE_ENTREGA',
        'Autorizacion'         => 'AUTORIZACION',
        'FechaAutorizacion'    => 'AUTORIZACION',
        'IPS'                  => 'AUTORIZACION',
        'Cliente.Entidad'      => 'AUTORIZACION',
        'Cliente.Regimen'      => 'AUTORIZACION',
        'Laboratorio'          => 'FACTURA',
        'VlrCobrado'           => 'FACTURA',
        'Mipres'               => 'FORMULA_MEDICA',
        'Lote'                 => 'FACTURA',
        'SelloRecepcion'       => 'ACTA_DE_ENTREGA',
    ];

    /**
     * Documentos alternativos donde el campo puede encontrarse
     * si no está en el autoritativo primario.
     */
    private const ALTERNATIVE_DOCS = [
        'NombrePaciente'       => ['ACTA_DE_ENTREGA', 'AUTORIZACION'],
        'NumeroIdentificacion' => ['ACTA_DE_ENTREGA', 'AUTORIZACION'],
        'NombreArticulo'       => ['FACTURA', 'ACTA_DE_ENTREGA'],
        'CantidadEntregada'    => ['FACTURA'],
        'NumeroFormula'        => ['FACTURA', 'AUTORIZACION'],
        'FechaEntrega'         => ['FACTURA'],
        'Autorizacion'         => ['FORMULA_MEDICA', 'FACTURA'],
        'Medico'               => ['AUTORIZACION'],
    ];

    /**
     * Clasifica un campo según su tipo de comparación.
     *
     * @param string $field Nombre del campo
     * @return string Tipo: 'exact'|'semantic'|'visual'|'business'
     */
    public function classify(string $field): string
    {
        return self::FIELD_TYPES[$field] ?? self::TYPE_EXACT;
    }

    /**
     * Retorna la severidad de discrepancia para un campo.
     *
     * @param string $field Nombre del campo
     * @return string Severidad: 'alta'|'media'|'baja'
     */
    public function getSeverity(string $field): string
    {
        return self::FIELD_SEVERITIES[$field] ?? self::SEVERITY_MEDIUM;
    }

    /**
     * Retorna el documento autoritativo primario para un campo.
     *
     * @param string $field Nombre del campo
     * @return string Tipo de documento autoritativo
     */
    public function getAuthoritativeDoc(string $field): string
    {
        return self::AUTHORITATIVE_DOCS[$field] ?? 'MULTIPLE';
    }

    /**
     * Retorna documentos alternativos donde buscar un campo.
     *
     * @param string $field Nombre del campo
     * @return array<string> Lista de tipos de documento alternativos
     */
    public function getAlternativeDocs(string $field): array
    {
        return self::ALTERNATIVE_DOCS[$field] ?? [];
    }

    /**
     * Retorna todos los campos de un tipo específico.
     *
     * @param string $type Tipo de comparación
     * @return array<string> Lista de campos
     */
    public function getFieldsByType(string $type): array
    {
        return array_keys(array_filter(
            self::FIELD_TYPES,
            static fn(string $t): bool => $t === $type
        ));
    }

    /**
     * Retorna todos los campos conocidos con su tipo y severidad.
     *
     * @return array<string, array{type: string, severity: string}>
     */
    public function getAllFields(): array
    {
        $result = [];
        foreach (self::FIELD_TYPES as $field => $type) {
            $result[$field] = [
                'type' => $type,
                'severity' => $this->getSeverity($field),
            ];
        }
        return $result;
    }
}
