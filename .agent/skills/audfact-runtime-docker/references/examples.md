# Ejemplos Extendidos - audfact-runtime-docker

## Happy path: ciclo completo local
1. Levantar:
```bash
wsl docker compose up --build -d
```
2. Validar API:
```bash
curl http://localhost:8080/health
```
3. Validar wrap:
```bash
curl http://localhost:8080/app/wrap/capabilities.php
```

## Failure path: php-fpm no responde
Sintoma: `502 Bad Gateway` en Nginx.

Pasos:
1. Revisar logs de Nginx:
```bash
wsl docker logs audfact-nginx --tail 100
```
2. Revisar logs de PHP:
```bash
wsl docker logs audfact-php --tail 100
```
3. Verificar extensiones SQL Server:
```bash
wsl docker exec audfact-php php -m | findstr /I "sqlsrv pdo_sqlsrv"
```
