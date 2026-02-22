<?php
$router->get('/', 'Controller', 'index');
$router->get('/health', 'HealthController', 'status');

// Clients
$router->get('/clients', 'ClientsController', 'index');
$router->get('/clients/{clientId}', 'ClientsController', 'show');
$router->post('/clients', 'ClientsController', 'lookup');

// Invoices
$router->get('/invoices', 'InvoicesController', 'index');
$router->post('/invoices', 'InvoicesController', 'search');

// Attachments
$router->get('/dispensation/{invoiceId}/attachments/{nitSec}', 'AttachmentsController', 'showByDispensation');
$router->get('/dispensation/{invoiceId}/attachments/download/{attachmentId}', 'AttachmentsController', 'downloadByDispensation');


// Dispensation
$router->get('/dispensation/{DisDetNro}', 'DispensationController', 'show');
$router->post('/dispensation', 'DispensationController', 'lookup');

// Audit
$router->post('/audit', 'AuditController', 'run'); // Batch
$router->post('/audit/single', 'AuditController', 'single'); // Individual HA
