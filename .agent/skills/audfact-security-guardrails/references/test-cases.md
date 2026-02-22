# Test Cases - audfact-security-guardrails

## Plantilla GWT
```text
Given: request o evento de error
When: pasa por validacion/seguridad
Then: bloqueo o respuesta segura segun regla
```

## Casos
1. Content-Type incorrecto
   - Given: body con `text/plain`.
   - When: endpoint que exige JSON.
   - Then: `415`.
2. Payload excedido
   - Given: body mayor a `MAX_JSON_SIZE`.
   - When: request.
   - Then: `413`.
3. Error interno en produccion
   - Given: `APP_ENV=production` y excepcion.
   - When: handler global responde.
   - Then: mensaje generico, sin detalle sensible.
