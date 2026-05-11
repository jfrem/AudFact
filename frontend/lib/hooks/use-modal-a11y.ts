import { useEffect, useRef, useCallback } from "react";

const FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  "input:not([disabled])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]:not([tabindex='-1'])",
].join(", ");

/**
 * Traps focus inside the referenced container while `active` is true.
 * Also blocks body scroll when active.
 */
export function useModalA11y(active: boolean) {
  const containerRef = useRef<HTMLDivElement>(null);

  // ── Body scroll lock ──
  useEffect(() => {
    if (!active) return;

    const scrollY = window.scrollY;
    const { style } = document.body;
    const prev = style.overflow;
    style.overflow = "hidden";

    return () => {
      style.overflow = prev;
      window.scrollTo(0, scrollY);
    };
  }, [active]);

  // ── Focus trap ──
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.key !== "Tab" || !containerRef.current) return;

      const focusable = containerRef.current.querySelectorAll<HTMLElement>(
        FOCUSABLE_SELECTOR,
      );

      if (focusable.length === 0) {
        e.preventDefault();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    },
    [],
  );

  useEffect(() => {
    if (!active) return;

    document.addEventListener("keydown", handleKeyDown);

    // Auto-focus first focusable element inside container
    const timer = requestAnimationFrame(() => {
      const first = containerRef.current?.querySelector<HTMLElement>(
        FOCUSABLE_SELECTOR,
      );
      first?.focus();
    });

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      cancelAnimationFrame(timer);
    };
  }, [active, handleKeyDown]);

  return containerRef;
}
