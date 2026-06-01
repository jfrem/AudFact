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
                    ["name" => "page", "type" => "integer", "required" => false, "description" => "Página 1-indexed."],
                    ["name" => "pageSize", "type" => "integer", "required" => false, "description" => "Registros por página 1..100."]
                ]
            ],
            [
                "name" => "GetDispensation",
                "description" => "Obtiene dispensación por DisDetNro.",
                "parameters" => [
                    ["name" => "DisDetNro", "type" => "string", "required" => true, "description" => "Identificador operativo de dispensación."]
                ]
            ],
            [
                "name" => "GetAttachments",
                "description" => "Lista adjuntos por DisDetNro+nitSec o descarga por DisDetNro+attachmentId.",
                "parameters" => [
                    ["name" => "DisDetNro", "type" => "string", "required" => true, "description" => "Identificador operativo de dispensación."],
                    ["name" => "nitSec", "type" => "string", "required" => false, "description" => "NIT del cliente. Requerido para listar adjuntos."],
                    ["name" => "attachmentId", "type" => "string", "required" => false, "description" => "ID del adjunto. Si se envía, ejecuta descarga del adjunto para ese DisDetNro."]
                ]
            ]
        ]
    ]
], JSON_PRETTY_PRINT);
