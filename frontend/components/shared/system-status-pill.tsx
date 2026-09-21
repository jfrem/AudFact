import {
  AlertTriangle,
  CheckCircle2,
  CircleHelp,
  XCircle,
} from "lucide-react";

import { cn } from "@/lib/utils";

const toneMap = {
  ok: "bg-emerald-500/10 text-emerald-300 ring-emerald-500/25",
  warn: "bg-amber-500/10 text-amber-300 ring-amber-500/25",
  fail: "bg-rose-500/10 text-rose-300 ring-rose-500/25",
  unknown: "bg-muted text-muted-foreground ring-border",
};

const statusIcon = {
  ok: CheckCircle2,
  warn: AlertTriangle,
  fail: XCircle,
  unknown: CircleHelp,
};

export function SystemStatusPill({
  label,
  status,
}: {
  label: string;
  status: keyof typeof toneMap;
}) {
  const Icon = statusIcon[status];

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset",
        toneMap[status],
      )}
    >
      <Icon className="h-3.5 w-3.5" aria-hidden="true" />
      {label}
    </span>
  );
}
