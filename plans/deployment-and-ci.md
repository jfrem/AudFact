# Estrategia de Despliegue y CI/CD

## Procedimiento de Rollback y Recuperación

### Contexto de deploy

- **Deploy actual**: manual por copia de archivos al servidor
- **Docker**: solo para desarrollo local (producción pendiente; se evaluará Docker en prod si se implementa Redis como caché)
- **Producción**: no existe aún, pero todo el desarrollo debe quedar **production-ready**

### Procedimiento de rollback (deploy manual)

```
1. ANTES de deployar
   └── Crear copia de respaldo del directorio actual en servidor
       └── cp -r /ruta/proyecto /ruta/proyecto.backup.YYYY-MM-DD

2. Deployar
   └── Copiar archivos nuevos
   └── Ejecutar composer install (si cambiaron dependencias)
   └── Verificar health check: curl http://servidor/health

3. Si algo falla
   └── Restaurar backup inmediatamente:
       └── rm -rf /ruta/proyecto
       └── mv /ruta/proyecto.backup.YYYY-MM-DD /ruta/proyecto
   └── Verificar health check nuevamente
   └── Documentar qué falló y por qué
```

### Rollback con Git (cuando el repo esté inicializado)

```bash
# Ver commits recientes
git log --oneline -10

# Revertir último commit (crea nuevo commit de reversión)
git revert HEAD --no-edit

# Revertir a un commit específico (PELIGRO: descarta commits)
# REQUIERE aprobación del usuario
git reset --hard <commit-hash>
```

### Rollback en Docker (entorno local)

```bash
# Si un cambio de config rompe el contenedor
wsl docker compose down
git checkout -- docker-compose.yml docker/
wsl docker compose up -d --build

# Rebuild completo desde cero
wsl docker compose down -v
wsl docker compose up -d --build --force-recreate
```

### Checklist pre-deploy

- [ ] Código funciona en entorno local Docker
- [ ] Health check (`/health`) responde correctamente
- [ ] Variables de entorno de producción configuradas (`.env`)
- [ ] No hay `exit()` sueltos que rompan el flujo
- [ ] Logs configurados en nivel apropiado (`LOG_LEVEL=warning` o `error` en prod)
- [ ] `APP_ENV=production` (desactiva mensajes de error detallados y CORS abierto)
- [ ] `composer install --no-dev` (sin dependencias de desarrollo)
- [ ] Backup del estado actual en servidor

---

## Configuración de CI/CD (Pipeline Propuesto)

Aunque el despliegue es manual, se define un flujo de **Integración Continua** para asegurar la calidad antes de cada release.

### Pipeline de Verificación (Pre-Push/PR)

| Etapa | Comando / Herramienta | Objetivo |
|---|---|---|
| **Lint** | `php -l` o `composer lint` | Detectar errores de sintaxis |
| **Estilos** | `php-cs-fixer` | Asegurar cumplimiento de PSR-12 |
| **Security Audit** | `composer audit` | Detectar vulnerabilidades en dependencias |
| **Unit Tests** | `phpunit` | Validar lógica core (cuando se implementen) |
| **Integración** | `tests/cli_test_audit.php` | Validar comunicación con Gemini/SQL |

### Flujo de Release
1. **Develop**: Los agentes integran features en la rama `develop`.
2. **QA Auto**: Al mergear a `develop`, el agente debe ejecutar la suite de tests CLI.
3. **Staging**: Un entorno Docker idéntico a producción para validación final.
4. **Main**: Merge a `main` → Usuario aprueba el tag de versión (ej: `v1.0.1`).
5. **Deploy**: Copia manual de archivos de `main` al servidor de producción.
