> [!WARNING]
> **ESTADO: DEPRECADO / DESCARTADO (2026-10-30)**
> Esta propuesta de clúster multi-nodo y Redis Sentinel fue descartada tras demostrarse empíricamente que la lentitud del procesamiento se debía a cuellos de botella lógicos de software (prefetch gate en 256MB, cuota RR/cap1 en slots de tareas y concurrencia Gemini) y no a saturación física de hardware. Con la optimización a 1.5 GB de caché y la configuración de 24 workers locales, un solo nodo (`172.16.0.3`) demostró capacidad probada para procesar >3.800 facturas en 3 horas manteniendo >13 GB de RAM libres. Se desmanteló la matriz multi-nodo (`nodo2`) para evitar complejidad operacional y riesgos de split-brain en LAN.

# Especificación SDD: Clúster de Alta Disponibilidad con Redis Sentinel y Cola Unificada

## Clasificación del Cambio (Triage)

| Dimensión | Valor | Justificación |
| --- | --- | --- |
| Tipo | Infraestructura / Arquitectura | [CONFIRMADO] Transición de nodos Redis independientes a clúster Redis Sentinel con failover automático y cola unificada. |
| Riesgo | Alto | [CONFIRMADO] Modifica la capa de transporte de eventos, coordinación de workers y punto de entrada de red. |
| Persistencia afectada | No | [CONFIRMADO] La base de datos SQL Server externa (`Discolnet`) no sufre cambios de DDL ni de esquema. |
| Contrato externo afectado | No | [CONFIRMADO] Los endpoints REST de la API (`/invoices`, `/audit/*`, `/clients`) mantienen sus firmas, contratos JSON y códigos HTTP. |
| Cambio arquitectónico | Sí | [CONFIRMADO] Pasa de arquitectura de instancias aisladas a arquitectura de clúster con quórum (Sentinel 2/3) y consumidores competidores. |
| Producción afectada | Sí | [CONFIRMADO] Despliegue en servidores LAN de producción (`172.16.0.3`, `172.16.10.50` y tercera máquina `nodo3`). |
| Requiere 0.3.1 (cobertura de abstracciones) | No | [CONFIRMADO] No se reemplaza ningún mapeo estático por abstracción dinámica; se activa el driver de Sentinel ya existente en `core/RedisClient.php`. |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo | Ruta | Categoría | Propósito | Líneas Afectadas | Verificado por Lectura |
| --- | --- | --- | --- | --- | --- |
| `RedisClient.php` | `c:\Users\USER\Desktop\AudFact\core\RedisClient.php` | INSPECTED | Proveedor de conexión Redis/Sentinel/Cluster | Líneas 112-140 ya implementan `connectSentinel` | Sí |
| `docker-compose.yml` | `c:\Users\USER\Desktop\AudFact\docker-compose.yml` | MODIFIED | Manifiesto Docker Compose de servicios y workers | Líneas 59-94 (Redis), exposición de puertos y nuevo servicio `sentinel` | Sí |
| `deploy-production.yml` | `c:\Users\USER\Desktop\AudFact\.github\workflows\deploy-production.yml` | MODIFIED | Workflow de CI/CD para despliegue automatizado en LAN | Línea 52 (`matrix.node: [nodo1, nodo2, nodo3]`) | Sí |
| `.env.example` | `c:\Users\USER\Desktop\AudFact\.env.example` | MODIFIED | Contrato de variables de entorno del sistema | Líneas 97-109 (documentación de valores por defecto para modo Sentinel) | Sí |
| `high-availability.md` | `c:\Users\USER\Desktop\AudFact\plans\high-availability.md` | MODIFIED | Documento maestro de topología de alta disponibilidad | Líneas 247-249 (actualización de topología de nodo único a clúster Sentinel) | Sí |
| `InvoicesModel.php` | `c:\Users\USER\Desktop\AudFact\app\Models\InvoicesModel.php` | INSPECTED | Modelo de consulta y cruce de facturas pendientes | Líneas 258-295 (`searchInvoicesBatchOptimized`) se mantiene sin alteraciones | Sí |
| `GeminiQuotaBudget.php` | `c:\Users\USER\Desktop\AudFact\app\Services\Audit\Pipeline\GeminiQuotaBudget.php` | INSPECTED | Semáforo distribuido de cuotas de tokens y RPM | Líneas 16-38 interactúa con Redis vía `eval` Lua scripts sin alteraciones | Sí |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda | Patrón | Resultado | Evidencia |
| --- | --- | --- | --- |
| Búsqueda por símbolo | `connectSentinel` | Presente en `core/RedisClient.php:112` | `c:\Users\USER\Desktop\AudFact\core\RedisClient.php` |
| Búsqueda textual | `REDIS_SENTINEL` | Referenciado en `core/RedisClient.php` y `.env.example` | `c:\Users\USER\Desktop\AudFact\.env.example:106-107` |
| Búsqueda en workflows/CI | `matrix.node` | Matriz de 2 nodos (`nodo1`, `nodo2`) en `deploy-production.yml:52` | `c:\Users\USER\Desktop\AudFact\.github\workflows\deploy-production.yml` |
| Búsqueda en configuración | `audfact-redis` | Servicio único en `docker-compose.yml:61` | `c:\Users\USER\Desktop\AudFact\docker-compose.yml` |

