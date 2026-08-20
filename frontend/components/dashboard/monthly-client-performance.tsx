"use client";

import { useMemo, useState, useTransition } from "react";
import {
  BarChart3,
  Calendar,
  FileText,
  Layers,
  RefreshCw,
  Search,
  ShieldAlert,
  ShieldCheck,
} from "lucide-react";
import type {
  AuditMonthlyPerformanceData,
  AuditMonthlyPerformanceItem,
} from "@/lib/schemas/domain";
import { getAuditMonthlyPerformance } from "@/lib/api/audfact";
import { formatNumber } from "@/lib/formatters";

const MONTH_NAMES: Record<number, string> = {
  1: "Enero",
  2: "Febrero",
  3: "Marzo",
  4: "Abril",
  5: "Mayo",
  6: "Junio",
  7: "Julio",
  8: "Agosto",
  9: "Septiembre",
  10: "Octubre",
  11: "Noviembre",
  12: "Diciembre",
};

interface MonthlyClientPerformanceProps {
  initialData: AuditMonthlyPerformanceData;
}

export function MonthlyClientPerformance({
  initialData,
}: MonthlyClientPerformanceProps) {
  const [data, setData] = useState<AuditMonthlyPerformanceData>(initialData);
  const [selectedYear, setSelectedYear] = useState<number>(initialData.year);
  const [filterQuery, setFilterQuery] = useState<string>("");
  const [isPending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const handleYearChange = (year: number) => {
    setSelectedYear(year);
    setError(null);
    startTransition(async () => {
      try {
        const result = await getAuditMonthlyPerformance({ year });
        if (result) {
          setData(result);
        }
      } catch (err) {
        setError(
          err instanceof Error
            ? err.message
            : "No se pudieron cargar las estadísticas del año seleccionado",
        );
      }
    });
  };

  // Filtrar filas por búsqueda de texto
  const filteredItems = useMemo(() => {
    if (!filterQuery.trim()) return data.items;
    const query = filterQuery.toLowerCase().trim();
    return data.items.filter(
      (item: AuditMonthlyPerformanceItem) =>
        item.tercero.toLowerCase().includes(query) ||
        String(item.fac_nit_sec).includes(query) ||
        (MONTH_NAMES[item.mes] ?? "").toLowerCase().includes(query),
    );
  }, [data.items, filterQuery]);

  const { summary } = data;

  // Totales calculados de las filas filtradas
  const filteredTotals = useMemo(() => {
    const totalFacturas = filteredItems.reduce((acc, i) => acc + i.total, 0);
    const totalConf = filteredItems.reduce((acc, i) => acc + i.aud_conf, 0);
    const totalRech = filteredItems.reduce((acc, i) => acc + i.aud_rech, 0);
    const totalDocs = filteredItems.reduce((acc, i) => acc + i.total_doc, 0);
    const rate = totalFacturas > 0 ? (totalConf / totalFacturas) * 100 : 0;
    return { totalFacturas, totalConf, totalRech, totalDocs, rate };
  }, [filteredItems]);

  return (
    <section className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 backdrop-blur-sm shadow-xl space-y-6">
      {/* Header & Controls */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-800/80 pb-5">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-400">
              <BarChart3 className="h-4 w-4" />
            </span>
            <h2 className="text-lg font-semibold text-slate-100 tracking-tight">
              Rendimiento y Producción Mensual por EPS
            </h2>
          </div>
          <p className="text-xs text-slate-400">
            Consolidado histórico de facturas conformadas, rechazadas y soportes documentales procesados por la IA
          </p>
        </div>

        {/* Year Selector */}
        <div className="flex items-center gap-3 self-start sm:self-auto">
          <label className="flex items-center gap-2 text-xs font-medium text-slate-400">
            <Calendar className="h-3.5 w-3.5" />
            <span>Año:</span>
          </label>
          <div className="inline-flex rounded-lg border border-slate-700/80 bg-slate-950 p-1">
            {[2026, 2025, 2024].map((year) => (
              <button
                key={year}
                type="button"
                onClick={() => handleYearChange(year)}
                disabled={isPending}
                className={`rounded-md px-3 py-1 text-xs font-medium transition-all ${
                  selectedYear === year
                    ? "bg-emerald-500/20 text-emerald-300 shadow-sm border border-emerald-500/30 font-semibold"
                    : "text-slate-400 hover:text-slate-200"
                }`}
              >
                {year}
              </button>
            ))}
          </div>
          {isPending && (
            <RefreshCw className="h-4 w-4 animate-spin text-emerald-400" />
          )}
        </div>
      </div>

      {error && (
        <div className="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs text-rose-300 flex items-center justify-between">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => handleYearChange(selectedYear)}
            className="font-medium underline hover:text-rose-200"
          >
            Reintentar
          </button>
        </div>
      )}

      {/* KPI Summary Pills */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div className="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
          <span className="text-[11px] font-medium uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
            <Layers className="h-3.5 w-3.5 text-sky-400" />
            Total Facturas
          </span>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-bold tracking-tight text-slate-100 font-mono">
              {formatNumber(summary.total_facturas)}
            </span>
          </div>
          <span className="text-[11px] text-slate-500 mt-1 block">
            Auditadas en {selectedYear}
          </span>
        </div>

        <div className="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
          <span className="text-[11px] font-medium uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
            <FileText className="h-3.5 w-3.5 text-cyan-400" />
            Soportes Evaluados IA
          </span>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-bold tracking-tight text-slate-100 font-mono">
              {formatNumber(summary.total_documentos)}
            </span>
          </div>
          <span className="text-[11px] text-slate-500 mt-1 block">
            Documentos multimodales
          </span>
        </div>

        <div className="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
          <span className="text-[11px] font-medium uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
            <ShieldCheck className="h-3.5 w-3.5 text-emerald-400" />
            Tasa Conformidad
          </span>
          <div className="mt-2 flex items-baseline gap-2">
            <span
              className={`text-2xl font-bold tracking-tight font-mono ${
                summary.global_rate_conf >= 70
                  ? "text-emerald-400"
                  : summary.global_rate_conf >= 50
                    ? "text-amber-400"
                    : "text-rose-400"
              }`}
            >
              {summary.global_rate_conf.toFixed(1)}%
            </span>
          </div>
          <span className="text-[11px] text-emerald-500/80 mt-1 block">
            {formatNumber(summary.total_conformes)} conformes
          </span>
        </div>

        <div className="rounded-xl border border-slate-800 bg-slate-950/70 p-4">
          <span className="text-[11px] font-medium uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
            <ShieldAlert className="h-3.5 w-3.5 text-rose-400" />
            Rechazos / Objeciones
          </span>
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-2xl font-bold tracking-tight text-rose-400 font-mono">
              {formatNumber(summary.total_rechazadas)}
            </span>
          </div>
          <span className="text-[11px] text-rose-500/80 mt-1 block">
            {summary.total_facturas > 0
              ? (
                  (summary.total_rechazadas / summary.total_facturas) *
                  100
                ).toFixed(1)
              : "0.0"}
            % tasa de objeción
          </span>
        </div>
      </div>

      {/* Detailed Breakdown Table */}
      <div className="space-y-3 pt-2">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-300">
            Detalle Consolidado por EPS y Mes
          </h3>
          <div className="relative w-full sm:w-72">
            <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-500" />
            <input
              type="text"
              value={filterQuery}
              onChange={(e) => setFilterQuery(e.target.value)}
              placeholder="Filtrar por EPS o Mes..."
              className="w-full rounded-lg border border-slate-800 bg-slate-950 py-1.5 pl-8 pr-3 text-xs text-slate-200 placeholder-slate-500 focus:border-emerald-500 focus:outline-none"
            />
          </div>
        </div>

        <div className="overflow-x-auto rounded-xl border border-slate-800">
          <table className="w-full text-left text-xs">
            <thead className="border-b border-slate-800 bg-slate-950/80 text-[11px] font-medium uppercase tracking-wider text-slate-400">
              <tr>
                <th className="px-4 py-3">Mes</th>
                <th className="px-4 py-3">Cliente / EPS</th>
                <th className="px-4 py-3 text-right">Facturas OK</th>
                <th className="px-4 py-3 text-right">Facturas Rech.</th>
                <th className="px-4 py-3 text-right">Total Facturas</th>
                <th className="px-4 py-3 text-center">% Conformidad</th>
                <th className="px-4 py-3 text-right">Soportes IA</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/60 bg-slate-900/30 font-mono">
              {filteredItems.length === 0 ? (
                <tr>
                  <td
                    colSpan={7}
                    className="px-4 py-8 text-center text-xs text-slate-500 font-sans"
                  >
                    No se encontraron registros de auditoría para los filtros aplicados.
                  </td>
                </tr>
              ) : (
                filteredItems.map((item: AuditMonthlyPerformanceItem, index: number) => {
                  const monthName = MONTH_NAMES[item.mes] ?? `Mes ${item.mes}`;
                  return (
                    <tr
                      key={`${item.mes}-${item.fac_nit_sec}-${index}`}
                      className="hover:bg-slate-800/40 transition-colors"
                    >
                      <td className="px-4 py-3 font-sans font-medium text-slate-200">
                        {monthName}
                      </td>
                      <td className="px-4 py-3 font-sans">
                        <div className="flex items-center gap-1.5">
                          <span className="font-semibold text-slate-200">
                            {item.tercero}
                          </span>
                          <span className="rounded bg-slate-800 px-1.5 py-0.2 text-[10px] text-slate-400 font-mono">
                            NIT {item.fac_nit_sec}
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right text-emerald-400 font-semibold">
                        {formatNumber(item.aud_conf)}
                      </td>
                      <td className="px-4 py-3 text-right text-rose-400">
                        {formatNumber(item.aud_rech)}
                      </td>
                      <td className="px-4 py-3 text-right font-bold text-slate-100">
                        {formatNumber(item.total)}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <div className="inline-flex items-center gap-2">
                          <span
                            className={`inline-block rounded-md px-2 py-0.5 text-[11px] font-semibold ${
                              item.rate_conf >= 70
                                ? "bg-emerald-500/20 text-emerald-300 border border-emerald-500/30"
                                : item.rate_conf >= 50
                                  ? "bg-amber-500/20 text-amber-300 border border-amber-500/30"
                                  : "bg-rose-500/20 text-rose-300 border border-rose-500/30"
                            }`}
                          >
                            {item.rate_conf.toFixed(1)}%
                          </span>
                          <div className="hidden sm:block h-1.5 w-12 overflow-hidden rounded-full bg-slate-800">
                            <div
                              style={{ width: `${item.rate_conf}%` }}
                              className={`h-full rounded-full ${
                                item.rate_conf >= 70
                                  ? "bg-emerald-500"
                                  : item.rate_conf >= 50
                                    ? "bg-amber-500"
                                    : "bg-rose-500"
                              }`}
                            />
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right text-cyan-300">
                        {formatNumber(item.total_doc)}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
            {filteredItems.length > 0 && (
              <tfoot className="border-t-2 border-slate-700 bg-slate-950/90 font-mono text-xs font-bold text-slate-200">
                <tr>
                  <td colSpan={2} className="px-4 py-3 font-sans text-slate-400 uppercase tracking-wider text-[11px]">
                    Total Consolidado ({selectedYear})
                  </td>
                  <td className="px-4 py-3 text-right text-emerald-400">
                    {formatNumber(filteredTotals.totalConf)}
                  </td>
                  <td className="px-4 py-3 text-right text-rose-400">
                    {formatNumber(filteredTotals.totalRech)}
                  </td>
                  <td className="px-4 py-3 text-right text-white">
                    {formatNumber(filteredTotals.totalFacturas)}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className="inline-block rounded-md bg-emerald-500/20 px-2 py-0.5 text-[11px] font-bold text-emerald-300 border border-emerald-500/30">
                      {filteredTotals.rate.toFixed(1)}%
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right text-cyan-300">
                    {formatNumber(filteredTotals.totalDocs)}
                  </td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      </div>
    </section>
  );
}
