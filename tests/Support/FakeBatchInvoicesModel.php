<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\InvoicesModel;

/**
 * Doble de `InvoicesModel` para el orquestador batch: registra cada llamada (incluido `queryTimeoutSeconds`).
 */
final class FakeBatchInvoicesModel extends InvoicesModel
{
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];
    /** @var (callable():void)|null Se invoca dentro de la consulta (p. ej. para simular timeout o carrera). */
    public $onQuery = null;

    /**
     * @param array<int,array<string,mixed>> $invoices
     */
    public function __construct(private array $invoices = [])
    {
        // Sin conexión SQL.
    }

    public function getCalls(): int
    {
        return count($this->calls);
    }

    public function getInvoicesForAuditBatch(
        int $facNitSec,
        string $dateFrom,
        string $dateTo,
        int $limit = 100,
        ?array $cursor = null,
        ?string $jobId = null,
        ?int $queryTimeoutSeconds = null
    ): array {
        $this->calls[] = [
            'facNitSec' => $facNitSec,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'cursor' => $cursor,
            'jobId' => $jobId,
            'queryTimeoutSeconds' => $queryTimeoutSeconds,
        ];
        if ($this->onQuery !== null) {
            ($this->onQuery)();
        }

        $startIndex = 0;
        if ($cursor !== null) {
            foreach ($this->invoices as $idx => $invoice) {
                if (($invoice['DisId'] ?? '') === ($cursor['disId'] ?? null) && ($invoice['Dispensa'] ?? '') === ($cursor['dispensa'] ?? null)) {
                    $startIndex = $idx + 1;
                    break;
                }
            }
        }

        return array_slice($this->invoices, $startIndex, $limit);
    }

    /**
     * Genera `$count` candidatas con `Dispensa` única `D{n}` y `DisId` propio.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows(int $count, int $nitSec = 2624): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'NitSec' => $nitSec,
                'DisId' => (string) (1000 + $i),
                'Dispensa' => 'D' . $i,
                'DisFecSol' => sprintf('2026-06-12T00:%02d:00', $i % 60),
            ];
        }

        return $rows;
    }
}
