<?php

declare(strict_types=1);

namespace App\Models;

use Core\Logger;
use PDO;

class ClientsModel extends Model
{
    public function getClientById(int $clientId): ?array
    {
        $sql = "SELECT
                n.NitSec,
                n.NitCom
            FROM NIT n
            INNER JOIN Clientes c WITH (NOLOCK) ON c.NitSec = n.NitSec
            WHERE c.ParEpsSec > 0
            and c.PerCliCod = '2'
            and n.NitSec = :clientId
            GROUP BY
                n.NitSec,
                n.NitCom
            order by n.NitCom Asc";
        $stmt = $this->readDb->prepare($sql);
        $stmt->bindParam(':clientId', $clientId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        Logger::info("Executed SQL: ", [
            'clientId' => $clientId,
            'result' => count($result ?? [])
        ]);
        return $result;
    }

    public function getAllClients(): array
    {
        $sql = "SELECT
                n.NitSec,
                n.NitCom
            FROM NIT n
            INNER JOIN Clientes c WITH (NOLOCK) ON c.NitSec = n.NitSec
            WHERE c.ParEpsSec > 0
            and c.PerCliCod = '2'
            and c.CliEst = 'A'
            GROUP BY
                n.NitSec,
                n.NitCom
            order by n.NitCom Asc";
        $stmt = $this->readDb->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Logger::info("Executed SQL to fetch all clients", [
            'resultCount' => count($results)
        ]);
        return $results;
    }
}
