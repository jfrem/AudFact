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
          "border-white/10 bg-white/[0.03] text-slate-200 hover:border-white/14 hover:bg-white/[0.045]",
        subtle:
          "border-white/10 bg-white/[0.025] text-slate-200 hover:border-white/12 hover:bg-white/[0.04]",
        ghost:
          "border-transparent bg-transparent text-slate-200 hover:border-white/10 hover:bg-white/[0.035]",
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
      "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/[0.04] text-slate-300",
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
    className={cn("truncate text-sm font-medium text-white", className)}
    {...props}
  />
));
ItemTitle.displayName = "ItemTitle";

export { Item, ItemContent, ItemMedia, ItemTitle };
