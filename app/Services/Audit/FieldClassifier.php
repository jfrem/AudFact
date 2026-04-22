<?php

namespace App\Services\Audit;

class FieldClassifier
{
    public const TYPE_EXACT = 'exact';
    public const TYPE_SEMANTIC = 'semantic';
    public const TYPE_VISUAL = 'visual';
    public const TYPE_BUSINESS = 'business';

    public const SEVERITY_HIGH = 'alta';
    public const SEVERITY_MEDIUM = 'media';
    public const SEVERITY_LOW = 'baja';

    private const FIELD_ALIASES = [
        'DocumentoPaciente' => 'NumeroIdentificacion',
        'TipoDocumentoPaciente' => 'TipoIdentificacion',
        'NumeroAutorizacion' => 'Autorizacion',
        'Cliente' => 'Cliente.Entidad',
        'RegimenPaciente' => 'Cliente.Regimen',
    ];

    private const FIELD_TYPES = [
        'NumeroFactura'       => self::TYPE_EXACT,
        'NumeroFormula'       => self::TYPE_EXACT,
        'Autorizacion'        => self::TYPE_EXACT,
        'TipoIdentificacion'  => self::TYPE_EXACT,
        'NumeroIdentificacion' => self::TYPE_EXACT,
        'FechaFormula'        => self::TYPE_EXACT,
        'FechaAutorizacion'   => self::TYPE_EXACT,
        'FechaEntrega'        => self::TYPE_EXACT,
        'FechaVencimiento'    => self::TYPE_EXACT,
        'VlrCobrado'          => self::TYPE_EXACT,
        'Mipres'              => self::TYPE_EXACT,
        'IdPrincipal'         => self::TYPE_EXACT,
        'IdDirec'             => self::TYPE_EXACT,
        'IdProg'              => self::TYPE_EXACT,
        'IdEntr'              => self::TYPE_EXACT,
        'IdRepEnt'            => self::TYPE_EXACT,
        'Lote'                => self::TYPE_EXACT,
        'NITCliente'          => self::TYPE_EXACT,
        'TipoDocumentoMedico' => self::TYPE_EXACT,
        'DocumentoMedico'     => self::TYPE_EXACT,
        'CodigoDiagnostico'   => self::TYPE_EXACT,
        'CodigoArticulo'      => self::TYPE_EXACT,
        'CodigoProducto'      => self::TYPE_EXACT,
        'CUM'                 => self::TYPE_EXACT,
        'Tipo'                => self::TYPE_EXACT,

        'NombrePaciente'      => self::TYPE_SEMANTIC,
        'NombreArticulo'      => self::TYPE_SEMANTIC,
        'Medico'              => self::TYPE_SEMANTIC,
        'Laboratorio'         => self::TYPE_SEMANTIC,
        'IPS'                 => self::TYPE_SEMANTIC,
        'Cliente.Entidad'     => self::TYPE_SEMANTIC,

        'FirmaActaEntrega'    => self::TYPE_VISUAL,
        'SelloRecepcion'      => self::TYPE_VISUAL,
        'FirmaPrescriptor'    => self::TYPE_VISUAL,

        'CantidadEntregada'   => self::TYPE_BUSINESS,
        'CantidadPrescrita'   => self::TYPE_BUSINESS,
        'Cliente.Regimen'     => self::TYPE_BUSINESS,
    ];

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
        'FirmaPrescriptor'     => self::SEVERITY_HIGH,
        'Cliente.Regimen'      => self::SEVERITY_HIGH,

