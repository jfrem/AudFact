import { Activity, AlertTriangle, ShieldCheck, Sparkles } from "lucide-react";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

export function StatCard({
  label,
  value,
  hint,
  tone = "blue",
}: {
  label: string;
  value: string;
  hint: string;
  tone?: "blue" | "emerald" | "amber" | "violet";
}) {
  const iconColor = {
    blue: "text-[var(--color-clinical-sky)]",
    emerald: "text-[var(--color-verdict-pass)]",
    amber: "text-[var(--color-verdict-warning)]",
    violet: "text-[var(--color-human-violet)]",
  };
  const iconMap = {
    blue: Activity,
    emerald: ShieldCheck,
    amber: AlertTriangle,
    violet: Sparkles,
  };
  const Icon = iconMap[tone];

  return (
    <Card
      className={cn(
        "rounded-xl border p-5 transition-[border-color,background-color] duration-150 hover:bg-white/[0.04]"
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="space-y-3">
          <div className="flex items-center gap-2 text-sm text-slate-400">
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-white/10 bg-white/[0.03]">
              <Icon className={cn("h-4 w-4", iconColor[tone])} />
            </span>
            <span>{label}</span>
          </div>
          <div className="[font-family:var(--font-heading)] text-2xl font-semibold tracking-tight text-white">
            {value}
          </div>
        </div>
      </div>
      <p className="mt-3 border-t border-white/[0.06] pt-3 text-[13px] leading-5 text-slate-500">{hint}</p>
    </Card>
  );
}
