"use client";

import { useMemo, useState, useTransition } from "react";
import {
  AlertTriangle,
  Calendar,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  FileText,
  Layers3,
  RefreshCw,
  Search,
  ShieldX,
  XCircle,
} from "lucide-react";

import { getAuditMonthlyPerformance } from "@/lib/api/audfact";
import {
  downloadMonthlyPerformance,
  type MonthlyPerformanceExportFormat,
} from "@/lib/export/monthly-performance-export";
import { formatNumber } from "@/lib/formatters";
import { MONTH_NAMES, summarizeMonthlyPerformance } from "@/lib/monthly-performance";
import type {
  AuditMonthlyPerformanceData,
  AuditMonthlyPerformanceItem,
} from "@/lib/schemas/domain";
import { SectionCard } from "@/components/shared/section-card";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";

type RateTone = "success" | "warning" | "danger";

function rateTone(rate: number): RateTone {
  if (rate >= 70) return "success";
  if (rate >= 50) return "warning";
  return "danger";
}

export function MonthlyClientPerformance({
  initialData,
}: {
  initialData: AuditMonthlyPerformanceData;
}) {
  const [data, setData] = useState(initialData);
  const [selectedYear, setSelectedYear] = useState(initialData.year);
  const [filterQuery, setFilterQuery] = useState("");
  const [isPending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);
  const [isExportMenuOpen, setIsExportMenuOpen] = useState(false);
  const availableYears = [initialData.year, initialData.year - 1, initialData.year - 2];

  const handleYearChange = (year: number) => {
    setIsExportMenuOpen(false);
    setSelectedYear(year);
    setError(null);
    setExportError(null);

    startTransition(async () => {
      try {
        const result = await getAuditMonthlyPerformance({ year });
        if (!result || result.year !== year) {
          throw new Error("La API no retornó el consolidado solicitado.");
        }
        setData(result);
      } catch (requestError) {
        setError(
          requestError instanceof Error
            ? requestError.message
            : "No se pudo cargar el año seleccionado.",
        );
      }
    });
  };

  const filteredItems = useMemo(() => {
    const query = filterQuery.toLocaleLowerCase("es-CO").trim();
    if (!query) return data.items;

    return data.items.filter(
      (item) =>
        item.tercero.toLocaleLowerCase("es-CO").includes(query) ||
        String(item.fac_nit_sec).includes(query) ||
        (MONTH_NAMES[item.mes] ?? "").toLocaleLowerCase("es-CO").includes(query),
    );
  }, [data.items, filterQuery]);

  const filteredTotals = useMemo(() => summarizeMonthlyPerformance(filteredItems), [filteredItems]);
  const canExport = !isPending && data.year === selectedYear && filteredItems.length > 0;

  const handleExport = (format: MonthlyPerformanceExportFormat) => {
    if (!canExport) return;
    setIsExportMenuOpen(false);
    setExportError(null);
    try {
      downloadMonthlyPerformance({ year: data.year, items: filteredItems }, format);
    } catch {
      setExportError("No se pudo generar el archivo. Intenta exportarlo de nuevo.");
    }
  };

  const { summary } = data;

  return (
    <SectionCard
      title="Producción mensual por EPS"
      description="Facturas conformadas, objeciones y soportes documentales procesados por la IA."
      actions={
        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Controles de rendimiento">
          <Calendar className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <div className="flex rounded-md border border-border bg-muted/35 p-0.5">
            {availableYears.map((year) => (
              <Button
                key={year}
                type="button"
                variant={selectedYear === year ? "secondary" : "ghost"}
                size="sm"
                disabled={isPending}
                onClick={() => handleYearChange(year)}
                className={cn("h-7 px-2.5 font-mono text-[11px]", selectedYear === year && "text-primary")}
                aria-pressed={selectedYear === year}
              >
                {year}
              </Button>
            ))}
          </div>
          {isPending ? <RefreshCw className="h-4 w-4 animate-spin text-primary" aria-label="Actualizando" /> : null}

          <Popover open={isExportMenuOpen} onOpenChange={setIsExportMenuOpen}>
            <PopoverTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={!canExport}
                className="h-7 gap-1.5 px-2.5 text-[11px] font-medium tracking-tight"
                aria-label="Exportar datos de la tabla"
              >
                <Download className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                <span>Exportar</span>
              </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-56 p-2" aria-label="Formatos de exportación">
              <div className="px-2 py-1.5 border-b border-border/60">
                <p className="text-xs font-semibold text-foreground">Exportar datos</p>
                <p className="text-[10px] text-muted-foreground">
                  {filteredItems.length} registros &bull; Año {data.year}
                </p>
              </div>
              <div className="mt-1 flex flex-col gap-0.5">
                <Button
                  type="button"
                  variant="ghost"
                  disabled={!canExport}
                  onClick={() => handleExport("xlsx")}
                  className="h-auto w-full justify-start gap-2.5 px-2 py-2 text-left text-xs"
                >
                  <FileSpreadsheet className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  <span>
                    <span className="block font-medium leading-none">Excel (.xlsx)</span>
                    <span className="mt-0.5 block text-[11px] text-muted-foreground">Hoja de cálculo con formato</span>
                  </span>
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  disabled={!canExport}
                  onClick={() => handleExport("csv")}
                  className="h-auto w-full justify-start gap-2.5 px-2 py-2 text-left text-xs"
                >
                  <FileText className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
                  <span>
                    <span className="block font-medium leading-none">CSV (.csv)</span>
                    <span className="mt-0.5 block text-[11px] text-muted-foreground">Texto separado por comas</span>
                  </span>
                </Button>
              </div>
            </PopoverContent>
          </Popover>
        </div>
      }
    >
      {error ? (
        <Alert variant="destructive" className="mb-5" aria-live="polite">
          <AlertTriangle />
          <AlertDescription className="flex items-center justify-between gap-3">
            <span>{error}</span>
            <Button type="button" variant="ghost" size="sm" disabled={isPending} onClick={() => handleYearChange(selectedYear)}>
              Reintentar
            </Button>
          </AlertDescription>
        </Alert>
      ) : null}
      {exportError ? (
        <Alert variant="destructive" className="mb-5" aria-live="polite">
          <AlertTriangle />
          <AlertDescription>{exportError}</AlertDescription>
        </Alert>
      ) : null}

      <div className="grid overflow-hidden rounded-md border border-border sm:grid-cols-2 xl:grid-cols-4">
        <SummaryMetric
          icon={<Layers3 />}
          label="Facturas"
          value={formatNumber(summary.total_facturas)}
          detail={`Auditadas en ${data.year}`}
        />
        <SummaryMetric
          icon={<FileText />}
          label="Soportes IA"
          value={formatNumber(summary.total_documentos)}
          detail="Documentos evaluados"
        />
        <SummaryMetric
          icon={<CheckCircle2 />}
          label="Conformidad"
          value={`${summary.global_rate_conf.toFixed(1)}%`}
          detail={`${formatNumber(summary.total_conformes)} conformes`}
          tone={rateTone(summary.global_rate_conf)}
        />
        <SummaryMetric
          icon={<ShieldX />}
          label="Objetadas"
          value={formatNumber(summary.total_rechazadas)}
          detail={`${
            summary.total_facturas > 0
              ? ((summary.total_rechazadas / summary.total_facturas) * 100).toFixed(1)
              : "0.0"
          }% del total`}
          tone="danger"
        />
      </div>

      <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h3 className="text-xs font-semibold uppercase tracking-[0.16em] text-foreground/85">
            Detalle consolidado
          </h3>
          <p className="mt-1 text-xs text-muted-foreground">
            {formatNumber(filteredItems.length)} combinaciones de mes y cliente.
          </p>
        </div>
        <label className="relative block w-full sm:w-72">
          <span className="sr-only">Filtrar por EPS o mes</span>
          <Search className="pointer-events-none absolute left-3 top-3 h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <Input
            value={filterQuery}
            onChange={(event) => setFilterQuery(event.target.value)}
            placeholder="Filtrar por EPS, NIT o mes"
            className="pl-9"
          />
        </label>
      </div>

      <div className="mt-3">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Mes</TableHead>
              <TableHead>Cliente / EPS</TableHead>
              <TableHead className="text-right">Conformes</TableHead>
              <TableHead className="text-right">Objetadas</TableHead>
              <TableHead className="text-right">Facturas</TableHead>
              <TableHead className="text-center">% Conformidad</TableHead>
              <TableHead className="text-right">Soportes IA</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredItems.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="h-28 text-center text-muted-foreground">
                  No hay registros para este filtro.
                </TableCell>
              </TableRow>
            ) : (
              filteredItems.map((item, index) => (
                <PerformanceRow
                  key={`${item.mes}-${item.fac_nit_sec}-${index}`}
                  item={item}
                />
              ))
            )}
          </TableBody>
          {filteredItems.length > 0 ? (
            <TableFooter>
              <TableRow>
                <TableCell colSpan={2} className="text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
                  Total visible, {data.year}
                </TableCell>
                <TableCell className="text-right font-mono text-emerald-300">
                  {formatNumber(filteredTotals.totalConf)}
                </TableCell>
                <TableCell className="text-right font-mono text-rose-300">
                  {formatNumber(filteredTotals.totalRech)}
                </TableCell>
                <TableCell className="text-right font-mono font-semibold">
                  {formatNumber(filteredTotals.totalFacturas)}
                </TableCell>
                <TableCell className="text-center">
                  <RateLabel rate={filteredTotals.rate} />
                </TableCell>
                <TableCell className="text-right font-mono text-sky-300">
                  {formatNumber(filteredTotals.totalDocs)}
                </TableCell>
              </TableRow>
            </TableFooter>
          ) : null}
        </Table>
      </div>
    </SectionCard>
  );
}

