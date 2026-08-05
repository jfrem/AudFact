import Link from "next/link";
import { SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";

export default function DashboardNotFound() {
  return (
    <div className="flex min-h-[50vh] items-center justify-center px-6">
      <div className="w-full max-w-lg space-y-6 text-center">
        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-sky-500/14 text-sky-300">
          <SearchX className="h-7 w-7" />
        </div>
        <div className="space-y-2">
          <h2 className="[font-family:var(--font-heading)] text-2xl font-semibold text-white">
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
