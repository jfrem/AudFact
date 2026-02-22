<?php
namespace Core;

class Env
{
    private static $cache = [];

    public static function load($path = __DIR__ . '/../.env')
    {
        if (!file_exists($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            putenv("{$name}={$value}");
            self::$cache[$name] = $value;
        }
    }

    public static function get($key, $default = null)
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        self::$cache[$key] = $value;
        return $value;
    }
}
