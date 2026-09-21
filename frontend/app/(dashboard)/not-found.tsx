import Link from "next/link";
import { SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";

export default function DashboardNotFound() {
  return (
    <div className="flex min-h-[50vh] items-center justify-center px-6">
      <div className="w-full max-w-lg space-y-6 text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-lg border border-primary/30 bg-primary/10 text-primary">
          <SearchX className="h-7 w-7" />
        </div>
        <div className="space-y-2">
          <h2 className="[font-family:var(--font-heading)] text-2xl font-semibold text-foreground">
            Página no encontrada
          </h2>
          <p className="text-sm text-slate-400">
            La ruta solicitada no existe o fue removida.
          </p>
        </div>
        <Button asChild>
          <Link href="/dashboard">
            Volver al Dashboard
          </Link>
        </Button>
      </div>
    </div>
  );
}
