<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use Core\Logger;

class DispensationModel extends Model
{
    /**
     * Obtiene los datos de dispensación para una factura
     * @param string $DisDetNro Identificador de la factura
     * @return array Array con los registros de dispensación (vacío si no se encuentra)
     */
    public function getDispensationData(string $DisDetNro): array
    {
        $sql = "SELECT
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
                Regimen AS RegimenPaciente,
                
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
                Cum AS CUM,
                Lot AS Lote,
                LotFec AS FechaVencimiento,
                Unidades_entr AS CantidadEntregada,
                Unidades_pres AS CantidadPrescrita,
                Mipres		  AS Mipres,
                IdPrincipal,
                IdDirec,
                IdProg,
                IdEntr,
                IdRepEnt,
                IdFact,
                'Obligatorio' FirmaActaEntrega
            FROM vw_discolnet_dispensas
            WHERE Dispensa = :DisDetNro --AND Tipo_servicio in ('POS','MIPRES')
            GROUP BY
                facsec, Dispensa, Cliente, Nit, NitSec, Copago, IPS, IPS_nit,
                Paciente, Paciente_doct, Paciente_doc, Fecha_nac, Regimen,
                Medico, Medico_DocT, Medico_Doc,
                Cie, CieNom,
                Fecha_solicitud, Fecha_formula, Fecha_autorizacion,
                Autorizacion, Tipo_servicio,
                Codigo, Codigo_aut, Producto, Laboratorio, Cum, Lot, LotFec,
                Unidades_entr, Unidades_pres, Mipres,
                IdPrincipal, IdDirec, IdProg, IdEntr, IdRepEnt, IdFact";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':DisDetNro', $DisDetNro, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Logger::info("Executed SQL: ", [
            'DisDetNro' => $DisDetNro,
            'result' => count($result ?? [])
        ]);

        return $result ?? [];
    }
}
