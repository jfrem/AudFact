import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"

import { cn } from "@/lib/utils"
import { Spinner } from "@/components/ui/spinner"

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-semibold transition-colors duration-150 ease-[cubic-bezier(0.16,1,0.3,1)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-45 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "border border-primary/35 bg-primary text-primary-foreground hover:bg-[oklch(0.81_0.12_244)]",
        destructive:
          "border border-destructive/45 bg-destructive text-destructive-foreground hover:bg-destructive/90",
        outline:
          "border border-border bg-transparent text-foreground hover:border-[var(--border-strong)] hover:bg-[var(--surface-hover)]",
        secondary:
          "border border-border bg-secondary text-secondary-foreground hover:bg-[var(--surface-hover)] hover:text-foreground",
        ghost: "border border-transparent text-muted-foreground hover:bg-[var(--surface-hover)] hover:text-foreground",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-8 px-3 text-xs",
        lg: "h-11 px-6",
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
  loading?: boolean
  loadingLabel?: React.ReactNode
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  (
    {
      className,
      variant,
      size,
      asChild = false,
      loading = false,
      loadingLabel,
      disabled,
      children,
      ...props
    },
    ref,
  ) => {
    const Comp = asChild ? Slot : "button"
    const isDisabled = disabled || loading
    const content = asChild ? (
      children
    ) : (
      <>
        {loading ? <Spinner aria-hidden="true" /> : null}
        {loading && loadingLabel ? loadingLabel : children}
      </>
    )

    return (
      <Comp
        ref={ref}
        {...props}
        className={cn(buttonVariants({ variant, size, className }))}
        disabled={asChild ? disabled : isDisabled}
        aria-busy={loading || props["aria-busy"] || undefined}
        data-loading={loading ? "true" : undefined}
      >
        {content}
      </Comp>
    )
  }
)
Button.displayName = "Button"

export { Button, buttonVariants }
