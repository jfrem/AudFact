<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

class Model
{
    protected \PDO $readDb;
    protected ?\PDO $writeDb = null;
    protected string $table = '';
    protected string $readConnectionName = 'db2';
    protected string $writeConnectionName = 'default';


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
}
