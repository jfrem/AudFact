<?php

declare(strict_types=1);

namespace Core;

use Core\Exceptions\HttpResponseException;

class Response
{
    public static function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Este método SIEMPRE lanza HttpResponseException y nunca retorna.
     * Cualquier código después de una llamada a Response::success() es inalcanzable.
     *
     * @return never
     */
    #[\NoReturn]
    public static function success(mixed $data = [], string $message = 'Operación exitosa', int $code = 200): void
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
        throw new HttpResponseException($response, $code);
    }

    /**
     * Este método SIEMPRE lanza HttpResponseException y nunca retorna.
     *
     * @return never
     */
    #[\NoReturn]
    public static function error(string $message, int $code = 400, mixed $errors = null): void
    {
        $response = ['success' => false, 'message' => $message];
        if ($errors !== null) $response['errors'] = $errors;
        throw new HttpResponseException($response, $code);
    }

    /**
     * Respuesta paginada estandarizada.
     * Este método SIEMPRE lanza HttpResponseException y nunca retorna.
     *
     * @return never
     */
    #[\NoReturn]
    public static function paginated(array $data, int $page, int $perPage, int $total, string $message = 'Operación exitosa'): void
    {
        $totalPages = (int)ceil($total / $perPage);

        $response = [
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
        ];
        throw new HttpResponseException($response, 200);
    }
}
