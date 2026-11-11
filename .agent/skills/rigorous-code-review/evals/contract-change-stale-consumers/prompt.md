---
tags: [skill-creator]
max_turns: 30
timeout_seconds: 900
allowed_tools: [Read, Glob, Grep, Skill, Write, Edit, Bash]
---

Cambié la firma de extract en AIProviderPort para que reciba el esquema del ruleset en cada llamada, en vez de fijarlo en el constructor del adapter de Gemini. Revisa el diff (change.diff) y dime si lo puedo integrar.

Input files: AGENTS.md, change.diff, src/
