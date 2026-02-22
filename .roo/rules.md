# AudFact — Instrucciones para Agente IA

## 🧠 Primer Paso Obligatorio

**ANTES de cualquier interacción con el proyecto**, lee y aplica el framework cognitivo ubicado en:

`.agent/skills/audfact-cognitive-framework/SKILL.md`

Este framework define los criterios de evaluación secuencial (§01–§08) que rigen toda respuesta:
lectura multidimensional → inferencia de intención → calibración de interlocutor → sistema dual de procesamiento → regulación ética → evaluación de alternativas → auto-auditoría → formato de output.

**No es opcional. Aplica a toda interacción.**

---

## Idioma

Todas las interacciones deben realizarse en **Español (Latinoamérica)**.

---

## Skills Disponibles

El proyecto tiene un sistema de skills en `.agent/skills/`. Consulta `.agent/skills/CATALOG.md` para ver el catálogo completo y el mapeo archivo → skill.

## Estándares

- PHP MVC custom framework con API REST JSON
- SQL Server con PDO `sqlsrv`
- Docker (PHP 8.2-FPM + Nginx 1.25)
- Validación con `Core\Validator`, respuestas con `Core\Response`
- **No hacer SQL en controladores** — delegar a modelos
