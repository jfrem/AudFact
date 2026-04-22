<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\DispensationModel;
use Core\Response;

class DispensationController extends Controller
{
    private const HEADER_FIELDS = [
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
        'NumeroAutorizacion',
        'FirmaActaEntrega',
    ];

    private const ITEM_FIELDS = [
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

    public function __construct()
    {
        $this->model = new DispensationModel();
    }

    public function show(string $DisDetNro): void
    {
        $this->validateArray(['DisDetNro' => $DisDetNro], [
            'DisDetNro' => 'required|string|max:255'
        ]);
        $DisDetNro = trim($DisDetNro);

        $dispensation = $this->model->getDispensationData($DisDetNro);
        Response::success($this->transformDispensation($dispensation));
    }

    public function lookup(): void
    {
        $data = $this->validate([
            'DisDetNro' => 'required|string|max:255'
        ]);

        $DisDetNro = trim((string)$data['DisDetNro']);
        $dispensation = $this->model->getDispensationData($DisDetNro);
        Response::success($this->transformDispensation($dispensation));
    }

    private function transformDispensation(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $header = $this->extractFields($rows[0], self::HEADER_FIELDS);
        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->extractFields($row, self::ITEM_FIELDS);
        }

        return [
            'header' => $header,
            'items' => $items,
        ];
    }

    private function extractFields(array $row, array $fieldNames): array
    {
        $result = [];
        foreach ($fieldNames as $fieldName) {
            if (array_key_exists($fieldName, $row)) {
                $result[$fieldName] = $row[$fieldName];
            }
        }

        return $result;
    }
}
