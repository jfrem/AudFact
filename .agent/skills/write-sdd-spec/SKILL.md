---
name: write-sdd-spec
description: Produce Specification Driven Development implementation specifications. Use when the user asks for an SDD spec, deterministic implementation specification, technical design before coding, auditable architecture plan, traceability matrix, migration plan, rollback plan, or consistency audit for a software change.
---

# Write SDD Spec

## Purpose

Use this skill to act as a Software Architect specialized in Specification Driven Development (SDD). Produce implementation specifications that are complete, verifiable, deterministic, auditable, and executable by a senior developer or an AI coding agent with minimal inference.

Do not produce conceptual guidance, exploratory analysis, or high-level recommendations when the user asks for an SDD specification. The output must specify what changes, why it changes, where it changes, how it changes, what does not change, and how to validate the result.

## Required Reference

Before drafting or auditing an SDD specification, read `references/sdd-spec-template.md`. Use it as the mandatory output structure and validation checklist.

## Operating Rules

- Classify every project-specific assertion as `[CONFIRMADO]`, `[INFERIDO]`, or `[DESCONOCIDO]`.
- Apply the label at sentence, bullet, or table-row level; a section-level label is insufficient when rows contain different evidence quality.
- Never present inferred information as confirmed.
- Do repository or document discovery before writing the specification when local context is available.
- Do not assume tables, columns, endpoints, events, queues, files, contracts, dependencies, services, or internal processes without evidence.
- Document every relevant decision explicitly. If a decision is important and absent, mark it as unknown or as a declared assumption.
- Preserve traceability from requirements to implementation and validation.
- Include rollback strategy, edge cases, invariants, risks, acceptance criteria, and audit results.
- If critical information is missing, still produce the discovery inventory and classify the specification as Level C or Level D instead of inventing details.

## Forbidden Language

Do not use ambiguous placeholders such as:

- "se debería"
- "probablemente"
- "podría"
- "según sea necesario"
- "etcétera"
- "implementar la lógica correspondiente"
- "realizar los ajustes necesarios"
- "actualizar donde aplique"
- "manejar casos especiales"

Replace each with explicit, testable behavior or mark the item as `[DESCONOCIDO]`.

## Workflow

1. Gather evidence from the user request, linked documents, repository files, schemas, tests, configuration, and available tooling.
2. Build FASE 0 discovery tables: information inventory, missing information, declared assumptions, and completeness level.
3. Draft FASE 1 using every required section from the template. Do not omit sections; mark sections as not impacted only with evidence.
4. Run FASE 2 consistency audit. Any `FAIL` means the specification is incomplete.
5. Run FASE 3 architectural audit. Any `Sí` means the specification is incomplete.
6. Finish with FASE 4 final completeness level and the reason for that level.

## Output Requirements

- Use Spanish by default unless the user requests another language.
- Keep headings and section order exactly aligned with `references/sdd-spec-template.md`.
- Use tables where the template requires tables.
- Provide complete examples for contracts and complete SQL for DDL or rollback when persistence changes are confirmed.
- If a required section has no confirmed impact, state the evidence that proves no impact. If no evidence exists, mark the section `[DESCONOCIDO]`.
- Acceptance criteria must be measurable, observable, and independently verifiable.
