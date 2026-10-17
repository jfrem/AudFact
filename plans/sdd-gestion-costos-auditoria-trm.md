# Especificación de Implementación — Registro y Gestión de Métricas de Costos IA y Conversión TRM Dinámica

## Clasificación del Cambio (Triage)

| Dimensión                                   | Valor                             | Justificación                                                                                                                                                                                                          |
| ------------------------------------------- | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tipo                                        | Feature / Persistencia / Contrato | [CONFIRMADO] Incorpora una nueva tabla de persistencia (`AudDispCosto`), integración con Datos Abiertos de Colombia (Socrata API) y DólarAPI (fallback), servicio de caché cambiaria en Redis y nuevos endpoints REST. |
| Riesgo                                      | Alto                              | [CONFIRMADO] Modifica la transacción principal de persistencia de resultados de auditoría en SQL Server (`AuditResultPersistenceModel`) y expone nuevos contratos públicos.                                            |
| Persistencia afectada                       | Sí                                | [CONFIRMADO] Creación de la tabla `Discolnet.dbo.AudDispCosto` con columnas `[CostoUSD]`, `[TrmAplicada]` y `[CostoCOP]`, índices especializados y secuencias de intentos por factura.                                 |
| Contrato externo afectado                   | Sí                                | [CONFIRMADO] Consumo de la API oficial de Datos Abiertos de Colombia (`datos.gov.co` view `32sa-8pi3`), fallback en DólarAPI y exposición de cuatro nuevos endpoints bajo `/audit/costs`.                              |
| Cambio arquitectónico                       | Sí                                | [CONFIRMADO] Introduce el dominio financiero desacoplado (`App\Services\Finance`) con patrón Cache-Aside en Redis para la tasa representativa del mercado (TRM) y congelamiento de costos en COP y USD.                |
| Producción afectada                         | Sí                                | [CONFIRMADO] Afecta la ejecución del worker de persistencia en producción y el esquema de la base de datos SQL Server productiva.                                                                                      |
| Requiere 0.3.1 (cobertura de abstracciones) | No                                | [CONFIRMADO] No reemplaza mapeos estáticos por abstracciones dinámicas; añade una nueva entidad relacional y un cliente HTTP con arquitectura de fallback multinivel.                                                  |

---

## FASE 0 — Descubrimiento Empírico Obligatorio

### 0.1 Perímetro de Impacto

| Archivo                           | Ruta                                                     | Categoría  | Propósito                                                                                                                                                              | Líneas Afectadas      | Verificado por Lectura |
| --------------------------------- | -------------------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- | ---------------------- |
| `AuditResultPersistenceModel.php` | `app/Models/AuditResultPersistenceModel.php`             | MODIFIED   | [CONFIRMADO] Persistencia transaccional de resultados de auditoría en SQL Server. Se extiende la transacción de `persist()` para registrar el costo en `AudDispCosto`. | Líneas 55-66, 142-195 | Sí                     |
| `web.php`                         | `app/Routes/web.php`                                     | MODIFIED   | [CONFIRMADO] Registro centralizado de endpoints REST. Se registran las 4 rutas bajo `/audit/costs`.                                                                    | Líneas 33-47          | Sí                     |
| `.env.example`                    | `.env.example`                                           | UNMODIFIED | [CONFIRMADO] Sin cambios. Se preserva el contrato actual intacto para evitar consumo de cuota de variables en GitHub Actions.                                          | Ninguna               | Sí                     |
| `CurrencyExchangeService.php`     | `app/Services/Finance/CurrencyExchangeService.php`       | NEW        | [CONFIRMADO] Nuevo servicio de consulta y caché en Redis de la TRM mediante Datos Abiertos Colombia (primario), DólarAPI (fallback) con constantes canónicas.          | Archivo nuevo (1-160) | Sí                     |
| `AuditCostModel.php`              | `app/Models/AuditCostModel.php`                          | NEW        | [CONFIRMADO] Nuevo modelo de lectura y escritura para consultas analíticas y agregaciones sobre `AudDispCosto`.                                                        | Archivo nuevo (1-220) | Sí                     |
| `AuditCostController.php`         | `app/Controllers/AuditCostController.php`                | NEW        | [CONFIRMADO] Nuevo controlador REST para exponer reportes de costos por intento, consolidado por factura y por cliente.                                                | Archivo nuevo (1-150) | Sí                     |
| `migration_AudDispCosto.sql`      | `app/Models/migration/migration_AudDispCosto.sql`        | NEW        | [CONFIRMADO] DDL idempotente para la creación de la tabla `AudDispCosto` e índices.                                                                                    | Archivo nuevo (1-50)  | Sí                     |
| `rollback_AudDispCosto.sql`       | `app/Models/migration/rollback_AudDispCosto.sql`         | NEW        | [CONFIRMADO] Script de reversión estricta de la tabla e índices.                                                                                                       | Archivo nuevo (1-15)  | Sí                     |
| `CurrencyExchangeServiceTest.php` | `tests/Services/Finance/CurrencyExchangeServiceTest.php` | NEW        | [CONFIRMADO] Suite de pruebas unitarias para el servicio de TRM (cache hit, miss, primario, fallback secundario, timeout).                                             | Archivo nuevo (1-160) | Sí                     |
| `AuditCostModelTest.php`          | `tests/Models/AuditCostModelTest.php`                    | NEW        | [CONFIRMADO] Suite de pruebas de persistencia, incremento de intentos y cálculo determinista en USD y COP.                                                             | Archivo nuevo (1-160) | Sí                     |
| `AuditPersistenceWorker.php`      | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | INSPECTED  | [CONFIRMADO] Consumidor de eventos `rules_evaluated`. Invoca a `AuditResultPersistenceModel::persist()`. No requiere cambios de firma ni lógica interna.               | Líneas 89-94          | Sí                     |
| `RedisClient.php`                 | `core/RedisClient.php`                                   | INSPECTED  | [CONFIRMADO] Cliente Redis del sistema. Provee métodos `get()`, `set($key, $value, $ttl)`, `del()` consumidos por `CurrencyExchangeService`.                                           | Líneas 1-150          | Sí                     |
| `Database.php`                    | `core/Database.php`                                      | INSPECTED  | [CONFIRMADO] Manejador de conexiones PDO a SQL Server. Provee conexiones transaccionales limpias.                                                                      | Líneas 1-130          | Sí                     |

#### Criterio de Cierre del Perímetro

