import * as React from "react";

import { cn } from "@/lib/utils";

type PageHeaderProps = {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: React.ReactNode;
  className?: string;
};

export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
  className,
}: PageHeaderProps) {
  return (
    <div
      className={cn(
        "flex flex-col gap-4 rounded-xl border border-white/[0.06] bg-white/[0.02] px-5 py-5 md:px-6 md:py-5 md:flex-row md:items-end md:justify-between",
        className,
      )}
    >
      <div className="space-y-2">
        {eyebrow ? (
          <p className="text-[11px] font-semibold uppercase tracking-[0.22em] text-sky-300">
            {eyebrow}
          </p>
        ) : null}
        <div className="space-y-1.5">
          <h1 className="[font-family:var(--font-heading)] text-[1.85rem] font-semibold tracking-tight text-white">
            {title}
          </h1>
          {description ? (
            <p className="max-w-3xl text-sm tabular-nums leading-6 text-slate-400">
              {description}
            </p>
          ) : null}
        </div>
      </div>
      {actions ? <div className="shrink-0 self-start md:self-auto">{actions}</div> : null}
    </div>
  );
}
