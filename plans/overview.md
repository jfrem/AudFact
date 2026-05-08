# AudFact — Sistema de Auditoría Documental Automatizada

## Propósito

Sistema de auditoría documental automatizada para el sector salud colombiano. Compara documentos escaneados (Actas de Entrega) contra datos de dispensación almacenados en SQL Server, utilizando **Google Gemini API** como motor de análisis multimodal (IA + OCR), con modelo configurable desde entorno.

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
| **IA** | Google Gemini API (Guzzle HTTP, modelo configurable) |
| **Almacenamiento** | Google Drive (JWT) + BLOB en BD |
| **Web Server** | Nginx 1.25 → PHP-FPM (FastCGI `:9000`) |
| **Contenedores** | Docker Compose (backend, worker, redis y frontend en compose separado) |
| **Frontend** | Next.js 16 (App Router) + React 19 |
| **Dependencias** | Guzzle 7.x, firebase/php-jwt 7.x |

## Directorios Clave

```
AudFact/
├── app/
│   ├── Controllers/       # 11 controladores HTTP (incluye base)
│   ├── Models/            # 7 modelos SQL Server (incluye base)
│   ├── Services/          # Google Drive + pipeline event-driven de auditoría IA
│   ├── Routes/            # web.php (25 endpoints)
│   └── wrap/              # MCP (webhook + 4 tools)
├── frontend/              # Frontend Next.js
├── bin/                   # Worker CLI (audit-worker.php)
├── core/                  # 9 módulos framework (Router, DB, Validator...)
├── public/                # index.php (entry point API)
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

# 3. Levantar backend con Docker
docker compose up -d

# 4. Levantar frontend
docker compose -f docker-compose.frontend.yml up -d
```

### Ejecución Local

```bash
# Con Docker (backend)
docker compose up -d
# API disponible en http://localhost:8080

# Frontend
docker compose -f docker-compose.frontend.yml up -d
# Frontend disponible en http://localhost:3000

# Sin Docker (desarrollo)
php -S localhost:8000 -t public/
```
