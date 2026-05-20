import type { ReactNode } from "react";

import { AppSidebar } from "@/components/layout/app-sidebar";
import { AppTopbar } from "@/components/layout/app-topbar";

export const dynamic = "force-dynamic";

export default function DashboardLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh w-full min-w-0 overflow-x-hidden px-2 py-2 text-slate-100 sm:px-3 sm:py-3 md:px-4 md:py-4">
      <div className="mx-auto flex min-h-[calc(100dvh-1rem)] w-full min-w-0 max-w-[1800px] gap-2 sm:gap-3 lg:gap-4">
        <AppSidebar />
        <div className="flex min-w-0 flex-1 flex-col gap-4 lg:gap-6">
          <AppTopbar />
          <main className="scrollbar-thin min-w-0 flex-1 overflow-x-hidden overflow-y-auto pb-2">{children}</main>
        </div>
      </div>
    </div>
  );
}
