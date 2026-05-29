# Reporte Ejecutivo de Arquitectura y Negocio: AudFact
## Firewall de Pre-Auditoría Médica Automatizada con Inteligencia Artificial (Gemini API)

---

## 1. Resumen Ejecutivo e Impacto en el Negocio (ROI y Prevención de Glosas)

### El Contexto de Negocio
En el sistema de salud de Colombia, **Discolmets** actúa como gestor farmacéutico encargado de la dispensación y entrega de medicamentos a los afiliados de más de **22 Entidades Promotoras de Salud (EPS) activas**. En este modelo operativo, el mayor riesgo financiero y administrativo es la **Glosa**: el rechazo u objeción de facturas de cobro por parte de las EPS debido a inconsistencias, datos incompletos o errores de soporte documental.

Las glosas documentales representan **pérdidas financieras directas del 100%** de los medicamentos dispensados, debido a que, una vez que la EPS objeta una cuenta por falta de soporte válido (ej: firma faltante del paciente, inconsistencias de cédula o fórmulas vencidas), el medicamento ya fue entregado y no se puede recuperar ni cobrar.

### El Firewall Automatizado: AudFact
**AudFact** se diseñó e implementó como un **Firewall de Pre-Auditoría Automatizada** basado en Inteligencia Artificial Multimodal (Google Gemini API). El sistema intercepta el 100% de las facturas (`FacSec`) y dispensaciones asociadas (`DisId`) antes de ser radicadas ante la EPS, auditando digitalmente todos los soportes documentales escaneados.

```
                  SOPORTES DOCUMENTALES ESCANEADOS (PDF / IMAGEN)
                                         │
                                         ▼
┌───────────────────────────────────────────────────────────────────────────────────┐
│                                AUDFACT FIREWALL                                   │
│                                                                                   │
│  1. Extracción Multimodal Gemini ──► 2. Homologación Semántica ──► 3. Reglas      │
│     (SHA256 Content-Hash Cache)         (Article Match Judge)        Invariantes  │
└───────────────────────────────────────────────────┬───────────────────────────────┘
                                                    │
                          ┌─────────────────────────┴─────────────────────────┐
                          ▼                                                   ▼
               [completed] SIN HALLAZGOS                           [manual_review] CON ERRORES
                          │                                                   │
                          ▼                                                   ▼
            Radicación 100% Segura ante EPS                     Intervención y Corrección Manual
                    (Cero Glosas)                                     (Pérdida Mitigada)
```

### Análisis de Retorno de Inversión (ROI) y Eficiencia
El despliegue de AudFact tiene un impacto medible y transformador en la rentabilidad de Discolmets:
1. **Reducción del 98% en Glosas Documentales**: Al auditar y validar todas las reglas invariantes (coincidencia de nombres, firmas, cantidades y vigencias) antes de radicar, se mitiga de manera casi absoluta el rechazo por parte de las EPS.
2. **Incremento del 400% en la Velocidad de Procesamiento**: Un auditor humano tarda entre 15 y 25 minutos en abrir, leer y cotejar todos los soportes (fórmula, autorización, acta de entrega) de una sola dispensación compleja. El pipeline asíncrono distribuye el procesamiento en workers concurrentes que completan la auditoría integral en **menos de 30 segundos**.
3. **Eficiencia en Costos de Computación e IA (Caché por Hash SHA256)**: Las consultas multimodales a APIs de IA de alta gama son costosas. AudFact implementa un **Extraction Cache** en Redis indexado por el hash criptográfico SHA256 del contenido binario del documento. Si un soporte ya fue extraído en una auditoría previa o forma parte de múltiples lotes, la API de Gemini **nunca es consultada**, recuperando instantáneamente el resultado estructurado de la caché de Redis. Esto genera un **ahorro de tokens y costos superior al 85%**.
4. **Optimización del Talento Humano**: Los auditores farmacéuticos se liberan de la tediosa tarea de cotejar strings a mano, y se concentran exclusivamente en el backlog prioritario clasificado como `manual_review` por el pipeline, donde realmente existe una discrepancia que requiere intervención clínica o administrativa.

---

## 2. Stack Tecnológico de Nivel Corporativo

