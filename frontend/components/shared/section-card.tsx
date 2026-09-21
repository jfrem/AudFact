import * as React from "react";

import { cn } from "@/lib/utils";

type SectionCardProps = React.HTMLAttributes<HTMLElement> & {
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
    <section
      className={cn("min-w-0 overflow-hidden rounded-lg border border-border bg-card", className)}
      {...props}
    >
      {(title || description || actions) ? (
        <header className="flex min-w-0 flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between md:px-5">
          <div className="min-w-0">
            {title ? (
              <h2 className="font-display text-base font-semibold tracking-[-0.015em] text-foreground">
                {title}
              </h2>
            ) : null}
            {description ? (
              <p className="mt-1 max-w-[72ch] text-sm leading-5 text-muted-foreground">{description}</p>
            ) : null}
          </div>
          {actions ? <div className="shrink-0">{actions}</div> : null}
        </header>
      ) : null}
      <div className="min-w-0 p-4 md:p-5">{children}</div>
    </section>
  );
}
