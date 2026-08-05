"use client";

import * as React from "react";
import { AlertTriangle, Info, ShieldAlert } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";

type Variant = "danger" | "warning" | "info";

/* ── Variant Tokens ───────────────────────────────────────────────────── */
const variantTokens: Record<
  Variant,
  {
    icon: typeof AlertTriangle;
    /** Outer ring glow + icon badge surface */
    iconBadge: string;
    /** Subtle accent stripe at the top of the modal */
    accentBar: string;
    /** Primary CTA button styling */
    ctaClass: string;
    /** Button variant for the primary CTA */
    buttonVariant: "destructive" | "default";
  }
> = {
  danger: {
    icon: ShieldAlert,
    iconBadge:
      "border-rose-500/25 bg-rose-500/10 text-rose-400 shadow-[0_0_20px_-4px_rgba(244,63,94,0.25)]",
    accentBar: "from-rose-500/80 to-rose-500/0",
    ctaClass:
      "bg-rose-500 text-white hover:bg-rose-400 active:bg-rose-600 shadow-lg shadow-rose-500/20",
    buttonVariant: "destructive",
  },
  warning: {
    icon: AlertTriangle,
    iconBadge:
      "border-amber-500/25 bg-amber-500/10 text-amber-400 shadow-[0_0_20px_-4px_rgba(245,158,11,0.25)]",
    accentBar: "from-amber-500/80 to-amber-500/0",
    ctaClass:
      "bg-amber-500 text-slate-950 hover:bg-amber-400 active:bg-amber-600 shadow-lg shadow-amber-500/20",
    buttonVariant: "default",
  },
  info: {
    icon: Info,
    iconBadge:
      "border-sky-500/25 bg-sky-500/10 text-sky-400 shadow-[0_0_20px_-4px_rgba(14,165,233,0.25)]",
    accentBar: "from-sky-500/80 to-sky-500/0",
    ctaClass:
      "bg-sky-500 text-white hover:bg-sky-400 active:bg-sky-600 shadow-lg shadow-sky-500/20",
    buttonVariant: "default",
  },
};

/* ── Component ────────────────────────────────────────────────────────── */
export function ConfirmDialog({
  open,
  onConfirm,
  onCancel,
  title,
  description,
  confirmLabel = "Confirmar",
  cancelLabel = "Cancelar",
  variant = "warning",
  loading = false,
}: {
  open: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: Variant;
  loading?: boolean;
}) {
  const tokens = variantTokens[variant];
  const Icon = tokens.icon;

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onCancel()}>
      <DialogContent
        className={cn(
          /* ── Size & shape ─────────────────────────────────── */
          "max-h-[96vh] w-[92vw] max-w-md gap-0 overflow-hidden rounded-2xl",
          /* ── Surface ──────────────────────────────────────── */
          "border border-white/15 bg-[color:var(--popover)] p-0 text-[color:var(--popover-foreground)]",
          /* ── Modal depth ──────────────────────────────────── */
          "shadow-2xl shadow-slate-950/75",
          /* ── Entry animation override ─────────────────────── */
          "data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.96]",
          "data-[state=open]:slide-in-from-bottom-3",
          "data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-[0.96]",
          "data-[state=closed]:slide-out-to-bottom-2",
          "duration-200",
        )}
      >
        {/* ── Accent bar (top color stripe) ─────────────────── */}
        <div
          className={cn(
            "h-[3px] w-full bg-gradient-to-r",
            tokens.accentBar,
          )}
          aria-hidden
        />

        {/* ── Header ────────────────────────────────────────── */}
        <DialogHeader className="px-6 pb-0 pt-6">
          <div className="flex items-start gap-4">
            {/* Icon badge */}
            <div
              className={cn(
                "flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border",
                "transition-all duration-300",
                tokens.iconBadge,
              )}
            >
              <Icon className="h-5 w-5" strokeWidth={1.8} />
            </div>

            <div className="min-w-0 space-y-1.5">
              <DialogTitle className="text-[15px] font-semibold leading-snug tracking-tight text-white">
                {title}
              </DialogTitle>

              {description && (
                <DialogDescription className="text-[13px] leading-relaxed text-slate-400">
                  {description}
                </DialogDescription>
              )}
            </div>
          </div>
        </DialogHeader>

        {/* ── Divider ───────────────────────────────────────── */}
        <div className="mx-6 mt-5 border-t border-white/[0.06]" aria-hidden />

        {/* ── Footer / Actions ──────────────────────────────── */}
        <DialogFooter className="flex-row justify-end gap-3 px-6 pb-5 pt-4">
          <Button
            type="button"
            variant="ghost"
            onClick={onCancel}
            disabled={loading}
            className={cn(
              "h-9 rounded-lg px-4 text-[13px] font-medium",
              "text-slate-400 hover:bg-white/[0.06] hover:text-slate-200",
              "transition-all duration-150",
              "focus-visible:ring-2 focus-visible:ring-white/20",
            )}
          >
            {cancelLabel}
          </Button>

          <Button
            type="button"
            variant={tokens.buttonVariant}
            onClick={onConfirm}
            disabled={loading}
            loading={loading}
            loadingLabel="Procesando..."
            className={cn(
              "h-9 rounded-lg px-5 text-[13px] font-semibold",
              "transition-all duration-150",
              "active:scale-[0.97]",
              tokens.ctaClass,
              loading && "pointer-events-none opacity-70",
            )}
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
