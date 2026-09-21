import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex min-h-6 items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11px] font-semibold tracking-[0.01em] focus:outline-none focus:ring-2 focus:ring-ring [&_svg]:h-3 [&_svg]:w-3",
  {
    variants: {
      variant: {
        default:
          "border-primary/35 bg-primary text-primary-foreground",
        secondary:
          "border-border bg-secondary text-secondary-foreground",
        destructive:
          "border-destructive/45 bg-destructive text-destructive-foreground",
        outline: "text-foreground",
        success: "border-emerald-500/25 bg-emerald-500/10 text-emerald-300",
        warning: "border-amber-500/25 bg-amber-500/10 text-amber-300",
        danger: "border-rose-500/25 bg-rose-500/10 text-rose-300",
        info: "border-sky-500/25 bg-sky-500/10 text-sky-300",
        human: "border-violet-500/25 bg-violet-500/10 text-violet-300",
        neutral: "border-border bg-muted text-muted-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <div className={cn(badgeVariants({ variant }), className)} {...props} />
  )
}

export { Badge, badgeVariants }
