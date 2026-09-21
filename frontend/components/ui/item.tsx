import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const itemVariants = cva(
  "group flex min-w-0 items-start gap-3 rounded-lg border text-left transition-colors",
  {
    variants: {
      variant: {
        default:
          "border-border bg-card text-foreground hover:border-[var(--border-strong)] hover:bg-[var(--surface-hover)]",
        subtle:
          "border-border bg-background text-foreground hover:border-[var(--border-strong)] hover:bg-[var(--surface-hover)]",
        ghost:
          "border-transparent bg-transparent text-foreground hover:border-border hover:bg-[var(--surface-hover)]",
      },
      size: {
        default: "px-4 py-3.5",
        sm: "px-3 py-2.5",
        lg: "px-5 py-4",
      },
      align: {
        start: "items-start",
        center: "items-center",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
      align: "start",
    },
  },
);

type ItemProps = React.HTMLAttributes<HTMLDivElement> &
  VariantProps<typeof itemVariants> & {
    asChild?: boolean;
  };

const Item = React.forwardRef<HTMLDivElement, ItemProps>(
  ({ className, variant, size, align, asChild = false, ...props }, ref) => {
    const Comp = asChild ? Slot : "div";

    return (
      <Comp
        ref={ref}
        className={cn(itemVariants({ variant, size, align }), className)}
        {...props}
      />
    );
  },
);
Item.displayName = "Item";

const ItemMedia = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn(
      "flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-muted-foreground",
      className,
    )}
    {...props}
  />
));
ItemMedia.displayName = "ItemMedia";

const ItemContent = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div ref={ref} className={cn("min-w-0 flex-1", className)} {...props} />
));
ItemContent.displayName = "ItemContent";

const ItemTitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("truncate text-sm font-medium text-foreground", className)}
    {...props}
  />
));
ItemTitle.displayName = "ItemTitle";

export { Item, ItemContent, ItemMedia, ItemTitle };