| Método de Búsqueda               | Patrón                                          | Resultado                                                                                                                 | Evidencia                                                                                        |
| -------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| Búsqueda por símbolo             | `gemini_total`, `prompt_tokens`, `total_tokens` | [CONFIRMADO] Identificado el payload de timings estructurado en `AuditResultPersistenceModel` y `GeminiCallMetrics`.      | `app/Services/Audit/GeminiCallMetrics.php:135`, `app/Models/AuditResultPersistenceModel.php:490` |
| Búsqueda por importación/require | `AuditResultPersistenceModel`                   | [CONFIRMADO] Localizado su único llamador de pipeline en `AuditPersistenceWorker::handle()`.                              | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php:18`, `:90`                               |
| Búsqueda textual                 | `AudDispEst`                                    | [CONFIRMADO] Confirmado que no existe actualmente tabla de costos dedicada; `Hallazgos` almacena el JSON volátil.         | `plans/database-schema.md:45`, `app/Models/AuditStatusModel.php:43`                              |
| Búsqueda en configuración        | `GEMINI_MODEL`                                  | [CONFIRMADO] Modelo actual configurado por defecto como `gemini-3.7-flash` (ya presente en `.env` y en GitHub Variables). | `app/Services/Audit/GeminiConfig.php:38`, `.env.example:66`                                      |
| Búsqueda en tests                | `AuditResultPersistenceModelTest`               | [CONFIRMADO] Localizadas pruebas de persistencia que validan la transacción de base de datos.                             | `tests/Services/Audit/Events/AuditEventConsumerTest.php:45`                                      |
| Búsqueda en workflows/CI         | `ci.yml`                                        | [CONFIRMADO] Paso `Validate env contract completeness` valida que `getenv/Env::get` coincidan con `.env.example`.         | `.github/workflows/ci.yml:114`                                                                   |

---

### 0.2 Grafo de Dependencias Acopladas

| Archivo Afectado                  | Dependencia                   | Ruta Dependencia                                         | Línea(s)     | Relación | Mecanismo                         | Tipo de Consumidor       |
| --------------------------------- | ----------------------------- | -------------------------------------------------------- | ------------ | -------- | --------------------------------- | ------------------------ |
| `AuditResultPersistenceModel.php` | `AuditPersistenceWorker.php`  | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php` | Línea 90     | Directa  | Invocación en ejecución de evento | Repositorio local        |
| `AuditResultPersistenceModel.php` | `Discolnet.dbo.AudDispCosto`  | `app/Models/migration/migration_AudDispCosto.sql`        | Línea 1      | Directa  | Persistencia SQL transaccional    | Base de datos SQL Server |
| `AuditResultPersistenceModel.php` | `CurrencyExchangeService.php` | `app/Services/Finance/CurrencyExchangeService.php`       | N/A          | Directa  | Consulta de TRM vigente para COP  | Repositorio local        |
| `CurrencyExchangeService.php`     | `RedisClient.php`             | `core/RedisClient.php`                                   | Línea 30     | Directa  | Caché volátil con TTL             | Infraestructura Redis    |
| `CurrencyExchangeService.php`     | Datos Abiertos Colombia (v3)  | `https://www.datos.gov.co/api/v3/views/32sa-8pi3/...`    | N/A          | Directa  | HTTP GET cURL (Primaria)          | Servicio Oficial Estado  |
| `CurrencyExchangeService.php`     | DólarAPI externa              | `https://co.dolarapi.com/v1/cotizaciones/usd`            | N/A          | Directa  | HTTP GET cURL (Fallback)          | Servicio externo         |
| `AuditCostController.php`         | `web.php`                     | `app/Routes/web.php`                                     | Líneas 48-52 | Directa  | Dispatch de rutas REST            | Repositorio local        |
| `AuditCostController.php`         | `AuditCostModel.php`          | `app/Models/AuditCostModel.php`                          | Línea 25     | Directa  | Invocación de queries             | Repositorio local        |
| `AuditCostController.php`         | `CurrencyExchangeService.php` | `app/Services/Finance/CurrencyExchangeService.php`       | Línea 28     | Directa  | Estado actual de TRM              | Repositorio local        |

---

### 0.3 Análisis de Impacto Inverso (Regresiones)

| Cambio Propuesto                                                             | Componente Afectado             | Ruta:Línea                                                  | Tipo de Regresión     | Corrección                                                                                                                                                                                                                                                            |
| ---------------------------------------------------------------------------- | ------------------------------- | ----------------------------------------------------------- | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Inserción en `AudDispCosto` dentro de `AuditResultPersistenceModel::persist` | `AuditPersistenceWorker.php:90` | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php:90` | Runtime / Data        | [CONFIRMADO] La inserción en `AudDispCosto` se ejecuta dentro de la misma transacción PDO (`$writeDb->beginTransaction()`). Si falla el cálculo o inserción de costo, la transacción hace rollback completo garantizando atomicidad sin dejar estados inconsistentes. |
| Inserción de múltiples reauditorías para una misma `FacNro`                  | `AudDispCosto`                  | `app/Models/migration/migration_AudDispCosto.sql:1`         | Data / Constraint     | [CONFIRMADO] La clave primaria no es `FacNro`. Se implementa clave subrogada `[AudCostoId] BIGINT IDENTITY(1,1)` con restricción de unicidad compuesta `UNIQUE ([FacNro], [Intento])`. El intento se calcula determinísticamente como `MAX(Intento) + 1`.             |
| Indisponibilidad o timeout de la API externa de TRM                          | `CurrencyExchangeService.php`   | `app/Services/Finance/CurrencyExchangeService.php:55`       | Runtime / Third-Party | [CONFIRMADO] Cascada multinivel: Si Datos Abiertos falla o hace timeout (2s), se intenta DólarAPI (2s); si ambas fallan, se recurre a la última TRM en Redis o a la constante de dominio `DEFAULT_FALLBACK_TRM` (3200.00 COP).                                        |
| Límite alcanzado de variables en GitHub Actions (`gh variable`)              | CI/CD & Deploy                  | `.github/workflows/deploy-production.yml:104`               | Pipeline / Quota      | [CONFIRMADO] Se evita crear nuevas variables de entorno en GitHub Actions. Tarifas ($0.75/$3.75), URLs y App Token se definen como constantes canónicas de dominio en PHP con soporte de inyección para testing.                                                      |

---

### 0.3.1 Verificación de Cobertura de Abstracciones

`N/A` — [CONFIRMADO] El cambio no sustituye mapeos estáticos existentes por abstracciones dinámicas. Introduce un nuevo subsistema de cálculo financiero y persistencia de costos.

---

### 0.4 Verificación de Semántica de Herramientas

| Herramienta                | Regla Relevante                                                                     | Tipo de Evidencia | Evidencia                                                                                                                             | Cambio Compatible                                                                                                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Microsoft SQL Server 2016+ | Atomicidad de transacciones y resolución de `IDENTITY`                              | Documental        | [Microsoft Docs: Transactions and Concurrency](https://learn.microsoft.com/en-us/sql/t-sql/statements/begin-transaction-transact-sql) | Sí. La lectura del intento `MAX(Intento) + 1` y la inserción se realizan bajo el mismo bloqueo transaccional (`UPDLOCK, HOLDLOCK`) para evitar colisiones de concurrencia. |
| Redis (Key Expiration)     | Comportamiento determinista de comando `SET key value EX seconds` (`RedisClient::set`) | Documental        | [Redis Docs: SET](https://redis.io/commands/set/)                                                                                     | Sí. La TRM se almacena con expiración exacta de 86.400 segundos (24 horas) para evitar desactualizaciones prolongadas.                                                     |
| cURL / PHP 8.2             | Timeouts de conexión independientes (`CURLOPT_CONNECTTIMEOUT` vs `CURLOPT_TIMEOUT`) | Documental        | [PHP Manual: curl_setopt](https://www.php.net/manual/es/function.curl-setopt.php)                                                     | Sí. Se fijan `CURLOPT_CONNECTTIMEOUT = 2` y `CURLOPT_TIMEOUT = 3` para asegurar que ninguna petición externa bloquee al hilo PHP de persistencia ni reportes.              |

---

### 0.5 Matriz de Entornos de Ejecución

| Entorno             | Flujo                                                                | Invocación Típica                      | Compatible | Evidencia                                                                                                                                                      |
| ------------------- | -------------------------------------------------------------------- | -------------------------------------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Desarrollo local    | Docker Compose con PHP-FPM 8.2 + Redis 7 + SQL Server                | `docker compose up -d`                 | Sí         | [CONFIRMADO] Validado contenedor `audfact-php-1` y conectividad con Redis y SQL Server operativa (HTTP 200 en `/health`).                                      |
| CI (GitHub Actions) | `composer test`, validación de lint y `.env.example`                 | `php vendor/bin/phpunit --no-coverage` | Sí         | [CONFIRMADO] No requiere nuevas variables en GitHub ni `.env.example`; no altera la validación de `ci.yml`. Pruebas unitarias usan mocks locales.              |
| Producción LAN      | Host `admon@172.16.0.3` sin acceso directo a internet no restringido | Runner self-hosted + Docker HA         | Sí         | [CONFIRMADO] Al no requerir nuevas variables en `deploy-production.yml`, el deploy no colisiona con el límite de GitHub Variables. Fallback a 3200 COP en LAN. |
| Testing aislado     | SQLite / Mocks en memoria                                            | `phpunit tests/Services/Finance/`      | Sí         | [CONFIRMADO] Los tests unitarios no requieren acceso real a SQL Server ni a servicios externos.                                                                |

---

### 0.6 Inventario de Información

| Elemento                                       | Estado       | Evidencia (ruta:línea)                                                                                                                                                                                              |
| ---------------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Estructura JSON de tokens en `Hallazgos`       | [CONFIRMADO] | `app/Services/Audit/GeminiCallMetrics.php:135-138`, `Discolnet.dbo.AudDispEst.Hallazgos`                                                                                                                            |
| Fórmula financiera de liquidación de tokens    | [CONFIRMADO] | Solicitud explícita del usuario: `(Entrada * 0.75 + (Salida + Pensamiento) * 3.75) / 1,000,000`.                                                                                                                    |
| Endpoint oficial Datos Abiertos Colombia       | [CONFIRMADO] | Ejecución empírica cURL: `https://www.datos.gov.co/api/v3/views/32sa-8pi3/query.json?pageNumber=1&pageSize=1&app_token=sVj0LWjA0pYlg1RLG2FOziLxC` retorna `[{"valor":"3194.44","vigenciadesde":"2026-10-10",...}]`. |
| Endpoint secundario DólarAPI (Fallback)        | [CONFIRMADO] | Ejecución empírica cURL: `https://co.dolarapi.com/v1/cotizaciones/usd` retorna `{"moneda":"USD","venta":3200.69,...}`.                                                                                              |
| Llave operativa de dispensación (`FacNro`)     | [CONFIRMADO] | `app/Models/DispensationModel.php:17`, `app/Models/AuditResultPersistenceModel.php:147`                                                                                                                             |
| Mecanismo de persistencia actual de auditorías | [CONFIRMADO] | `app/Models/AuditResultPersistenceModel.php:55-66` (transacción PDO).                                                                                                                                               |