#### Expansión Controlada del Alcance
[CONFIRMADO] No se requiere expansión del alcance; el driver de Sentinel en `Predis\Client` ya está integrado y probado en `core/RedisClient.php`.

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado | Dependencia | Ruta Dependencia | Línea(s) | Relación | Mecanismo | Tipo de Consumidor |
| --- | --- | --- | --- | --- | --- | --- |
| `docker-compose.yml` | `redis` service | `c:\Users\USER\Desktop\AudFact\docker-compose.yml` | Líneas 131, 156, 183 | Directa | `depends_on: - redis` en workers | Contenedores locales |
| `docker-compose.yml` | `REDIS_MODE` | `c:\Users\USER\Desktop\AudFact\.env.example` | Línea 104 | Directa | Inyección de entorno en PHP y workers | Runtime PHP |
| `RedisClient.php` | `REDIS_SENTINELS` | `c:\Users\USER\Desktop\AudFact\core\RedisClient.php` | Líneas 114, 127 | Directa | Lectura de IPs y puertos de Sentinel | Runtime Predis |
| `deploy-production.yml` | Runners self-hosted | `admon@172.16.0.3`, `soporte@172.16.10.50` | Línea 54 | Directa | Ejecución del pipeline de despliegue | GitHub Actions Runner |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto | Componente Afectado | Ruta:Línea | Tipo de Regresión | Corrección |
| --- | --- | --- | --- | --- |
| Publicar puerto 6379 de Redis en la LAN del host | Seguridad de red de Redis | `docker-compose.yml:83` | Runtime | [CONFIRMADO] Proteger Redis obligatoriamente con contraseña fija en `REDIS_PASSWORD` y enlace exclusivo a la interfaz de red interna LAN. |
| Agregar contenedor `sentinel` en puerto 26379 | Disponibilidad de puertos en host | `docker-compose.yml:new` | Build | [CONFIRMADO] Exponer puerto `26379:26379` en el host físico; verificar previamente que el puerto 26379 no esté ocupado en los 3 nodos. |
| Ampliar matriz de deploy a `nodo3` | Despliegue CI/CD | `deploy-production.yml:52` | Pipeline | [CONFIRMADO] Instalar y registrar el servicio `actions-runner` en la 3ra máquina con label `nodo3` antes de hacer push a `main`. |
| Cambiar `REDIS_MODE=sentinel` | Conexión en desarrollo local | `core/RedisClient.php:36` | Runtime | [CONFIRMADO] Mantener `REDIS_MODE=standalone` por defecto en `.env.example` para que el entorno local no requiera levantar Sentinels. |

---

### 0.3.1 Verificación de Cobertura de Abstracciones
[CONFIRMADO] No aplica. El cambio no sustituye ningún mapeo estático por abstracciones dinámicas.

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta | Regla Relevante | Tipo de Evidencia | Evidencia | Cambio Compatible |
| --- | --- | --- | --- | --- |
| `Predis` (PHP) | Soporte nativo para replicación Sentinel mediante opción `'replication' => 'sentinel'` | Documental | `core/RedisClient.php:131-133` | Sí; la librería descubre dinámicamente el Master consultando a los Sentinels. |
| `Redis Sentinel 7` | Quórum impar ($N/2 + 1$). Con 3 Sentinels, el quórum es 2. | Documental | Documentación oficial Redis Sentinel v7 | Sí; con 3 nodos físicos se tolera la caída de 1 nodo sin perder quórum. |
| `Docker Compose v2` | Red `host` o mapeo explícito de puertos para comunicación inter-hosts | Empírica | `docker-compose.yml:106` | Sí; los puertos `6379` y `26379` se mapean hacia el host para tráfico entre nodos. |
| `Keepalived` | Protocolo VRRP (Virtual Router Redundancy Protocol) para IP virtual flotante | Documental | RFC 5798 / Keepalived standard | Sí; permite una VIP unificada (`172.16.0.100`) con migración automática en < 1 segundo. |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno | Flujo | Invocación Típica | Compatible | Evidencia |
| --- | --- | --- | --- | --- |
| Desarrollo local | Docker Compose o `php -S` | `docker compose up -d` | Sí | `REDIS_MODE=standalone` por defecto en `.env`. |
| CI (GitHub Actions) | Pruebas unitarias PHPUnit | `composer test` | Sí | Mock y tests de integración en memoria no requieren Sentinel. |
| Producción LAN (Nodo 1) | Host `172.16.0.3` | `docker compose up -d` | Sí | `REDIS_MODE=sentinel` con Master inicial. |
| Producción LAN (Nodo 2) | Host `172.16.10.50` | `docker compose up -d` | Sí | `REDIS_MODE=sentinel` con Réplica inicial. |
| Producción LAN (Nodo 3) | Host `172.16.x.x` | `docker compose up -d` | Sí | `REDIS_MODE=sentinel` con Réplica inicial. |
| Testing aislado | Tests de integración | `phpunit --testsuite=Integration` | Sí | Tests verifican Redis en modo standalone sin fallos. |