        // Media
        'NumeroFormula'        => self::SEVERITY_MEDIUM,
        'Medico'               => self::SEVERITY_MEDIUM,
        'Laboratorio'          => self::SEVERITY_MEDIUM,
        'FechaFormula'         => self::SEVERITY_MEDIUM,
        'FechaAutorizacion'    => self::SEVERITY_MEDIUM,
        'FechaEntrega'         => self::SEVERITY_MEDIUM,
        'FechaVencimiento'     => self::SEVERITY_MEDIUM,
        'IPS'                  => self::SEVERITY_MEDIUM,
        'Cliente.Entidad'      => self::SEVERITY_MEDIUM,
        'NITCliente'           => self::SEVERITY_MEDIUM,
        'TipoDocumentoMedico'  => self::SEVERITY_MEDIUM,
        'DocumentoMedico'      => self::SEVERITY_MEDIUM,
        'CodigoDiagnostico'    => self::SEVERITY_MEDIUM,
        'CodigoArticulo'       => self::SEVERITY_MEDIUM,
        'CodigoProducto'       => self::SEVERITY_MEDIUM,
        'CUM'                  => self::SEVERITY_MEDIUM,
        'Tipo'                 => self::SEVERITY_MEDIUM,

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
        'FirmaPrescriptor'     => 'FORMULA_MEDICA',
        'FechaEntrega'         => 'ACTA_DE_ENTREGA',
        'FechaVencimiento'     => 'FACTURA',
        'Autorizacion'         => 'AUTORIZACION',
        'FechaAutorizacion'    => 'AUTORIZACION',
        'IPS'                  => 'AUTORIZACION',
        'Cliente.Entidad'      => 'AUTORIZACION',
        'Cliente.Regimen'      => 'AUTORIZACION',
        'NITCliente'           => 'AUTORIZACION',
        'TipoDocumentoMedico'  => 'FORMULA_MEDICA',
        'DocumentoMedico'      => 'FORMULA_MEDICA',
        'CodigoDiagnostico'    => 'FORMULA_MEDICA',
        'Laboratorio'          => 'FACTURA',
        'VlrCobrado'           => 'FACTURA',
        'Mipres'               => 'FORMULA_MEDICA',
        'Lote'                 => 'FACTURA',
        'CodigoArticulo'       => 'FACTURA',
        'CodigoProducto'       => 'FACTURA',
        'CUM'                  => 'FACTURA',
        'Tipo'                 => 'FACTURA',
        'SelloRecepcion'       => 'ACTA_DE_ENTREGA',
    ];

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

    private const FIELD_SQL_COLUMNS = [
        'NumeroFactura'        => 'NumeroFactura',
        'NumeroFormula'        => null,                 // No existe en SQL, solo en docs
        'Autorizacion'         => 'NumeroAutorizacion',
        'TipoIdentificacion'   => 'TipoDocumentoPaciente',
        'NumeroIdentificacion' => 'DocumentoPaciente',
        'FechaFormula'         => 'FechaFormula',
        'FechaAutorizacion'    => 'FechaAutorizacion',
        'FechaEntrega'         => 'FechaEntrega',
        'FechaVencimiento'     => 'FechaVencimiento',
        'VlrCobrado'           => 'VlrCobrado',
        'Mipres'               => 'Mipres',
        'IdPrincipal'          => 'IdPrincipal',
        'IdDirec'              => 'IdDirec',
        'IdProg'               => 'IdProg',
        'IdEntr'               => 'IdEntr',
        'IdRepEnt'             => 'IdRepEnt',
        'Lote'                 => 'Lote',
        'NITCliente'           => 'NITCliente',
        'TipoDocumentoMedico'  => 'TipoDocumentoMedico',
        'DocumentoMedico'      => 'DocumentoMedico',
        'CodigoDiagnostico'    => 'CodigoDiagnostico',
        'CodigoArticulo'       => 'CodigoArticulo',
        'CodigoProducto'       => 'CodigoProducto',
        'CUM'                  => 'CUM',
        'Tipo'                 => 'Tipo',
        'NombrePaciente'       => 'NombrePaciente',
        'NombreArticulo'       => 'NombreArticulo',
        'Medico'               => 'Medico',
        'Laboratorio'          => 'Laboratorio',
        'IPS'                  => 'IPS',
        'Cliente.Entidad'      => 'Cliente',
        'Cliente.Regimen'      => 'RegimenPaciente',
        'FirmaActaEntrega'     => 'FirmaActaEntrega',
        'SelloRecepcion'       => null,                 // No existe en BD, solo verificación visual
        'FirmaPrescriptor'     => null,                 // No existe en BD, solo verificación visual
        'CantidadEntregada'    => 'CantidadEntregada',
        'CantidadPrescrita'    => 'CantidadPrescrita',
    ];

    /**
     * Normaliza alias históricos o de base de datos al nombre canónico del campo.
     *
     * @param  string $field  Nombre recibido desde configuración, extracción o FDV.
     * @return string Nombre canónico usado por el pipeline.
     */
    public function normalizeField(string $field): string
    {
        return self::FIELD_ALIASES[$field] ?? $field;
    }

    /**
     * Obtiene la columna SQL asociada a un campo canónico.
     *
     * @param  string $field  Nombre del campo a resolver.
     * @return string|null Columna SQL correspondiente, o null si solo existe en documentos.
     */
    public function getSqlColumn(string $field): ?string
    {
        $field = $this->normalizeField($field);
        if (array_key_exists($field, self::FIELD_SQL_COLUMNS)) {
            return self::FIELD_SQL_COLUMNS[$field];
        }
        return null;
    }

    /**
     * Clasifica un campo por estrategia de evaluación.
     *
     * @param  string $field  Nombre del campo a clasificar.
     * @return string Tipo de comparación: exact, semantic, visual o business.
     */
    public function classify(string $field): string
    {
        $field = $this->normalizeField($field);
        return self::FIELD_TYPES[$field] ?? self::TYPE_EXACT;
    }

    /**
     * Devuelve la severidad por defecto configurada para un campo.
     *
     * @param  string $field  Nombre del campo a evaluar.
     * @return string Severidad normalizada: alta, media o baja.
     */
    public function getSeverity(string $field): string
    {
        $field = $this->normalizeField($field);
        return self::FIELD_SEVERITIES[$field] ?? self::SEVERITY_MEDIUM;
    }

    /**
     * Identifica el documento autoritativo para validar un campo.
     *
     * @param  string $field  Nombre del campo a consultar.
     * @return string Tipo documental canónico o MULTIPLE si no hay uno específico.
     */
    public function getAuthoritativeDoc(string $field): string
    {
        $field = $this->normalizeField($field);
        return self::AUTHORITATIVE_DOCS[$field] ?? 'MULTIPLE';
    }

    /**
     * Lista documentos alternativos aceptables para buscar un campo.
     *
     * @param  string $field  Nombre del campo a consultar.
     * @return array<int, string> Tipos documentales alternativos en orden de prioridad.
     */
    public function getAlternativeDocs(string $field): array
    {
        $field = $this->normalizeField($field);
        return self::ALTERNATIVE_DOCS[$field] ?? [];
    }

    /**
     * Obtiene todos los campos clasificados con un tipo de evaluación específico.
     *
     * @param  string $type  Tipo de evaluación requerido.
     * @return array<int, string> Nombres de campos que pertenecen al tipo solicitado.
     */
    public function getFieldsByType(string $type): array
    {
        return array_keys(array_filter(
            self::FIELD_TYPES,
            static fn(string $t): bool => $t === $type
        ));
    }

    /**
     * Devuelve el catálogo completo de campos evaluables con tipo y severidad.
     *
     * @return array<string, array{type: string, severity: string}> Metadatos por campo.
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
