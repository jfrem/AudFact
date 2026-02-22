<?php

namespace App\Models;

use PDO;
use Core\Logger;

class InvoicesModel extends Model
{
    public function getInvoices(int $facNitSec, $date, int $limit = 100)
    {
        $limit = min(max($limit, 1), 1000);
        $sql = "SELECT TOP (:limit)
                f.FacNitSec,
                f.FacSec,
                f.FacNro,
                f.DisId
            FROM dbo.factura f
            LEFT JOIN Discolnet.dbo.AudDispEst a WITH (NOLOCK) ON a.FacSec = f.DisId
            WHERE
                f.FacFec = :date
                AND f.FacNitSec = :facNitSec
                AND (a.EstAud IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':facNitSec', $facNitSec, PDO::PARAM_INT);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Logger::info("Executed SQL: ", [
            'facNitSec' => $facNitSec,
            'date' => $date,
            'limit' => $limit,
            'result' => count($result ?? [])
        ]);
        return $result ?? [];
    }
}