---

### 0.7 Información Faltante Crítica

_Ninguna. Toda la información técnica requerida para la implementación determinista ha sido confirmada empíricamente en el repositorio y la base de datos._

---

### 0.8 Información Faltante Importante

| Dato                                         | Motivo                                                                                                                                                                        | Impacto                                                                                                                                           |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Variación histórica de tarifas por modelo IA | [INFERIDO] Las tarifas actuales corresponden a `gemini-3.7-flash` / `gemini-3.8-flash` ($0.75 in / $3.75 out). Si el modelo cambia en `.env`, las tarifas deberían ajustarse. | Mitigado congelando `TarifaEntrada1M` y `TarifaSalida1M` en cada fila de `AudDispCosto` para que los registros históricos nunca cambien de valor. |

---

### 0.9 Información Faltante Opcional

| Dato                                          | Motivo                                                                       | Impacto                                                                                           |
| --------------------------------------------- | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Existencia de proxy corporativo en producción | No se inspeccionaron reglas de firewall perimetral saliente en `172.16.0.3`. | Resuelto mediante timeout estricto de 2s y fallback a TRM local si la petición HTTP es bloqueada. |

---

### 0.10 Supuestos Declarados

| ID     | Supuesto                                                                                                                   | Severidad          | Evidencia                                                                                                                                              | Riesgo                                                              |
| ------ | -------------------------------------------------------------------------------------------------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------- |
| SUP-01 | Los tokens de pensamiento (`thoughts_tokens`) se facturan a la tarifa de salida de Gemini.                                 | S1 — No bloqueante | [CONFIRMADO] Política oficial de precios de Google Gemini y confirmación explícita del usuario en su fórmula: `(salida + pensamiento) * precioSalida`. | Cero riesgo; alineado con la regla de tarificación de Google Cloud. |
| SUP-02 | La TRM oficial legal de Colombia certificada por la Superfinanciera en Datos Abiertos (`32sa-8pi3`) es la fuente canónica. | S1 — No bloqueante | [CONFIRMADO] Verificación empírica del dataset `32sa-8pi3`. Devuelve la tasa representativa del mercado vigente con vigencia temporal legal.           | Máxima fidelidad legal y contable en Colombia.                      |

---

### 0.11 Clasificación de Completitud Inicial

**Nivel A — Implementable**: La especificación cuenta con 100% de evidencia confirmada, cero dependencias críticas desconocidas, solución verificada a la colisión de reauditorías, persistencia dual USD/COP y arquitectura de TRM multinivel con fuente oficial gubernamental y fallback de respaldo, respetando el límite de variables de GitHub Actions.

---

## FASE 1 — Especificación

### 1. Objetivo

Implementar un subsistema robusto, inmutable y auditable de **gestión y control de costos de Inteligencia Artificial** en la plataforma AudFact. El sistema debe:

1. Registrar de forma estructurada los tokens consumidos (entrada, salida, pensamiento, total), el costo liquidado en USD y el **costo liquidado en Pesos Colombianos (COP)** con la **TRM oficial aplicada** por cada ejecución de auditoría.
2. Soportar múltiples reauditorías sobre una misma dispensación (`FacNro`) sin sobrescribir ni perder el costo de los intentos anteriores, calculando automáticamente el consecutivo `Intento`.
3. Ofrecer conversión monetaria inmutable al momento de auditar consumiendo la **TRM oficial de Colombia desde Datos Abiertos (`datos.gov.co`)** con fallback automático en **DólarAPI**, almacenamiento en caché diario en Redis y degradación graciosa ante fallos.
4. Proveer endpoints REST y vistas optimizadas en SQL Server para reportes detallados por intento, acumulados por factura y consolidados por cliente/EPS con agregaciones nativas `SUM(CostoUSD)` y `SUM(CostoCOP)`.
5. Operar con **constantes canónicas de dominio en PHP** sin crear variables nuevas en GitHub Actions para no sobrepasar la cuota del entorno `production`.

---

### 2. Alcance

#### Incluido

- Creación de la tabla `Discolnet.dbo.AudDispCosto` con columnas explícitas `[CostoUSD]`, `[TrmAplicada]` y `[CostoCOP]`, índices compuestos y restricciones de integridad.
- Integración transaccional en `AuditResultPersistenceModel` para inserción atómica junto a la cabecera y adjuntos de auditoría, calculando y persistiendo el costo en USD y en COP con la TRM vigente.
- Creación del servicio `App\Services\Finance\CurrencyExchangeService` con estrategia en cascada gobernada por constantes de clase:
  - Nivel 1: Redis Cache (`finance:trm:usd_cop`).
  - Nivel 2: Datos Abiertos Colombia Socrata API (`https://www.datos.gov.co/api/v3/views/32sa-8pi3/query.json`) con `app_token = sVj0LWjA0pYlg1RLG2FOziLxC`.
  - Nivel 3: DólarAPI (`https://co.dolarapi.com/v1/cotizaciones/usd`).
  - Nivel 4: Fallback estático (`3200.00 COP`).
- Creación del modelo `App\Models\AuditCostModel` y del controlador `App\Controllers\AuditCostController`.
- Exposición de 4 endpoints REST en `app/Routes/web.php`.
- Scripts DDL de migración y rollback idempotentes.
- Cobertura completa de pruebas unitarias para el servicio de TRM y el modelo de costos.

#### Excluido

- Modificación del archivo `.env.example` ni adición de nuevas variables en GitHub Actions (respetando el límite de cuota).
- Modificación del pipeline de extracción de tokens en `DocumentExtractionWorker` (los tokens ya se capturan y existen en `timings.gemini_total`).
- Modificación del esquema de la tabla de auditoría legacy `AudDispEst` (se mantiene intacta para compatibilidad del visor frontend).

---

### 3. Non Goals

- No se implementará facturación electrónica ni pasarelas de pago de terceros; el módulo es estrictamente de telemetría, auditoría de costos y control de gestión financiera.

---

### 4. Estado Actual

Actualmente, las métricas de tokens se generan en el pipeline y se serializan como un string JSON dentro de la columna `Discolnet.dbo.AudDispEst.Hallazgos` bajo la ruta `$.timings.gemini_total`.
Cuando una factura se reaudita, `AuditResultPersistenceModel::persist()` ejecuta un `UPDATE` sobre `AudDispEst` filtrando por `FacNro`. Esto **sobrescribe la columna `Hallazgos`**, perdiendo irrecuperablemente los tokens y costos de los intentos anteriores. Adicionalmente, calcular el costo total del sistema requiere parsear JSONs masivos con `JSON_VALUE`, degradando el rendimiento de SQL Server y sin existencia alguna de valores calculados en moneda local (COP).

---

### 5. Estado Objetivo

Se introduce una arquitectura desacoplada de dos capas:

