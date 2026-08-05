import { cn } from "@/lib/utils";

const toneMap = {
  ok: "bg-emerald-500/15 text-emerald-300 ring-emerald-500/25",
  warn: "bg-amber-500/15 text-amber-300 ring-amber-500/25",
  fail: "bg-rose-500/15 text-rose-300 ring-rose-500/25",
  unknown: "bg-slate-500/15 text-slate-300 ring-slate-500/25",
};

export function SystemStatusPill({
  label,
  status,
}: {
  label: string;
  status: keyof typeof toneMap;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset",
        toneMap[status],
      )}
    >
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {label}
    </span>
  );
}
