<?php
declare(strict_types=1);

namespace Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];
    private array $compiledPatterns = [];

    public function get(string $path, string $controller, string $method): Route
    {
        $route = new Route($path, $controller, $method);
        $this->routes['GET'][$path] = $route;
        return $route;
    }

    public function post(string $path, string $controller, string $method): Route
    {
        $route = new Route($path, $controller, $method);
        $this->routes['POST'][$path] = $route;
        return $route;
    }

    public function put(string $path, string $controller, string $method): Route
    {
        $route = new Route($path, $controller, $method);
        $this->routes['PUT'][$path] = $route;
        return $route;
    }

    public function delete(string $path, string $controller, string $method): Route
    {
        $route = new Route($path, $controller, $method);
        $this->routes['DELETE'][$path] = $route;
        return $route;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

        if (!isset($this->routes[$method])) {
            Logger::error("Método no soportado: {$method} en {$uri}");
            Response::error("Método no soportado: {$method}", 405);
        }

        foreach ($this->routes[$method] as $route => $routeObj) {
            if (!isset($this->compiledPatterns[$route])) {
                $compiledRoute = preg_replace_callback(
                    '#\{(\w+)(\?)?\}#',
                    static function (array $matches): string {
                        $isOptional = isset($matches[2]) && $matches[2] === '?';
                        return $isOptional ? '([\w-]*)' : '([\w-]+)';
                    },
                    rtrim($route, '/')
                );

                $this->compiledPatterns[$route] = '#^' . $compiledRoute . '$#';
            }

            $pattern = $this->compiledPatterns[$route];

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $this->execute($routeObj, $matches);
                return;
            }
        }

        Logger::error("Ruta no encontrada: {$method} {$uri}");
        Response::error("Ruta no encontrada: {$uri}", 404);
    }

    private function execute(Route $route, array $params): void
    {
        $controller = $route->getController();
        $action = $route->getAction();
        $middlewares = $route->getMiddlewares();

        $class = "App\\Controllers\\{$controller}";

        if (!class_exists($class)) {
            Logger::error("Controlador no encontrado: {$controller}");
            Response::error("Controlador no encontrado: {$controller}", 404);
        }

        $instance = new $class();

        if (!method_exists($instance, $action)) {
            Logger::error("Método no encontrado: {$action} en {$controller}");
            Response::error("Método no encontrado: {$action}", 404);
        }

        try {
            // Ejecutar middlewares
            Middleware::run($middlewares);

            // Sanitizar parámetros (eliminar truncado HTML destructivo en URL)
            $sanitizedParams = array_map(function ($param) {
                // Añadir validación de longitud máxima para buffers
                if (strlen($param) > 255) {
                    Logger::error("Parámetro de ruta excede longitud máxima");
                    Response::error('Parámetro inválido', 400);
                }
                // Confíamos en Prepared Statements para SQL y raw params puros para Controller.
                return $param;
            }, $params);

            // Ejecutar controlador
            call_user_func_array([$instance, $action], $sanitizedParams);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Logger::error("Excepción en {$controller}::{$action}: " . $e->getMessage());
            Response::error('Error interno del servidor', 500);
        }
    }
}
