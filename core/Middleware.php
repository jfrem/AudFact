<?php
namespace Core;

class Middleware
{
    private static $middlewares = [];
    
    public static function register(string $name, string $handler): void
    {
        self::$middlewares[$name] = $handler;
    }
    
    public static function run(array $names): void
    {
        foreach ($names as $name) {
            if (!isset(self::$middlewares[$name])) {
                Logger::error("Middleware no encontrado: {$name}");
                Response::error("Middleware no encontrado: {$name}", 500);
            }
            
            $handler = self::$middlewares[$name];
            
            if (is_string($handler) && str_contains($handler, '::')) {
                [$class, $method] = explode('::', $handler);
                
                if (!class_exists($class)) {
                    Logger::error("Clase de middleware no encontrada: {$class}");
                    Response::error("Error de configuración de middleware", 500);
                }
                
                if (!method_exists($class, $method)) {
                    Logger::error("Método de middleware no encontrado: {$method} en {$class}");
                    Response::error("Error de configuración de middleware", 500);
                }
                
                call_user_func([$class, $method]);
            } elseif (is_callable($handler)) {
                call_user_func($handler);
            } else {
                Logger::error("Middleware inválido: {$name}");
                Response::error("Error de configuración de middleware", 500);
            }
        }
    }
}
