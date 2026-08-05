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
        $result = $this->read(function (PDO $connection) use ($sql, $clientId): ?array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':clientId', $clientId, PDO::PARAM_INT);
            $stmt->execute();

            try {
                return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } finally {
                $stmt->closeCursor();
            }
        });
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
            inner join ParametricaEps p with(nolock) on p.ParNitSec=c.NitSec and p.ParCliSec=c.CliSec
            WHERE c.ParEpsSec > 0
            and c.PerCliCod = '2'
            and c.CliEst = 'A'
            GROUP BY
                n.NitSec,
                n.NitCom
            order by n.NitCom Asc";
        $results = $this->read(function (PDO $connection) use ($sql): array {
            $stmt = $connection->prepare($sql);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });
        Logger::info("Executed SQL to fetch all clients", [
            'resultCount' => count($results)
        ]);
        return $results;
    }

    public function getDocumentsByClient(int $clientId): array
    {
        $sql = "SELECT
                NitSec,
                NitMedDocId,
                NitMedDocCodAlt,
                NitMedDocNom
            FROM NitDocumentos WITH (NOLOCK)
            WHERE NitSec = :clientId
            AND NitMedDocOpc = 'N'
            ORDER BY NitMedDocId ASC";
        $results = $this->read(function (PDO $connection) use ($sql, $clientId): array {
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(':clientId', $clientId, PDO::PARAM_INT);
            $stmt->execute();

            try {
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } finally {
                $stmt->closeCursor();
            }
        });
        Logger::info("Executed SQL to fetch documents by client", [
            'clientId' => $clientId,
            'resultCount' => count($results)
        ]);
        return $results;
    }
}
