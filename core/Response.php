<?php

namespace Core;

class Response
{
    public static function json($data, $code = 200)
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function success($data = [], $message = 'Operación exitosa', $code = 200)
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    public static function error($message, $code = 400, $errors = null)
    {
        $response = ['success' => false, 'message' => $message];
        if ($errors !== null) $response['errors'] = $errors;
        self::json($response, $code);
    }

    /**
     * Respuesta paginada estandarizada
     * @param array $data Datos de la página actual
     * @param int $page Página actual
     * @param int $perPage Elementos por página
     * @param int $total Total de elementos
     * @param string $message Mensaje opcional
     */
    public static function paginated(array $data, int $page, int $perPage, int $total, string $message = 'Operación exitosa')
    {
        $totalPages = (int)ceil($total / $perPage);

        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ]
        ]);
    }
}
