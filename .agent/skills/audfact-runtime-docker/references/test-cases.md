# Test Cases - audfact-runtime-docker

## Plantilla GWT
```text
Given: compose y entorno configurados
When: se levanta o inspecciona runtime
Then: contenedores saludables y endpoints accesibles
```

## Casos
1. Levantamiento base
   - Given: `.env` correcto.
   - When: `docker compose up --build -d`.
   - Then: `audfact-nginx` y `audfact-php` en estado `Up`.
2. Endpoint health
   - Given: runtime arriba.
   - When: `GET /health`.
   - Then: respuesta JSON con `status`.
3. Falla de PHP-FPM
   - Given: error de extensiones o config.
   - When: request al API.
   - Then: diagnostico con `docker logs audfact-php`.
