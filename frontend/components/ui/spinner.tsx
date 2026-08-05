import * as React from "react";
import { LoaderCircle } from "lucide-react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const spinnerVariants = cva("animate-spin text-current", {
  variants: {
    size: {
      sm: "h-3.5 w-3.5",
      default: "h-4 w-4",
      lg: "h-5 w-5",
    },
  },
  defaultVariants: {
    size: "default",
  },
});

type SpinnerProps = Omit<
  React.ComponentPropsWithoutRef<typeof LoaderCircle>,
  "size"
> &
  VariantProps<typeof spinnerVariants>;

function Spinner({ className, size, ...props }: SpinnerProps) {
  const isDecorative = !props["aria-label"] && !props["aria-labelledby"];

  return (
    <LoaderCircle
      aria-hidden={isDecorative ? true : undefined}
      role={isDecorative ? undefined : "status"}
      className={cn(spinnerVariants({ size }), className)}
      {...props}
    />
  );
}

export { Spinner };