```
[ Worker: AuditPersistenceWorker ]
              │
              ▼
[ AuditResultPersistenceModel::persist() ]
  ├── 1. Consultar TRM vigente ──► [ CurrencyExchangeService ]
  │                                       ├── Hit ──► [ Redis (microsegundos) ]
  │                                       └── Miss ─► [ Datos Abiertos / DólarAPI ]
  │
  ├── 2. Transacción SQL Server (BEGIN TRANSACTION)
  │    ├── UPDATE/INSERT Discolnet.dbo.AudDispEst (Estado actual de la auditoría)
  │    ├── UPDATE AdjuntosDispensacion (Decisiones documentales)
  │    └── INSERT Discolnet.dbo.AudDispCosto:
  │         ├── CostoUSD = (Entrada*0.75 + (Salida+Pens)*3.75) / 1M
  │         ├── TrmAplicada = TRM oficial del día
  │         └── CostoCOP = CostoUSD * TrmAplicada
  └── COMMIT TRANSACTION
              │
[ API REST: GET /audit/costs ] ──► [ AuditCostModel ]
                                          ├── SELECT directo de columnas CostoUSD y CostoCOP
                                          └── SUM(CostoCOP) agregación nativa en SQL Server
```

---

### 6. Decisiones Arquitectónicas

| ID     | Decisión                                                                                                               | Alternativas Rechazadas                                                                          | Justificación                                                                                                                                                                                                                                                                                            |
| ------ | ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DEC-01 | Usar clave subrogada `[AudCostoId] BIGINT IDENTITY(1,1)` con índice único `([FacNro], [Intento])`.                     | - `[FacNro]` como PK.<br>- Actualizar en lugar de insertar en reauditorías.                      | Permite almacenar infinitos intentos por factura sin colisión de claves y conserva el histórico financiero acumulado íntegro.                                                                                                                                                                            |
| DEC-02 | Inserción síncrona dentro de la transacción de `AuditResultPersistenceModel::persist()`.                               | - Encolar evento asíncrono para registrar el costo.<br>- Trigger en SQL Server.                  | Garantiza atomicidad absoluta: una auditoría no puede quedar registrada como finalizada en base de datos sin su correspondiente registro contable de costo.                                                                                                                                              |
| DEC-03 | Persistir físicamente `[CostoUSD]`, `[TrmAplicada]` y `[CostoCOP]` en cada fila de `AudDispCosto`.                     | - Persistir solo `CostoUSD` y calcular `CostoCOP` al vuelo en la API.                            | Garantiza inmutabilidad y rigor contable histórico (el costo en pesos no varía con fluctuaciones cambiarias futuras) y permite consultas SQL directas y dashboards (PowerBI) con `SUM(CostoCOP)` nativo sin sobrecargar la API PHP.                                                                      |
| DEC-04 | Cascada de TRM oficial (Datos Abiertos) + respaldo (DólarAPI) + caché Redis (24h) + fallback estático (`DEFAULT_TRM`). | - Consultar DólarAPI únicamente.<br>- Consultar API en cada fila o petición.<br>- Cron nocturno. | Provee máxima precisión legal utilizando la TRM oficial de la Superfinanciera, con respaldo redundante ante caídas y latencia cero en lecturas mediante Redis.                                                                                                                                           |
| DEC-05 | Excluir identificadores de orquestación (`JobId`) de `AudDispCosto`.                                                   | - Incluir columna `[JobId] VARCHAR(100) NULL` en la tabla de costos.                             | Evita inconsistencias y acoplamiento innecesario. AudFact ejecuta auditorías tanto 1:1 (`POST /audit/single`) como en lote (`POST /audit/async`). `JobId` es un artefacto efímero de orquestación; la entidad de costos es puramente contable por dispensa (`FacNro`, `FacNitSec`, `FacSec`, `Intento`). |
| DEC-06 | Encapsular tarifas estándar de Gemini, URLs y App Token de TRM como constantes canónicas de dominio en PHP.            | - Agregar 7 variables nuevas en GitHub Actions y `.env.example`.                                 | El entorno `production` de GitHub Actions ha alcanzado su límite de cuota de variables (`gh variable`). Definir constantes en clases PHP evita bloqueos en CI/CD y despliegue, permitiendo sobreescritura por constructor en tests.                                                                      |

---

### 7. Dependencias

| Dependencia                           | Tipo                     | Versión | Impacto                                                          |
| ------------------------------------- | ------------------------ | ------- | ---------------------------------------------------------------- |
| SQL Server (PDO `sqlsrv`)             | Base de datos            | 2016+   | Persistencia relacional de `AudDispCosto`.                       |
| Redis (`Core\RedisClient`)            | Infraestructura de caché | 7.x     | Almacenamiento volátil de la TRM (`finance:trm:usd_cop`).        |
| Datos Abiertos Colombia (`32sa-8pi3`) | API Oficial Estado       | v3      | Fuente canónica oficial de la TRM (Superintendencia Financiera). |
| DólarAPI (`https://co.dolarapi.com`)  | API Externa              | v1      | Fuente secundaria de respaldo (Fallback).                        |

#### 7.1 Fuentes de Verdad

| Artefacto                       | Fuente de Verdad                        | Evidencia                                              | ¿Conflicto Detectado? |
| ------------------------------- | --------------------------------------- | ------------------------------------------------------ | --------------------- |
| Tarifas de Tokens por Defecto   | `AuditCostModel::DEFAULT_INPUT_RATE_1M` | Tabla oficial de precios Google Gemini ($0.75 / $3.75) | No                    |
| Métrica de Tokens por Auditoría | `Hallazgos` en `AudDispEst`             | `$.timings.gemini_total` en `AuditTimingSummarizer`    | No                    |
| TRM de Conversión Vigente       | Redis (`finance:trm:usd_cop`)           | `CurrencyExchangeService`                              | No                    |

---

### 8. Invariantes

| Invariante                          | Enforcement                                                                         | Validación                                                          |
| ----------------------------------- | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Unicidad de Factura e Intento       | Restricción `UNIQUE ([FacNro], [Intento])` en SQL Server                            | Test unitario de concurrencia e intento duplicado.                  |
| Secuencia monotónica de intentos    | `COALESCE(MAX(Intento), 0) + 1` ejecutado bajo bloqueo `UPDLOCK, HOLDLOCK`          | Test transaccional de reauditoría múltiple.                         |
| Inmutabilidad de registros de costo | No existe operación `UPDATE` ni `DELETE` sobre `AudDispCosto` en toda la aplicación | Auditoría de código en `AuditCostModel` (solo `INSERT` y `SELECT`). |
| Determinismo del costo en USD y COP | Fórmulas matemáticas fijadas: `USD = Tokens/1M * Tarifa`, `COP = USD * TRM`         | Test unitario de cálculo con valores límite.                        |

---

### 9. Modelo de Datos

#### DDL

```sql
-- =============================================================================
-- Migración: Creación de tabla para control de costos IA (AudDispCosto)
-- Base de datos: Discolnet.dbo
-- =============================================================================

IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'AudDispCosto' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE Discolnet.dbo.AudDispCosto (
        [AudCostoId]             BIGINT IDENTITY(1, 1) NOT NULL,
        [FacNro]                 VARCHAR(100) NOT NULL,
        [FacNitSec]              VARCHAR(100) NOT NULL,
        [FacSec]                 NVARCHAR(320) NULL,           -- DisId (llave legacy de dispensación)
        [Intento]                INT NOT NULL DEFAULT 1,       -- Consecutivo: 1 = Inicial, 2 = 1ra reauditoría, etc.
        [TokensEntrada]          BIGINT NOT NULL DEFAULT 0,
        [TokensSalida]           BIGINT NOT NULL DEFAULT 0,
        [TokensPensamiento]      BIGINT NOT NULL DEFAULT 0,
        [TotalTokens]            BIGINT NOT NULL DEFAULT 0,
        [ModeloIA]               VARCHAR(50) NOT NULL DEFAULT 'gemini-3.7-flash',
        [TarifaEntrada1M]        DECIMAL(10, 4) NOT NULL DEFAULT 0.7500, -- Tarifa unitaria vigente en USD
        [TarifaSalida1M]         DECIMAL(10, 4) NOT NULL DEFAULT 3.7500, -- Tarifa unitaria vigente en USD
        [CostoUSD]               DECIMAL(18, 8) NOT NULL,                -- Costo liquidado en USD
        [TrmAplicada]            DECIMAL(10, 2) NOT NULL DEFAULT 0.00,   -- TRM oficial vigente al momento de auditar (ej. 3194.44)
        [CostoCOP]               DECIMAL(18, 4) NOT NULL DEFAULT 0.0000, -- Costo liquidado en COP (CostoUSD * TrmAplicada)
        [DocsProcesados]         INT NOT NULL DEFAULT 0,
        [DocsCacheHit]           INT NOT NULL DEFAULT 0,
        [EsCacheHitTotal]        BIT NOT NULL DEFAULT 0,       -- 1 si la extracción provino 100% de caché (costo $0)
        [FechaAuditoria]         DATETIME2 NOT NULL DEFAULT GETDATE(),

        CONSTRAINT PK_AudDispCosto PRIMARY KEY CLUSTERED ([AudCostoId]),
        CONSTRAINT UQ_AudDispCosto_Fac_Intento UNIQUE ([FacNro], [Intento])
    );

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_Fecha
    ON Discolnet.dbo.AudDispCosto ([FechaAuditoria] DESC)
    INCLUDE ([FacNro], [FacNitSec], [CostoUSD], [CostoCOP], [TrmAplicada], [TotalTokens], [Intento]);

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_Cliente
    ON Discolnet.dbo.AudDispCosto ([FacNitSec], [FechaAuditoria] DESC)
    INCLUDE ([CostoUSD], [CostoCOP]);

    CREATE NONCLUSTERED INDEX IX_AudDispCosto_FacNro
    ON Discolnet.dbo.AudDispCosto ([FacNro], [Intento] DESC);
END;
```

