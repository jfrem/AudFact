<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\wrap\core\MCPServer;
use App\wrap\core\tools\GetClients;
use App\wrap\core\tools\GetInvoices;
use App\wrap\core\tools\GetAttachments;
use App\wrap\core\tools\GetDispensation;

header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['tools']) || !is_array($data['tools'])) {
    http_response_code(400);
    echo json_encode(["error" => "Formato MCP invalido. Faltan 'tools'."]);
    exit;
}

$mcp = new MCPServer();
$mcp->addTools('GetClients', new GetClients());
$mcp->addTools('GetInvoices', new GetInvoices());
$mcp->addTools('GetAttachments', new GetAttachments());
$mcp->addTools('GetDispensation', new GetDispensation());

$response = $mcp->processTools($data['tools']);

echo json_encode($response, JSON_PRETTY_PRINT);
