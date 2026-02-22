# Ejemplos Extendidos - audfact-sqlsrv-models

## Happy path: query paginada con limite de negocio
```php
public function getInvoices(int $facNitSec, $date, int $limit = 100)
{
    $limit = min(max($limit, 1), 1000);
    $sql = "SELECT TOP (:limit) FacSec, DisId FROM dbo.factura
            WHERE FacNitSec = :facNitSec AND FacFec = :date";
    $stmt = $this->db->prepare($sql);
    $stmt->bindParam(':facNitSec', $facNitSec, \PDO::PARAM_INT);
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

## Failure path: concatenacion insegura
No hacer:
```php
$sql = "SELECT * FROM factura WHERE FacNitSec = $facNitSec";
```

Si aparece este patron, reemplazar con prepared statement.
