# Checkpoint: Prompt Compacto v2 con Funciones Dinamicas

Fecha: 2026-06-02

## Objetivo

Reducir tokens de entrada del perfil Gemini extraction sin cambiar reglas de negocio ni reintroducir valores FDV en el prompt.

## Estado base

- Prompt compacto v1 ya evita `target_context`, `target_context_hash` y bloques de valores esperados FDV.
- Caso manual `U78260400375` fue validado por auditor humano con resultado correcto.
- Metricas base del caso: 14.234 tokens totales, 11.980 prompt tokens.

## Cambio planificado

- Compactar el JSON Schema de function declarations manteniendo el shape v1 `{valor, valores, presente, estadoExtraccion}`.
- Declarar y exigir solo funciones Gemini con trabajo real:
  - `extract_fields` cuando existan campos de cabecera.
  - `extract_items` cuando existan campos de item.
  - `detect_visual_checks` cuando existan visual checks activos.
  - `assess_document_quality` siempre.
- Mantener defaults canonicos en el worker para funciones omitidas: `fields={}`, `items=[]`, `visual_checks=[]`, `quality_notes=[]`.

## Fuera de alcance

- Cambiar `GEMINI_MEDIA_RESOLUTION`.
- Eliminar `assess_document_quality`.
- Modificar audit-config, reglas PHP o severidades.
- Inyectar FDV al prompt Gemini.
