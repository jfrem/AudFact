import { Badge } from "@/components/ui/badge";

type JobStatusType = "queued" | "running" | "completed" | "failed";

const variantMap: Record<JobStatusType, "warning" | "info" | "success" | "danger"> = {
  queued: "warning",
  running: "info",
  completed: "success",
  failed: "danger",
};

const labelMap: Record<JobStatusType, string> = {
  queued: "En cola",
  running: "Ejecutando",
  completed: "Completado",
  failed: "Fallido",
};

export function JobStatusBadge({ status }: { status?: string | null }) {
  const normalized =
    status === "queued" ||
    status === "running" ||
    status === "completed" ||
    status === "failed"
      ? (status as JobStatusType)
      : "queued";

  const variant = variantMap[normalized];
  const label = labelMap[normalized];

  return (
    <Badge variant={variant}>
      {label}
    </Badge>
  );
}
