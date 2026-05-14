"use client";

import * as React from "react";
import { Toaster } from "sonner";

import { QueryProvider } from "@/providers/query-provider";
import { ThemeProvider } from "@/providers/theme-provider";
import { TooltipProvider } from "@/components/ui/tooltip";

export function AppProviders({ children }: { children: React.ReactNode }) {
  return (
    <ThemeProvider>
      <QueryProvider>
        <TooltipProvider delayDuration={250} skipDelayDuration={100}>
          {children}
        </TooltipProvider>
        <Toaster position="top-right" richColors />
      </QueryProvider>
    </ThemeProvider>
  );
}