function SummaryMetric({
  icon,
  label,
  value,
  detail,
  tone = "neutral",
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  detail: string;
  tone?: RateTone | "neutral";
}) {
  const toneClass = {
    neutral: "text-foreground",
    success: "text-emerald-300",
    warning: "text-amber-300",
    danger: "text-rose-300",
  };

  return (
    <div className="border-b border-border p-4 last:border-b-0 sm:[&:nth-last-child(-n+2)]:border-b-0 sm:[&:nth-child(even)]:border-l xl:border-b-0 xl:border-l xl:first:border-l-0">
      <div className="flex items-center gap-2 text-muted-foreground [&_svg]:h-4 [&_svg]:w-4" aria-hidden="true">
        {icon}
        <span className="text-[10px] font-semibold uppercase tracking-[0.16em]">{label}</span>
      </div>
      <p className={cn("mt-3 font-mono text-xl font-semibold tabular-nums", toneClass[tone])}>
        {value}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}

function PerformanceRow({ item }: { item: AuditMonthlyPerformanceItem }) {
  return (
    <TableRow>
      <TableCell className="font-medium">{MONTH_NAMES[item.mes] ?? `Mes ${item.mes}`}</TableCell>
      <TableCell>
        <p className="max-w-72 truncate font-medium text-foreground">{item.tercero}</p>
        <p className="mt-0.5 font-mono text-[10px] text-muted-foreground">NIT {item.fac_nit_sec}</p>
      </TableCell>
      <TableCell className="text-right font-mono text-emerald-300">{formatNumber(item.aud_conf)}</TableCell>
      <TableCell className="text-right font-mono text-rose-300">{formatNumber(item.aud_rech)}</TableCell>
      <TableCell className="text-right font-mono font-semibold">{formatNumber(item.total)}</TableCell>
      <TableCell className="text-center"><RateLabel rate={item.rate_conf} /></TableCell>
      <TableCell className="text-right font-mono text-sky-300">{formatNumber(item.total_doc)}</TableCell>
    </TableRow>
  );
}

function RateLabel({ rate }: { rate: number }) {
  const tone = rateTone(rate);
  const config = {
    success: { icon: CheckCircle2, className: "border-emerald-500/25 bg-emerald-500/10 text-emerald-300" },
    warning: { icon: AlertTriangle, className: "border-amber-500/25 bg-amber-500/10 text-amber-300" },
    danger: { icon: XCircle, className: "border-rose-500/25 bg-rose-500/10 text-rose-300" },
  }[tone];
  const Icon = config.icon;

  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded px-2 py-1 font-mono text-[11px] font-semibold", config.className)}>
      <Icon className="h-3 w-3" aria-hidden="true" />
      {rate.toFixed(1)}%
    </span>
  );
}
