<?php

declare(strict_types=1);

namespace App\Services\Audit\Pipeline;

/**
 * Value Object inmutable que representa un campo extraído por Gemini.
 *
 * Reemplaza el array asociativo v1 genérico:
 *   ['valor' => ..., 'valores' => [...], 'presente' => bool, ...]
 *
 * Implementa JsonSerializable para producir el mismo JSON que el array v1,
 * garantizando backward compatibility con Redis cache y payloads de eventos.
 */
final class ExtractedEvidence implements \JsonSerializable
{
    /**
     * @param string|int|float|bool|null  $valor           Valor principal extraído
     * @param array<int,string|int|float> $valores         Tokens individuales (multi-valor)
     * @param bool                        $presente        Si el dato fue encontrado
     * @param ?string                     $confianza       Nivel de certeza (alta/media/baja)
     * @param ExtractionState             $estadoExtraccion Estado de la extracción
     * @param ?string                     $evidencia       Texto literal visible en el documento
     * @param ?string                     $ubicacion       Posición aproximada en el documento
     */
    public function __construct(
        public readonly string|int|float|bool|null $valor,
        public readonly array $valores,
        public readonly bool $presente,
        public readonly ?string $confianza,
        public readonly ExtractionState $estadoExtraccion,
        public readonly ?string $evidencia,
        public readonly ?string $ubicacion,
    ) {}

    /**
     * Hidrata desde un array v1 (output de Normalizer, cache Redis, payload de evento).
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
            confianza: self::nullableString($data['confianza'] ?? null),
            estadoExtraccion: ExtractionState::fromInput($data['estadoExtraccion'] ?? null),
            evidencia: self::nullableString($data['evidencia'] ?? null),
            ubicacion: self::nullableString($data['ubicacion'] ?? null),
        );
    }

    /**
     * Extrae la metadata de evidencia para inyección en hallazgos canónicos.
     *
     * DRY: reemplaza los bloques duplicados en DocumentPolicyEngine::resolveDocumentValue().
     *
     * @return array<string,mixed>
     */
    public function extractMeta(): array
    {
        return [
            'confianza'        => $this->confianza,
            'estadoExtraccion' => $this->estadoExtraccion->value,
            'evidencia'        => $this->evidencia,
            'ubicacion'        => $this->ubicacion,
            'valores'          => $this->valores !== [] ? $this->valores : null,
        ];
    }

    /**
     * Serializa al mismo shape v1 que el array original.
     *
     * Garantiza backward compatibility con Redis cache y payloads JSON.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'valor'             => $this->valor,
            'valores'           => $this->valores,
            'presente'          => $this->presente,
            'confianza'         => $this->confianza,
            'estadoExtraccion'  => $this->estadoExtraccion->value,
            'evidencia'         => $this->evidencia,
            'ubicacion'         => $this->ubicacion,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