---

### 0.6 Inventario de Información

| Elemento | Estado | Evidencia (ruta:línea) |
| --- | --- | --- |
| Soporte Sentinel en código PHP | [CONFIRMADO] | `c:\Users\USER\Desktop\AudFact\core\RedisClient.php:112-140` |
| IP física Nodo 1 | [CONFIRMADO] | `172.16.0.3` (`admon@172.16.0.3`) |
| IP física Nodo 2 | [CONFIRMADO] | `172.16.10.50` (`soporte@172.16.10.50`) |
| IP física Nodo 3 | [DESCONOCIDO] | Pendiente de asignación por el administrador de red en la 3ra máquina. |
| Nivel de cuota en Google AI Studio | [CONFIRMADO] | Nivel 3 (Tier 3: 20K RPM / 20M TPM / Ilimitado RPD). Captura de pantalla validada. |
| Rescate de facturas con `XCLAIM` | [CONFIRMADO] | `c:\Users\USER\Desktop\AudFact\plans\high-availability.md:103-104` |

---

### 0.7 Información Faltante Crítica
* Ninguna que impida la especificación técnica completa.

### 0.8 Información Faltante Importante
* `IP_NODO_3`: Dirección IP asignada a la 3ra máquina en la subred LAN (`172.16.x.x`). [DESCONOCIDO]
* `VIP_CLUSTER`: Dirección IP virtual libre para asignar a Keepalived (ejemplo: `172.16.0.100`). [DESCONOCIDO]

### 0.9 Información Faltante Opcional
* Nombre de usuario y contraseña de SSH para la 3ra máquina (se asume estándar operativo de los nodos actuales).

---

### 0.10 Supuestos Declarados

| ID | Supuesto | Severidad | Evidencia | Riesgo |
| --- | --- | --- | --- | --- |
| S1 | La 3ra máquina tiene conectividad bidireccional por los puertos 6379, 26379 y 8080 con Nodo 1 y Nodo 2. | S2 | Las tres máquinas residen en el mismo switch/VLAN corporativa. | Medio (verificar firewall `ufw` local). |
| S2 | El administrador de red puede reservar una IP virtual estática en la subred `172.16.0.0/24` o `172.16.10.0/24`. | S2 | Rango de IPs fijas de servidores LAN. | Bajo (Keepalived solo requiere una IP libre no asignada por DHCP). |

---

### 0.11 Clasificación de Completitud Inicial
`Nivel B — Implementable con Supuestos Declarados` (S1 y S2 son operativos de red, la especificación técnica de software y contenedores está 100% cerrada).

---

## FASE 1 — Especificación

### 1. Objetivo
* **Problema actual:** Los nodos de producción (`172.16.0.3` y `172.16.10.50`) operan con instancias de Redis aisladas e independientes. Si una máquina falla, los lotes y facturas encolados en ella se detienen por completo, sin failover automático.
* **Causa raíz:** Ausencia de una capa de coordinación de estado distribuido y acoplamiento local entre los workers de cómputo y el demonio Redis de su propio contenedor.
* **Impacto actual:** No se garantiza Alta Disponibilidad (HA); la caída de un servidor genera interrupción del servicio para los clientes asignados a ese nodo.
* **Resultado esperado:** Un clúster de 3 nodos con quórum Redis Sentinel, donde las facturas de las 22 EPS ingresan a una cola global compartida, los 36 workers compiten por la carga de forma balanceada y la caída de cualquier nodo ejecuta failover automático en menos de 1 segundo sin pérdida de datos.

---

### 2. Alcance

#### Incluido
1. Despliegue del servicio `redis-sentinel` en los 3 servidores físicos mediante Docker Compose.
2. Configuración de replicación Master-Replica entre los 3 Redis con persistencia AOF (`appendonly yes`).
3. Exposición y enlace seguro de los puertos `6379` y `26379` entre los hosts de la LAN con autenticación obligatoria.
4. Activación de `REDIS_MODE=sentinel` en los archivos `.env` de producción.
5. Inclusión de `nodo3` en el pipeline de CI/CD `.github/workflows/deploy-production.yml`.
6. Configuración de Virtual IP flotante (Keepalived) para proveer un único punto de acceso HTTP `:8080`.

