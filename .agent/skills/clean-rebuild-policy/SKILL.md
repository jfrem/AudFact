---
name: clean-rebuild-policy
description: "Política estricta para proyectos en fase de desarrollo temprano centrada en reconstrucción desde cero (clean rebuild). Prioriza arquitectura limpia, prohíbe dependencias legacy y código muerto, y exige implementación rigurosa del MVP. Úsala cuando se inicien nuevos módulos, se evalúen refactorizaciones profundas frente a parches, o el usuario pida establecer bases sólidas y mantenibles sin herencia de deuda técnica. Actívala ante términos como 'reconstrucción', 'clean rebuild', 'MVP', 'arquitectura desacoplada', o 'eliminar legacy'."
---

# Política de Desarrollo — Clean Rebuild & MVP

## Contexto del Proyecto

El proyecto se encuentra en una **fase de desarrollo temprano**, y se ha priorizado una estrategia de **reconstrucción desde cero (clean rebuild)**. El objetivo es construir cimientos técnicos impecables, maximizando la claridad estructural, la extensibilidad futura y la calidad inherente del código.

## Directrices Fundamentales

Esta política impone restricciones severas sobre cómo se debe escribir, integrar y mantener el código:

1.  **Arquitectura Limpia y Desacoplada**: El código debe organizarse en módulos independientes, con responsabilidades únicas y claras.
2.  **Robustez y Escalabilidad**: Las soluciones deben diseñarse pensando en el mantenimiento a largo plazo, evitando atajos ("quick fixes") que comprometan la arquitectura.
3.  **Cero Tolerancia a Legacy**: Queda **prohibido** el uso de adaptadores, capas de compatibilidad retroactiva o soluciones híbridas diseñadas para mantener vivo código antiguo.
4.  **Erradicación de Código Muerto**: Prohibición estricta de código redundante, comentado, variables no utilizadas, o módulos obsoletos.
5.  **Enfoque Estricto en el MVP**: La implementación debe limitarse rigurosamente al Alcance Mínimo Viable necesario para la operación funcional. El "overengineering" para casos de uso futuros no validados es una infracción.

---

## Cómo aplicar esta política

Antes de proponer o aceptar cambios en el código, asegúrate de cumplir estos criterios:

### 1. ¿Es verdaderamente limpio y modular?
- Verifica que el nuevo código no tenga un acoplamiento fuerte no intencionado con otros dominios.
- Los límites entre componentes (ej. capa de acceso a datos vs. lógica de negocio) deben ser evidentes e infranqueables.

### 2. ¿Depende del pasado?
- Si para que el nuevo código funcione debes agregar un adaptador que simule respuestas de un sistema viejo, **detente**. Se debe reescribir la integración completamente o rediseñar el flujo.
- Elimina cualquier dependencia legacy detectada en el proceso.

### 3. ¿Sobran cosas?
- Revisa el diff meticulosamente antes de dar una tarea por terminada. Todo lo que no se ejecuta, debe eliminarse en el mismo commit.

### 4. ¿Está dentro del MVP?
- Cuestiona cada función añadida: "¿Es esto estrictamente necesario para la funcionalidad básica en este momento?". Si la respuesta es no, descártalo.

## Criterios de Aceptación (Definición de Hecho)

- [ ] La solución prescinde de parches y atajos en favor de refactorizaciones estructurales.
- [ ] No existen rastros de compatibilidad con código obsoleto.
- [ ] El PR / commit no contiene código muerto, imports sin uso o comentarios de código inactivo.
- [ ] La entrega satisface *únicamente* los requerimientos del MVP validado.

