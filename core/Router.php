<?php
namespace Core;

class Router
{
    private $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];
    private $compiledPatterns = [];

    public function get($path, $controller, $method)
    {
        $route = new Route($path, $controller, $method);
        $this->routes['GET'][$path] = $route;
        return $route;
    }

    public function post($path, $controller, $method)
    {
        $route = new Route($path, $controller, $method);
        $this->routes['POST'][$path] = $route;
        return $route;
    }

    public function put($path, $controller, $method)
    {
        $route = new Route($path, $controller, $method);
        $this->routes['PUT'][$path] = $route;
        return $route;
    }

    public function delete($path, $controller, $method)
    {
        $route = new Route($path, $controller, $method);
        $this->routes['DELETE'][$path] = $route;
        return $route;
    }

    public function dispatch()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') ?: '/';

        if (!isset($this->routes[$method])) {
            Logger::error("Método no soportado: {$method} en {$uri}");
            Response::error("Método no soportado: {$method}", 405);
        }

        foreach ($this->routes[$method] as $route => $routeObj) {
            if (!isset($this->compiledPatterns[$route])) {
                $this->compiledPatterns[$route] = '#^' . preg_replace('#\{(\w+)\??\}#', '([\w-]*)', rtrim($route, '/')) . '$#';
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

    private function execute(Route $route, array $params)
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
            
            // Sanitizar parámetros
            $sanitizedParams = array_map(function($param) {
                // Añadir validación de longitud máxima
                if (strlen($param) > 255) {
                    Logger::error("Parámetro de ruta excede longitud máxima");
                    Response::error('Parámetro inválido', 400);
                }
                return filter_var($param, FILTER_SANITIZE_SPECIAL_CHARS);
            }, $params);
            
            // Ejecutar controlador
            call_user_func_array([$instance, $action], $sanitizedParams);
        } catch (\Exception $e) {
            Logger::error("Excepción en {$controller}::{$action}: " . $e->getMessage());
            Response::error('Error interno del servidor', 500);
        }
    }
}
