<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;

class Database
{
    /** @var array<string, PDO> Cache por fingerprint de configuración */
    private static array $connections = [];
    /** @var array<string, string> Mapa nombre lógico => fingerprint */
    private static array $connectionAliases = [];

    /**
     * Obtiene una conexión a la base de datos
     * 
     * @param string $name Nombre de la conexión (default: 'default')
     * @return PDO
     * @throws \RuntimeException
     */
    public static function getConnection(string $name = 'default'): PDO
    {
        $config = self::resolveConnectionConfig($name);
        $cacheKey = self::buildCacheKey($config);

        if (isset(self::$connections[$cacheKey])) {
            self::$connectionAliases[$name] = $cacheKey;
            return self::$connections[$cacheKey];
        }

        $host = $config['host'];
        $port = $config['port'];
        $db = $config['db'];
        $user = $config['user'];
        $pass = $config['pass'];
        $persistent = $config['persistent'];
        $pooling = $config['pooling'];
        $timeout = $config['timeout'];
        $encrypt = $config['encrypt'];
        $trustCert = $config['trustCert'];

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

        $dsn = "sqlsrv:Server={$server};Database={$db};Encrypt={$encrypt};TrustServerCertificate={$trustCert}";
        $dsn .= $pooling ? ';ConnectionPooling=1' : ';ConnectionPooling=0';
        $dsn .= ";LoginTimeout={$timeout}";

        try {
            self::$connections[$cacheKey] = new PDO($dsn, $user, $pass, $options);
            self::$connectionAliases[$name] = $cacheKey;
            Logger::info("Conexión a base de datos '{$name}' establecida correctamente.");
            return self::$connections[$cacheKey];
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
    public static function closeConnection(?string $name = null): void
    {
        if ($name === null) {
            self::$connections = [];
            self::$connectionAliases = [];
            Logger::info('Todas las conexiones de base de datos han sido cerradas');
            return;
        }

        if (!isset(self::$connectionAliases[$name])) {
            return;
        }

        $cacheKey = self::$connectionAliases[$name];
        unset(self::$connectionAliases[$name]);

        if (!in_array($cacheKey, self::$connectionAliases, true) && isset(self::$connections[$cacheKey])) {
            unset(self::$connections[$cacheKey]);
        }

        Logger::info("Conexión '{$name}' cerrada correctamente");
    }

    /**
     * Verifica si existe una conexión activa
     * 
     * @param string $name Nombre de la conexión
     * @return bool
     */
    public static function hasConnection(string $name = 'default'): bool
    {
        if (isset(self::$connectionAliases[$name])) {
            return isset(self::$connections[self::$connectionAliases[$name]]);
        }

        $config = self::resolveConnectionConfig($name);
        $cacheKey = self::buildCacheKey($config);
        return isset(self::$connections[$cacheKey]);
    }

    /**
     * Obtiene todas las conexiones activas
     * 
     * @return array
     */
    public static function getActiveConnections(): array
    {
        return array_keys(self::$connectionAliases);
    }

    /**
     * Ejecuta una transacción con callback
     * 
     * @param callable $callback Función a ejecutar dentro de la transacción
     * @param string $connectionName Nombre de la conexión
     * @return mixed Retorna el resultado del callback
     * @throws \Exception
     */
    public static function transaction(callable $callback, string $connectionName = 'default')
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
    public static function query(string $sql, array $params = [], string $connectionName = 'default'): \PDOStatement
    {
        try {
            $conn = self::getConnection($connectionName);
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Logger::error("Error ejecutando query: " . $e->getMessage() . " | SQL: [REDACTED]");
            throw new \RuntimeException('Error ejecutando consulta SQL', 500, $e);
        }
    }

    /**
     * Obtiene el último ID insertado
     * 
     * @param string $connectionName Nombre de la conexión
     * @return string
     */
    public static function lastInsertId(string $connectionName = 'default'): string
    {
        return self::getConnection($connectionName)->lastInsertId();
    }

    /**
     * @return array{host:string,port:string,db:string,user:string,pass:string,persistent:bool,pooling:bool,timeout:int,encrypt:string,trustCert:string}
     */
    private static function resolveConnectionConfig(string $name): array
    {
        $prefix = self::resolveEnvPrefix($name);

        $host = Env::get($prefix . 'HOST', 'localhost');
        $port = Env::get($prefix . 'PORT', '1433');
        $db = Env::get($prefix . 'NAME', 'mi_base');
        $user = Env::get($prefix . 'USER', 'sa');
        $pass = Env::get($prefix . 'PASS', '');
        $persistent = Env::get($prefix . 'PERSISTENT', '0') === '1';
        $pooling = Env::get($prefix . 'POOLING', '1') === '1';
        $timeout = (int) Env::get($prefix . 'TIMEOUT', '30');
        $encrypt = Env::get($prefix . 'ENCRYPT', 'no');
        $trustCert = Env::get($prefix . 'TRUST_SERVER_CERT', 'yes');

        return [
            'host' => $host,
            'port' => $port,
            'db' => $db,
            'user' => $user,
            'pass' => $pass,
            'persistent' => $persistent,
            'pooling' => $pooling,
            'timeout' => $timeout,
            'encrypt' => $encrypt,
            'trustCert' => $trustCert,
        ];
    }

    private static function buildCacheKey(array $config): string
    {
        $fingerprint = [
            'host' => $config['host'],
            'port' => $config['port'],
            'db' => $config['db'],
            'user' => $config['user'],
            'persistent' => $config['persistent'],
            'pooling' => $config['pooling'],
            'timeout' => $config['timeout'],
            'encrypt' => $config['encrypt'],
            'trustCert' => $config['trustCert'],
            // Incluye hash de credencial para evitar colisión entre usuarios/contraseñas.
            'secret' => hash('sha256', (string) $config['pass']),
        ];

        return hash('sha256', (string) json_encode($fingerprint));
    }

    private static function resolveEnvPrefix(string $name): string
    {
        if ($name === 'default') {
            return 'DB_';
        }

        if ($name === 'db2') {
            return 'DB2_';
        }

        return strtoupper($name) . '_DB_';
    }
}
