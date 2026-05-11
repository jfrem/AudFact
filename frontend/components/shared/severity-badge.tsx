import { Badge } from "@/components/ui/badge";

export function SeverityBadge({ severity }: { severity?: string | null }) {
  const normalized = String(severity ?? "").toUpperCase();
  const config: Record<
    string,
    { label: string; variant: "danger" | "warning" | "neutral" }
  > = {
    CRITICO: { label: "Crítico", variant: "danger" },
    MENOR: { label: "Menor", variant: "warning" },
    NONE: { label: "Sin severidad", variant: "neutral" },
  };
  const entry = config[normalized] ?? {
    label: normalized || "Sin severidad",
    variant: "neutral" as const,
  };

  return (
    <Badge variant={entry.variant}>
      {entry.label}
    </Badge>
  );
}
