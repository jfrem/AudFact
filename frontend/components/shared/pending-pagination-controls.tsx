"use client";

import * as React from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { BackendRequestSkeleton } from "@/components/shared/backend-request-skeleton";
import { Button } from "@/components/ui/button";
import { usePendingNavigation } from "@/lib/hooks/use-pending-navigation";

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
  const navigation = usePendingNavigation();
  const [pendingDirection, setPendingDirection] = React.useState<"next" | "previous" | null>(null);

  React.useEffect(() => {
    if (!navigation.isPending) {
      setPendingDirection(null);
    }
  }, [navigation.isPending]);

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="secondary"
          disabled={previousDisabled || navigation.isPending}
          loading={navigation.isPending && pendingDirection === "previous"}
          loadingLabel="Cargando"
          onClick={() => {
            setPendingDirection("previous");
            navigation.push(previousHref);
          }}
        >
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          Anterior
        </Button>
        <Button
          type="button"
          variant="secondary"
          disabled={nextDisabled || navigation.isPending}
          loading={navigation.isPending && pendingDirection === "next"}
          loadingLabel="Cargando"
          onClick={() => {
            setPendingDirection("next");
            navigation.push(nextHref);
          }}
        >
          Siguiente
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>
      {navigation.isPending ? (
        <BackendRequestSkeleton
          description="El backend está cargando la página solicitada."
          title="Actualizando paginación"
          variant="compact"
        />
      ) : null}
    </div>
  );
}
