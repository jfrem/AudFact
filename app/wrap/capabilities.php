<?php

header('Content-Type: application/json');
echo json_encode([
    "capabilities" => [
        "tools" => [
            [
                "name" => "GetClients",
                "description" => "Obtiene clientes o un cliente por ID.",
                "parameters" => [
                    ["name" => "clientId", "type" => "integer", "required" => false, "description" => "ID del cliente."]
                ]
            ],
            [
                "name" => "GetInvoices",
                "description" => "Obtiene facturas por facNitSec y fecha.",
                "parameters" => [
                    ["name" => "facNitSec", "type" => "integer", "required" => true, "description" => "NIT del cliente."],
                    ["name" => "date", "type" => "string", "required" => true, "description" => "Fecha YYYY-MM-DD."],
                    ["name" => "limit", "type" => "integer", "required" => false, "description" => "Límite 1..1000."]
                ]
            ],
            [
                "name" => "GetDispensation",
                "description" => "Obtiene dispensación por invoiceId (acepta aliases legacy: DisDetNro/facSec).",
                "parameters" => [
                    ["name" => "invoiceId", "type" => "string", "required" => true, "description" => "Identificador de factura/dispensación (DisDetNro/FacNro)."],
                    ["name" => "DisDetNro", "type" => "string", "required" => false, "description" => "Alias legacy de invoiceId."],
                    ["name" => "facSec", "type" => "string", "required" => false, "description" => "Alias legacy de invoiceId."]
                ]
            ],
            [
                "name" => "GetAttachments",
                "description" => "Lista adjuntos por invoiceId+nitSec o descarga por invoiceId+attachmentId.",
                "parameters" => [
                    ["name" => "invoiceId", "type" => "string", "required" => true, "description" => "Identificador de factura/dispensación."],
                    ["name" => "nitSec", "type" => "string", "required" => false, "description" => "NIT del cliente. Requerido para listar adjuntos."],
                    ["name" => "attachmentId", "type" => "string", "required" => false, "description" => "ID del adjunto. Si se envía, ejecuta descarga del adjunto para ese invoiceId."]
                ]
            ]
        ]
    ]
], JSON_PRETTY_PRINT);
