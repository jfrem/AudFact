import { Badge } from "@/components/ui/badge";

type JobStatusType = "queued" | "running" | "completed" | "completed_with_errors" | "failed";

const variantMap: Record<JobStatusType, "warning" | "info" | "success" | "danger"> = {
  queued: "warning",
  running: "info",
  completed: "success",
  completed_with_errors: "warning",
  failed: "danger",
};

const labelMap: Record<JobStatusType, string> = {
  queued: "En cola",
  running: "Ejecutando",
  completed: "Completado",
  completed_with_errors: "Completado con errores",
  failed: "Fallido",
};

export function JobStatusBadge({ status }: { status?: string | null }) {
  const normalized =
    status === "queued" ||
    status === "running" ||
    status === "completed" ||
    status === "completed_with_errors" ||
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
