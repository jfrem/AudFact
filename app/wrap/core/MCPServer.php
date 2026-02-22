<?php

namespace App\wrap\core;

use Exception;

class MCPServer
{
    private $tools = [];

    public function addTools($name, $tool)
    {
        $this->tools[$name] = $tool;
    }

    public function processRequest($request)
    {
        $toolName = $request['tool'];
        $params = $request['params'] ?? [];

        if (!isset($this->tools[$toolName])) {
            throw new Exception("Herramienta no encontrada: " . $toolName);
        }
        try {
            return $this->tools[$toolName]->execute($params);
        } catch (Exception $e) {
            return ["error" => "Error en '$toolName' " . $e->getMessage()];
        }
    }

    public function processTools(array $requests): array
    {
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->processRequest($request);
        }
        return $responses;
    }
}
