"use client";

import * as React from "react";
import { CalendarIcon } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

const MONTH_LABELS = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
const ISO_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

interface DatePickerInputProps
  extends Omit<
    React.ButtonHTMLAttributes<HTMLButtonElement>,
    "defaultValue" | "onChange" | "value"
  > {
  name?: string;
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  placeholder?: string;
  buttonClassName?: string;
  clearable?: boolean;
}

function DatePickerInput({
  id,
  name,
  value,
  defaultValue = "",
  onValueChange,
  placeholder = "Selecciona fecha",
  buttonClassName,
  className,
  clearable = true,
  disabled,
  ...buttonProps
}: DatePickerInputProps) {
  const [open, setOpen] = React.useState(false);
  const [internalValue, setInternalValue] = React.useState(defaultValue);
  const isControlled = value !== undefined;
  const selectedValue = isControlled ? value : internalValue;
  const selectedDate = React.useMemo(() => parseDateValue(selectedValue), [selectedValue]);

  React.useEffect(() => {
    if (!isControlled) {
      setInternalValue(defaultValue);
    }
  }, [defaultValue, isControlled]);

  const commitValue = (nextValue: string) => {
    if (!isControlled) {
      setInternalValue(nextValue);
    }
    onValueChange?.(nextValue);
  };

  return (
    <div className={cn("min-w-0", className)}>
      {name ? <input type="hidden" name={name} value={selectedValue} /> : null}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            disabled={disabled}
            className={cn(
              "h-11 w-full justify-start px-3 text-left font-normal",
              !selectedValue && "text-slate-500",
              buttonClassName,
            )}
            {...buttonProps}
          >
            <CalendarIcon className="h-4 w-4 text-slate-400" />
            <span className="truncate">
              {selectedDate ? formatDisplayDate(selectedDate) : placeholder}
            </span>
          </Button>
        </PopoverTrigger>
        <PopoverContent
          align="start"
          className="w-auto overflow-hidden rounded-lg border border-white/15 bg-[color:var(--popover)] p-0 text-[color:var(--popover-foreground)] shadow-2xl shadow-slate-950/70"
        >
          <Calendar
            mode="single"
            selected={selectedDate}
            onSelect={(date) => {
              if (!date) return;
              commitValue(formatDateValue(date));
              setOpen(false);
            }}
          />
          {clearable && selectedValue ? (
            <div className="border-t border-white/10 p-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="w-full text-slate-300 hover:text-slate-100"
                onClick={() => {
                  commitValue("");
                  setOpen(false);
                }}
              >
                Limpiar
              </Button>
            </div>
          ) : null}
        </PopoverContent>
      </Popover>
    </div>
  );
}

function parseDateValue(value?: string) {
  if (!value || !ISO_DATE_PATTERN.test(value)) {
    return undefined;
  }

  const [year, month, day] = value.split("-").map(Number);
  const date = new Date(year, month - 1, day, 12, 0, 0, 0);

  if (
    date.getFullYear() !== year ||
    date.getMonth() !== month - 1 ||
    date.getDate() !== day
  ) {
    return undefined;
  }

  return date;
}

function formatDateValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

function formatDisplayDate(date: Date) {
  return `${date.getDate()} ${MONTH_LABELS[date.getMonth()]} ${date.getFullYear()}`;
}

DatePickerInput.displayName = "DatePickerInput";

export { DatePickerInput };