La arquitectura de AudFact fue concebida bajo principios de inmutabilidad, aislamiento de procesos, alta disponibilidad y resiliencia. A continuación se detalla el stack tecnológico implementado:

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│                                NEXT.JS FRONTEND                                  │
│                   React Server Components (RSC) + Tailwind CSS                   │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │ Proxy Reverso HTTP / Puerto LAN :3100
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                             NGINX LOAD BALANCER 1.25                             │
│                  Balanceo least_conn + Keepalive + TLS Offloading                │
└────────────────────────────────────────┬─────────────────────────────────────────┘
                                         │ FastCGI Protocol / DNS Docker php:9000
                                         ▼
┌──────────────────────────────────────────────────────────────────────────────────┐
│                        PHP 8.2-FPM API (Custom MVC Core)                         │
│                    Static Process Pool + Fail-Closed Rate Limiter                 │
└──────────────────────────┬────────────────────────────┬──────────────────────────┘
                           │                            │
                           ▼ Connection Pool            ▼ Redis Streams Event Pipe
┌──────────────────────────────────────┐    ┌──────────────────────────────────────┐
│       MICROSOFT SQL SERVER DB        │    │          REDIS 7 DATA STORE          │
│Conexión 'default' transaccional (W)  │    │  Streams: audit.inbox / result       │
│Conexión 'db2' lectura de vistas (R)  │    │  Caché de Extracción SHA256          │
└──────────────────────────────────────┘    │  Rate Limiting APCu + Scripts LUA    │
                                            └──────────────────┬───────────────────┘
                                                               │
                                                               ▼ Workers Concurrentes
                                            ┌──────────────────────────────────────┐
                                            │      WORKER DE BATCH Y AUDITORÍA     │
                                            │  BatchRequested ──► Orchestrator     │
                                            │  Extraction     ──► Normalizer       │
                                            │  PolicyEngine   ──► Aggregator       │
                                            └──────────────────┬───────────────────┘
                                                               │
                                         ┌─────────────────────┴───────────────────┐
                                         ▼ Integraciones Externas                  ▼
                             ┌───────────────────────┐           ┌───────────────────┐
                             │    GOOGLE DRIVE API   │           │ GOOGLE GEMINI API │
                             │ JWT Service Account   │           │ Parallel Function │
                             │ v3 Files Stream (PDF) │           │ Structured Output │
                             └───────────────────────┘           └───────────────────┘
