<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Contrato para el acceso a los datos de dominio requeridos por el pipeline de auditoría.
 *
 * Implementaciones posibles:
 *   - AuditDataService       : acceso directo a modelos PHP + SQL Server (producción)
 *   - Stub en tests unitarios: datos en memoria sin BD
 */
interface AuditDataServiceInterface
{
    /**
     * Retorna la fuente de verdad de una dispensación en el contrato canónico {header, items}.
     *
     * @param  string $disDetNro  Número de factura/dispensación (ej: "T38250701547")
     * @return array{header:array<string,mixed>,items:array<int,array<string,mixed>>}
     * @throws \RuntimeException  Si la dispensación no existe o los datos son inválidos
     */
    public function getDispensation(string $disDetNro): array;

    /**
     * Retorna la configuración de auditoría activa para un cliente/EPS.
     *
     * @param  string $nitSec  Identificador interno del cliente (NitSec)
     * @return array{nitSec:string,activo:bool,systemPrompt:string|null,documents:array<string,mixed>}
     * @throws \RuntimeException  Si el cliente no tiene configuración o está inactiva
     */
    public function getAuditConfig(string $nitSec): array;

    /**
     * Retorna el catálogo de documentos requeridos para un cliente.
     *
     * @param  string $nitSec
     * @return array<int,array{NitMedDocId:int,NitMedDocCodAlt:string,NitMedDocNom:string}>
     */
    public function getClientDocuments(string $nitSec): array;

    /**
     * Retorna los adjuntos disponibles para una dispensación filtrados por cliente.
     *
     * @param  string $disDetNro
     * @param  string $nitSec
     * @return array<int,array<string,mixed>>
     */
    public function getAttachments(string $disDetNro, string $nitSec): array;
}
