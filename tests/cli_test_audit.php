<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Env;
use App\Models\InvoicesModel;
use App\Worker\GeminiAuditService;
use Core\Logger;

// Cargar variables de entorno
try {
    Env::load();
    echo "Environment loaded.\n";
} catch (\Exception $e) {
    echo "Error loading environment: " . $e->getMessage() . "\n";
    exit(1);
}

// Parámetros de prueba
$facNitSec = 1165;
$date = '2025-12-30';
$limit = 10;

echo "Running PHP Audit Test...\n";
echo "Client: $facNitSec\n";
echo "Date: $date\n";
echo "Limit: $limit\n";

// Instanciar Modelo de Facturas
try {
    $invoicesModel = new InvoicesModel();
    $invoices = $invoicesModel->getInvoices($facNitSec, $date, $limit);

    if (empty($invoices)) {
        echo "No invoices found.\n";
        exit(0);
    }

    echo "Found " . count($invoices) . " invoices.\n";

    // Instanciar Cliente HTTP sin verificación SSL (para evitar error cURL 60 en local)
    $http = new \GuzzleHttp\Client([
        'timeout' => 300,
        'verify' => false
    ]);

    // Instanciar Servicio de Auditoría
    $auditor = new GeminiAuditService($http);

    foreach ($invoices as $i => $invoice) {
        $FacNro = (string)($invoice['FacNro'] ?? '');
        $disId = (string)($invoice['DisId'] ?? $FacNro);

        echo "\n[" . ($i + 1) . "/" . count($invoices) . "] Auditing Invoice: $FacNro (DisId: $disId)...\n";

        if ($FacNro === '' || $disId === '') {
            echo "Skipping invalid invoice data.\n";
            continue;
        }

        try {
            $result = $auditor->auditInvoice($disId, $FacNro, null);

            echo "Result: " . $result['response'] . "\n";
            if ($result['response'] !== 'success') {
                echo "Message: " . $result['message'] . "\n";
            }
            // print_r($result); 
        } catch (\Exception $e) {
            echo "Error auditing invoice: " . $e->getMessage() . "\n";
        }
    }

    echo "\nTest Completed.\n";
} catch (\Exception $e) {
    echo "Critical Error: " . $e->getMessage() . "\n";
    Logger::error("CLI Test Error: " . $e->getMessage());
}
