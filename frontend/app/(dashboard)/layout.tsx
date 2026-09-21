import type { ReactNode } from "react";

import { AppSidebar } from "@/components/layout/app-sidebar";
import { AppTopbar } from "@/components/layout/app-topbar";

export const dynamic = "force-dynamic";

export default function DashboardLayout({ children }: { children: ReactNode }) {
  return (
    <div className="min-h-dvh w-full min-w-0 overflow-x-clip bg-background text-foreground">
      <div className="mx-auto flex min-h-dvh w-full min-w-0 max-w-[1920px]">
        <AppSidebar />
        <div className="flex min-w-0 flex-1 flex-col">
          <AppTopbar />
          <main id="main-content" className="min-w-0 flex-1 overflow-x-hidden px-4 py-5 sm:px-6 lg:px-8 lg:py-7 xl:px-10">
            <div className="mx-auto w-full max-w-[1640px]">{children}</div>
          </main>
        </div>
      </div>
    </div>
  );
}
