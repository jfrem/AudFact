# Testing Strategy — AudFact

## Estado actual

- **No hay PHPUnit configurado** — solo scripts CLI en `tests/`
- Tests de integración existentes: `cli_test_audit.php`, `cli_test_single.php`
- Estos tests requieren conexión real a SQL Server y API key de Gemini

## Al agregar tests

- Framework objetivo: **PHPUnit 10+** con **Mockery**
- Tests unitarios: `tests/Unit/<namespace>/<Clase>Test.php`
- Tests de integración: `tests/Integration/<Clase>Test.php`
- Ejecutar tests antes de push cuando se toque lógica core
- **No mockear** la base de datos en tests de integración — usar datos reales o fixtures
- Cada test file debe ser autocontenido (setup/teardown propios)
