---
name: AudFact
description: Sistema experto de auditoría documental con IA
colors:
  deep-ink: "#09111d"
  slate-surface: "#111c2b"
  elevated-slate: "#0d1724"
  clinical-sky: "#57b0ff"
  clinical-sky-strong: "#197dff"
  foreground-bright: "#eef4fc"
  muted-steel: "#9db0c7"
  faded-steel: "#a7b7ca"
  verdict-pass: "#16c784"
  verdict-warning: "#ffb84d"
  verdict-fail: "#ff6b7a"
  human-violet: "#b892ff"
  border-faint: "rgba(148, 163, 184, 0.14)"
  accent-wash: "rgba(87, 176, 255, 0.1)"
typography:
  display:
    fontFamily: "Space Grotesk, system-ui, sans-serif"
    fontSize: "clamp(1.75rem, 3vw, 2.25rem)"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "Space Grotesk, system-ui, sans-serif"
    fontSize: "1.15rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "-0.01em"
  body:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "IBM Plex Sans, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "0.22em"
rounded:
  sm: "4px"
  md: "8px"
  lg: "12px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "20px"
  xl: "24px"
components:
  button-primary:
    backgroundColor: "{colors.clinical-sky}"
    textColor: "{colors.deep-ink}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  button-primary-hover:
    backgroundColor: "#6bbfff"
  button-outline:
    backgroundColor: "rgba(255,255,255,0.03)"
    textColor: "#e2e8f0"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "#cbd5e1"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  card-surface:
    backgroundColor: "{colors.slate-surface}"
    textColor: "{colors.foreground-bright}"
    rounded: "{rounded.lg}"
    padding: "20px"
  input-default:
    backgroundColor: "rgba(255,255,255,0.03)"
    textColor: "{colors.foreground-bright}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
  nav-item-active:
    backgroundColor: "rgba(255,255,255,0.06)"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "8px 12px"
---

# Design System: AudFact

## 1. Overview

**Creative North Star: "El Escritorio del Forense"**

Cada pieza de evidencia visible, limpia, trazable. Sin decoración, pura sustancia. La interfaz de AudFact no intenta impresionar con efectos visuales: impone respeto con la claridad de sus datos y la precisión de su tipografía. Un auditor abre esta herramienta para trabajar, no para admirarla. La información no compite por atención: la jerarquía visual separa lo urgente de lo contextual con escala y peso, no con color o cajas.

El sistema rechaza explícitamente lo que PRODUCT.md llama "otro Dashboard más": nada de grids de tarjetas idénticas con icono + métrica + etiqueta, nada de gráficos decorativos sin acción asociada, nada de fondos coloridos que enmascaren la falta de jerarquía tipográfica. La estética es tonal, no cromática: las superficies se diferencian por luminosidad, no por hue.

**Key Characteristics:**
- Densidad informativa alta con legibilidad controlada
- Separación por tipografía y espacio, no por contenedores
- Paleta restringida: neutrales teñidos de azul profundo + un único acento sky
- Instrumentalidad: cada elemento existe para una acción o una lectura, sin intermediarios decorativos
- Dark mode calibrado para jornadas largas (WCAG AA)

## 2. Colors: The Forensic Palette

Una paleta deliberadamente fría, anclada en azules profundos desaturados con un único acento sky que marca interactividad y estado activo. Los colores semánticos (pass/warning/fail) son los únicos cromáticamente expresivos.

### Primary
- **Clinical Sky** (`#57b0ff`): Acento interactivo — botones primarios, links activos, indicador de item seleccionado en navegación. Usado con moderación (≤10% de cualquier pantalla).
- **Clinical Sky Strong** (`#197dff`): Variante de énfasis — skip-link, badges de acción urgente. Más saturado para contraste sobre fondos oscuros.

### Neutral
- **Deep Ink** (`#09111d`): Fondo base del viewport. El "papel" del escritorio. Todo descansa sobre esto.
- **Slate Surface** (`#111c2b`): Superficie de tarjetas y paneles. El primer nivel de elevación tonal.
- **Elevated Slate** (`#0d1724`): Popover y áreas de contexto secundario. Apenas perceptible contra Deep Ink, suficiente para demarcar.
- **Foreground Bright** (`#eef4fc`): Texto primario. Ligeramente azulado, nunca blanco puro.
- **Muted Steel** (`#9db0c7`): Texto secundario, timestamps, descripciones.
- **Faded Steel** (`#a7b7ca`): Labels terciarios, texto de menor prioridad.
- **Border Faint** (`rgba(148, 163, 184, 0.14)`): Bordes de separación. Casi imperceptibles en reposo, suficientes para trazar límites.

