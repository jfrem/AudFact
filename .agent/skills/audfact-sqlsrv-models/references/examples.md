# Ejemplos Extendidos - audfact-sqlsrv-models

## Happy path: búsqueda interactiva paginada
```php
public function searchInvoices(array $filters, int $page = 1, int $pageSize = 20): array
{
    $page = max($page, 1);
    $pageSize = min(max($pageSize, 1), 100);
    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT NitSec, FacSec, Dispensa
            FROM (
                SELECT tb3.FacNitSec NitSec, tb3.FacSec FacSec, tb2.DisDetNro Dispensa
                FROM Factura tb3 WITH(NOLOCK)
                INNER JOIN DispensacionDetalleServicio tb2 WITH(NOLOCK)
                    ON tb3.DisId = tb2.DisId AND tb3.DisDetId = tb2.DisDetId
                WHERE tb3.FacNitSec = :facNitSec
            ) candidates
            ORDER BY FacSec ASC, Dispensa ASC
            OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

    $stmt = $this->db->prepare($sql);
    $stmt->bindValue(':facNitSec', (int) $filters['facNitSec'], \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->bindValue(':pageSize', $pageSize, \PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}
```

Para batches internos, `InvoicesModel::getInvoicesForAuditBatch()` usa keyset pagination con `TOP({$safeLimit})`, tope `1..1000` y subquery derivada; evitar CTEs por compatibilidad con `pdo_sqlsrv`.

## Failure path: concatenacion insegura
No hacer:
```php
$sql = "SELECT * FROM factura WHERE FacNitSec = $facNitSec";
```

Si aparece este patron, reemplazar con prepared statement.
