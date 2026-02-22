<?php
// tests/cli_test_single.php

use Core\Env;
use Core\Database;
use App\Worker\GeminiAuditService;
use Core\Logger;
use GuzzleHttp\Client;

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Cargar Entorno
try {
    Env::load();
} catch (\Exception $e) {
    echo "Error loading environment: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Verificar argumentos
if ($argc < 2) {
    echo "Uso: php tests/cli_test_single.php <FACSEC>\n";
    exit(1);
}

$FacNro = $argv[1];

echo "=== INICIO TEST SINGLE AUDIT: FacNro $FacNro ===\n";

// 3. Obtener Datos de la Factura usando la conexión central de la aplicación
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    echo "Error Conexión DB: " . $e->getMessage() . "\n";
    exit(1);
}

// Buscar factura para obtener FacSec (si aplica) o validar existencia
$startTime = microtime(true);
echo "Buscando en dbo.factura...\n";
$stmt = $pdo->prepare("SELECT TOP 1 FacSec, FacNro FROM dbo.factura WHERE FacNro = ? ");
$stmt->execute([$FacNro]);
$invoice = $stmt->fetch();

if (!$invoice) {
    echo "No encontrada en dbo.factura. Buscando en vw_discolnet_dispensas...\n";
    $stmt = $pdo->prepare("SELECT TOP 1 facsec AS FacSec, Dispensa AS FacNro FROM vw_discolnet_dispensas WHERE Dispensa = ? ");
    $stmt->execute([$FacNro]);
    $invoice = $stmt->fetch();
}

if (!$invoice) {
    echo "Factura $FacNro no encontrada en ninguna de las fuentes de datos.\n";
    exit(1);
}

$FacNro = $invoice['FacNro'] ?? $FacNro;
$FacSec = $invoice['FacSec'] ?? '0';
echo "Registro Localizado. FacNro: $FacNro, FacSec: $FacSec\n";

// 4. Instanciar Servicio de Auditoría
$http = new Client([
    'timeout' => 300,
    'verify' => false
]);

$service = new GeminiAuditService($http);

// 5. Ejecutar Auditoría
try {
    echo "Enviando a auditoría...\n";
    $result = $service->auditInvoice((string)$invoice['FacSec'], (string)$FacNro, null);

    echo "\n=== RESULTADO ===\n";
    echo "Response: " . $result['response'] . "\n";
    echo "Message: " . $result['message'] . "\n";

    if (isset($result['data']['items'])) {
        echo "Items count: " . count($result['data']['items']) . "\n";
        // Mostrar muestra de items
        foreach (array_slice($result['data']['items'], 0, 3) as $item) {
            echo " - " . ($item['item'] ?? 'N/A') . ": " . ($item['detalle'] ?? 'N/A') . "\n";
        }
    }

    // Si hay error de parsing (fallback)
    if ($result['response'] === 'error' && isset($result['data']['details'])) {
        echo "Raw Details (Partial): " . substr($result['data']['details'], 0, 200) . "...\n";
    }

    $duration = microtime(true) - $startTime;
    echo "\nDuración total: " . number_format($duration, 2) . "s\n";
} catch (Exception $e) {
    echo "\n[EXCEPCION CRITICA] " . $e->getMessage() . "\n";
    Logger::error("CLI Single Test Error: " . $e->getMessage());
    exit(1);
}