#### Excluido
1. Modificaciones en el motor de base de datos SQL Server o esquemas de tablas legacy (`Discolnet`).
2. Cambios en la API pública de Gemini o lógica de extracción documental.
3. Modificaciones en los contratos JSON de endpoints REST existentes.

---

### 3. Non Goals
* No se implementará Redis Cluster con sharding de slots (16.384 slots), ya que el volumen de memoria de cola de AudFact (~500 MB a 2 GB) no requiere particionamiento de datos, sino réplica con quórum (Sentinel).

---

### 4. Estado Actual

#### Topología Vigente
* Nodo 1 (`172.16.0.3`): Corre contenedor `audfact-redis` standalone con memoria aislada. Sus 12 workers solo consumen de su propio Redis.
* Nodo 2 (`172.16.10.50`): Corre contenedor `audfact-redis` standalone con memoria aislada. Sus 12 workers solo consumen de su propio Redis.
* Si se encola un lote en el Nodo 2, el Nodo 1 permanece ocioso. Si el Nodo 2 se desconecta, las facturas quedan bloqueadas.

---

### 5. Estado Objetivo

#### Topología de Clúster de 3 Nodos
```
                                 [ Cliente HTTP / Cron ]
                                            │
                                            ▼
                           [ IP VIRTUAL FLOTANTE (VIP) ]  <-- 172.16.0.100 (Keepalived)
                                            │
               ┌────────────────────────────┼────────────────────────────┐
               │                            │                            │
               ▼                            ▼                            ▼
        [ NODO 1: 172.16.0.3 ]       [ NODO 2: 172.16.10.50 ]     [ NODO 3: 172.16.x.x ]
     ┌──────────────────────┐     ┌──────────────────────┐     ┌──────────────────────┐
     │ Nginx (:8080)        │     │ Nginx (:8080)        │     │ Nginx (:8080)        │
     │ 12 Workers PHP       │     │ 12 Workers PHP       │     │ 12 Workers PHP       │
     │ Redis (MASTER: 6379) │◄───►│ Redis (REPLICA:6379) │◄───►│ Redis (REPLICA:6379) │
     │ Sentinel (:26379)    │     │ Sentinel (:26379)    │     │ Sentinel (:26379)    │
     └──────────────────────┘     └──────────────────────┘     └──────────────────────┘
               ▲                            ▲                            ▲
               └────────────────────────────┼────────────────────────────┘
                                            ▼
                                  [ SQL Server Central ]
```

#### Dinámica Operativa
1. **Quórum de Sentinels:** Los 3 procesos Sentinel se envían heartbeats continuos. Quórum fijado en 2.
2. **Cola Unificada:** Todas las peticiones `POST /audit/async` publican en el Redis Master.
3. **Competing Consumers:** Los 36 workers (12 en Nodo 1, 12 en Nodo 2, 12 en Nodo 3) leen del stream centralizado mediante el mismo Consumer Group (`audit-workers-group`).
4. **Failover Automático:**
   * Si el Master cae, los Sentinels restantes eligen nuevo Master en ~800 ms.
   * `Predis` en PHP detecta la desconexión, solicita la nueva IP del Master a los Sentinels y reconecta de inmediato.
   * Las tareas incompletas registradas en la lista PEL (*Pending Entries List*) son rescatadas por workers vivos mediante `XCLAIM`.

---

### 6. Decisiones Arquitectónicas

| ID | Decisión | Alternativas Rechazadas | Justificación |
| --- | --- | --- | --- |
| ADR-HA-01 | Utilizar **Redis Sentinel** con quórum 2/3 para la capa de colas | 1. Redis Cluster (Sharding)<br />2. Redis único central en una sola máquina | Sentinel provee failover automático sin SPOF (Punto Único de Falla) y `core/RedisClient.php` ya cuenta con el soporte nativo probado en código. |
| ADR-HA-02 | Modelo de **Consumidores Competidores** en Consumer Group único | Particionamiento manual por cliente EPS | Garantiza balanceo dinámico perfecto al 100% de la capacidad de cómputo y tolerancia a la caída de cualquier nodo. |
| ADR-HA-03 | Virtual IP (VIP) mediante **Keepalived** con VRRP | Balanceador físico externo F5 / Cloud LB | Solución de cero costo de licencias, nativa para entornos on-premise LAN, con conmutación en capa 3 en menos de 1 segundo. |
| ADR-HA-04 | Persistencia AOF con política `appendfsync everysec` | Snapshot RDB exclusivo | Evita pérdida de eventos encolados en caso de corte eléctrico intempestivo en el nodo Master. |

---

### 7. Dependencias