### Semantic (verdict tones)
- **Verdict Pass** (`#16c784`): Estado conforme / aprobado.
- **Verdict Warning** (`#ffb84d`): Discrepancia leve / atención requerida.
- **Verdict Fail** (`#ff6b7a`): Rechazado / fallo de auditoría.
- **Human Violet** (`#b892ff`): Señal de acción humana requerida (vs IA).

### Named Rules
**The Forensic Restraint Rule.** El acento sky aparece en ≤10% de la superficie visible. Su escasez es lo que le da autoridad. Si todo es azul, nada es importante.

**The Tonal Depth Rule.** La profundidad se comunica con luminosidad, no con sombras ni bordes gruesos. Deep Ink → Elevated Slate → Slate Surface es toda la jerarquía de capas. Si necesitas más de tres niveles, estás anidando demasiado.

## 3. Typography

**Display Font:** Space Grotesk (con system-ui fallback)
**Body Font:** IBM Plex Sans (con system-ui fallback)

**Character:** Space Grotesk aporta presencia técnica sin frialdad: sus formas geométricas son legibles a escala grande sin parecer genéricas. IBM Plex Sans es la voz del dato denso: neutral, alta legibilidad a tamaño pequeño (14px), industrial sin ser brutal.

### Hierarchy
- **Display** (600, clamp(1.75rem, 3vw, 2.25rem), line-height 1.15): Títulos de página únicamente. Una sola instancia por vista.
- **Headline** (600, 1.15rem, line-height 1.3): Títulos de sección dentro de cards. Space Grotesk.
- **Title** (500, 0.875rem, line-height 1.4): Subtítulos, headers de columna de tabla. IBM Plex Sans weight 500.
- **Body** (400, 0.875rem/14px, line-height 1.5): Texto base de datos y contenido. Máximo 65–75ch por línea.
- **Label** (600, 0.6875rem/11px, tracking 0.22em, uppercase): Eyebrows, categorías, secciones de sidebar.

### Named Rules
**The No-Default Rule.** IBM Plex Sans e Space Grotesk son obligatorias. Si un componente renderiza en system font, es un bug. Las fuentes predeterminadas de Tailwind (Inter, etc.) están prohibidas.

## 4. Elevation: The Flat Evidence Table

Sin sombras como herramienta de profundidad. Las superficies son planas en reposo. La elevación se expresa exclusivamente por diferencia tonal entre fondos (Deep Ink < Elevated Slate < Slate Surface). Las sombras definidas en el sistema (`--shadow-soft`, `--shadow-card`) existen como herramienta de transición (hover, focus), no como decoración en reposo.

### Shadow Vocabulary
- **Ambient Low** (`0 4px 16px rgba(2, 8, 23, 0.12)`): Solo en hover de cards. Sutil y difusa. No visible en estado de reposo.
- **Ambient Soft** (`0 8px 24px rgba(2, 8, 23, 0.2)`): Solo en popovers y drawers mobile. Refuerza la separación contextual del overlay.

### Named Rules
**The Flat Evidence Rule.** Ninguna superficie muestra sombra en reposo. Las sombras aparecen solo como respuesta a estado (hover, focus, popover abierto). Si un elemento tiene sombra visible al cargar la página, eliminarla.

## 5. Components

Contenidos e instrumentales. Cada componente existe para transmitir o capturar información, no para llenar espacio.

### Buttons
- **Shape:** Bordes con curvatura suave (8px radius)
- **Primary:** Sky sobre Deep Ink, borde `sky-500/30`, padding `8px 16px`, 40px de altura. Interactividad confirmada por su unicidad cromática.
- **Hover / Focus:** `sky-400` hover, focus ring `sky-500/38` 3px. Transición 150ms ease.
- **Outline:** Fondo `white/[0.03]`, borde `white/10`, texto `slate-100`. Acción secundaria.
- **Ghost:** Sin fondo, sin borde. Hover `white/[0.04]`. Para acciones terciarias y navegación.

