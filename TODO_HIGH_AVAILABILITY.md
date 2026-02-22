# TODO: Estrategia de Alta Disponibilidad — Auditoría en Punto de Atención

> **Caso de uso**: Dispensación de medicamentos con auditoría individual por factura.  
> **Carga esperada**: +100 usuarios/min (~1.7 req/s, ~42 auditorías concurrentes).  
> **Tiempo por auditoría**: ~25 segundos (Gemini Flash).

---

## Estado Actual

```
┌── Nginx (:80) ──┐       ┌── PHP-FPM (1 contenedor) ──┐
│  fastcgi_pass    │ ────► │  pm.max_children = default  │
│  php:9000        │       │  1 pool, sin réplicas       │
└──────────────────┘       └─────────────────────────────┘
```

- **1 sola réplica PHP-FPM** → saturación con +10 requests concurrentes
- Nginx apunta directamente a `php:9000` (sin upstream pool)
- Rutas no preparadas para balanceo (se requiere configuración de sesiones/red separada si es necesario)
- Logs compartidos (riesgo de colisión si múltiples procesos escriben el mismo archivo)

---

## Arquitectura Objetivo

```text
                    ┌── Nginx Load Balancer ──┐
                    │  upstream audit_pool {   │
                    │    least_conn; # <----   │
                    │    server php-1:9000;    │
                    │    server php-2:9000;    │
                    │    server php-3:9000;    │
                    │    server php-4:9000;    │
                    │    server php-5:9000;    │
                    │  }                       │
                    └────────┬─────────────────┘
                             │
        ┌────────┬───────┬───┴───┬────────┐
        ▼        ▼       ▼       ▼        ▼
    PHP-FPM 1  PHP-FPM 2  PHP-FPM 3  PHP-FPM 4  PHP-FPM 5
    (10 children)  (10 children)  (10 children)  (10 children)  (10 children)
    = 50 workers concurrentes estáticos
```

---

## Checklist de Implementación

### 1. Crear Endpoint de Auditoría Individual

- [ ] Crear ruta `POST /api/audit/single` en `app/Routes/web.php`
- [ ] Crear método `single()` en `AuditController.php`
- [ ] Request body: `{ "FacNro": "Q28251100162" }`
- [ ] Response: JSON con resultado de auditoría + `_meta` (timing)

```php
// AuditController.php
public function single(): void
{
    $data = $this->validate([
        'FacNro' => 'required|string|min_length:1',
    ]);

    $FacNro = (string)$data['FacNro'];
    $auditor = new GeminiAuditService();
    $result = $auditor->auditInvoice($FacNro, $FacNro, null);

    // TODO: Recuperar Rate Limit Remaining y añadirlo al final de todo si la IA nos da métricas

    Response::success($result, 'Auditoría individual completada');
}
```

### 2. Configurar PHP-FPM para Alta Concurrencia

- [ ] Crear `docker/php-fpm-pool.conf` con tuning para auditorías largas

```ini
; docker/php-fpm-pool.conf
[www]
pm = static               ; <-- Force allocation at boot for predictable RAM
pm.max_children = 10      ; <-- Maximum workers to hold memory
request_terminate_timeout = 120s
```

- [ ] Copiar en Dockerfile:

```dockerfile
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
```

### 3. Escalar PHP-FPM con Docker Compose

- [ ] Modificar `docker-compose.yml` para eliminar `container_name` (no permite réplicas)
- [ ] Agregar `deploy.replicas`
- [ ] Exponer puerto dinámico

```yaml
# docker-compose.yml
services:
  php:
    build:
      context: .
      dockerfile: docker/Dockerfile
    # NOTA: Eliminar "container_name" — impide crear réplicas
    env_file:
      - .env
    volumes:
      - ./:/var/www/html
      - ./logs:/var/www/html/logs
      - ./tmp:/var/www/html/tmp
    expose:
      - "9000"
    deploy:
      replicas: 5
      resources:
        limits:
          memory: 512M
          cpus: '0.5'

  nginx:
    image: nginx:1.25-alpine
    depends_on:
      - php
    ports:
      - "8080:80"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx-ha.conf:/etc/nginx/conf.d/default.conf:ro
```

### 4. Configurar Nginx como Load Balancer

- [ ] Crear `docker/nginx-ha.conf` con upstream pool

