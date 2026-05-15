<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Value Object inmutable que representa un campo extraído por Gemini.
 *
 * Shape canónica (contrato v1): {valor, valores, presente, estadoExtraccion}
 *
 * Implementa JsonSerializable para producir JSON compatible con
 * Redis cache y payloads de eventos.
 */
final class ExtractedEvidence implements \JsonSerializable
{
    /**
     * @param string|int|float|bool|null  $valor           Valor principal extraído
     * @param array<int,string|int|float> $valores         Tokens individuales (multi-valor)
     * @param bool                        $presente        Si el dato fue encontrado
     * @param ExtractionState             $estadoExtraccion Estado de la extracción
     */
    public function __construct(
        public readonly string|int|float|bool|null $valor,
        public readonly array $valores,
        public readonly bool $presente,
        public readonly ExtractionState $estadoExtraccion,
    ) {}

    /**
     * Hidrata desde un array (output de Normalizer, cache Redis, payload de evento).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $valor = $data['valor'] ?? null;
        $rawValores = is_array($data['valores'] ?? null) ? $data['valores'] : [];

        return new self(
            valor: $valor,
            valores: $rawValores,
            presente: (bool) ($data['presente'] ?? ($valor !== null)),
            estadoExtraccion: ExtractionState::fromInput($data['estadoExtraccion'] ?? null),
        );
    }

    /**
     * Extrae la metadata de evidencia para inyección en hallazgos canónicos.
     *
     * Solo incluye campos que participan en decisiones de auditoría.
     *
     * @return array<string,mixed>
     */
    public function extractMeta(): array
    {
        return [
            'estadoExtraccion' => $this->estadoExtraccion->value,
            'valores'          => $this->valores !== [] ? $this->valores : null,
        ];
    }

    /**
     * Serializa al shape compacto para Redis cache y payloads JSON.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'valor'             => $this->valor,
            'valores'           => $this->valores,
            'presente'          => $this->presente,
            'estadoExtraccion'  => $this->estadoExtraccion->value,
        ];
    }
}
