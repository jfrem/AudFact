import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const alertVariants = cva(
  "relative grid w-full grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg border px-4 py-3 text-sm [&>svg]:mt-0.5 [&>svg]:size-4 [&>svg]:shrink-0",
  {
    variants: {
      variant: {
        default:
          "border-white/10 bg-white/[0.035] text-slate-200 [&>svg]:text-slate-300",
        info:
          "border-sky-500/20 bg-sky-500/[0.06] text-sky-100/90 [&>svg]:text-sky-300",
        success:
          "border-emerald-500/20 bg-emerald-500/[0.06] text-emerald-100/90 [&>svg]:text-emerald-300",
        warning:
          "border-amber-500/20 bg-amber-500/[0.06] text-amber-100/90 [&>svg]:text-amber-300",
        destructive:
          "border-rose-500/20 bg-rose-500/[0.06] text-rose-100/90 [&>svg]:text-rose-300",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

type AlertProps = React.HTMLAttributes<HTMLDivElement> &
  VariantProps<typeof alertVariants>;

const Alert = React.forwardRef<HTMLDivElement, AlertProps>(
  ({ className, variant, role = "alert", ...props }, ref) => (
    <div
      ref={ref}
      role={role}
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  ),
);
Alert.displayName = "Alert";

const AlertTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, ...props }, ref) => (
  <h5
    ref={ref}
    className={cn("font-semibold leading-none text-white", className)}
    {...props}
  />
));
AlertTitle.displayName = "AlertTitle";

const AlertDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("col-start-2 text-sm leading-6 text-current/80", className)}
    {...props}
  />
));
AlertDescription.displayName = "AlertDescription";

export { Alert, AlertTitle, AlertDescription };
