"use client";

import { useEffect } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

export default function DashboardError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error("[DashboardError]", error);
  }, [error]);

  return (
    <div className="flex min-h-[50vh] items-center justify-center px-6">
      <div className="w-full max-w-lg space-y-6 text-center">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-rose-500/14 text-rose-300">
          <AlertTriangle className="h-7 w-7" />
        </div>
        <div className="space-y-2">
          <h2 className="[font-family:var(--font-heading)] text-2xl font-semibold text-white">
            Algo salió mal
          </h2>
          <p className="text-sm text-slate-400">
            Ha ocurrido un error al cargar esta sección. Puedes intentar recargar o volver al dashboard.
          </p>
        </div>
        <div className="flex justify-center gap-3">
          <Button onClick={reset} className="gap-2">
            <RefreshCw className="h-4 w-4" />
            Reintentar
          </Button>
        </div>
        {error.digest && (
          <p className="text-xs text-slate-600">
            Referencia: {error.digest}
          </p>
        )}
      </div>
    </div>
  );
}
