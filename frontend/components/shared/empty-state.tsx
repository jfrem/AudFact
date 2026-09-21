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
    <div
      className="flex min-h-52 items-center justify-center border-y border-dashed border-border px-5 py-10 text-center"
      role="status"
    >
      <div className="max-w-md">
        <div
          className="mx-auto flex h-10 w-10 items-center justify-center rounded-md border border-border bg-muted/55 text-muted-foreground"
          aria-hidden="true"
        >
          {icon ?? <SearchSlash className="h-5 w-5" />}
        </div>
        <p className="mt-4 font-medium text-foreground">{title}</p>
        {description ? (
          <p className="mt-1.5 text-sm leading-6 text-muted-foreground">{description}</p>
        ) : null}
        {action ? <div className="mt-4">{action}</div> : null}
      </div>
    </div>
  );
}
