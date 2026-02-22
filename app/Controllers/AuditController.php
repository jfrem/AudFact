<?php

namespace App\Controllers;

use App\Models\InvoicesModel;
use App\Worker\GeminiAuditService;
use Core\Response;
use Core\Logger;

class AuditController extends Controller
{
    public function run(): void
    {
        // C03: Prevenir timeout en auditorías masivas cerrando límites
        set_time_limit(3600); // 1 hora máximo para el lote
        // C04: Proveer memoria suficiente para el array de resultados y procesamiento base64
        ini_set('memory_limit', '1024M');

        // Validar y sanitizar los parámetros de entrada
        $data = $this->validate([
            'facNitSec' => 'required|integer|min_value:1',
            'date' => 'required|date',
            'limit' => 'required|integer|min_value:1|max_value:1000',
        ]);

        Logger::info("AuditController: Received request with parameters: " . json_encode($data));

        $facNitSec = (int)$data['facNitSec'];
        $date = (string)$data['date'];
        $limit = (int)$data['limit'];

        $invoices = (new InvoicesModel())->getInvoices($facNitSec, $date, $limit);
        Logger::info("AuditController: Retrieved " . count($invoices) . " invoices for facNitSec={$facNitSec}, date={$date}, limit={$limit}");
        if (empty($invoices)) {
            Response::success(['items' => []], 'No se encontraron facturas para los parámetros indicados.');
        }

        $auditor = new GeminiAuditService();
        $results = [];
        foreach ($invoices as $invoice) {
            $FacNro = (string)($invoice['FacNro'] ?? '');
            $disId = (string)($invoice['DisId'] ?? $FacNro);

            if ($FacNro === '' || $disId === '') {
                $results[] = [
                    'invoice' => $invoice,
                    'result' => [
                        'response' => 'error',
                        'message' => 'Factura inválida: FacNro/DisId faltante',
                        'data' => ['items' => []],
                    ],
                ];
                continue;
            }

            $results[] = [
                'invoice' => $invoice,
                'result' => $auditor->auditInvoice($disId, $FacNro, null),
            ];
        }

        Response::success([
            'items' => $results,
        ], 'Auditoría ejecutada');
    }
}
