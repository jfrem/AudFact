<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * Generador desacoplado de prompts y schemas Structured Output
 * para arbitraje semántico de equivalencia con Google Gemini.
 *
 * Separa la definición de contratos y plantillas de la orquestación
 * y ejecución en SemanticMatchJudge (principio de responsabilidad única).
 */
final class SemanticMatchPromptBuilder
{
    /**
     * Construye el contrato adecuado según el propósito de la llamada.
     *
     * @param array<string,mixed> $context
     * @return array{0: string, 1: string, 2: array<string,mixed>} [prompt, systemInstruction, schema]
     */
    public function buildContract(string $callPurpose, string $expected, string $actual, array $context = []): array
    {
        return match ($callPurpose) {
            SemanticMatchJudge::PURPOSE_PERSON_MATCH  => $this->buildPersonNameContract($expected, $actual),
            default                                  => $this->buildProductContract($expected, $actual, $context),
        };
    }

    /**
     * Contrato Gemini Structured Output para arbitraje de productos con soporte de contexto documental.
     *
     * @param array<string,mixed> $context
     * @return array{0: string, 1: string, 2: array<string,mixed>}
     */
    public function buildProductContract(string $expected, string $actual, array $context = []): array
    {
        $docContext = trim((string) ($context['document_context'] ?? ''));
        $contextSection = $docContext !== ''
            ? "\nInformación Contextual del Documento Soporte (posología, forma farmacéutica, concentración o notas adyacentes del documento):\n\"{$docContext}\"\n"
            : '';

        $prompt = "Valor Esperado (Registro de Dispensación / Fuente de Verdad): \"{$expected}\"\n"
            . "Valor del Documento (extraído del soporte físico): \"{$actual}\"\n"
            . $contextSection;

        $systemInstruction = implode("\n", [
            'Eres un evaluador experto de equivalencia semántica para auditoría documental.',
            'Tu tarea es determinar si el Valor del Documento y el Valor Esperado corresponden al MISMO producto o artículo.',
            '',
            'REGLAS DE EVALUACIÓN:',
            '- EQUIVALENCIA DE DENOMINACIÓN (GENÉRICO VS COMERCIAL): Si un valor usa denominación genérica o estándar (principio activo o sustancia) y el otro usa nombre comercial, marca o referencia de fabricante, son EQUIVALENTES (is_match=true) siempre que correspondan a la misma entidad/sustancia y no haya contradicción de especificaciones.',
            '- OMISIÓN DE MARCA COMERCIAL: La ausencia de marca, modelo o fabricante en el documento no es una diferencia sustancial si la categoría o sustancia coinciden.',
            '- CONTEXTO DOCUMENTAL Y ESPECIFICACIONES: Si el nombre extraído del documento omite concentración, dosis o forma farmacéutica en el texto del nombre, pero la Información Contextual del Documento Soporte (posología, concentración, forma farmacéutica o notas adyacentes) confirma o es compatible con la especificación esperada, evalúa is_match=true y same_dimensions_or_dose=true.',
            '- CONTRADICCIÓN EXPLÍCITA: Evalúa is_match=false ÚNICAMENTE cuando exista una contradicción explícita en la sustancia o entidad (ej: compuestos o categorías totalmente distintas) o en los valores cuantitativos de concentración o dosis (ej: 50mcg frente a 200mcg, o 500mg frente a 1000mg).',
            '- CRITERIO CONSERVADOR: Para same_clinical_use, same_dimensions_or_dose, same_material_or_technology y presentation_compatible, responde true si la información no se contradice y es compatible con el contexto disponible.',
        ]);

        $schema = [
            'type' => 'object',
            'properties' => [
                'is_match' => [
                    'type' => 'boolean',
                    'description' => 'True si ambas descripciones corresponden al mismo producto sin contradicción en especificaciones o dosis.',
                ],
                'same_clinical_use' => [
                    'type' => 'boolean',
                    'description' => 'True si ambos productos tienen el mismo uso clínico o categoría funcional.',
                ],
                'same_dimensions_or_dose' => [
                    'type' => 'boolean',
                    'description' => 'True si las dimensiones/dosis coinciden, están respaldadas por el contexto documental o ninguno las contradice. False ante contradicción cuantitativa explícita.',
                ],
                'same_material_or_technology' => [
                    'type' => 'boolean',
                    'description' => 'True si el principio activo, material o tecnología no se contradicen.',
                ],
                'presentation_compatible' => [
                    'type' => 'boolean',
                    'description' => 'True si la forma farmacéutica o presentación es compatible.',
                ],
                'unresolved_differences' => [
                    'type' => 'boolean',
                    'description' => 'True si existe una contradicción explícita sin resolver. False si no hay contradicciones y la información es consistente.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Justificación técnica breve de la decisión.',
                ],
            ],
            'required' => [
                'is_match',
                'same_clinical_use',
                'same_dimensions_or_dose',
                'same_material_or_technology',
                'presentation_compatible',
                'unresolved_differences',
                'reasoning',
            ],
        ];

        return [$prompt, $systemInstruction, $schema];
    }

    /**
     * Contrato Gemini Structured Output para verificación de identidad de personas.
     *
     * @return array{0: string, 1: string, 2: array<string,mixed>}
     */
    public function buildPersonNameContract(string $expected, string $actual): array
    {
        $prompt = "Nombre Esperado (Registro de Referencia): \"{$expected}\"\nNombre del Documento: \"{$actual}\"";

        $systemInstruction = 'Eres un evaluador experto en verificación de identidad. '
            . 'Determina si el Nombre Esperado y el Nombre del Documento se refieren a la misma persona. '
            . 'Considera variaciones comunes: orden de nombres y apellidos, iniciales, apellidos parciales, '
            . 'títulos profesionales y abreviaciones comunes. '
            . 'Responde is_match=true si hay suficiente evidencia de que se trata de la misma persona y no hay contradicción explícita. '
            . 'Ante duda o evidencia insuficiente, is_match=false.';

        $schema = [
            'type' => 'object',
            'properties' => [
                'is_match' => [
                    'type' => 'boolean',
                    'description' => 'True si ambos nombres se refieren a la misma persona.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Justificación breve de la decisión.',
                ],
            ],
            'required' => ['is_match', 'reasoning'],
        ];

        return [$prompt, $systemInstruction, $schema];
    }
}