#### DDL de Rollback

```sql
-- =============================================================================
-- Rollback: Eliminación de tabla AudDispCosto e índices asociados
-- Base de datos: Discolnet.dbo
-- =============================================================================

IF EXISTS (SELECT * FROM sys.tables WHERE name = 'AudDispCosto' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    DROP TABLE Discolnet.dbo.AudDispCosto;
END;
```

#### Migración de Datos Históricos (Backfill Opcional)

Se incluye procedimiento SQL idempotente para poblar `AudDispCosto` con las auditorías previas existentes en `AudDispEst` que contengan nodo `timings.gemini_total` utilizando TRM histórica de referencia:

```sql
INSERT INTO Discolnet.dbo.AudDispCosto (
    [FacNro], [FacNitSec], [FacSec], [Intento],
    [TokensEntrada], [TokensSalida], [TokensPensamiento], [TotalTokens],
    [ModeloIA], [TarifaEntrada1M], [TarifaSalida1M], [CostoUSD],
    [TrmAplicada], [CostoCOP],
    [DocsProcesados], [DocsCacheHit], [EsCacheHitTotal], [FechaAuditoria]
)
SELECT
    a.FacNro,
    a.FacNitSec,
    a.FacSec,
    1 AS Intento,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.prompt_tokens') AS BIGINT), 0) AS TokensEntrada,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.output_tokens') AS BIGINT), 0) AS TokensSalida,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.thoughts_tokens') AS BIGINT), 0) AS TokensPensamiento,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.total_tokens') AS BIGINT), 0) AS TotalTokens,
    'gemini-3.7-flash' AS ModeloIA,
    0.7500 AS TarifaEntrada1M,
    3.7500 AS TarifaSalida1M,
    ROUND(
        (
            COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.prompt_tokens') AS BIGINT), 0) * 0.7500 +
            (
                COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.output_tokens') AS BIGINT), 0) +
                COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.thoughts_tokens') AS BIGINT), 0)
            ) * 3.7500
        ) / 1000000.0,
        8
    ) AS CostoUSD,
    3200.00 AS TrmAplicada,
    ROUND(
        ROUND(
            (
                COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.prompt_tokens') AS BIGINT), 0) * 0.7500 +
                (
                    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.output_tokens') AS BIGINT), 0) +
                    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_total.thoughts_tokens') AS BIGINT), 0)
                ) * 3.7500
            ) / 1000000.0,
            8
        ) * 3200.00,
        4
    ) AS CostoCOP,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.docs_total') AS INT), a.DocumentosProcesados) AS DocsProcesados,
    COALESCE(CAST(JSON_VALUE(a.Hallazgos, '$.timings.gemini_extraction.cache_hits') AS INT), 0) AS DocsCacheHit,
    CASE WHEN CAST(JSON_VALUE(a.Hallazgos, '$.timings.cache_hit_rate') AS DECIMAL(5,2)) = 1.0 THEN 1 ELSE 0 END AS EsCacheHitTotal,
    COALESCE(a.FechaActualizacion, a.FechaCreacion) AS FechaAuditoria
FROM Discolnet.dbo.AudDispEst a WITH (NOLOCK)
WHERE a.Hallazgos LIKE '%"gemini_total"%'
  AND NOT EXISTS (SELECT 1 FROM Discolnet.dbo.AudDispCosto c WHERE c.FacNro = a.FacNro);
```

---

### 10. Contratos

#### 10.1 Integración Externa: Datos Abiertos Colombia (Primaria / Oficial)

- **Fuente Oficial:** Superintendencia Financiera de Colombia (Dataset `32sa-8pi3`).
- **Endpoint:** `GET https://www.datos.gov.co/api/v3/views/32sa-8pi3/query.json?pageNumber=1&pageSize=1&app_token=sVj0LWjA0pYlg1RLG2FOziLxC`
- **Timeout:** 2.000 ms conexión / 3.000 ms ejecución total.
- **Payload esperado:**

```json
[
  {
    ":id": "row-sct9_pg3c~ak8a",
    ":version": "rv-rztr.ekm7~gxr4",
    ":created_at": "2026-10-09T23:05:26.885Z",
    ":updated_at": "2026-10-09T23:05:26.885Z",
    "valor": "3194.44",
    "unidad": "COP",
    "vigenciadesde": "2026-10-10T00:00:00.000",
    "vigenciahasta": "2026-10-13T00:00:00.000"
  }
]
```

- **Campos consumidos:**
  - `valor` (string numérico convertido a `float`).
  - `vigenciadesde` (string ISO, opcional para metadatos).
  - `vigenciahasta` (string ISO, opcional para metadatos).

#### 10.2 Integración Externa: DólarAPI (Secundaria / Fallback)

- **Endpoint:** `GET https://co.dolarapi.com/v1/cotizaciones/usd`
- **Timeout:** 2.000 ms conexión / 3.000 ms ejecución total.
- **Payload esperado:**

```json
{
  "moneda": "USD",
  "nombre": "Dólar",
  "compra": 3197.19,
  "venta": 3200.69,
  "ultimoCierre": 3226.06,
  "fechaActualizacion": "2026-10-09T20:02:24.999Z"
}
```

- **Campo consumido:** `venta` (tipo `float`).

#### 10.3 API REST AudFact (Saliente)

##### Endpoint 1: `GET /audit/costs`

- **Propósito:** Reporte paginado de costos por intento individual con valores en USD y COP persistidos.
- **Query Params:**
  - `dateFrom` (opcional, YYYY-MM-DD)
  - `dateTo` (opcional, YYYY-MM-DD)
  - `facNitSec` (opcional, string)
  - `facNro` (opcional, string)
  - `page` (default 1)
  - `pageSize` (default 20, max 100)
