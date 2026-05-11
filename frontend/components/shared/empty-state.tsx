import type { ReactNode } from "react";
import { SearchSlash } from "lucide-react";

export function EmptyState({
  icon,
  title,
  description,
  action,
}: {
  icon?: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex min-h-[220px] items-center justify-center rounded-xl border border-dashed border-white/10 bg-card px-6 py-8 text-center" role="status">
      <div className="max-w-sm space-y-3">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] text-slate-400" aria-hidden="true">
          {icon ?? <SearchSlash className="h-6 w-6" />}
        </div>
        <p className="font-medium text-slate-200">{title}</p>
        {description && (
          <p className="text-sm leading-6 text-slate-400">{description}</p>
        )}
        {action && <div className="pt-2">{action}</div>}
      </div>
    </div>
  );
}
