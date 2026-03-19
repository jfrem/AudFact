<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Env;
use PDO;

class Model
{
    protected \PDO $readDb;
    protected ?\PDO $writeDb = null;
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected string $readConnectionName = 'db2';
    protected string $writeConnectionName = 'default';

    /**
     * Lista blanca de columnas que pueden ser asignadas masivamente
     * DEBE ser definida en cada modelo hijo para seguridad
     */
    protected array $fillable = [];

    /**
     * Inicializa conexiones separadas para lectura y escritura.
     *
     * @return void
     */
    public function __construct()
    {
        $this->readDb = Database::getConnection($this->readConnectionName);
    }

    /**
     * Retorna la conexión de escritura, instanciándola on-demand (lazy).
     * Modelos de solo lectura nunca crean esta conexión.
     *
     * @return \PDO
     */
    protected function getWriteDb(): \PDO
    {
        if ($this->writeDb === null) {
            $this->writeDb = Database::getConnection($this->writeConnectionName);
        }
        return $this->writeDb;
    }

    /**
     * Valida que el modelo tenga definida la propiedad $fillable
     * @throws \RuntimeException
     */
    protected function validateFillable(): void
    {
        if (empty($this->fillable)) {
            throw new \RuntimeException(
                'Security: $fillable must be defined in ' . static::class
            );
        }
    }

    /**
     * Filtra los datos usando la lista blanca $fillable
     */
    protected function filterFillable(array $data): array
    {
        $this->validateFillable();
        return array_intersect_key($data, array_flip($this->fillable));
    }

    protected function escapeIdentifier(string $identifier): string
    {
        $dbType = Env::get('DB_TYPE', 'mysql');

        switch ($dbType) {
            case 'sqlsrv':
                return '[' . str_replace(']', ']]', $identifier) . ']';
            case 'mysql':
            default:
                $backtick = chr(96);
                return $backtick . str_replace($backtick, $backtick . $backtick, $identifier) . $backtick;
        }
    }

    protected function getQuotedTable(): string
    {
        if (empty($this->table)) {
            throw new \Exception('La propiedad $table no puede estar vacía en el modelo.');
        }

        // Validate table name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table)) {
            throw new \RuntimeException('Nombre de tabla inválido: ' . $this->table);
        }

        return $this->escapeIdentifier($this->table);
    }

    /**
     * Retorna todos los registros de la tabla usando conexión de lectura.
     *
     * @return array
     */
    public function all(): array
    {
        $table = $this->getQuotedTable();
        $stmt = $this->readDb->prepare("SELECT * FROM {$table}");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Busca un registro por su identificador usando conexión de lectura.
     *
     * @param int|string $id Identificador del registro
     * @return array|false
     */
    public function find(int|string $id): array|false
    {
        $table = $this->getQuotedTable();
        $pk = $this->escapeIdentifier($this->primaryKey);
        $stmt = $this->readDb->prepare("SELECT * FROM {$table} WHERE {$pk} = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Crea un registro usando conexión de escritura.
     *
     * @param array $data Datos a persistir
     * @return array|false
     * @throws \InvalidArgumentException Si no hay datos válidos para asignación masiva
     */
    public function create(array $data): array|false
    {
        // Filtrar datos por la lista blanca $fillable
        $data = $this->filterFillable($data);

        if (empty($data)) {
            throw new \InvalidArgumentException('No valid data provided for mass assignment');
        }

        $table = $this->getQuotedTable();

        $escapedCols = array_map(
            fn($col) => $this->escapeIdentifier($col),
            array_keys($data)
        );

        $cols = implode(', ', $escapedCols);
        $vals = implode(', ', array_fill(0, count($data), '?'));

        $stmt = $this->getWriteDb()->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$vals})");
        $stmt->execute(array_values($data));

        return $this->findByConnection($this->getWriteDb(), $this->getWriteDb()->lastInsertId());
    }

    /**
     * Actualiza un registro usando conexión de escritura.
     *
     * @param int|string $id Identificador del registro
     * @param array $data Datos a actualizar
     * @return array|false
     * @throws \InvalidArgumentException Si no hay datos válidos para asignación masiva
     */
    public function update(int|string $id, array $data): array|false
    {
        // Filtrar datos por la lista blanca $fillable
        $data = $this->filterFillable($data);

        if (empty($data)) {
            throw new \InvalidArgumentException('No valid data provided for mass assignment');
        }

        $table = $this->getQuotedTable();

        $sets = implode(', ', array_map(
            fn($k) => $this->escapeIdentifier($k) . ' = ?',
            array_keys($data)
        ));

        $pk = $this->escapeIdentifier($this->primaryKey);
        $stmt = $this->getWriteDb()->prepare("UPDATE {$table} SET {$sets} WHERE {$pk} = ?");
        $stmt->execute([...array_values($data), $id]);

        return $this->findByConnection($this->getWriteDb(), $id);
    }

    /**
     * Elimina un registro usando conexión de escritura.
     *
     * @param int|string $id Identificador del registro
     * @return bool
     */
    public function delete(int|string $id): bool
    {
        $table = $this->getQuotedTable();
        $pk = $this->escapeIdentifier($this->primaryKey);
        $stmt = $this->getWriteDb()->prepare("DELETE FROM {$table} WHERE {$pk} = ?");
        return $stmt->execute([$id]);
    }

    protected function findByConnection(PDO $connection, int|string $id): array|false
    {
        $table = $this->getQuotedTable();
        $pk = $this->escapeIdentifier($this->primaryKey);
        $stmt = $connection->prepare("SELECT * FROM {$table} WHERE {$pk} = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