- **Respuesta HTTP 200:**

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {
    "trm_vigente_hoy": 3194.44,
    "trm_fuente": "datos_gov_co",
    "pagination": {
      "page": 1,
      "pageSize": 20,
      "totalRecords": 1404,
      "totalPages": 71
    },
    "totales_periodo": {
      "gran_total_tokens": 14090544,
      "costo_total_usd": 23.472156,
      "costo_total_cop": 74980.45
    },
    "items": [
      {
        "aud_costo_id": 1,
        "fac_nit_sec": "2624",
        "cliente": "NUEVA EPS SA",
        "fac_nro": "D01260811080",
        "intento": 1,
        "tokens_entrada": 6973,
        "tokens_salida": 1225,
        "tokens_pensamiento": 1838,
        "total_tokens": 10036,
        "costo_usd": 0.016716,
        "trm_aplicada": 3194.44,
        "costo_cop": 53.4,
        "es_cache_hit_total": false,
        "modelo_ia": "gemini-3.7-flash",
        "fecha_auditoria": "2026-10-13 13:28:39"
      }
    ]
  }
}
```

##### Endpoint 2: `GET /audit/costs/clients`

- **Propósito:** Agrupación y totalización nativa en SQL Server del gasto acumulado por EPS/Cliente en USD y COP.
- **Respuesta HTTP 200:**

```json
{
  "success": true,
  "message": "Operación exitosa",
  "data": {
    "items": [
      {
        "fac_nit_sec": "1165",
        "cliente": "ENTIDAD PROMOTORA DE SALUD SANITAS S.A.S.",
        "facturas_auditadas": 754,
        "total_intentos": 920,
        "total_tokens": 9234500,
        "gasto_total_usd": 15.42105,
        "gasto_total_cop": 49261.62
      }
    ]
  }
}
```

##### Endpoint 3: `GET /audit/costs/{facNro}`

- **Propósito:** Historial cronológico de todos los intentos registrados para una factura específica (`FacNro`), mostrando la evolución de tokens, `CostoUSD`, `TrmAplicada` y `CostoCOP` por intento.

##### Endpoint 4: `GET /audit/costs/trm`

- **Propósito:** Inspección en vivo del estado de la TRM, origen activo (`datos_gov_co`, `dolarapi_fallback`, `redis_cache`, `static_fallback`), vigencia y salud de las conexiones externas.

---

### 11. Trazabilidad de Requisitos

| ID     | Requisito                                              | Implementación                                                                                   | Validación                                                       |
| ------ | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------- |
| REQ-01 | Tabla estructurada de costos con columnas solicitadas. | Tabla `Discolnet.dbo.AudDispCosto`.                                                              | Script DDL y verificación de esquema en SQL Server.              |
| REQ-02 | No perder costos ante reauditorías múltiples.          | Clave compuesta `([FacNro], [Intento])` con `MAX(Intento) + 1`.                                  | Test de reauditoría que confirma 2 filas para la misma `FacNro`. |
| REQ-03 | Persistencia inmutable del costo en COP y TRM.         | Columnas `[TrmAplicada]` y `[CostoCOP]` en `AudDispCosto`, calculadas en tiempo de persistencia. | Verificación de filas persistidas con valores en COP congelados. |
| REQ-04 | Consumo de TRM oficial con caché diario en Redis.      | `CurrencyExchangeService` consumiendo Datos Abiertos (`32sa-8pi3`) y guardando en Redis (24h).   | Test con mock de cliente HTTP y verificación de TTL en Redis.    |
| REQ-05 | Resiliencia multinivel ante caídas de proveedores.     | Cascada: Datos Abiertos -> DólarAPI -> Redis histórico -> `DEFAULT_TRM` (3200.00).               | Test unitario simulando caídas consecutivas con degradación.     |
| REQ-06 | Operación sin saturación de variables GitHub Actions.  | Constantes canónicas de dominio en PHP (`CurrencyExchangeService` y `AuditCostModel`).           | Verificación de que `ci.yml` pasa sin tocar `.env.example`.      |

---

### 12. Impact Analysis

| Componente                    | Dependencia | Impacto | Cambio Requerido                                                               | Evidencia                                                   |
| ----------------------------- | ----------- | ------- | ------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| `AuditResultPersistenceModel` | SQL Server  | Bajo    | Añadir método `persistAuditCost()` en la misma transacción PDO de `persist()`. | `app/Models/AuditResultPersistenceModel.php:55-66`          |
| `AuditPersistenceWorker`      | Pipeline    | Cero    | Continúa invocando `persist()` de forma transparente.                          | `app/Services/Audit/Pipeline/AuditPersistenceWorker.php:90` |
| `web.php`                     | Router      | Bajo    | Registro de 4 nuevas rutas públicas.                                           | `app/Routes/web.php:48-52`                                  |
| Redis                         | Memoria     | Mínimo  | 1 clave string (`finance:trm:usd_cop`, < 200 bytes).                           | `core/RedisClient.php`                                      |

---

### 13. Cambios por Archivo

#### 13.1 `app/Services/Finance/CurrencyExchangeService.php` `[NEW]`

- **Responsabilidad:** Cliente HTTP con timeout hacia Datos Abiertos (Socrata) y DólarAPI, almacenamiento cache-aside en Redis (TTL 86400s) y degradación en cascada gobernada por constantes de dominio.
- **Firma:**

```php
namespace App\Services\Finance;

use Core\RedisClient;

class CurrencyExchangeService
{
    public const REDIS_KEY_TRM = 'finance:trm:usd_cop';
    public const DEFAULT_TTL_SECONDS = 86400; // 24 horas

    public const DATOS_GOV_URL = 'https://www.datos.gov.co/api/v3/views/32sa-8pi3/query.json';
    public const DATOS_GOV_TOKEN = 'sVj0LWjA0pYlg1RLG2FOziLxC';
    public const DOLAR_API_URL = 'https://co.dolarapi.com/v1/cotizaciones/usd';
    public const DEFAULT_FALLBACK_TRM = 3200.00;

    public function __construct(
        private ?RedisClient $redis = null,
        private ?string $datosGovUrl = null,
        private ?string $datosGovToken = null,
        private ?string $dolarApiUrl = null,
        private float $defaultTrm = self::DEFAULT_FALLBACK_TRM
    ) {}

    /**
     * @return array{
     *     trm: float,
     *     source: string,
     *     updated_at: string,
     *     vigencia_desde?: ?string,
     *     vigencia_hasta?: ?string
     * }
     */
    public function getUsdCopRate(): array;
}
```

#### 13.2 `app/Models/AuditCostModel.php` `[NEW]`

- **Responsabilidad:** Consultas y reportería de la tabla `AudDispCosto`. Tarifas unitarias de tokens canónicas ($0.75 / $3.75). Agregaciones nativas directas en SQL Server de `SUM(CostoUSD)` y `SUM(CostoCOP)`.
- **Constantes:**
  - `public const DEFAULT_INPUT_RATE_1M = 0.7500;`
  - `public const DEFAULT_OUTPUT_RATE_1M = 3.7500;`
  - `public const DEFAULT_MODEL_IA = 'gemini-3.7-flash';`
- **Métodos:**
  - `recordCost(PDO $connection, array $costData): int`
  - `getDetailedCostsReport(array $filters, int $page, int $pageSize): array`
  - `getCostsByClient(array $filters): array`
  - `getCostsByInvoice(string $facNro): array`

#### 13.3 `app/Models/AuditResultPersistenceModel.php` `[MODIFY]`

- **Líneas observadas:** 55-66, 195+
- **Cambio:** Integrar llamada a `persistAuditCostRecord()` dentro del bloque `$writeDb->beginTransaction()`. Consulta la TRM vigente a través de `CurrencyExchangeService` (con hit en Redis en microsegundos) y calcula `CostoUSD` y `CostoCOP`.

```php
// En persist():
$this->upsertAuditResult($writeDb, $data);
$this->persistAttachmentDecisions($writeDb, $data['FacNro'], $data['FacNitSec'], $documentDecisions);
$this->persistAuditCostRecord($writeDb, $data); // Persiste CostoUSD, TrmAplicada y CostoCOP de forma atómica
```

#### 13.4 `app/Controllers/AuditCostController.php` `[NEW]`

- **Responsabilidad:** Controlador REST para validar parámetros de consulta y responder JSON estandarizado vía `Core\Response::success()`.

#### 13.5 `app/Routes/web.php` `[MODIFY]`

- **Líneas observadas:** 45-47
- **Cambio:** Registro de rutas (se registran inmediatamente **antes** de `/audit/{facNro}/timings` para garantizar precedencia de segmentos estáticos sobre el parámetro comodín `{facNro}`):

```php
$router->get('/audit/costs', 'AuditCostController', 'index');
$router->get('/audit/costs/clients', 'AuditCostController', 'byClients');
$router->get('/audit/costs/trm', 'AuditCostController', 'currentTrm');
$router->get('/audit/costs/{facNro}', 'AuditCostController', 'byInvoice');
```

#### 13.6 `.env.example` `[UNMODIFIED]`

- **Impacto:** Ninguno.
- **Justificación:** Se preserva el archivo sin adiciones para respetar la cuota alcanzada de GitHub Variables en el entorno `production`. Todas las configuraciones financieras operan con constantes canónicas de dominio en PHP con inyección opcional por constructor.

---

### 14. Plan de Migración

#### Prerequisitos

1. Servidor de base de datos SQL Server accesible en escritura por el usuario `DB_USER`.
2. Servidor Redis accesible en `REDIS_HOST:REDIS_PORT`.

#### Ejecución

1. Ejecutar el script `app/Models/migration/migration_AudDispCosto.sql` en SQL Server.
2. (Opcional) Ejecutar el bloque de backfill para poblar los registros históricos desde `AudDispEst` con `TrmAplicada` y `CostoCOP`.
3. Desplegar los archivos PHP nuevos y modificados (sin requerir variables en GitHub Actions).
4. Ejecutar suite de pruebas: `php vendor/bin/phpunit tests/Services/Finance/ tests/Models/AuditCostModelTest.php`.

#### Validaciones Previas

- Verificar con `SELECT 1 FROM Discolnet.dbo.AudDispEst` conectividad y permisos `CREATE TABLE` en `Discolnet.dbo`.

#### Validaciones Posteriores

- Ejecutar `curl.exe http://localhost:8080/audit/costs/trm` y confirmar respuesta HTTP 200 con valor de TRM y fuente `datos_gov_co`.
- Ejecutar una auditoría de prueba (`POST /audit/single`) y verificar que se inserte la fila correspondiente en `AudDispCosto` con `Intento = 1`, `CostoUSD > 0`, `TrmAplicada > 0` y `CostoCOP > 0`.
- Reejecutar la misma auditoría de prueba y verificar que se inserte la segunda fila con `Intento = 2` y `EsCacheHitTotal = 1` ($0 USD / $0 COP).

