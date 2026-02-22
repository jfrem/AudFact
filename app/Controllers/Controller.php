<?php
namespace App\Controllers;

use Core\Response;
use Core\Validator;

class Controller
{
    protected $model;

    public function index()
    {
        Response::success([], 'Controlador base funcionando');
    }

    /**
     * Obtiene el cuerpo de la petición como JSON
     * Solo acepta application/json para APIs REST
     */
    protected function getBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        // Solo aceptar JSON para APIs REST
        if (strpos($contentType, 'application/json') === false) {
            Response::error('Content-Type must be application/json', 415);
        }
        
        // Límite de tamaño de JSON (por defecto 1MB, configurable vía MAX_JSON_SIZE)
        $maxSize = (int)(getenv('MAX_JSON_SIZE') ?: 1048576);
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 0 && $maxSize > 0 && $contentLength > $maxSize) {
            Response::error('Payload Too Large', 413);
        }
        
        $input = file_get_contents('php://input');
        if ($maxSize > 0 && strlen($input) > $maxSize) {
            Response::error('Payload Too Large', 413);
        }
        
        if (empty($input)) {
            return [];
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::error('Invalid JSON payload: ' . json_last_error_msg(), 400);
        }
        
        return is_array($data) ? $data : [];
    }

    /**
     * Validación conveniente para datos de entrada
     */
    protected function validate(array $rules): array
    {
        $data = $this->getBody();
        $errors = Validator::validate($data, $rules);
        
        if (!empty($errors)) {
            Response::error('Errores de validación', 422, $errors);
        }
        
        return $data;
    }

    /**
     * Validación para parámetros (query/route) sin depender del body JSON
     */
    protected function validateArray(array $data, array $rules): array
    {
        $errors = Validator::validate($data, $rules);
        if (!empty($errors)) {
            Response::error('Errores de validación', 422, $errors);
        }
        return $data;
    }
}
