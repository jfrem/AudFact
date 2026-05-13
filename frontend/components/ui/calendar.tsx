"use client";

import * as React from "react";
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp } from "lucide-react";
import {
  DayPicker,
  type DayPickerProps,
  type ChevronProps,
} from "react-day-picker";

import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

function Calendar({
  className,
  classNames,
  components,
  showOutsideDays = true,
  ...props
}: DayPickerProps) {
  return (
    <DayPicker
      showOutsideDays={showOutsideDays}
      className={cn("relative p-3", className)}
      classNames={{
        root: cn("rdp text-slate-100", classNames?.root),
        months: cn("flex flex-col gap-4 sm:flex-row", classNames?.months),
        month: cn("space-y-4", classNames?.month),
        month_caption: cn("flex h-9 items-center justify-center", classNames?.month_caption),
        caption_label: cn("text-sm font-medium text-slate-100", classNames?.caption_label),
        nav: cn("absolute inset-x-3 top-3 flex items-center justify-between", classNames?.nav),
        button_previous: cn(
          buttonVariants({ variant: "ghost", size: "icon" }),
          "h-8 w-8 rounded-md text-slate-400 hover:bg-white/[0.05] hover:text-slate-100",
          classNames?.button_previous,
        ),
        button_next: cn(
          buttonVariants({ variant: "ghost", size: "icon" }),
          "h-8 w-8 rounded-md text-slate-400 hover:bg-white/[0.05] hover:text-slate-100",
          classNames?.button_next,
        ),
        chevron: cn("h-4 w-4", classNames?.chevron),
        month_grid: cn("w-full border-collapse", classNames?.month_grid),
        weekdays: cn("flex", classNames?.weekdays),
        weekday: cn(
          "flex h-8 w-9 items-center justify-center rounded-md text-[0.72rem] font-medium text-slate-500",
          classNames?.weekday,
        ),
        weeks: cn("space-y-1", classNames?.weeks),
        week: cn("flex w-full", classNames?.week),
        day: cn("relative h-9 w-9 p-0 text-center text-sm", classNames?.day),
        day_button: cn(
          "flex h-9 w-9 items-center justify-center rounded-md text-sm text-slate-200 transition-colors duration-150 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none",
          classNames?.day_button,
        ),
        selected: cn(
          "[&>button]:bg-sky-500 [&>button]:font-medium [&>button]:text-slate-950 [&>button]:hover:bg-sky-400 [&>button]:hover:text-slate-950",
          classNames?.selected,
        ),
        today: cn("[&>button]:border [&>button]:border-sky-400/40 [&>button]:text-sky-200", classNames?.today),
        outside: cn("[&>button]:text-slate-600 [&>button]:opacity-60", classNames?.outside),
        disabled: cn("[&>button]:text-slate-700 [&>button]:opacity-40", classNames?.disabled),
        hidden: cn("invisible", classNames?.hidden),
        range_start: cn("[&>button]:rounded-r-none", classNames?.range_start),
        range_middle: cn("[&>button]:rounded-none [&>button]:bg-sky-500/15 [&>button]:text-sky-100", classNames?.range_middle),
        range_end: cn("[&>button]:rounded-l-none", classNames?.range_end),
      }}
      components={{
        Chevron: CalendarChevron,
        ...components,
      }}
      {...props}
    />
  );
}

function CalendarChevron({ orientation = "left", className, size = 16 }: ChevronProps) {
  const Icon =
    orientation === "right"
      ? ChevronRight
      : orientation === "up"
        ? ChevronUp
        : orientation === "down"
          ? ChevronDown
          : ChevronLeft;

  return <Icon aria-hidden="true" className={cn("h-4 w-4", className)} size={size} />;
}

Calendar.displayName = "Calendar";

export { Calendar };
