import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex min-h-6 items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tracking-[0.02em] transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-0",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground hover:bg-primary/80",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground hover:bg-secondary/80",
        destructive:
          "border-transparent bg-destructive text-destructive-foreground hover:bg-destructive/80",
        outline: "text-foreground",
        success: "border-emerald-500/25 bg-emerald-500/14 text-emerald-300 hover:bg-emerald-500/20",
        warning: "border-amber-500/25 bg-amber-500/14 text-amber-300 hover:bg-amber-500/20",
        danger: "border-rose-500/25 bg-rose-500/14 text-rose-300 hover:bg-rose-500/20",
        info: "border-sky-500/25 bg-sky-500/14 text-sky-300 hover:bg-sky-500/20",
        human: "border-violet-500/25 bg-violet-500/14 text-violet-300 hover:bg-violet-500/20",
        neutral: "border-slate-500/25 bg-slate-500/14 text-slate-300 hover:bg-slate-500/20",
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