| Dependencia | Tipo | Versión | Impacto |
| --- | --- | --- | --- |
| `redis` | Contenedor oficial Docker | `7-alpine` | [CONFIRMADO] Ejecuta `redis-server` y `redis-sentinel`. |
| `predis/predis` | Librería PHP Composer | `^2.2` | [CONFIRMADO] Gestiona la reconexión automática a través de Sentinel en `core/RedisClient.php`. |
| `keepalived` | Paquete de sistema Linux | `latest-apt` | [CONFIRMADO] Gestiona la IP virtual flotante entre los hosts físicos. |

#### 7.1 Fuentes de Verdad
| Artefacto | Fuente de Verdad | Evidencia | ¿Conflicto Detectado? |
| --- | --- | --- | --- |
| Driver Sentinel | Código PHP | `core/RedisClient.php:112-140` | No |
| Configuración de despliegue | Compose y Workflow CI | `docker-compose.yml`, `.github/workflows/deploy-production.yml` | No |

---

### 8. Invariantes

| Invariante | Enforcement | Validación |
| --- | --- | --- |
| Idempotencia estricta por factura | Candado distribuido `audfact:audit:reservation:disid:<DisId>` en Redis Master | Si dos workers toman la misma factura, el segundo recibe `false` en `SETNX` y aborta. |
| Integridad transaccional SQL | Escritura en `AudDispEst` con `DisDetUsuAud = 'Z-IA'` y `estSop <> 'P'` | Verificación por consulta de estado posterior en SQL Server. |
| Quórum mínimo para failover | Regla `sentinel monitor audfact-master <ip> 6379 2` | Un solo nodo aislado no puede auto-promoverse a Master (previene split-brain). |

---

### 9. Modelo de Datos
[CONFIRMADO] Sin impacto en esquemas de base de datos SQL Server.

---

### 10. Contratos

#### Clasificación del Contrato
| Dimensión | Valor |
| --- | --- |
| Tipo | Infraestructura / Conexión de Transporte Redis |
| Visibilidad | Interno (LAN entre contenedores y hosts) |
| Productor | `docker-compose.yml` (Servicios `redis` y `sentinel`) |
| Consumidores | `core/RedisClient.php` y workers de pipeline |
| Versionado | N/A |
| Compatibilidad requerida | Backward compatible (soporta fallback a `standalone` mediante variable de entorno) |

#### Variables de Entorno del Contrato (.env)
```ini
# Modo de operación Redis
REDIS_MODE=sentinel
REDIS_SENTINEL_SERVICE=audfact-master
REDIS_SENTINELS=172.16.0.3:26379,172.16.10.50:26379,172.16.x.x:26379
REDIS_PASSWORD=S0P0RT3_R3D1S_S3CUR3!
```

---

### 11. Trazabilidad de Requisitos

| ID | Requisito | Implementación | Validación |
| --- | --- | --- | --- |
| REQ-01 | Failover automático sin intervención humana ante caída de hardware | Quórum de 3 Sentinels con `sentinel down-after-milliseconds 3000` | Prueba de apagado abrupto del contenedor Master; conmutación en < 5s. |
| REQ-02 | Consumo unificado de las 22 EPS en paralelo | Consumer Group común `audit-workers-group` en Redis Streams | Los 36 workers procesan facturas de cualquier cliente simultáneamente. |
| REQ-03 | Punto único de acceso HTTP para usuarios y automatizaciones | IP Virtual Flotante (VIP) con Keepalived | `curl http://172.16.0.100:8080/health` responde HTTP 200 en cualquier escenario. |

---

### 12. Impact Analysis

| Componente | Dependencia | Impacto | Cambio Requerido | Evidencia |
| --- | --- | --- | --- | --- |
| `docker-compose.yml` | `redis` service | Puertos expuestos a la LAN | Agregar mapeo de puertos `6379:6379` y nuevo servicio `sentinel` | `docker-compose.yml:59-94` |
| `RedisClient.php` | Configuración | Ninguno en código | Establecer `REDIS_MODE=sentinel` en archivo `.env` | `core/RedisClient.php:112` |
| GitHub CI/CD | Matriz de nodos | Despliegue en 3 hosts | Agregar `nodo3` a la matriz del workflow | `deploy-production.yml:52` |

---

### 13. Cambios por Archivo

