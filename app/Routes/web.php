<?php
$router->get('/', 'Controller', 'index');
$router->get('/health', 'HealthController', 'status');
$router->get('/metrics/async', 'ObservabilityController', 'asyncMetrics');
$router->get('/config/public', 'ConfigController', 'publicConfig');

// Clients
$router->get('/clients', 'ClientsController', 'index');
$router->get('/clients/{clientId}', 'ClientsController', 'show');
$router->get('/clients/{clientId}/documents', 'ClientsController', 'documents');
$router->post('/clients', 'ClientsController', 'lookup');

// Audit Config (Sample-Driven Configuration)
$router->get('/clients/{clientId}/audit-config', 'AuditConfigController', 'show');
$router->post('/clients/{clientId}/audit-config', 'AuditConfigController', 'save');

// Invoices
$router->get('/invoices', 'InvoicesController', 'index');
$router->post('/invoices', 'InvoicesController', 'search');

// Attachments (download route MUST come first — {nitSec} wildcard matches 'download')
$router->get('/dispensation/{disDetNro}/attachments/download/{attachmentId}', 'AttachmentsController', 'downloadByDispensation');
$router->get('/dispensation/{disDetNro}/attachments/{nitSec}', 'AttachmentsController', 'showByDispensation');


// Dispensation
$router->get('/dispensation/{DisDetNro}', 'DispensationController', 'show');
$router->post('/dispensation', 'DispensationController', 'lookup');

// Audit
// TODO: FIX #3 PENDIENTE — Aplicar ->middleware('auth') cuando se implemente AuthMiddleware + JWT
$router->get('/audit/results', 'AuditController', 'results'); // Historial persistido
$router->get('/audit/results/{facSec}', 'AuditController', 'resultDetail'); // Detalle persistido por FacSec
$router->get('/audit/stats', 'AuditController', 'stats'); // Conteos agregados para dashboard
$router->get('/audit/documents-history', 'AuditController', 'documentsHistory');
$router->post('/audit/single', 'AuditController', 'single');
$router->post('/audit/async', 'AuditController', 'async');
$router->get('/audit/jobs/{jobId}', 'AuditController', 'jobStatus');
$router->get('/audit/status/{auditId}', 'AuditController', 'status');
$router->get('/audit/dlq', 'AuditDlqController', 'index');
$router->post('/audit/dlq/reprocess', 'AuditDlqController', 'reprocess');
$router->get('/audit/{facNro}/timings', 'AuditController', 'timings');
