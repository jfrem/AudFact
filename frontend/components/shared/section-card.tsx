import * as React from "react";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

type SectionCardProps = React.HTMLAttributes<HTMLDivElement> & {
  title?: string;
  description?: string;
  actions?: React.ReactNode;
};

export function SectionCard({
  title,
  description,
  actions,
  className,
  children,
  ...props
}: SectionCardProps) {
  return (
    <Card
      className={cn(
        "min-w-0 overflow-hidden rounded-xl border text-slate-100",
        className,
      )}
      {...props}
    >
      <div className="min-w-0 px-4 py-4 md:px-5 md:py-5">
        {(title || description || actions) && (
          <header className="mb-4 flex min-w-0 flex-col gap-3 border-b border-white/10 pb-4 md:flex-row md:items-start md:justify-between">
            <div className="min-w-0 space-y-1">
              {title ? (
                <h2 className="[font-family:var(--font-heading)] text-lg font-semibold tracking-tight text-white">
                  {title}
                </h2>
              ) : null}
              {description ? (
                <p className="max-w-3xl text-sm leading-6 text-slate-400">{description}</p>
              ) : null}
            </div>
            {actions ? <div className="shrink-0 self-start md:self-auto">{actions}</div> : null}
          </header>
        )}
        {children}
      </div>
    </Card>
  );
}