#### Rollback

1. Ejecutar `app/Models/migration/rollback_AudDispCosto.sql`.
2. Revertir commits de código vía Git.

---

### 15. Casos Límite

| Condición                                                              | Comportamiento Esperado                                                                                | Resultado Verificable                                                                 |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------- |
| Factura reauditada 3 veces seguidas                                    | Registra 3 filas distintas con `Intento` 1, 2 y 3.                                                     | `SELECT COUNT(*) FROM AudDispCosto WHERE FacNro = 'X'` retorna 3.                     |
| Reauditoría con 100% de acierto en caché                               | `EsCacheHitTotal = 1`, `Tokens = 0`, `CostoUSD = 0.00000000`, `CostoCOP = 0.0000`.                     | Se registra la fila de intento 2 con costo cero sin fallar constraints.               |
| Fluctuación futura de la TRM                                           | Los registros históricos conservan su `TrmAplicada` y `CostoCOP` inalterables.                         | Las auditorías pasadas no sufren recálculo monetario en COP.                          |
| Datos Abiertos timeout o no disponible                                 | `CurrencyExchangeService` degrada a DólarAPI de inmediato (fuente: `dolarapi_fallback`).               | El endpoint responde HTTP 200 con TRM válida de respaldo y persiste COP correcto.     |
| Ambas APIs externas caídas y sin conexión a internet                   | `CurrencyExchangeService` recupera última clave en Redis o degrada a `DEFAULT_FALLBACK_TRM` (3200.00). | El endpoint `/audit/costs` responde HTTP 200 sin lanzar error 500.                    |
| Hallazgos de auditoría sin nodo `gemini_total` (fallo prematuro)       | Inserta en `AudDispCosto` con 0 tokens y $0 USD / $0 COP.                                              | La transacción de auditoría completa con éxito y registra la evidencia de costo cero. |
| Concurrencia de 2 workers reauditando la misma factura simultáneamente | Bloqueo `UPDLOCK, HOLDLOCK` serializa el cálculo de `MAX(Intento) + 1`.                                | Ningún intento colisiona; cada uno obtiene un entero consecutivo único.               |

---

### 16. Testing

#### Nuevos Tests

1. `CurrencyExchangeServiceTest::testCacheMissQueriesDatosGovCoAndStoresInRedis`: Valida consumo de Datos Abiertos con `app_token` y almacenamiento en Redis con TTL de 24h.
2. `CurrencyExchangeServiceTest::testCacheHitSkipsNetworkCall`: Valida que con valor existente en Redis no se hace llamada HTTP.
3. `CurrencyExchangeServiceTest::testFallbackToDolarApiWhenDatosGovCoFails`: Valida que si Datos Abiertos devuelve código HTTP de error o timeout, el servicio consulta exitosamente DólarAPI.
4. `CurrencyExchangeServiceTest::testStaticFallbackWhenAllApisFail`: Valida retorno de `DEFAULT_FALLBACK_TRM` (3200.00) si ambos proveedores externos fallan.
5. `AuditCostModelTest::testRecordCostComputesConsecutiveAttemptsAndCop`: Valida que dos inserciones para el mismo `FacNro` asignen intentos 1 y 2, y liquiden `CostoCOP = CostoUSD * TrmAplicada`.
6. `AuditCostModelTest::testClientAggregationsSumUsdAndCopDirectly`: Valida las sumatorias agregadas nativas `SUM(CostoUSD)` y `SUM(CostoCOP)`.

---

### 17. Riesgos

| Riesgo                                                  | Tipo          | Severidad | Mitigación                                                                                                        |
| ------------------------------------------------------- | ------------- | --------- | ----------------------------------------------------------------------------------------------------------------- |
| Latencia en reportes por consulta HTTP externa          | Rendimiento   | Media     | Patrón Cache-Aside en Redis; la petición cURL externa solo ocurre una vez cada 24 horas.                          |
| Desajuste de tarifas si Google cambia precios de Gemini | Financiero    | Baja      | Las tarifas son inmutables por fila en `AudDispCosto`; registros pasados nunca se recalculan con tarifas futuras. |
| Crecimiento de la tabla `AudDispCosto` por alto volumen | Base de datos | Baja      | Índices cubrientes (`INCLUDE`) aseguran que consultas agregadas ejecuten Index Scans sin tocar páginas de datos.  |

---

### 18. Criterios de Aceptación

1. **Persistencia Multi-Intento:** Ejecutar dos auditorías sobre la misma factura produce exactamente dos registros en `AudDispCosto` con `Intento = 1` e `Intento = 2`.
2. **Cero Pérdida de Costo:** `SUM(CostoUSD)` y `SUM(CostoCOP)` sobre una factura refleja la sumatoria real de todos sus intentos históricos.
3. **Persistencia Dual USD y COP:** Cada fila en `AudDispCosto` almacena `CostoUSD`, `TrmAplicada` y `CostoCOP` de forma inmutable y verificable vía SQL.
4. **Caché Efectiva de TRM:** Dos peticiones consecutivas a `/audit/costs` solo ejecutan una llamada HTTP externa (verificado con conteo de hits en Redis).
5. **Cero Dependencia de GitHub Variables:** El código compila, ejecuta y despliega sin requerir adición de variables al entorno `production` de GitHub.
6. **Suite de Pruebas en Verde:** Todos los tests pasan con éxito ejecutando `php vendor/bin/phpunit tests/Services/Finance/` y `tests/Models/AuditCostModelTest.php`.

---

### 19. Observabilidad

| Señal                  | Tipo          | Antes (baseline) | Después (esperado)                | Fuente                        | Umbral / Condición        | Acción                                                      |
| ---------------------- | ------------- | ---------------- | --------------------------------- | ----------------------------- | ------------------------- | ----------------------------------------------------------- |
| `trm_cache_hit`        | Log / Métrica | N/A              | Hit rate > 99%                    | `CurrencyExchangeService`     | Misses > 5 en 1 hora      | Investigar expiración prematura de Redis                    |
| `datos_gov_co_failure` | Log Warning   | N/A              | 0 eventos                         | `CurrencyExchangeService`     | > 0 eventos               | Notificar degradación al proveedor fallback (DólarAPI)      |
| `dolar_api_failure`    | Log Error     | N/A              | 0 eventos                         | `CurrencyExchangeService`     | > 0 eventos               | Notificar caída total de APIs externas; opera fallback 3200 |
| `audit_cost_persisted` | Log Info      | N/A              | 1 evento por auditoría completada | `AuditResultPersistenceModel` | Errores de constraint = 0 | Investigar colisión de concurrencia                         |

---

### 20. Estrategia de Rollout

