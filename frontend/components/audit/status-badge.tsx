import {
  AlertTriangle,
  CheckCircle2,
  CircleHelp,
  Clock3,
  LoaderCircle,
  UserRoundCheck,
  XCircle,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";

type BadgeTone = "success" | "danger" | "warning" | "neutral" | "info" | "human";

const statusConfig: Record<
  string,
  { label: string; variant: BadgeTone; icon: typeof CheckCircle2 }
> = {
  CONCILIADO: { label: "Conciliado", variant: "success", icon: CheckCircle2 },
  CONCILIADO_PARCIAL: { label: "Parcial", variant: "warning", icon: AlertTriangle },
  DISCREPANCIA: { label: "Discrepancia", variant: "danger", icon: XCircle },
  FAILED: { label: "Fallido", variant: "danger", icon: XCircle },
  PENDIENTE: { label: "Pendiente", variant: "warning", icon: Clock3 },
  MANUAL_REVIEW: { label: "Revisión manual", variant: "human", icon: UserRoundCheck },
  EN_PROCESO: { label: "En proceso", variant: "info", icon: LoaderCircle },
};

export function AuditStatusBadge({ status }: { status?: string | null }) {
  const normalized = String(status ?? "").toUpperCase();
  const entry = statusConfig[normalized] ?? {
    label: normalized || "Sin estado",
    variant: "neutral" as const,
    icon: CircleHelp,
  };
  const Icon = entry.icon;

  return (
    <Badge variant={entry.variant}>
      <Icon aria-hidden="true" />
      {entry.label}
    </Badge>
  );
}
