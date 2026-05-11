import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-lg text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-0 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "border border-sky-500/30 bg-sky-500 text-slate-950 hover:bg-sky-400",
        destructive:
          "border border-rose-500/30 bg-destructive text-destructive-foreground hover:bg-destructive/90",
        outline:
          "border border-input bg-background/70 text-slate-100 hover:border-white/14 hover:bg-white/[0.04]",
        secondary:
          "border border-white/10 bg-secondary text-slate-200 hover:bg-white/[0.06]",
        ghost: "text-slate-300 hover:bg-white/[0.04] hover:text-slate-100",
        link: "text-primary underline-offset-4 hover:underline",
        gradient: "border border-sky-500/30 bg-sky-500 text-slate-950 hover:bg-sky-400",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-8 rounded-md px-3",
        lg: "h-11 rounded-lg px-8",
        icon: "h-10 w-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : "button"
    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        {...props}
      />
    )
  }
)
Button.displayName = "Button"

export { Button, buttonVariants }
