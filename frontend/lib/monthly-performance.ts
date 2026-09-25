import type { AuditMonthlyPerformanceItem } from "@/lib/schemas/domain";

export const MONTH_NAMES: Readonly<Record<number, string>> = {
  1: "Enero", 2: "Febrero", 3: "Marzo", 4: "Abril",
  5: "Mayo", 6: "Junio", 7: "Julio", 8: "Agosto",
  9: "Septiembre", 10: "Octubre", 11: "Noviembre", 12: "Diciembre",
};

/** El porcentaje consolidado se pondera por facturas, no por filas. */
export function summarizeMonthlyPerformance(items: readonly AuditMonthlyPerformanceItem[]) {
  const totals = {
    totalFacturas: 0,
    totalConf: 0,
    totalRech: 0,
    totalDocConf: 0,
    totalDocRech: 0,
    totalDocs: 0,
  };

  for (const item of items) {
    totals.totalFacturas += item.total;
    totals.totalConf += item.aud_conf;
    totals.totalRech += item.aud_rech;
    totals.totalDocConf += item.aud_conf_doc;
    totals.totalDocRech += item.aud_rech_doc;
    totals.totalDocs += item.total_doc;
  }

  return {
    ...totals,
    rate: totals.totalFacturas > 0 ? (totals.totalConf / totals.totalFacturas) * 100 : 0,
  };
}
