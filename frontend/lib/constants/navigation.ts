import {
  Activity,
  BarChart3,
  ClipboardCheck,
  Clock3,
  FileSearch,
  Files,
  LayoutDashboard,
  PackageSearch,
  ScanSearch,
  Settings2,
  Users2,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

export type NavigationItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  code: string;
};

type NavigationSection = {
  label: string;
  items: readonly NavigationItem[];
};

export const navigationSections: readonly NavigationSection[] = [
  {
    label: "Operación",
    items: [
      { href: "/dashboard", label: "Mesa de control", icon: LayoutDashboard, code: "01" },
      { href: "/audit/single", label: "Auditoría 1:1", icon: ClipboardCheck, code: "02" },
      { href: "/audit/batch", label: "Auditoría batch", icon: Files, code: "03" },
      { href: "/audit/jobs", label: "Jobs async", icon: Clock3, code: "04" },
      { href: "/audit/results", label: "Resultados", icon: BarChart3, code: "05" },
      {
        href: "/audit/documents-history",
        label: "Historial documental",
        icon: FileSearch,
        code: "06",
      },
    ],
  },
  {
    label: "Consulta",
    items: [
      { href: "/invoices", label: "Facturas", icon: PackageSearch, code: "07" },
      { href: "/clients", label: "Clientes", icon: Users2, code: "08" },
      { href: "/dispensation", label: "Dispensación", icon: ScanSearch, code: "09" },
      { href: "/observability", label: "Observabilidad", icon: Activity, code: "10" },
    ],
  },
  {
    label: "Configuración",
    items: [
      {
        href: "/clients/audit-config",
        label: "Config auditoría",
        icon: Settings2,
        code: "11",
      },
    ],
  },
];

export const productLabel = process.env.NEXT_PUBLIC_APP_NAME ?? "AudFact";