```nginx
# docker/nginx-ha.conf
upstream php_pool {
    # Least Connections strategy es crítica ante latencia asimétrica de IA
    least_conn;
    
    # Docker Compose DNS resuelve a todas las réplicas
    server php:9000;

    # Mantener conexiones persistentes con FPM
    keepalive 32;
}

server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    # Timeout para auditorías largas (25s + margen)
    fastcgi_read_timeout 120s;
    fastcgi_send_timeout 30s;
    proxy_connect_timeout 10s;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass php_pool;

        # Keepalive con FPM
        fastcgi_keep_conn on;
    }
}
```

### 5. Rate Limiting de Gemini API

- [ ] **Verificar cuota actual** del API key de Gemini
  - Free tier: ~15 RPM / 1M TPM
  - Pay-as-you-go: ~1000 RPM
- [ ] Si es free tier: implementar cola de espera o solicitar aumento de cuota
- [ ] Opción: agregar rate limiter en PHP (Redis/APCu) antes de llamar a Gemini

```
⚠️  CRÍTICO: Con +100 req/min se excede el free tier (15 RPM).
    Se REQUIERE plan de pago o Blaze para este volumen.
    Costo estimado Gemini Flash: ~$0.075/1M input tokens + $0.30/1M output tokens
```

### 6. Health Checks y Monitoreo

- [ ] Agregar healthcheck en `docker-compose.yml`

```yaml
  php:
    healthcheck:
      test: ["CMD-SHELL", "php-fpm-healthcheck || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
```

- [ ] Endpoint de health existente: `GET /health` (vía `HealthController`)
- [ ] Monitorear logs centralizados en `./logs/`
- [ ] Considerar agregar métricas Prometheus/Grafana en fase 2

### 7. Manejo de Concurrencia en Base de Datos

- [ ] Verificar que las conexiones SQL Server soporten N conexiones simultáneas
  - Cada réplica × cada child FPM = **50 conexiones potenciales**
  - Verificar `max pool size` en connection string
- [ ] Considerar connection pooling si hay saturación

---

## Cálculo de Capacidad

| Parámetro | Valor |
|-----------|-------|
| Réplicas PHP-FPM | 5 |
| `pm.max_children` por réplica | 10 |
| **Workers totales** | **50** |
| Tiempo por auditoría | ~25s |
| **Throughput máximo** | **~120 req/min** |
| Carga esperada | +100 req/min |
| **Margen** | **~20%** |

> Para aumentar capacidad: escalar réplicas a 7 → ~168 req/min (~68% margen)

---

## Orden de Ejecución Recomendado

| Paso | Prioridad | Dependencia | Estimado |
|------|-----------|-------------|----------|
| 1. Endpoint individual | 🔴 Alta | Ninguna | 30 min |
| 2. PHP-FPM tuning | 🔴 Alta | Ninguna | 15 min |
| 3. Docker Compose réplicas | 🔴 Alta | Paso 2 | 20 min |
| 4. Nginx Load Balancer | 🔴 Alta | Paso 3 | 20 min |
| 5. Rate Limiting Gemini | 🟡 Media | Verificar cuota | 1-2 hrs |
| 6. Health Checks | 🟡 Media | Paso 3 | 15 min |
| 7. DB Connection Pool | 🟢 Baja | Monitorear primero | Variable |

---

## Comandos de Despliegue

```bash
# Construir imagen actualizada
docker compose build php

# Levantar con réplicas
docker compose up -d --scale php=5

# Verificar réplicas activas
docker compose ps

# Ver distribución de carga (logs Nginx)
docker compose logs -f nginx

# Escalar en caliente (sin downtime)
docker compose up -d --scale php=7

# Reducir réplicas
docker compose up -d --scale php=3
```

---

## Notas Importantes

1. **`container_name: audfact-php`** debe eliminarse — Docker no permite réplicas con nombre fijo
2. **Volúmenes compartidos**: `./logs/` y `./tmp/` serán compartidos por todas las réplicas.
3. **`.env`** se comparte por todas las réplicas vía `env_file` — una sola configuración
4. **Logs Concurrentes CRÍTICO**: Al tener N réplicas escribiendo en `./logs/app.log`, colisionarán y las escrituras se sobrepondrán y truncarán. El componente `Core\Logger` será modificado de manera **obligatoria** para incluir un sufijo con el `hostname` del emisor. (E.g. `app-0ff5db123.log`).