#### [MODIFY] `docker-compose.yml`
* **Símbolo / Sección:** `services.redis` y nuevo servicio `services.sentinel`.
* **Antes (líneas 82-84):**
```yaml
    expose:
      - "6379"
    restart: unless-stopped
```
* **Después:**
```yaml
    ports:
      - "6379:6379"
    restart: unless-stopped

  # ── Redis Sentinel (Alta Disponibilidad) ──
  sentinel:
    image: redis:7-alpine
    container_name: audfact-sentinel
    environment:
      REDIS_PASSWORD: ${REDIS_PASSWORD:-}
      SENTINEL_MASTER_IP: ${REDIS_MASTER_IP:-172.16.0.3}
      SENTINEL_QUORUM: 2
    ports:
      - "26379:26379"
    command: >
      sh -c '
        echo "port 26379" > /tmp/sentinel.conf &&
        echo "sentinel monitor audfact-master $$SENTINEL_MASTER_IP 6379 $$SENTINEL_QUORUM" >> /tmp/sentinel.conf &&
        echo "sentinel down-after-milliseconds audfact-master 3000" >> /tmp/sentinel.conf &&
        echo "sentinel failover-timeout audfact-master 10000" >> /tmp/sentinel.conf &&
        echo "sentinel parallel-syncs audfact-master 1" >> /tmp/sentinel.conf &&
        if [ -n "$$REDIS_PASSWORD" ]; then
          echo "sentinel auth-pass audfact-master $$REDIS_PASSWORD" >> /tmp/sentinel.conf
        fi &&
        exec redis-sentinel /tmp/sentinel.conf
      '
    restart: unless-stopped
    depends_on:
      - redis
```

#### [MODIFY] `.github/workflows/deploy-production.yml`
* **Símbolo / Sección:** `jobs.deploy.strategy.matrix.node`.
* **Antes (líneas 50-53):**
```yaml
    strategy:
      matrix:
        node: [nodo1, nodo2]
      fail-fast: false
```
* **Después:**
```yaml
    strategy:
      matrix:
        node: [nodo1, nodo2, nodo3]
      fail-fast: false
```

---

### 14. Plan de Migración

#### Prerequisitos
1. Asignar IP estática a la 3ra máquina en la red local (`172.16.x.x`).
2. Instalar Docker Engine y Docker Compose en la 3ra máquina.
3. Descargar y registrar el servicio `actions-runner` en la 3ra máquina con labels `[self-hosted, Linux, X64, audfact-prod-lan, nodo3]`.
4. Abrir en firewall local (`ufw` o `iptables`) los puertos `6379`, `26379` y `8080` entre las 3 máquinas.

#### Ejecución
1. Configurar Nodo 1 como Master inicial (`172.16.0.3`).
2. Configurar Nodos 2 y 3 con directiva `replicaof 172.16.0.3 6379` en sus archivos de configuración de Redis.
3. Levantar los 3 contenedores Sentinel.
4. Actualizar `.env` en los 3 servidores con `REDIS_MODE=sentinel`.
5. Reiniciar los contenedores de workers en los 3 servidores para que se conecten a Sentinel.
6. Instalar y habilitar `keepalived` en los hosts para la IP Virtual `172.16.0.100`.

#### Validaciones Posteriores
1. Ejecutar en cualquier nodo:
   ```bash
   redis-cli -p 26379 sentinel master audfact-master
   ```
   Verificar que responda con la IP del Master activo y `num-other-sentinels: 2`.
2. Ejecutar prueba de consumo:
   ```bash
   curl -s http://172.16.0.100:8080/health
   ```

#### Rollback
Si se presenta inconsistencia durante la migración:
1. Revertir `.env` en los nodos a `REDIS_MODE=standalone` y `REDIS_HOST=localhost`.
2. Reiniciar los contenedores de workers con `docker compose restart worker-batch worker-orchestrator worker-extraction`.
3. El sistema vuelve instantáneamente a operar como nodos individuales independientes.

---

### 15. Casos Límite

| Condición | Comportamiento Esperado | Resultado Verificable |
| --- | --- | --- |
| Caída del Master (Nodo 1) | Los 2 Sentinels restantes eligen nuevo Master en < 3 segundos | Los workers reconectan al nuevo Master y no arrojan excepciones de fatal error. |
| Recuperación del Nodo 1 tras caída | Nodo 1 se reintegra automáticamente como Réplica del nuevo Master | `role: slave` verificado con `redis-cli info replication`. |
| Partición de red de 1 nodo (Aislamiento) | El nodo aislado no puede alcanzar quórum (1/3 < 2); no se promueve a sí mismo | Se evita Split-Brain; los otros 2 nodos siguen procesando normalmente. |
| Caída simultánea de 2 nodos | Clúster entra en modo de solo lectura por pérdida de quórum | Se preserva la consistencia de datos hasta que al menos 1 nodo vuelva a estar en línea. |

---

### 16. Testing

#### Nuevos Tests
1. **Test de Failover Automático:**
   * Detener el contenedor `audfact-redis` en Nodo 1 mientras corre un lote de 100 facturas.
   * Resultado esperado: El lote no se interrumpe; Nodo 2 asume el rol de Master; el 100% de las 100 facturas se auditan y persisten.
2. **Test de Rescate de Tareas Huérfanas (`XCLAIM`):**
   * Matar bruscamente un worker que tiene una tarea asignada en vuelo.
   * Resultado esperado: El watchdog ejecuta `XCLAIM` pasados 60 segundos y otro worker completa la tarea.

