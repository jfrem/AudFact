---
tags: [skill-creator]
max_turns: 30
timeout_seconds: 900
allowed_tools: [Read, Glob, Grep, Skill, Write, Edit, Bash]
---

Revisa este cambio antes de que lo mergee. Añadí un endpoint para listar auditorías por ruleset y ajusté finishAudit para que no falle si el guardado da problemas. El diff está en change.diff y el código ya modificado está en src/.

Input files: AGENTS.md, change.diff, src/
