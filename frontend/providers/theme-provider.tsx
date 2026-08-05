"use client";

import * as React from "react";
import { ThemeProvider as NextThemesProvider } from "next-themes";

import { appConfig } from "@/lib/api/config";

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  return (
    <NextThemesProvider
      attribute="class"
      defaultTheme={appConfig.defaultTheme}
      enableSystem={false}
      disableTransitionOnChange
    >
      {children}
    </NextThemesProvider>
  );
}
