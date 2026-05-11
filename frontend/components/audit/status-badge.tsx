import { Badge } from "@/components/ui/badge";

type BadgeTone = "success" | "danger" | "warning" | "neutral" | "info" | "human";

export function AuditStatusBadge({ status }: { status?: string | null }) {
  const normalized = String(status ?? "").toUpperCase();
  const config: Record<string, { label: string; variant: BadgeTone }> = {
    CONCILIADO: { label: "Conciliado", variant: "success" },
    CONCILIADO_PARCIAL: { label: "Parcial", variant: "warning" },
    DISCREPANCIA: { label: "Discrepancia", variant: "danger" },
    FAILED: { label: "Fallido", variant: "danger" },
    PENDIENTE: { label: "Pendiente", variant: "warning" },
    MANUAL_REVIEW: { label: "Revisión manual", variant: "human" },
    EN_PROCESO: { label: "En proceso", variant: "info" },
  };
  const entry = config[normalized] ?? {
    label: normalized || "Sin estado",
    variant: "neutral" as const,
  };

  return (
    <Badge variant={entry.variant}>
      {entry.label}
    </Badge>
  );
}
