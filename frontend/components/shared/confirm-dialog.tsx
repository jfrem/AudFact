"use client";

import { AlertTriangle, Info, ShieldAlert } from "lucide-react";

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";

type Variant = "danger" | "warning" | "info";

const variantConfig = {
  danger: {
    icon: ShieldAlert,
    iconClass: "border-rose-500/25 bg-rose-500/10 text-rose-300",
    buttonVariant: "destructive",
  },
  warning: {
    icon: AlertTriangle,
    iconClass: "border-amber-500/25 bg-amber-500/10 text-amber-300",
    buttonVariant: "default",
  },
  info: {
    icon: Info,
    iconClass: "border-sky-500/25 bg-sky-500/10 text-sky-300",
    buttonVariant: "default",
  },
} satisfies Record<
  Variant,
  {
    icon: typeof AlertTriangle;
    iconClass: string;
    buttonVariant: "destructive" | "default";
  }
>;

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
  const config = variantConfig[variant];
  const Icon = config.icon;

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onCancel()}>
      <DialogContent className="max-w-md gap-0 overflow-hidden p-0">
        <DialogHeader className="px-5 pb-5 pt-6 sm:px-6">
          <div className="flex items-start gap-4">
            <div
              className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-md border ${config.iconClass}`}
            >
              <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
            </div>
            <div className="min-w-0 pr-6 text-left">
              <DialogTitle className="text-base leading-6">{title}</DialogTitle>
              {description ? (
                <DialogDescription className="mt-1.5 text-sm leading-5">
                  {description}
                </DialogDescription>
              ) : null}
            </div>
          </div>
        </DialogHeader>

        <DialogFooter className="flex-row justify-end gap-2 border-t border-border bg-muted/35 px-5 py-4 sm:px-6">
          <Button type="button" variant="ghost" onClick={onCancel} disabled={loading}>
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={config.buttonVariant}
            onClick={onConfirm}
            loading={loading}
            loadingLabel="Procesando"
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
