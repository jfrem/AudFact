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
        'EstadoPaciente',
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
        'Autorizacion',
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
        'FactorConv',
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
            return ['header' => new \stdClass(), 'items' => []];
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
        'dis_id'      => 'v.facsec',
        'dis_det_nro' => 'v.Dispensa',
        'DisId'       => 'v.facsec',
        'Dispensa'    => 'v.Dispensa',
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

        $row = $this->read(function (PDO $connection) use ($sql, $disDetNro): array|false {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':disDetNro', $disDetNro, PDO::PARAM_STR);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

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
        if (!in_array('v.facsec', $resolvedColumns, true) || !in_array('v.Dispensa', $resolvedColumns, true)) {
            throw new \InvalidArgumentException(
                'DispensationModel requiere filtros para ambas columnas: DisId y DisDetNro'
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
                v.facsec AS DisId,
                v.Dispensa AS NumeroFactura,

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
                'Activo' AS EstadoPaciente,
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
                iif(Autorizacion=v.Dispensa,'',Autorizacion) AS NumeroAutorizacion,
                CASE
                    WHEN (Autorizacion = v.Dispensa OR LEN(Autorizacion) = 0)
                         AND ISNULL(cr.ConDisRefSinAut, 'N') = 'S' THEN 'N'
                    WHEN (Autorizacion = v.Dispensa OR LEN(Autorizacion) = 0)
                         AND ISNULL(cr.ConDisRefSinAut, 'N') = 'N' THEN 'R'
                    WHEN (Autorizacion <> v.Dispensa AND LEN(Autorizacion) > 0)
                         AND ISNULL(cr.ConDisRefSinAut, 'N') = 'S' THEN 'R'
                    ELSE 'S'
                END AS Autorizacion,
                Tipo_servicio AS Tipo,

                -- Producto
                Codigo AS CodigoArticulo,
                LEFT(Codigo_aut, CHARINDEX('-', Codigo_aut + '-') - 1) AS CodigoProducto,
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
                FactorConv,
                Mipres        AS Mipres,
                IdPrincipal,
                IdDirec,
                IdProg,
                IdEntr,
                IdRepEnt,
                IdFact,
                '828002423' NITDiscolmets,
                'Obligatorio' FirmaActaEntrega
            FROM vw_discolnet_dispensas v
            LEFT JOIN Factura f WITH (NOLOCK) ON f.DisId = v.facsec AND f.DisDetId = v.DisDetId
            LEFT JOIN FacturaKardex k WITH (NOLOCK) ON k.FacSec = f.FacSec
            LEFT JOIN ContratosDispensacionReferenci cr WITH (NOLOCK)
                ON cr.ContDisCod = k.KarContDisCod AND cr.ConDisRefCod = k.KarConDisRefCod
            WHERE {$whereClause}
            ORDER BY Codigo, Lot, Cum, Producto, IdFact, Cie, Unidades_entr";

        $result = $this->read(function (PDO $connection) use ($sql, $bindings): array {
            $stmt = $connection->prepare($sql);
            foreach ($bindings as $param => $val) {
                $stmt->bindValue($param, $val, PDO::PARAM_STR);
            }
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });

        Logger::info("Executed SQL: ", [
            $logKey     => $bindings,
            'result'    => count($result ?? []),
        ]);

        return $result ?? [];
    }
}
