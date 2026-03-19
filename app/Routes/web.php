<?php
$router->get('/', 'Controller', 'index');
$router->get('/health', 'HealthController', 'status');
$router->get('/config/public', 'ConfigController', 'publicConfig');

// Clients
$router->get('/clients', 'ClientsController', 'index');
$router->get('/clients/{clientId}', 'ClientsController', 'show');
$router->post('/clients', 'ClientsController', 'lookup');

// Invoices
$router->get('/invoices', 'InvoicesController', 'index');
$router->post('/invoices', 'InvoicesController', 'search');

// Attachments (download route MUST come first — {nitSec} wildcard matches 'download')
$router->get('/dispensation/{invoiceId}/attachments/download/{attachmentId}', 'AttachmentsController', 'downloadByDispensation');
$router->get('/dispensation/{invoiceId}/attachments/{nitSec}', 'AttachmentsController', 'showByDispensation');


// Dispensation
$router->get('/dispensation/{DisDetNro}', 'DispensationController', 'show');
$router->post('/dispensation', 'DispensationController', 'lookup');

// Audit
// TODO: FIX #3 PENDIENTE — Aplicar ->middleware('auth') cuando se implemente AuthMiddleware + JWT
$router->get('/audit/results', 'AuditController', 'results'); // Historial persistido
$router->get('/audit/documents-history', 'AuditController', 'documentsHistory'); // Nuevo: historial facturas/documentos
$router->post('/audit', 'AuditController', 'run'); // Batch (síncrono)
$router->post('/audit/single', 'AuditController', 'single'); // Individual HA
$router->post('/audit/async', 'AuditController', 'async'); // Batch async (Fase 3)
$router->get('/audit/jobs/{jobId}', 'AuditController', 'jobStatus'); // Estado de job async