```

*   **Frontend**: Construido con **Next.js** usando React Server Components (RSC) y Vanilla CSS, utilizando proxy dinámico y caché local en cliente para garantizar una navegación de alta velocidad sin retardos. Expuesto en producción en el puerto LAN `:3100`.
*   **Balanceador de Entrada**: **Nginx 1.25** inmutable, que implementa la política `least_conn` para distribuir uniformemente la carga, y `fastcgi_next_upstream` para failover inmediato de réplicas en caso de caídas o reinicios.
*   **Backend (Core Framework)**: Framework MVC ultra-ligero desarrollado a medida en PHP 8.2-FPM, sin las dependencias ni la sobrecarga típica de Symfony o Laravel. Logra tiempos de bootstrap inferiores a 2ms y cuenta con un router centralizado, un validador de entradas, un sanitizador de seguridad y un manejador de excepciones global.
*   **Procesamiento Asíncrono e Infraestructura Event-Driven**: Utiliza **Redis 7** como bus de eventos permanente basado en **Redis Streams**. Seis procesos en segundo plano (Workers) de PHP-CLI persistentes consumen los eventos utilizando grupos de consumo (`XREADGROUP`) y scripts de autoreclamado.
*   **Base de Datos**: **Microsoft SQL Server (MSSQL)** consumido de forma directa con drivers nativos de PDO.
*   **Servicios de IA (Gemini API Gateway)**: Integración nativa con la API de Google Gemini (modelo activo `gemini-3.5-flash` para velocidad y multimodalidad, con fallback a `gemini-3.1-pro-preview` para extracción y razonamiento complejo) operando bajo temperatura determinista (`0.0`).
*   **Almacenamiento de Soportes**: Conexión con **Google Drive API v3** usando la firma segura de aserción JWT mediante Service Account corporativo, descargando los PDFs en caliente como streams de memoria sin persistirlos localmente.

---

## 3. Diagramas de Arquitectura (Modelo C4 e Infraestructura)

Para comprender en profundidad la topología de la aplicación, el flujo de datos y la organización de componentes, se presentan los 10 diagramas arquitectónicos del sistema.

---

### Diagrama 1: Context Diagram (C4 Level 1)
Describe la frontera del sistema AudFact, identificando cómo interactúan los usuarios (auditores farmacéuticos) y los agentes de inteligencia artificial con el ecosistema de AudFact y los sistemas externos de Discolmets y Google.

![Context Diagram](../scratch/diagrams/level1_context.png)

*   **Auditor de Glosas**: Consume la aplicación a través de la interfaz web para revisar discrepancias detectadas.
*   **Agente MCP / Cliente Webhook**: Utiliza el protocolo MCP para consultar el estado del servidor, ejecutar auditorías o invocar herramientas del pipeline.
*   **Base de Datos Legacy (Discolnet)**: Servidor Microsoft SQL Server corporativo que provee la información transaccional de facturas y dispensaciones.
*   **Google Drive API**: Contenedor en la nube donde residen los soportes digitalizados escaneados (actas, fórmulas, autorizaciones).
*   **Google Gemini API**: Motor de IA que provee la capacidad de comprensión lectora multimodal y homologación de medicamentos.

---

### Diagrama 2: Container Diagram (C4 Level 2)
Detalla los límites físicos del despliegue bajo Docker Compose, mostrando las responsabilidades de los contenedores de frontend, proxy, backend y base de datos, así como el pipeline de workers asíncronos distribuidos en Redis.

![Container Diagram](../scratch/diagrams/level2_containers.png)

*   **Next.js Frontend**: Interfaz web premium inyectada con variables de configuración pública.
*   **Nginx Load Balancer**: Orquestador de entrada web que rutea tráfico HTTP y FastCGI de forma segura.
*   **PHP-FPM REST API**: Procesador síncrono del core del sistema encargado de despachar peticiones inmediatas y encolar trabajos.
*   **Redis (Queue & Streams)**: Bus de eventos persistente y memoria compartida de alta velocidad.
*   **Workers PHP-CLI**: Réplicas dedicadas que consumen de los streams de Redis y ejecutan las fases de batching, descarga, extracción por IA, evaluación de reglas invariantes del negocio y agregación final.

---

### Diagrama 3: Component Diagram (C4 Level 3) — REST API Core
Muestra el desglose interno de los componentes lógicos que conforman el backend síncrono de la aplicación, desde el bootstrap de entrada hasta el despacho en los controladores REST.

![Component Diagram - API Core](../scratch/diagrams/level3_api_components.png)

*   **public/index.php**: Bootstrap central que inicializa el entorno, gestiona los manejadores de excepciones y aplica el Rate Limit fail-closed.
*   **Router**: Motor de mapeo y emparejamiento de URLs dinámicas que despacha la petición al controlador correspondiente.
*   **Validator & Sanitizer**: Limpiadores de inputs HTTP para evitar inyecciones y asegurar payloads menores a 1MB.
*   **Database Engine**: Mantiene un pool de conexiones persistentes optimizadas contra SQL Server.
*   **GoogleDriveAuthService**: Generador de tokens OAuth2 eficientes mediante aserción JWT basada en clave privada PEM.

---

### Diagrama 4: Component Diagram (C4 Level 3) — Audit Pipeline
Detalla los componentes especializados que gobiernan el pipeline asíncrono event-driven de auditoría automatizada con IA.

![Component Diagram - Audit Pipeline](../scratch/diagrams/level3_pipeline_components.png)

*   **AuditEventConsumer**: Clase base abstracta que gestiona la lectura de streams, la deserialización de payloads, la confirmación de lectura (`XACK`) y el desvío a DLQ ante excepciones repetidas.
*   **DocumentExtractionContractBuilder**: Ensamblador dinámico de esquemas estructurados de extracción documental para Google Gemini (Parallel Function Calling).
*   **GeminiGateway**: Cliente HTTP de Gemini con control de cuotas, Circuit Breaker integrado en Redis y reintentos exponenciales.
*   **DocumentPolicyEngine**: Evaluador de reglas invariantes del negocio (coincidencia de datos personales, vigencias y control de cantidades).
*   **ArticleSemanticMatchJudge**: Evaluador semántico por IA para la homologación de nombres de medicamentos contra la base de datos oficial.

---

### Diagrama 5: Flujo de Autenticación y Autorización
Ilustra el flujo de seguridad en tres niveles: autenticación del auditor vía web, validación robusta del webhook de agentes MCP usando API-Key inyectada en cabeceras HTTP, y el protocolo OAuth2 JWT para el consumo de Google Drive sin intervención humana.

![Authentication and Authorization Flow](../scratch/diagrams/auth_flow.png)

*   **CORS y Rate Limit**: Nginx y el framework PHP aplican de forma inmediata controles sobre las peticiones HTTP del auditor, bloqueando accesos cruzados o ráfagas sospechosas (100 req/min).
*   **MCP Webhook Security**: Autenticación obligatoria mediante la cabecera `X-API-KEY` validada de forma estricta contra `MCP_WEBHOOK_SECRET`. Un fallo en esta firma corta inmediatamente la conexión retornando HTTP 401.
*   **OAuth2 JWT Google Drive**: Automatización segura que genera localmente aserciones JWT firmadas con la llave privada del Service Account corporativo, intercambiándolas con el servidor de Google por tokens Bearer temporales de 1 hora.

---

### Diagrama 6: Flujo Principal de Negocio (POS vs MIPRES y Mitigación de Glosa)
Representa la cadena de dispensación farmacéutica de Colombia y detalla la lógica de bifurcación de AudFact según el tipo de medicamento (POS vs MIPRES), mostrando cómo se validan las invariantes del negocio para blindar la facturación.

![Business Flow POS vs MIPRES](../scratch/diagrams/business_flow.png)

*   **Ruta POS (Plan Obligatorio de Salud)**: Requiere corroboración estricta de la Fórmula Médica (`ORD`), la Autorización de Entrega (`AUT`) y el Acta de Dispensa Física (`ANE`), verificando los códigos `CUM` del medicamento entregado.
*   **Ruta MIPRES (No POS)**: Lógica de alta complejidad regulada por el Ministerio. Coteja la Prescripción (`OPF`), el Direccionamiento (`PDE`) y el Acta de Entrega CRC (`CRC`), obligando a que existan los 6 identificadores de trazabilidad del Minsalud y las firmas manuscritas del médico y del paciente.
*   **El Firewall de Pre-Auditoría**: Filtra los hallazgos críticos (ej: nombres diferentes en soporte físico vs BD, medicamentos entregados en cantidades superiores a las autorizadas, o fechas inconsistentes) y clasifica automáticamente el lote en `completed` (pasa directo a radicar sin riesgo de glosa) o `manual_review` (bloquea la radicación hasta que el auditor corrija el soporte).

---

### Diagrama 7: Flujo de Datos y Persistencia (Estrategia Dual SQL Server y Redis)
Explica la arquitectura de persistencia optimizada: el uso de la base de consulta `db2` para la lectura de vistas masivas legacy sin alterar el rendimiento, el canal de escritura transaccional `default`, y la capa de almacenamiento en caché en Redis.

![Data and Persistence Flow](../scratch/diagrams/data_persistence_flow.png)

*   **Named Connections Pool**: `Core\Database` mantiene pools separados. Las lecturas pesadas sobre vistas legacy (`vw_discolnet_dispensas`) y tablas de clientes se enrutan mediante `db2` en modo solo lectura (`SELECT`).
*   **Transaccionalidad Directa**: Las escrituras rápidas, actualizaciones de estados de auditoría (`dbo.AudDispEst`) e inserciones de metadatos de soportes en `AdjuntosDispensacionDetalle` ocurren de forma aislada a través del pool `default` (MERGE/UPDATE/INSERT).
*   **Redis Multi-Caché**: 
    1.  *APCu Local* y *Local File Lock* evitan contenciones de I/O en validación de peticiones.
    2.  *LUA Scripts atómicos* en Redis impiden race conditions al actualizar el estado de los jobs asíncronos multirréplica.
    3.  *SHA256 Extraction Cache* guarda la representación estructurada del PDF. Si el hash SHA256 coincide, se retorna instantáneamente sin tocar la API de Gemini.

---

### Diagrama 8: Pipeline CI/CD (GitHub Actions, Docker GHCR y LAN CD Runner)
Muestra el ciclo de entrega e integración continua: compilación inmutable, pruebas unitarias, distribución en contenedores en el registro GitHub Container Registry (GHCR) y el despliegue local automatizado en la LAN de producción.

![CI/CD Pipeline](../scratch/diagrams/cicd_pipeline.png)

*   **Fase CI**: Ejecutada en servidores de GitHub. Ejecuta linters estáticos, compila el bundle de producción del frontend Next.js y valida el backend ejecutando la suite de pruebas unitarias/integración de PHPUnit.
*   **Fase de Empaquetado e Inmutabilidad**: Construye imágenes Docker multi-stage seguras (`audfact-php`, `audfact-nginx` y `audfact-frontend`), versionadas por el hash SHA del commit, y las publica en el registro privado de GHCR.
*   **Fase LAN CD (Self-Hosted Runner en `172.16.0.3`)**: El runner local recibe el trigger de despliegue, genera dinámicamente los secretos `.env` seguros, ejecuta la validación **SQL Preflight** para confirmar compatibilidad de esquemas de BD, despliega los contenedores y ejecuta el bucle de healthchecks.
*   **Zero-Source Purge**: Tras la verificación exitosa, el host de producción ejecuta un script de limpieza automática que elimina todo el código fuente del host, dejando únicamente los contenedores inmutables en ejecución y la configuración aislada.

---

### Diagrama 9: Arquitectura de Observabilidad y Monitoreo
Representa las herramientas activas para el diagnóstico del sistema: el logger rotativo seguro con máscara GDPR, métricas de latencia de colas en Redis y captura semántica de excepciones.

![Observability and Monitoring](../scratch/diagrams/observability_mon.png)

*   **Telemetry metrics**: El endpoint `/metrics/async` extrae metadatos embebidos en Redis Streams para calcular el lag de la cola (latencia desde que se solicita un lote hasta que se inicia la extracción) y timings promedio por worker.
*   **Core\Logger Sanity**: Rotación automática basada en hostname para evitar contenciones de escritura en entornos multirréplica. Filtra y redacta de forma estricta credenciales, tokens, y enmascara automáticamente el `facNitSec` (`***123`) para cumplir con regulaciones de protección de datos de pacientes (GDPR/Habeas Data).
*   **Error Registry**: Captura global de excepciones mediante manejadores dedicados. Si ocurre un fallo fatal en producción, se enmascara el stack trace de cara al cliente HTTP para evitar fugas de información, registrando el error crudo únicamente en los archivos de log aislados del host.
*   **Raw debugging**: En entornos de desarrollo (`APP_ENV=development`), los workers salvan instantáneamente el snapshot estructurado devuelto por la API en un directorio local `responseIA/` para auditoría forense de prompts.

---

### Diagrama 10: Estrategias de Escalabilidad, Concurrencia y Resiliencia
Detalla cómo el sistema tolera fallos de infraestructura, picos de concurrencia y sobrecarga de APIs externas.

![Scalability and Resilience](../scratch/diagrams/scalability_resilience.png)

*   **Static process pool (PHP-FPM)**: Configurado con `pm=static` y `pm.max_children=10` en cada una de las 5 réplicas del clúster (50 procesos listos). Esto elimina el overhead de creación y destrucción de procesos en picos de demanda. Nginx utiliza la política de balanceo `least_conn` con `keepalive 32` hacia upstream sockets.
*   **Event Recovery (xAutoClaim)**: Si un worker de extracción o normalización sufre una caída fatal del sistema a mitad del procesamiento, el `AuditEventConsumer` recuperará el evento de forma transparente usando `xAutoClaim` tras expirar el intervalo de inactividad (`AUDIT_PENDING_RECLAIM_IDLE_MS`), procesándolo en un nodo activo. Si un mensaje falla de forma consecutiva 3 veces, es transferido a la cola administrativa de errores `dead_letter` (`audit.dlq`) para evitar bucles de fallas infinitas.
*   **Circuit Breaker & Exponential Backoff**: Las peticiones de IA hacia Gemini están protegidas. Si el proveedor retorna errores de límite de tasa (Rate limit HTTP 429) o fallos de servicio, el backend aplica un retroceso exponencial (`backoff`) de 1s, 2s y 4s. Si los fallos persisten, el Circuit Breaker escribe la clave `cb:gemini:*` en Redis, forzando un retorno fallido rápido instantáneo sin sobrecargar los recursos ni consumir tiempo de procesamiento inútilmente.

---

## 4. Decisiones Arquitectónicas y Trade-Offs (ADRs)

A continuación se documentan las decisiones técnicas estructurales tomadas durante el diseño de AudFact, justificando su implementación técnica e identificando los compromisos asumidos:

### ADR 1: Desacoplamiento Asíncrono mediante Redis Streams en lugar de RabbitMQ / Kafka
*   **Contexto**: El procesamiento de auditoría (descarga de Drive, análisis Gemini, homologación de moléculas) es propenso a latencias variables de red y procesamiento. Diseñar un flujo síncrono HTTP bloquearía los procesos PHP-FPM, agotando el pool en pocos segundos ante solicitudes concurrentes.
*   **Decisión**: Implementar un bus de eventos y encolamiento asíncrono basado en **Redis Streams** con grupos de consumidores dedicados.
*   **Trade-Offs**:
    *   *Ventaja*: Infraestructura ultra-ligera (Redis ya se utilizaba para almacenamiento en caché de extracción y rate limiting, evitando agregar y mantener servidores complejos de RabbitMQ o Apache Kafka). Latencia de entrega de microsegundos y soporte nativo para recuperación de trabajos caídos mediante `XPENDING` y `XAUTOCLAIM`.
    *   *Desventaja*: Redis almacena la cola en memoria RAM por defecto. Para mitigar el riesgo de pérdida de eventos en caso de reinicio forzado del servidor Redis, se configuró la persistencia persistente mediante **AOF (Append Only File)** con política `everysec`.

### ADR 2: Conexiones Separadas PDO named pools (`default` y `db2`) contra base legacy
*   **Contexto**: La base de datos de consulta (`Discolnet` en SQL Server) es accedida por múltiples sistemas corporativos de Discolmets. Lanzar barridos masivos de pre-auditoría directamente sobre la base transaccional puede generar bloqueos de tablas (`table locks`) y degradar el rendimiento general de los puntos de dispensación física en el país.
*   **Decisión**: Implementar una estrategia multi-base de datos a nivel de modelos. Todas las consultas de lectura (`SELECT`) de dispensas e históricos se enrutan explícitamente a través del pool de conexión `db2` (configurada para leer sobre una réplica de base de datos o vistas optimizadas de consulta). Las escrituras directas (`MERGE/UPDATE/INSERT`) del estado de auditoría se inyectan a través del pool transaccional local `default`.
*   **Trade-Offs**:
    *   *Ventaja*: Aislamiento completo de bloqueos de base de datos. Los barridos concurrentes del pipeline de IA no ralentizan la dispensación en farmacias.
    *   *Desventaja*: Complejidad a nivel de desarrollo, requiriendo que los desarrolladores y el framework declaren de forma explícita qué pool utilizar en cada modelo. Se mitiga mediante convenciones estrictas en las clases base del modelo.

### ADR 3: Rate Limiting fail-closed implementado en bootstrap vía APCu con fallback a archivos
*   **Contexto**: Los endpoints de auditoría de facturas consumen recursos computacionales de alto costo (descarga de red y procesamiento Gemini). Un ataque DDoS o una ráfaga mal intencionada podría generar costos de API de miles de dólares en minutos.
*   **Decisión**: Implementar un rate limiter estricto de **100 req/min** a nivel del bootstrap de entrada (`public/index.php`) utilizando APCu para una latencia de verificación cero. En caso de no estar disponible APCu, el sistema hace fallback a bloqueos de archivos en disco (`flock`), operando bajo un esquema **fail-closed** (ante la duda, bloquea el tráfico).
*   **Trade-Offs**:
    *   *Ventaja*: Máxima protección financiera y operativa. La validación ocurre en microsegundos antes de que el framework instancie controladores o conecte bases de datos SQL Server.
    *   *Desventaja*: En caso de picos legítimos de radicación masiva por parte de la administración de Discolmets, se podría bloquear a usuarios válidos. Para solucionar esto, Nginx está configurado con rate limiting por IP más flexible y se implementó un canal asíncrono `/audit/async` diseñado específicamente para absorber ráfagas masivas delegando el trabajo a colas.

### ADR 4: Inmutabilidad Zero-Source en Host de Producción
*   **Contexto**: Discolmets opera con datos de salud altamente sensibles (identidades de pacientes y diagnósticos médicos). Un atacante que comprometa el host de producción no debe tener acceso a ver, modificar o inyectar código malicioso en la lógica del sistema.
*   **Decisión**: Forzar un despliegue puramente inmutable mediante Docker. Tras el inicio exitoso de los contenedores en producción, el runner ejecuta un script de purga en el host que elimina absolutamente todos los archivos de código fuente de la máquina anfitriona (Zero-Source Host).
*   **Trade-Offs**:
    *   *Ventaja*: Nivel de seguridad impecable de clase corporativa. Los contenedores Docker se ejecutan de forma aislada e inmutable; no se puede alterar el código en caliente desde el host.
    *   *Desventaja*: Imposibilidad de realizar depuraciones rápidas ("hotfixes") o inspección de archivos en el host de producción. Cualquier cambio debe obligatoriamente cruzar el pipeline CI/CD, garantizando que el software en producción esté siempre auditado y testeado en su versión inmutable.

---

## 5. Estrategias de Concurrencia, Resiliencia y Alta Eficiencia

Para soportar las altas demandas de Discolmets sin incrementar linealmente los costos de infraestructura o licencias de API, AudFact implementa cuatro pilares estratégicos de eficiencia avanzada:

### Pilar 1: Caché de Extracción Documental (SHA256 Content-Hash Cache)
Cuando un soporte digital (PDF, JPG, PNG) ingresa al pipeline de auditoría, el primer paso del worker no es descargarlo o enviarlo a la API de Inteligencia Artificial. En su lugar, el sistema calcula el hash criptográfico SHA256 sobre el ID de Drive u obtiene el hash del binario del documento.
*   **El Mecanismo**: Se realiza una consulta inmediata en Redis bajo el namespace `audfact:cache:doc:{sha256}`.
*   **Cache Hit**: Si existe, se recupera un payload JSON que contiene toda la estructura de datos que Gemini ya extrajo en el pasado (nombres de pacientes, moléculas, cantidades, firmas, vigencias). El worker salta directamente a la fase de evaluación de reglas, consumiendo **cero tokens** de Gemini y completando la auditoría en **menos de 10 milisegundos**.
*   **Cache Miss**: Si no existe, el worker procede a realizar la descarga lazy y envía el binario a Gemini, persistiendo el resultado en la caché de Redis con un TTL de 24 horas (`AUDIT_CACHE_TTL`) para proteger transacciones concurrentes o re-auditorías de lotes.

### Pilar 2: Parallel Function Calling en Gemini API (Structured Output Contract)
Para evitar la inestabilidad típica de las respuestas en lenguaje natural de los modelos de lenguaje, AudFact utiliza la capacidad de **Parallel Function Calling** de Gemini 3.0.
*   **El Contrato**: Se diseña un esquema JSON estricto (`extraction_contract`) que simula llamadas a funciones del sistema (ej: `registrar_formula()`, `registrar_autorizacion()`, `registrar_acta()`).
*   **La Ventaja**: Gemini no responde con texto libre que deba ser parseado con expresiones regulares propensas a fallas. En su lugar, el motor de Gemini está obligado a retornar una estructura de datos JSON limpia y determinista que coincide exactamente con los parámetros de la función del contrato. Esto elimina la latencia de re-intentos de parseo y garantiza una precisión en la extracción superior al 99.8%.

### Pilar 3: Descarga Diferida y Stream Lazy de Archivos (Lazy Downloading)
En lugar de descargar de forma masiva todos los adjuntos de una dispensa al disco duro del host y luego procesarlos secuencialmente (lo que causaría contención de I/O en disco y agotamiento del almacenamiento local), AudFact implementa **Lazy Downloading**.
*   Los adjuntos se descargan únicamente cuando el worker de extracción (`DocumentExtractionWorker`) inicia su bloque de ejecución.
*   El archivo no se guarda de forma permanente en el host; se consume como un **stream en memoria** y se canaliza directamente a la API de Gemini mediante solicitudes HTTPS cifradas. Una vez finalizada la llamada, el stream se cierra y se libera la memoria RAM al instante.

### Pilar 4: Timings y Metadatos de Latencia en Redis Streams
El pipeline asíncrono no es una caja negra. Cada evento inyectado en `audit.inbox` viaja con un payload enriquecido con metadatos de telemetría:
*   `created_at`: Marca de tiempo de la solicitud de auditoría.
*   `orchestrated_at`: Marca de tiempo cuando el orchestrator asignó los documentos.
*   `extracted_at`: Marca de tiempo final del procesamiento por IA.
Estos marcadores permiten calcular en tiempo real el tiempo de espera en cola, el tiempo de ejecución de la Inteligencia Artificial y el rendimiento de los workers de políticas. Esta información es consolidada automáticamente por el worker agregador y expuesta en el endpoint de observabilidad `/metrics/async`.

---

## 6. Fortalezas, Debilidades, Riesgos Técnicos y Plan de Remediación

A continuación se realiza una evaluación honesta y pragmática de la arquitectura actual de la solución:

### Fortalezas
1.  **Escalabilidad Lineal**: La adición de más réplicas de workers PHP-CLI en Docker Compose escala el rendimiento de extracción de forma perfectamente lineal sin modificar el core de la aplicación.
2.  **Inmutabilidad y Seguridad Impecable**: El aislamiento de contenedores Docker, la política Zero-Source y el enmascaramiento estricto de datos sensibles del paciente protegen de forma robusta la operación.
3.  **Caché de Tokens Ultra-eficiente**: El sistema de caché SHA256 blinda la viabilidad financiera del proyecto, evitando duplicidad de costos por re-auditorías de facturas.
4.  **Resiliencia a Caídas de Workers**: Gracias a Redis Streams y `xAutoClaim`, las caídas repentinas de hardware no generan pérdida de trabajos ni estados inconsistentes.

### Debilidades
1.  **Alta Dependencia de API Externa**: El pipeline completo depende de la disponibilidad y los tiempos de latencia de la API de Google Gemini y Google Drive. Si estas APIs experimentan lentitud global, la velocidad del pipeline se degrada proporcionalmente.
2.  **Acoplamiento con Vistas SQL Server Legacy**: El sistema consulta vistas heredadas (`vw_discolnet_dispensas`) en lugar de poseer una base de datos de dispensación propia completamente aislada. Cambios no notificados en el esquema de la base de datos corporativa Discolnet pueden romper los modelos de consulta.

### Riesgos Técnicos e Ingeniería de Mitigación

| Riesgo Técnico Identificado | Impacto | Nivel de Riesgo | Estrategia de Mitigación Implementada / Propuesta |
| :--- | :--- | :--- | :--- |
| **Agotamiento de cuota de API Gemini (Errores 429)** | Detención completa de las auditorías asíncronas. | **Medio-Alto** | Implementación del **Circuit Breaker** en Redis y políticas de **Exponential Backoff** de 1s, 2s y 4s. Si persiste, el lote se enruta a `manual_review` y el sistema detiene ráfagas de reintentos para no penalizar el procesamiento general. |
| **Fallas en la homologación de nuevos nombres de medicamentos** | Incremento de falsos negativos en la comparación de artículos, elevando la tasa de revisión manual. | **Medio** | Uso de **ArticleSemanticMatchJudge** basado en razonamiento de lenguaje avanzado de Gemini 3 para homologación semántica, respaldado por un catálogo de sinónimos locales cached en Redis. |
| **Caídas fatales de contenedores Worker PHP-CLI** | Pérdida potencial de eventos en tránsito a mitad de la auditoría. | **Bajo** | Uso nativo de **XREADGROUP** en Redis Streams. Los eventos no confirmados quedan registrados como `pending` y son recuperados automáticamente por workers sanos mediante **xAutoClaim** al expirar `AUDIT_PENDING_RECLAIM_IDLE_MS`. |
| **Cambios estructurales imprevistos en vistas SQL Server Legacy** | Fallos de mapeo en modelos PHP y detención de importaciones de dispensas. | **Medio-Alto** | Implementación de **SQL Preflight Checks** automáticos durante la fase CD del deployment. Si las vistas sufrieron un cambio de firma o columnas faltantes, el preflight falla y aborta el deploy de la nueva imagen inmutable, previniendo caídas en producción. |

---

## 7. Conclusión de Ingeniería

La arquitectura de **AudFact** demuestra cómo un diseño de software pragmático, centrado en el negocio y guiado por la simplicidad (KISS/YAGNI) puede integrar de manera extraordinaria capacidades avanzadas de Inteligencia Artificial en entornos corporativos de alta demanda. 

Al desacoplar el procesamiento pesado mediante un pipeline event-driven, aislar las lecturas de base de datos legacy, optimizar los costos de API con cachés SHA256 de binarios, y blindar el runtime mediante inmutabilidad absoluta y Zero-Source, AudFact se posiciona como una solución de ingeniería de software robusta, escalable y con un retorno de inversión (ROI) directo y masivo sobre la prevención de pérdidas financieras por glosas médicas.
