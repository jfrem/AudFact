<?php

namespace Core;

use PDO;
use PDOException;

class Database
{
    private static $connections = [];

    /**
     * Obtiene una conexión a la base de datos
     * 
     * @param string $name Nombre de la conexión (default: 'default')
     * @return PDO
     * @throws \RuntimeException
     */
    public static function getConnection($name = 'default')
    {
        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        // Obtener configuración desde variables de entorno
        $prefix = $name === 'default' ? 'DB_' : strtoupper($name) . '_DB_';

        $host = Env::get($prefix . 'HOST', 'localhost');
        $port = Env::get($prefix . 'PORT', '1433');
        $db = Env::get($prefix . 'NAME', 'mi_base');
        $user = Env::get($prefix . 'USER', 'sa');
        $pass = Env::get($prefix . 'PASS', '');
        $persistent = Env::get($prefix . 'PERSISTENT', '0') === '1';
        $pooling = Env::get($prefix . 'POOLING', '1') === '1';
        $timeout = (int)Env::get($prefix . 'TIMEOUT', '30');

        // Validación de parámetros requeridos
        if (empty($host) || empty($db)) {
            Logger::error("Configuración de base de datos incompleta para '{$name}'");
            throw new \RuntimeException("DB_HOST y DB_NAME son requeridos para la conexión '{$name}'", 500);
        }

        // Construir string del servidor
        $server = $host;
        if ($port !== '' && strpos($host, '\\') === false) {
            $server .= ",{$port}";
        }

        // Opciones de PDO
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ];

        // Manejo de conexiones persistentes
        if ($persistent) {
            if (strpos($host, '\\') !== false) {
                Logger::warning("DB_PERSISTENT con instancias nombradas ('{$host}') puede causar problemas de estabilidad");
            }
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $encrypt = Env::get($prefix . 'ENCRYPT', 'no');
        $trustCert = Env::get($prefix . 'TRUST_SERVER_CERT', 'yes');
        $dsn = "sqlsrv:Server={$server};Database={$db};Encrypt={$encrypt};TrustServerCertificate={$trustCert}";
        $dsn .= $pooling ? ';ConnectionPooling=1' : ';ConnectionPooling=0';
        $dsn .= ";LoginTimeout={$timeout}";

        try {
            self::$connections[$name] = new PDO($dsn, $user, $pass, $options);
            Logger::info("Conexión a base de datos '{$name}' establecida correctamente.");
            return self::$connections[$name];
        } catch (PDOException $e) {
            Logger::error("Error de conexión a la base de datos '{$name}': " . $e->getMessage());
            throw new \RuntimeException("Error de conexión a la base de datos '{$name}'", 500, $e);
        }
    }

    /**
     * Cierra una conexión específica o todas las conexiones
     * 
     * @param string|null $name Nombre de la conexión a cerrar (null = todas)
     * @return void
     */
    public static function closeConnection($name = null)
    {
        if ($name === null) {
            self::$connections = [];
            Logger::info('Todas las conexiones de base de datos han sido cerradas');
        } elseif (isset(self::$connections[$name])) {
            unset(self::$connections[$name]);
            Logger::info("Conexión '{$name}' cerrada correctamente");
        }
    }

    /**
     * Verifica si existe una conexión activa
     * 
     * @param string $name Nombre de la conexión
     * @return bool
     */
    public static function hasConnection($name = 'default')
    {
        return isset(self::$connections[$name]);
    }

    /**
     * Obtiene todas las conexiones activas
     * 
     * @return array
     */
    public static function getActiveConnections()
    {
        return array_keys(self::$connections);
    }

    /**
     * Ejecuta una transacción con callback
     * 
     * @param callable $callback Función a ejecutar dentro de la transacción
     * @param string $connectionName Nombre de la conexión
     * @return mixed Retorna el resultado del callback
     * @throws \Exception
     */
    public static function transaction(callable $callback, $connectionName = 'default')
    {
        $conn = self::getConnection($connectionName);

        try {
            $conn->beginTransaction();
            $result = $callback($conn);
            $conn->commit();
            return $result;
        } catch (\Exception $e) {
            $conn->rollBack();
            Logger::error("Error en transacción: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ejecuta un query preparado de forma segura
     * 
     * @param string $sql Consulta SQL
     * @param array $params Parámetros para bind
     * @param string $connectionName Nombre de la conexión
     * @return \PDOStatement
     * @throws \RuntimeException
     */
    public static function query($sql, array $params = [], $connectionName = 'default')
    {
        try {
            $conn = self::getConnection($connectionName);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Logger::error("Error ejecutando query: " . $e->getMessage() . " | SQL: {$sql}");
            throw new \RuntimeException('Error ejecutando consulta SQL', 500, $e);
        }
    }

    /**
     * Obtiene el último ID insertado
     * 
     * @param string $connectionName Nombre de la conexión
     * @return string
     */
    public static function lastInsertId($connectionName = 'default')
    {
        return self::getConnection($connectionName)->lastInsertId();
    }
}
