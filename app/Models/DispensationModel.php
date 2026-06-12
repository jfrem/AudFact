<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;

class DispensationModel extends Model
{
    /**
     * Campos de cabecera de la FDV.
     *
     * Contrato de identidad:
     * - DisId es la llave canónica de auditoría y se lee desde DisId.
     * - NumeroFactura es la llave operativa de dispensación y se lee desde Dispensa.
     */
    public const HEADER_FIELDS = [
        'DisId',
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
     * Mapeo estricto de filtros permitidos → columnas SQL reales.
     * Aliases snake_case (pipeline) y PascalCase (REST) están soportados.
     */
    private const ALLOWED_FILTERS = [
        'dis_id'      => 'facsec',
        'dis_det_nro' => 'Dispensa',
        'DisId'       => 'facsec',
        'Dispensa'    => 'Dispensa',
    ];

    /**
     * Resuelve el DisId interno asociado a un número de dispensación.
     * Consulta rápida a tabla secundaria indexada para evitar timeouts en vistas masivas.
     *
     * @param string $disDetNro Número de dispensación/factura (ej: D14260600440)
     * @return string Identificador interno (DisId)
     * @throws \RuntimeException Si no se encuentra la dispensación
     */
    public function resolveIdentityByDisDetNro(string $disDetNro): string
    {
        $sql = "SELECT TOP 1 DisId
                FROM DispensacionDetalleServicio WITH (NOLOCK)
                WHERE DisDetNro = :disDetNro";
        
        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':disDetNro', $disDetNro, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['DisId'])) {
            throw new \RuntimeException("No se encontró la dispensación con número {$disDetNro}.");
        }

        return (string) $row['DisId'];
    }

    /**
     * Obtiene la fuente de verdad filtrando por parámetros validados contra whitelist.
     *
     * @param array<string,string> $filters Asociativo clave => valor (solo claves permitidas)
     * @return array<int,array<string,mixed>> Filas de FDV
     * @throws \InvalidArgumentException Si algún filtro no está en el whitelist o no hay filtros
     */
    public function getDispensationData(array $filters): array
    {
        if (empty($filters)) {
            throw new \InvalidArgumentException('No se proporcionaron filtros para DispensationModel');
        }

        $whereParts = [];
        $bindings = [];
        foreach ($filters as $key => $value) {
            if (!isset(self::ALLOWED_FILTERS[$key])) {
                throw new \InvalidArgumentException("Filtro no permitido en DispensationModel: {$key}");
            }
            $dbCol = self::ALLOWED_FILTERS[$key];
            $placeholder = ":{$key}";
            $whereParts[] = "{$dbCol} = {$placeholder}";
            $bindings[$placeholder] = $value;
        }

        $resolvedColumns = array_map(fn(string $k) => self::ALLOWED_FILTERS[$k], array_keys($filters));
        if (!in_array('facsec', $resolvedColumns, true) || !in_array('Dispensa', $resolvedColumns, true)) {
            throw new \InvalidArgumentException(
                'DispensationModel requiere filtros para ambas columnas: facsec (DisId) y Dispensa (DisDetNro)'
            );
        }

        return $this->getDispensationRows(
            implode(' AND ', $whereParts),
            $bindings,
            'DispensationFilters'
        );
    }


    /**
     * Ejecuta la consulta FDV con un predicado de identidad fijo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getDispensationRows(
        string $whereClause,
        array $bindings,
        string $logKey
    ): array {
        $sql = "SELECT DISTINCT
                facsec AS DisId,
                Dispensa AS NumeroFactura,

                -- Cliente/EPS
                Cliente,
                Nit AS NITCliente,
                NitSec,
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
            WHERE {$whereClause}
            ORDER BY Codigo, Lot, Cum, Producto, IdFact, Cie, Unidades_entr";

        $stmt = $this->readDb->prepare($sql);
        foreach ($bindings as $param => $val) {
            $stmt->bindValue($param, $val, PDO::PARAM_STR);
        }
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        Logger::info("Executed SQL: ", [
            $logKey     => $bindings,
            'result'    => count($result ?? []),
        ]);

        return $result ?? [];
    }
}
