# AudFact — Sistema de Auditoría Documental Automatizada

## Propósito

Sistema de auditoría documental automatizada para el sector salud colombiano. Compara documentos escaneados (Actas de Entrega) contra datos de dispensación almacenados en SQL Server, utilizando **Google Gemini Flash** como motor de análisis multimodal (IA + OCR).

## Alcance

- Auditoría automatizada de facturas de dispensación farmacéutica
- Validación cruzada de documentos adjuntos contra datos de base de datos
- Detección de fraude y discrepancias administrativas mediante IA
- Integración con Google Drive para almacenamiento documental
- Interfaz MCP para asistentes de IA

## Stack Tecnológico

| Capa | Tecnología |
|---|---|
| **Backend** | PHP 8.2-FPM — Framework MVC custom |
| **Base de datos** | SQL Server (PDO `sqlsrv`) |
| **IA** | Google Gemini Flash API (Guzzle HTTP) |
| **Almacenamiento** | Google Drive (JWT) + BLOB en BD |
| **Web Server** | Nginx 1.25 → PHP-FPM (FastCGI `:9000`) |
| **Contenedores** | Docker Compose (2 servicios: `audfact-php` + `audfact-nginx`) |
| **Frontend** | HTML estático + JS (`AuditBatch.html`, `admin.html`) |
| **Dependencias** | Guzzle 7.x, firebase/php-jwt 7.x |

## Directorios Clave

```
AudFact/
├── app/
│   ├── Controllers/       # 7 controladores REST
│   ├── Models/            # 5 modelos SQL Server
│   ├── Services/          # Google Drive + 6 servicios de auditoría IA
│   ├── worker/            # GeminiAuditService (orquestador)
│   ├── Routes/            # web.php (12 endpoints)
│   └── wrap/              # MCP (webhook + 4 tools)
├── core/                  # 9 módulos framework (Router, DB, Validator...)
├── public/                # index.php (entry point) + frontend HTML
├── docker/                # Dockerfile + nginx.conf
├── logs/                  # Logs rotados por fecha
└── plans/                 # Documentación del proyecto
```

## Getting Started

### Prerrequisitos

- Docker + Docker Compose
- SQL Server con base de datos de dispensación
- Cuenta Google Cloud con API Gemini habilitada
- Credenciales de servicio Google Drive (JSON)

### Instalación

```bash
# 1. Clonar y configurar
cp .env.example .env
# Editar .env con credenciales BD, Gemini, Google Drive

# 2. Instalar dependencias
composer install

# 3. Levantar con Docker
docker-compose up -d
```

### Ejecución Local

```bash
# Con Docker (recomendado)
docker-compose up -d
# API disponible en http://localhost:8080

# Sin Docker (desarrollo)
php -S localhost:8000 -t public/
```
