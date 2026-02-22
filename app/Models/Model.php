<?php
namespace App\Models;

use Core\Database;
use Core\Env;
use PDO;

class Model
{
    protected $db;
    protected $table = '';
    
    /**
     * Lista blanca de columnas que pueden ser asignadas masivamente
     * DEBE ser definida en cada modelo hijo para seguridad
     */
    protected $fillable = [];

    public function __construct()
    {
        $this->db = Database::getConnection();
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

    public function all(): array
    {
        $table = $this->getQuotedTable();
        $stmt = $this->db->prepare("SELECT * FROM {$table}");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find($id)
    {
        $table = $this->getQuotedTable();
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function create(array $data)
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
        
        $stmt = $this->db->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$vals})");
        $stmt->execute(array_values($data));
        
        return $this->find($this->db->lastInsertId());
    }

    public function update($id, array $data)
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
        
        $stmt = $this->db->prepare("UPDATE {$table} SET {$sets} WHERE id = ?");
        $stmt->execute([...array_values($data), $id]);
        
        return $this->find($id);
    }

    public function delete($id): bool
    {
        $table = $this->getQuotedTable();
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
