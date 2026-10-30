# SDD — Optimización de la Interfaz del Visor de Adjuntos en el Modal de Resultados

> **Fecha:** 2026-10-30  
> **Estado:** Aprobado para Implementación  
> **Prioridad:** P2 (Experiencia Forense y Usabilidad Crítica)  
> **Componentes Afectados:**
> - `frontend/components/results/audit-result-detail-modal.tsx`
> - `frontend/components/attachments/attachment-viewer-panel.tsx`
> - `frontend/components/attachments/attachment-iframe-viewer.tsx`
> - `frontend/components/attachments/attachment-list.tsx`

---

## 1. Contexto y Justificación

En las auditorías médicas de AudFact, los documentos adjuntos (fórmulas médicas, actas de entrega, facturas, soportes de MIPRES) constituyen el **cuerpo de evidencia física** contra el cual se contrastan los hallazgos del motor de IA.

### Diagnóstico del Estado Anterior
1. **Pérdida de Espacio Vertical**: En `AuditResultDetailModal`, el tab de "Adjuntos" utilizaba una lista horizontal de tarjetas voluminosas (`w-[220px]`) apilada sobre el visor, consumiendo espacio vertical valioso y forzando al usuario a lidiar con doble barra de desplazamiento (scroll en el modal y scroll dentro del iframe).
2. **Carencia de Controles Forenses**: No existían controles de zoom, rotación de 90° (crítica para fórmulas escaneadas de forma horizontal o invertida) ni modo maximizado/pantalla completa.
3. **Desaprovechamiento de Pantallas Anchas**: En resoluciones de escritorio donde el modal abarca hasta `1600px` de ancho, el documento quedaba confinado en una caja verticalmente recortada de `h-[65vh]`.

---

## 2. Especificación Arquitectónica y de Diseño ("El Escritorio del Forense")

### 2.1 Espacio de Trabajo Split-View a Pantalla Completa
En `AuditResultDetailModal`, cuando `tab === "adjuntos"`, el contenedor del tab adopta `h-[calc(96vh-140px)] p-0 overflow-hidden`, eliminando paddings externos redundantes y suprimiendo el scroll-jacking.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ MODAL HEADER (DisId, Cliente, Paciente, Incidencias, Docs, IA)                         │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ TABS: [Incidencias] [Campos auditados] [Rendimiento] [★ Adjuntos (3) ★]                │
├─────────────────────────┬──────────────────────────────────────────────────────────────┤
│ EXPEDIENTE DOCUMENTAL   │ BARRA DE HERRAMIENTAS FORENSE:                               │
│ (Rail Lateral 280px)    │ [Doc Actual] [BLOB/PDF] │ [Zoom -/+] [Girar 90°] [⛶ Max] [↓]│
├─────────────────────────┼──────────────────────────────────────────────────────────────┤
│ 📄 Fórmula Médica       │                                                              │
│    BLOB · 242086        │                                                              │
│                         │                     CANVAS DE EVIDENCIA                      │
│ 📄 Acta de Entrega      │                   (100% Altura y Anchura)                    │
│    BLOB · 242087        │                 • PDF: Iframe nativo full                    │
│                         │                 • Imagen: Zoom/Rotación fluida               │
│ 🔗 Soporte Externo      │                                                              │
│    URL · Drive          │                                                              │
└─────────────────────────┴──────────────────────────────────────────────────────────────┘
```

### 2.2 Componentes Mejorados y Nuevas Capacidades

1. **`AttachmentViewerPanel`**:
   - Integración de barra de herramientas forense en la parte superior del canvas:
     - Selector de zoom interactivo (50%, 75%, 100%, 125%, 150%, 200%).
     - Control de rotación continua a 90° (`RotateCw`) con persistencia por documento seleccionado.
     - Botón de maximizado / modo inmersivo a pantalla completa (`Maximize2` / `Minimize2`).
     - Botones de acción directa: "Abrir en nueva pestaña" (`ExternalLink`) y "Descargar" (`Download`).
2. **`AttachmentIframeViewer`**:
   - Soporte para transformaciones de rotación y escala CSS en imágenes escaneadas (`scale(${zoom}) rotate(${rotation}deg)`).
   - Renderizado al 100% del alto y ancho disponible sin márgenes artificiales ni barras de desplazamiento duplicadas.
3. **`AttachmentsTab` en `AuditResultDetailModal`**:
   - Arquitectura Split-View: riel lateral izquierdo con lista vertical de archivos y stage principal a la derecha. En pantallas móviles, degrade automático a selector horizontal compacto.

---

## 3. Plan de Pruebas y Validación

1. **Compilación TypeScript**: `cd frontend && npx tsc --noEmit` con 0 errores.
2. **Suite PHPUnit**: Ejecutar `php vendor/bin/phpunit --colors=never` garantizando 1017 tests en verde.
3. **Inspección Visual y de Estados**:
   - Validación de documentos PDF con visualizador nativo.
   - Validación de imágenes escaneadas con zoom (100% -> 150%) y rotación (0° -> 90° -> 180° -> 270°).
   - Validación de estado vacío y registros `SIN_DOCUMENTOS`.