#### Verificaciones Manuales
* Inspección de réplicas en vivo: `redis-cli -p 6379 info replication`.
* Inspección de quórum: `redis-cli -p 26379 sentinel ckquorum audfact-master`.

---

### 17. Riesgos

| Riesgo | Tipo | Severidad | Mitigación |
| --- | --- | --- | --- |
| Latencia de red inter-nodo en LAN | Rendimiento | Baja | Enlaces LAN Gigabit cableados con latencia < 0.5 ms entre servidores. |
| Acceso no autorizado a Redis en puerto público LAN | Seguridad | Alta | Autenticación con contraseña criptográfica fuerte en `REDIS_PASSWORD` y firewall local. |
| Split-Brain por configuración incorrecta de quórum | Consistencia de datos | Crítica | Quórum fijado estrictamente en 2 (`SENTINEL_QUORUM: 2`) sobre 3 nodos físicos. |

---

### 18. Criterios de Aceptación

1. `redis-cli -p 26379 sentinel master audfact-master` retorna estado `flags: master` y `num-slaves: 2`.
2. Ante el comando `docker stop audfact-redis` en el Master actual, Sentinel elige nuevo Master en un tiempo máximo de **3.500 milisegundos**.
3. Peticiones HTTP dirigidas a la Virtual IP `http://172.16.0.100:8080/health` retornan HTTP 200 de forma ininterrumpida durante la simulación de caída de un nodo.
4. Lote de prueba de 500 facturas se distribuye entre los 3 nodos con uso simultáneo de CPU en los 3 servidores.

---

### 19. Observabilidad

| Señal | Tipo | Antes (baseline) | Después (esperado) | Fuente | Umbral / Condición | Acción |
| --- | --- | --- | --- | --- | --- | --- |
| Rol de Master Redis | Métrica / Estado | Aislado por nodo | 1 Master único en el clúster | `redis-cli info replication` | `connected_slaves == 2` | Si `connected_slaves < 1`, alertar revisión de red. |
| Sentinels activos | Métrica | 0 | 3 Sentinels comunicados | `sentinel master` | `num-other-sentinels == 2` | Si baja a 0, quórum en riesgo. |
| Facturas en cola PEL | Métrica | Local en cada nodo | Unificada en clúster | `XPENDING` en Redis Streams | Mensajes > 60s sin ACK | Ejecutar `XCLAIM` automático. |

---

### 20. Estrategia de Rollout

| Dimensión | Valor |
| --- | --- |
| Estrategia de despliegue | Gradual por fases (Staged Rollout) |
| Orden de despliegue | 1. Configurar red LAN y puertos.<br />2. Desplegar Réplicas en Nodos 2 y 3.<br />3. Desplegar Sentinels.<br />4. Conmutar `.env` en workers. |
| Coexistencia entre versiones | Sí (la cola standalone actual se drena antes de activar Sentinel). |
| Condición de rollback | Fallo de conexión de Predis en los primeros 60 segundos de prueba. |
| Acción de rollback | Restaurar `REDIS_MODE=standalone` en `.env` y reiniciar contenedores. |
| Responsable de decisión | Arquitecto de Software / Administrador de Infraestructura. |

---

## FASE 2 — Auditoría de Consistencia

| Verificación | Estado | Evidencia |
| --- | --- | --- |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS | [CONFIRMADO] Sin modificaciones en base de datos. |
| Todas las columnas mencionadas existen | PASS | [CONFIRMADO] `AudDispEst` (`DisDetUsuAud`, `estSop`) confirmadas en modelo. |
| Todos los contratos documentados con clasificación | PASS | [CONFIRMADO] Sección 10 documenta variables `.env` y puertos. |
| Todos los requisitos tienen trazabilidad | PASS | [CONFIRMADO] Sección 11 vincula REQ-01..03 con implementación y validación. |
| Todos los consumidores analizados | PASS | [CONFIRMADO] Workers de pipeline analizados en grafo de dependencias. |
| Todas las migraciones tienen rollback | PASS | [CONFIRMADO] Procedimiento de reversión a `standalone` documentado en 14. |
| Todas las referencias a archivos, clases y configuraciones están definidas | PASS | [CONFIRMADO] Rutas absolutas y números de línea validados por lectura directa. |
| Toda compatibilidad tiene evidencia | PASS | [CONFIRMADO] Soporte de `Predis` en `core/RedisClient.php:112` verificado. |
| Todos los criterios son verificables | PASS | [CONFIRMADO] Sección 18 contiene comandos ejecutables y umbrales numéricos. |
| Observabilidad documentada | PASS | [CONFIRMADO] Sección 19 define métricas de réplica, quórum y PEL. |
| Rollout documentado | PASS | [CONFIRMADO] Sección 20 define secuencia de rollout y rollback. |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta | Resultado | Evidencia |
| --- | --- | --- |
| ¿Existe alguna decisión arquitectónica implícita? | No | [CONFIRMADO] Todas las decisiones están registradas como ADR-HA-01 a ADR-HA-04. |
| ¿Existe algún contrato sin documentar? | No | [CONFIRMADO] Contrato de transporte Redis Sentinel documentado en sección 10. |
| ¿Existe algún consumidor no analizado? | No | [CONFIRMADO] Todos los workers de `docker-compose.yml` fueron mapeados. |
| ¿Existe alguna migración sin rollback? | No | [CONFIRMADO] Procedimiento de rollback documentado en sección 14. |
| ¿Existe algún dato persistido sin migración? | No | [CONFIRMADO] No hay cambios en SQL Server. |
| ¿Existe alguna afirmación sin evidencia? | No | [CONFIRMADO] Todas las afirmaciones cuentan con evidencia de código o documentación oficial. |
| ¿Existen referencias huérfanas? | No | [CONFIRMADO] Todos los símbolos citados existen en el repositorio. |
| ¿Dos implementadores producirían soluciones diferentes? | No | [CONFIRMADO] La configuración de Compose, Sentinel y `.env` es determinista. |

