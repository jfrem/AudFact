<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;

class DispensationModel extends Model
{
    /** Campos de la cabecera de la dispensación (uno por factura) */
    public const HEADER_FIELDS = [
        'FacSec',
        'NumeroFactura',
        'Cliente',
        'NITCliente',
        'NitSec',
        'VlrCobrado',
        'IPS',
        'IPS_NIT',
        'NombrePaciente',
        'TipoDocumentoPaciente',
        'DocumentoPaciente',
        'FechaNacimiento',
        'RegimenPaciente',
        'Medico',
        'TipoDocumentoMedico',
        'DocumentoMedico',
        'CodigoDiagnostico',
        'FechaEntrega',
        'FechaFormula',
        'FechaAutorizacion',
        'NITDiscolmets',
        'NumeroAutorizacion',
        'FirmaActaEntrega',
    ];

    /** Campos de cada ítem/producto dispensado */
    public const ITEM_FIELDS = [
        'Tipo',
        'CodigoArticulo',
        'CodigoProducto',
        'NombreArticulo',
        'Laboratorio',
        'CUM',
        'Lote',
        'FechaVencimiento',
        'CantidadEntregada',
        'CantidadPrescrita',
        'Mipres',
        'IdPrincipal',
        'IdDirec',
        'IdProg',
        'IdEntr',
        'IdRepEnt',
        'IdFact',
    ];

    /**
     * Transforma filas planas de la BD en el contrato canónico {header, items}.
     * Método estático puro — reutilizable por el Controlador HTTP y por AuditDataService.
     *
     * @param  array<int,array<string,mixed>> $rows  Filas crudas de getDispensationData()
     * @return array{header:array<string,mixed>,items:array<int,array<string,mixed>>}
     */
    public static function formatDispensation(array $rows): array
    {
        if ($rows === []) {
            return ['header' => [], 'items' => []];
        }

        $header = array_intersect_key($rows[0], array_flip(self::HEADER_FIELDS));
        $items  = array_map(
            static fn(array $r): array => array_intersect_key($r, array_flip(self::ITEM_FIELDS)),
            $rows
        );

        return ['header' => $header, 'items' => $items];
    }

    /**
     * Obtiene los datos de dispensación para una factura
     * @param string $DisDetNro Identificador de la factura
     * @return array Array con los registros de dispensación (vacío si no se encuentra)
     */
    public function getDispensationData(string $DisDetNro): array
    {
        $sql = "SELECT DISTINCT
                -- Identificación de la dispensación
                facsec AS FacSec,
                Dispensa AS NumeroFactura,

                -- Cliente/EPS
                Cliente,
                Nit AS NITCliente,
                NitSec,  -- EPS para validación de documentos requeridos
                Copago AS VlrCobrado,
                IPS,
                IPS_nit AS IPS_NIT,

                -- Paciente
                Paciente AS NombrePaciente,
                Paciente_doct AS TipoDocumentoPaciente,
                Paciente_doc AS DocumentoPaciente,
                Fecha_nac AS FechaNacimiento,
                CASE
                    WHEN NitSec IN ('1045', '80455','2426') THEN NULL
                    WHEN Regimen = 'Subsidiado' THEN 'Subsidiado'
                    WHEN Regimen = 'Contributivo' THEN 'Contributivo'
                    ELSE 'ARL'
                END AS RegimenPaciente,

                -- Médico
                Medico,
                Medico_DocT AS TipoDocumentoMedico,
                Medico_Doc AS DocumentoMedico,

                -- Diagnóstico
                Cie AS CodigoDiagnostico,

                -- Fechas
                CAST(Fecha_solicitud AS date) AS FechaEntrega,
                Fecha_formula AS FechaFormula,
                Fecha_autorizacion AS FechaAutorizacion,

                -- Autorización
                Autorizacion AS NumeroAutorizacion,
                Tipo_servicio AS Tipo,

                -- Producto
                Codigo AS CodigoArticulo,
                Codigo_aut AS CodigoProducto,
                Producto AS NombreArticulo,
                Laboratorio,
                CASE
                    WHEN NitSec IN ('3080','2426','1163','33527','1181') THEN Codigo_aut
                    ELSE Cum
                END AS CUM,
                Lot AS Lote,
                LotFec AS FechaVencimiento,
                Unidades_entr AS CantidadEntregada,
                Unidades_pres AS CantidadPrescrita,
                Mipres        AS Mipres,
                IdPrincipal,
                IdDirec,
                IdProg,
                IdEntr,
                IdRepEnt,
                IdFact,
                '828002423' NITDiscolmets,
                'Obligatorio' FirmaActaEntrega
            FROM vw_discolnet_dispensas
            WHERE Dispensa = :DisDetNro
            ORDER BY Codigo, Lot, Cum, Producto, IdFact, Cie, Unidades_entr";

        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':DisDetNro', $DisDetNro, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Logger::info("Executed SQL: ", [
            'DisDetNro' => $DisDetNro,
            'result'    => count($result ?? []),
        ]);

        return $result ?? [];
    }
}
