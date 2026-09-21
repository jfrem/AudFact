import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  LoaderCircle,
  XCircle,
} from "lucide-react";

import { Badge } from "@/components/ui/badge";

type JobStatus = "queued" | "running" | "completed" | "completed_with_errors" | "failed";

const statusConfig = {
  queued: { label: "En cola", variant: "warning", icon: Clock3 },
  running: { label: "Ejecutando", variant: "info", icon: LoaderCircle },
  completed: { label: "Completado", variant: "success", icon: CheckCircle2 },
  completed_with_errors: {
    label: "Completado con errores",
    variant: "warning",
    icon: AlertTriangle,
  },
  failed: { label: "Fallido", variant: "danger", icon: XCircle },
} satisfies Record<
  JobStatus,
  {
    label: string;
    variant: "warning" | "info" | "success" | "danger";
    icon: typeof Clock3;
  }
>;

function normalizeStatus(status?: string | null): JobStatus {
  if (
    status === "queued" ||
    status === "running" ||
    status === "completed" ||
    status === "completed_with_errors" ||
    status === "failed"
  ) {
    return status;
  }

  return "queued";
}

export function JobStatusBadge({ status }: { status?: string | null }) {
  const entry = statusConfig[normalizeStatus(status)];
  const Icon = entry.icon;

  return (
    <Badge variant={entry.variant}>
      <Icon aria-hidden="true" />
      {entry.label}
    </Badge>
  );
}
