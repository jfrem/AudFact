"use client";

import * as React from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";


import { Button } from "@/components/ui/button";
import { useRouter, useSearchParams } from "next/navigation";

export function PendingPaginationControls({
  nextDisabled,
  nextHref,
  previousDisabled,
  previousHref,
}: {
  nextDisabled: boolean;
  nextHref: string;
  previousDisabled: boolean;
  previousHref: string;
}) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [pendingDirection, setPendingDirection] = React.useState<"next" | "previous" | null>(null);

  const currentParams = searchParams.toString();

  React.useEffect(() => {
    setPendingDirection(null);
  }, [currentParams]);

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          disabled={previousDisabled || pendingDirection !== null}
          loading={pendingDirection === "previous"}
          loadingLabel="Cargando"
          onClick={() => {
            const newParams = previousHref.split('?')[1] || "";
            if (currentParams !== newParams) {
              setPendingDirection("previous");
              router.push(previousHref);
            }
          }}
        >
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          Anterior
        </Button>
        <Button
          type="button"
          variant="secondary"
          disabled={nextDisabled || pendingDirection !== null}
          loading={pendingDirection === "next"}
          loadingLabel="Cargando"
          onClick={() => {
            const newParams = nextHref.split('?')[1] || "";
            if (currentParams !== newParams) {
              setPendingDirection("next");
              router.push(nextHref);
            }
          }}
        >
          Siguiente
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
    </div>
  );
}