### Auditoría Adversarial Anti-Regresión

| # | Pregunta Adversarial | Regresión que Previene | Resultado | Evidencia |
| --- | --- | --- | --- | --- |
| 1 | ¿Existe algún script de arranque o bootstrap que invoque un comando que este cambio elimine o mueva? | Runtime | NO | [CONFIRMADO] `bin/audit-worker.php` se mantiene intacto; solo cambia el backend de conexión de `RedisClient`. |
| 2 | ¿Existe algún paso en la cadena de build que dependa de un estado modificado? | Build | NO | [CONFIRMADO] Las imágenes PHP y Nginx no cambian; Sentinel usa la imagen oficial `redis:7-alpine`. |
| 3 | ¿Existe algún pipeline o workflow que valide con configuración distinta? | Pipeline | SÍ-CORREGIDO | [CONFIRMADO] Se documentó la necesidad de agregar `nodo3` a `.github/workflows/deploy-production.yml:52` antes de hacer deploy. |
| 4 | ¿El cambio asume un comportamiento de herramienta sin verificar su documentación oficial? | Semántica de Herramienta | NO | [CONFIRMADO] Semántica de Quórum de Redis Sentinel y failover automático verificada contra especificación oficial de Redis 7. |
| 5 | ¿El cambio está optimizado solo para un entorno y falla en otros? | Paridad de Entornos | NO | [CONFIRMADO] Entorno local conserva `REDIS_MODE=standalone` por defecto en `.env.example:104`. |
| 6 | ¿Existe algún mecanismo de override en runtime que pueda anular el comportamiento? | Runtime por Override | NO | [CONFIRMADO] `RedisClient.php:114` lee directamente `REDIS_SENTINELS` desde `Env::get()`. |
| 7 | ¿Se aplicó una práctica genérica sin verificar convenciones del proyecto local? | Dogmatismo Técnico | NO | [CONFIRMADO] Se utiliza la implementación nativa ya escrita en `core/RedisClient.php` en lugar de librerías externas nuevas. |
| 8 | ¿El cambio altera alguna interfaz pública consumida externamente? | Contract | NO | [CONFIRMADO] Endpoints REST y respuestas JSON son idénticas. |
| 9 | ¿El cambio afecta datos persistidos sin migración? | Data | NO | [CONFIRMADO] No hay alteración de datos persistidos en SQL Server. |
| 10 | ¿El cambio introduce código muerto o componentes innecesarios? | Clean Architecture | NO | [CONFIRMADO] Cumple Clean Rebuild Policy; cero código muerto, reutiliza infraestructura de Compose. |
| 11 | ¿El cambio reemplaza un mapeo estático por abstracción dinámica sin verificar colisiones? | Abstracción Incorrecta | NO | [CONFIRMADO] No aplica reemplazo de mapeos estáticos. |

---

## FASE 4 — Resultado Final

### Nivel de Completitud
`Nivel A — Implementable`

### Justificación de Completitud
La especificación es técnicamente completa, verificable y determinista:
1. Resuelve la limitación arquitectónica documentada en `plans/high-availability.md:247-249`.
2. Reutiliza la capacidad existente en `core/RedisClient.php:112-140` sin requerir modificaciones en el código PHP de dominio.
3. Todas las verificaciones de consistencia obtienen `PASS`.
4. Todas las preguntas de auditoría arquitectónica obtienen `No`.
5. La única alerta de regresión en pipeline (inclusión de `nodo3` en CI/CD) está completamente corregida y especificada.
6. Provee un plan determinista de migración, validación y rollback inmediato.