### Cards / Containers
- **Corner Style:** Gently curved (12px radius)
- **Background:** Slate Surface (`#111c2b`)
- **Shadow Strategy:** Flat by default. Ver Elevation.
- **Border:** `white/10`, 1px. Apenas visible, traza límite sin gritar.
- **Internal Padding:** 20px (`spacing.lg`)
- **The No-Nesting Doctrine:** Cards no contienen otras cards. Si necesitas sub-agrupación, usa tipografía y espacio.

### Inputs / Fields
- **Style:** Fondo `white/[0.03]`, borde `white/10`, radius 8px.
- **Focus:** Border shifts a `white/14`, glow ring `sky-500/18` 3px.
- **Error:** Border `rose-500/50`, label color shifts a `rose-400`.

### Navigation (Sidebar)
- **Desktop collapsed** (76px): Icon-only, tooltip on hover. Logo reducido a initial.
- **Desktop expanded** (280px): Icon + label + chevron indicator. Active item: `white/[0.06]` bg, `sky-500/30` border, 1px left accent line en sky-400.
- **Mobile:** Drawer lateral con backdrop `black/60 blur-sm`. Transición 300ms ease-out.
- **Section dividers:** Label uppercase en `slate-500`, tracking 0.24em, 10px.

### Signature Component: Verdict Badge
Indicador circular compacto que comunica el resultado de una auditoría IA:
- **Pass (C):** Círculo `emerald-500`, letra blanca.
- **Warning:** Círculo `amber-500`, letra dark.
- **Fail (R):** Círculo `rose-500`, letra blanca.
Aparece en tablas de historial y resultados de auditoría. Su densidad visual es su virtud: ocupa mínimo espacio horizontal en columnas de datos.

## 6. Do's and Don'ts

### Do:
- **Do** usar diferencia tonal (Deep Ink → Slate Surface) para crear profundidad, nunca sombras en reposo.
- **Do** mantener el acento sky por debajo del 10% de superficie visible en cada vista.
- **Do** usar Space Grotesk exclusivamente para títulos (h1, h2) e IBM Plex Sans para todo lo demás.
- **Do** respetar el tracking `0.22em` en todos los labels uppercase (eyebrows, section dividers).
- **Do** aplicar animaciones con `cubic-bezier(0.16, 1, 0.3, 1)` — ease-out exponencial. Sin bounce. Sin elastic.
- **Do** respetar `prefers-reduced-motion: reduce` desactivando todas las animaciones.
- **Do** usar los verdict badges (pass/warning/fail) como sistema semántico cerrado — no inventar nuevos colores de estado.

### Don't:
- **Don't** crear tarjetas anidadas dentro de tarjetas. Usar tipografía y espacio para sub-agrupar. (PRODUCT.md: *"Evitar las típicas tarjetas anidadas dentro de tarjetas"*)
- **Don't** usar la plantilla hero-metric (número gigante + etiqueta minúscula) sin contexto accionable. (PRODUCT.md: *"Cero uso de hero-metrics sin contexto"*)
- **Don't** usar gráficos decorativos que no deriven en una acción del usuario. (PRODUCT.md: *"Nada de gráficos decorativos que no aportan información accionable"*)
- **Don't** usar `border-left` o `border-right` mayor a 1px como acento colorido en cards, list items o alertas (side-stripe ban).
- **Don't** usar texto con gradiente (`background-clip: text` + gradient). Un color sólido basta.
- **Don't** usar glassmorphism como patrón por defecto. El `glass-bg` actual debe usarse solo en la sticky bar y popovers, nunca como tratamiento de superficie general.
- **Don't** usar Inter, Arial, o system-ui como fuente visible. Space Grotesk e IBM Plex Sans son obligatorias.
- **Don't** usar `#000` (negro puro) o `#fff` (blanco puro). Los neutrales siempre están teñidos hacia el hue base (Deep Ink).
- **Don't** usar bounce o elastic easing en ninguna animación.
- **Don't** crear grids de cards idénticas (mismo tamaño, icon + heading + text repetido). Variar jerarquía y densidad. (PRODUCT.md: *"No utilizar la típica estructura genérica de paneles sin jerarquía de escala"*)
