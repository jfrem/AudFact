import type { ReactNode } from "react";
import type { Metadata as NextMetadata } from "next";
import { IBM_Plex_Sans, Space_Grotesk } from "next/font/google";

import { AppProviders } from "@/providers/app-providers";
import { appConfig } from "@/lib/api/config";
import "@/app/globals.css";

const headingFont = Space_Grotesk({
  subsets: ["latin"],
  variable: "--font-heading",
});

const bodyFont = IBM_Plex_Sans({
  subsets: ["latin"],
  variable: "--font-body",
  weight: ["400", "500", "600", "700"],
});

export const metadata: NextMetadata = {
  title: `${appConfig.appName} | Control Center`,
  description: "Centro de operación para auditoría documental y seguimiento de jobs.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: ReactNode;
}>) {
  return (
    <html lang="es-CO" suppressHydrationWarning>
      <body className={`${headingFont.variable} ${bodyFont.variable}`}>
        <a href="#main-content" className="skip-link">Saltar al contenido principal</a>
        <div id="main-content">
          <AppProviders>{children}</AppProviders>
        </div>
      </body>
    </html>
  );
}
