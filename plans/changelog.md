# Changelog AudFact

## [2026-03-17]

### Feature (Pipeline IA)
- **Ámbito**: Implementación de Schema Dinámico para Gemini
  - Archivos modificados: `AuditResponseSchema.php`, `GeminiGateway.php`, `AuditOrchestrator.php`, `AuditPromptBuilder.php`
  - Detalles: El pipeline de auditoría ahora extrae dinámicamente los nombres de los documentos (ej. `DISPENSA`, `FORMULA MEDICA`) directamente de la base de datos `AdjuntosDispensacion` y los inyecta en el JSON Schema de Gemini. Esto fuerza a la IA a responder con nomenclatura 100% idéntica a la BD, eliminando los fallos de conciliación en el modelo `AuditStatusModel` por el uso de nomenclatura SNAKE_CASE impuesta previamente.
  - Hito: Sincronización de skills P2.5 (Schema Dinámico).

## [2026-03-10]

### Rediseño Visual Premium (Dashboard)
- **UI/UX Holística**: Se implementó un rediseño visual completo basado en referentes de alta gama (Falcon, Label, Corona).
- **Tema Deep Navy**: Paleta de colores profesional (`oklch 0.11`) para reducir fatiga visual y mejorar contraste.
- **Micro-interacciones**: Se agregaron efectos de "glow border", elevación de tarjetas en hover y animaciones de entrada (`scale-in`, `shimmer`).
- **Nuevos Componentes**: KPI Cards rediseñadas con gradientes duales, Dashboard Header con badges de status, y Charts con tooltips de alta fidelidad.
- **Tipografía**: Implementación de Inter (Display) y Outfit para una estética moderna.

### Optimizaciones Docker & Infra
- **Fix Standalone Build**: Se habilitó `output: 'standalone'` en `next.config.ts` para permitir la creación correcta de imágenes Docker optimizadas.
- **Workflow de Rebuild**: Documentado el proceso de reconstrucción para el frontend desacoplado.

### Fixes & Bug Fixes
- **KPI Alertas (Dashboard)**: Se corrigió la lógica de `EstAud` en backend para que marque registros procesados con errores o advertencias. Se robusteció el mapeo de estados en frontend.
- **React Hydration Mismatch (#418)**: Se eliminó el error diferiendo la renderización de fechas (`new Date()`) en `DashboardHeader` hasta la etapa del cliente mediante `useEffect`.
- **Navegación 404 (/settings)**: Se agregó la página "Configuración (En Construcción)" para resolver rutas inexistentes de los menús laterales y superior.

## [2026-03-07]

### Migración Frontend a Next.js
- **Migración a SPA**: Se migró la interfaz originalmente servida como HTML renderizados estáticamente desde PHP a una **Arquitectura Desacoplada** con Next.js (App Router).
- **Stack Frontend**: React 19, TypeScript, Tailwind CSS v4, shadcn/ui, eCharts, Lucide Icons, Zustand y React Query (TanStack).
- **Consumo de APIs**: Se creó un cliente `api.ts` estándar y seguro para interactuar con la API PHP existente, unificando los tipos e interfaces.


### Optimización de Estándares (Skills)
- **Alineación de Endpoints**: Se formalizó el "Patrón de Endpoint Estándar" en la skill `audfact-api-rest`. Ahora todos los controladores deben usar `validateQuery` para capturar filtros y devolver respuestas con metadatos de paginación y el objeto `filters` (echo).
- **Consumo de Datos en Modelos**: Se formalizó el "Patrón de Consumo de Datos y Filtrado" en la skill `audfact-sqlsrv-models`. Los modelos ahora deben aceptar un array `$filters` inyectado desde el controlador para construir cláusulas `WHERE` dinámicas de manera consistente.
- **Workflow de Generación**: Se creó el archivo `.agent/workflows/generate-endpoint.md` para guiar a los agentes en la creación de nuevos endpoints siguiendo estos estándares.
- **Impacto**: Reducción de la deuda técnica y garantía de una API predecible y uniforme para el frontend.

## 2026-03-09
- Fix: Implementado deep-linking en tablas de auditoría (Dashboard) inyectando estado inicial vía `useSearchParams` hacia las páginas `audit/history` y `audit/single`. Se eliminó la dependencia exclusiva de hooks de efecto para hidratar variables del URL.

## 2026-03-08
- Fix: Corregido el mapeo de parámetros (FacSec a NumeroFactura) en la Auditoría 1:1.
- Fix: Resuelto el renderizado vacío del modal de resultados de Auditoría 1:1 en la UI gestionando correctamente la envoltura data.data del backend y el estado de error de la IA.
