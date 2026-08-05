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
  ShieldCheck,
  Users2,
} from "lucide-react";

export const navigationSections = [
  {
    label: "Operación",
    items: [
      { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
      { href: "/audit/single", label: "Auditoría 1:1", icon: ClipboardCheck },
      { href: "/audit/batch", label: "Auditoría batch", icon: Files },
      { href: "/audit/jobs", label: "Jobs async", icon: Clock3 },
      { href: "/audit/results", label: "Resultados", icon: BarChart3 },
      {
        href: "/audit/documents-history",
        label: "Historial documental",
        icon: FileSearch,
      },
    ],
  },
  {
    label: "Consulta",
    items: [
      { href: "/invoices", label: "Facturas", icon: PackageSearch },
      { href: "/clients", label: "Clientes", icon: Users2 },
      { href: "/dispensation", label: "Dispensación", icon: ScanSearch },
      { href: "/observability", label: "Observabilidad", icon: Activity },
    ],
  },
  {
    label: "Configuración",
    items: [
      {
        href: "/clients/audit-config",
        label: "Config auditoría",
        icon: Settings2,
      },
    ],
  },
];

export const productLabel = process.env.NEXT_PUBLIC_APP_NAME ?? "AudFact";

export const statusLegend = [
  { key: "CONCILIADO", label: "Conciliado" },
  { key: "DISCREPANCIA", label: "Discrepancia" },
] as const;

export const healthLegend = {
  ok: { label: "OK", tone: "emerald" },
  warn: { label: "Alerta", tone: "amber" },
  fail: { label: "Falla", tone: "rose" },
  unknown: { label: "Desconocido", tone: "slate" },
};

export const auditWorkspaceTabs = [
  { key: "summary", label: "Resumen", icon: ShieldCheck },
  { key: "findings", label: "Hallazgos", icon: FileSearch },
  { key: "attachments", label: "Adjuntos", icon: Files },
] as const;