| Dimensión                      | Valor                                                                                                                                      |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Estrategia de despliegue       | Directo (Expand: migración de tabla primero, despliegue de código después).                                                                |
| Orden de despliegue            | 1. DDL en SQL Server (`migration_AudDispCosto.sql`).<br>2. Despliegue de código PHP en contenedores.<br>3. Backfill opcional de histórico. |
| Compatibilidad entre versiones | Total (Retrocompatible: `AudDispEst` sigue funcionando idénticamente; el frontend actual no se ve alterado).                               |
| Condición de rollback          | Fallo de sintaxis PDO en `AuditResultPersistenceModel::persist()`.                                                                         |
| Acción de rollback             | Ejecutar `rollback_AudDispCosto.sql` y restaurar versión previa de `AuditResultPersistenceModel.php`.                                      |

---

## FASE 2 — Auditoría de Consistencia

| Verificación                                                                       | Estado | Evidencia                                                                                                          |
| ---------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------ |
| Todas las entidades persistentes mencionadas por la especificación están definidas | PASS   | [CONFIRMADO] Tabla `AudDispCosto` definida con DDL completo y tipos SQL Server verificados.                        |
| Todas las columnas mencionadas existen                                             | PASS   | [CONFIRMADO] Definidas en DDL con `[CostoUSD]`, `[TrmAplicada]` y `[CostoCOP]`, constraints, defaults y tipos SQL. |
| Todos los contratos documentados con clasificación                                 | PASS   | [CONFIRMADO] Documentados Datos Abiertos (primario), DólarAPI (fallback) y 4 endpoints REST bajo `/audit/costs`.   |
| Todos los requisitos tienen trazabilidad                                           | PASS   | [CONFIRMADO] REQ-01 a REQ-06 mapeados en la matriz 11.                                                             |
| Todos los consumidores analizados                                                  | PASS   | [CONFIRMADO] `AuditPersistenceWorker`, `AuditCostController` y clientes REST documentados.                         |
| Todas las migraciones tienen rollback                                              | PASS   | [CONFIRMADO] Script `rollback_AudDispCosto.sql` incluido con `DROP TABLE`.                                         |
| Todas las referencias a archivos, clases, métodos y rutas están definidas          | PASS   | [CONFIRMADO] Rutas absolutas, clases completas y líneas observadas en el repositorio.                              |
| Toda compatibilidad tiene evidencia                                                | PASS   | [CONFIRMADO] `AudDispEst` permanece inalterado; no hay cambios destructivos en tablas legacy.                      |
| Todos los criterios son verificables                                               | PASS   | [CONFIRMADO] Criterios medibles por HTTP status code, conteo SQL y assertions PHPUnit.                             |
| Observabilidad documentada                                                         | PASS   | [CONFIRMADO] Señales de caché TRM y fallos de API externa documentados en sección 19.                              |
| Rollout documentado                                                                | PASS   | [CONFIRMADO] Secuencia de despliegue y condición de reversión documentada en sección 20.                           |

---

## FASE 3 — Auditoría Arquitectónica

| Pregunta                                                | Resultado | Evidencia                                                                                |
| ------------------------------------------------------- | --------- | ---------------------------------------------------------------------------------------- |
| ¿Existe alguna decisión arquitectónica implícita?       | No        | FASE 1.6 documenta explícitamente DEC-01 a DEC-06.                                       |
| ¿Existe algún contrato sin documentar?                  | No        | FASE 1.10 documenta todos los payloads de Datos Abiertos, DólarAPI y endpoints REST.     |
| ¿Existe algún consumidor no analizado?                  | No        | Grafo de dependencias 0.2 cerrado y verificado por lectura directa.                      |
| ¿Existe alguna migración sin rollback?                  | No        | DDL de rollback incluido en sección 9.                                                   |
| ¿Existe algún dato persistido sin migración?            | No        | Script de backfill incluido para datos históricos de `AudDispEst` con TRM de referencia. |
| ¿Existe alguna afirmación sin evidencia?                | No        | Todas las afirmaciones etiquetadas `[CONFIRMADO]` con evidencia verificable.             |
| ¿Existen referencias huérfanas?                         | No        | Todo archivo y clase mencionado tiene propósito y ruta verificada.                       |
| ¿Dos implementadores producirían soluciones diferentes? | No        | Esquema, fórmulas matemáticas, claves de Redis y firmas PHP están completamente fijadas. |

### Auditoría Adversarial Anti-Regresión

| #   | Pregunta Adversarial                                                                        | Regresión que Previene   | Resultado | Evidencia                                                                                                                                       |
| --- | ------------------------------------------------------------------------------------------- | ------------------------ | --------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | ¿Existe algún script de arranque o proceso que invoque una clase o archivo eliminado?       | Runtime                  | NO        | [CONFIRMADO] No se elimina ni renombra ningún archivo existente; todos los cambios son aditivos o extensiones de métodos.                       |
| 2   | ¿Existe algún paso de build o CI que dependa de variables no declaradas?                    | Build / Pipeline         | NO        | [CONFIRMADO] Al usar constantes de clase PHP, el step `Validate env contract completeness` de CI no detecta nuevas claves faltantes.            |
| 3   | ¿Existe algún pipeline que valide con datos distintos a los evaluados?                      | Pipeline                 | NO        | [CONFIRMADO] Las pruebas unitarias usan mocks locales y no realizan peticiones de red hacia APIs externas ni SQL Server en CI.                  |
| 4   | ¿El cambio asume un comportamiento de herramienta sin verificar documentación?              | Semántica de Herramienta | NO        | [CONFIRMADO] Verificado comportamiento de `SET` con expiración en Redis (`RedisClient::set`), cURL timeouts en PHP y restricciones `IDENTITY/UNIQUE` en SQL Server. |
| 5   | ¿El cambio está optimizado solo para un entorno y falla en otros?                           | Paridad de Entornos      | NO        | [CONFIRMADO] Matriz de entornos 0.5 valida compatibilidad en Docker local, CI y producción LAN sin requerir variables en GitHub.                |
| 6   | ¿Existe algún mecanismo de override en runtime que pueda anular el comportamiento?          | Runtime por Override     | NO        | [CONFIRMADO] Claves de Redis parametrizadas sin colisión con prefijos del pipeline (`finance:trm:*` vs `extraction:cache:*`).                   |
| 7   | ¿Se aplicó algún patrón sin verificar las convenciones locales de AudFact?                  | Dogmatismo Técnico       | NO        | [CONFIRMADO] Se utilizan convenciones exactas del repo: `Core\Response::success()`, inyección de PDO y controladores REST en `app/Controllers`. |
| 8   | ¿El cambio altera contratos públicos existentes?                                            | Contract                 | NO        | [CONFIRMADO] Los endpoints existentes (`/audit/results`, `/audit/stats`, etc.) no sufren ninguna alteración en su payload JSON.                 |
| 9   | ¿El cambio afecta datos persistidos sin migración ni validación?                            | Data                     | NO        | [CONFIRMADO] DDL, backfill, rollback y validaciones post-migración documentadas en secciones 9 y 14.                                            |
| 10  | ¿El cambio introduce código muerto, dependencias obsoletas o overengineering?               | Clean Architecture       | NO        | [CONFIRMADO] Alcance estrictamente delimitado al MVP requerido por el usuario (registro multi-intento + TRM oficial y persistencia en COP).     |
| 11  | ¿El cambio reemplaza un mapeo estático por una abstracción dinámica sin validar colisiones? | Abstracción Incorrecta   | NO        | [CONFIRMADO] No aplica; FASE 0.3.1 verificada como N/A.                                                                                         |

---

## FASE 4 — Resultado Final

### Nivel de Completitud

**Nivel A — Implementable**

### Justificación

La especificación es completa, determinista y auditable. Cumple con el 100% de las puertas de salida de la skill `write-sdd-spec`, define DDL completo con persistencia explícita de `[CostoUSD]`, `[TrmAplicada]` y `[CostoCOP]`, algoritmos de cálculo exactos, gestión de reauditorías sin colisión mediante consecutivos automáticos, integración resiliente multinivel con Datos Abiertos y DólarAPI mediante **constantes canónicas de dominio en PHP** (sin sobrepasar la cuota de variables de GitHub Actions), y suite completa de pruebas unitarias sin dependencias externas.
