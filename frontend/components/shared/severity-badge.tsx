import { AlertTriangle, CircleMinus, OctagonAlert } from "lucide-react";

import { Badge } from "@/components/ui/badge";

const severityConfig: Record<
  string,
  {
    label: string;
    variant: "danger" | "warning" | "neutral";
    icon: typeof AlertTriangle;
  }
> = {
  CRITICO: { label: "Crítico", variant: "danger", icon: OctagonAlert },
  MENOR: { label: "Menor", variant: "warning", icon: AlertTriangle },
  NONE: { label: "Sin severidad", variant: "neutral", icon: CircleMinus },
};

export function SeverityBadge({ severity }: { severity?: string | null }) {
  const normalized = String(severity ?? "").toUpperCase();
  const entry = severityConfig[normalized] ?? {
    label: normalized || "Sin severidad",
    variant: "neutral" as const,
    icon: CircleMinus,
  };
  const Icon = entry.icon;

  return (
    <Badge variant={entry.variant}>
      <Icon aria-hidden="true" />
      {entry.label}
    </Badge>
  );
}
