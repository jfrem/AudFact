import type { AuditMonthlyPerformanceItem } from "@/lib/schemas/domain";
import { MONTH_NAMES, summarizeMonthlyPerformance } from "../monthly-performance";
import { createStoredZip } from "./stored-zip";

export type MonthlyPerformanceExportFormat = "csv" | "xlsx";

interface ExportPerformanceOptions {
  year: number;
  items: readonly AuditMonthlyPerformanceItem[];
}

type CellValue = string | number;
type ExportRow = readonly CellValue[];

interface ExportColumn {
  csvHeader: string;
  label: string;
  width: number;
  percentage?: boolean;
}

// Ambos formatos comparten el orden, las filas y el significado de las columnas.
const COLUMNS: readonly ExportColumn[] = [
  { csvHeader: "Mes_Nro", label: "Mes Nro", width: 60 },
  { csvHeader: "Mes_Nombre", label: "Mes", width: 90 },
  { csvHeader: "NIT_Cliente", label: "NIT Cliente", width: 80 },
  { csvHeader: "EPS_Aseguradora", label: "EPS / Aseguradora", width: 260 },
  { csvHeader: "Facturas_Conformes", label: "Facturas Conformes", width: 110 },
  { csvHeader: "Facturas_Objetadas", label: "Facturas Objetadas", width: 110 },
  { csvHeader: "Total_Facturas", label: "Total Facturas", width: 100 },
  { csvHeader: "Porcentaje_Conformidad", label: "% Conformidad", width: 100, percentage: true },
  { csvHeader: "Soportes_Conformes", label: "Soportes Conformes", width: 110 },
  { csvHeader: "Soportes_Objetados", label: "Soportes Objetados", width: 110 },
  { csvHeader: "Total_Soportes_IA", label: "Total Soportes IA", width: 110 },
];

function buildRows({ year, items }: ExportPerformanceOptions): ExportRow[] {
  const rows: ExportRow[] = items.map((item) => [
    item.mes, MONTH_NAMES[item.mes], String(item.fac_nit_sec), item.tercero,
    item.aud_conf, item.aud_rech, item.total, item.rate_conf / 100,
    item.aud_conf_doc, item.aud_rech_doc, item.total_doc,
  ]);
  const totals = summarizeMonthlyPerformance(items);
  rows.push([
    "", `TOTALES AÑO ${year}`, "", "CONSOLIDADO VISIBLE",
    totals.totalConf, totals.totalRech, totals.totalFacturas, totals.rate / 100,
    totals.totalDocConf, totals.totalDocRech, totals.totalDocs,
  ]);
  return rows;
}

function escapeCsvField(value: CellValue): string {
  // Las comillas CSV no impiden que Excel ejecute una celda como fórmula.
  const text = typeof value === "string" && (/^\s*[=+@-]/u.test(value) || /^[\t\r\n]/u.test(value))
    ? `'${value}`
    : String(value);
  return /[,"\r\n]/u.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

function serializeCsv(rows: readonly ExportRow[]): string {
  const header = COLUMNS.map((column) => column.csvHeader).join(",");
  const lines = rows.map((row) => row.map((value, index) => {
    const formatted = COLUMNS[index].percentage && typeof value === "number"
      ? `${(value * 100).toFixed(1)}%`
      : value;
    return escapeCsvField(formatted);
  }).join(","));
  // sep= es una directiva de Excel; este archivo no es CSV RFC 4180 estricto.
  return `\uFEFFsep=,\r\n${[header, ...lines].join("\r\n")}\r\n`;
}

function escapeXml(value: CellValue): string {
  // XML 1.0 excluye controles, surrogates aislados y U+FFFE/U+FFFF.
  return Array.from(String(value)).filter((character) => {
    const code = character.codePointAt(0)!;
    return code === 9 || code === 10 || code === 13
      || (code >= 0x20 && code <= 0xd7ff)
      || (code >= 0xe000 && code <= 0xfffd)
      || code >= 0x10000;
  }).join("")
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&apos;");
}

function serializeXlsx(rows: readonly ExportRow[], year: number): ArrayBuffer {
  if (rows.length + 1 > 1048576) throw new Error("Se excedió el límite de filas de Excel.");
  const spreadsheetNs = "http://schemas.openxmlformats.org/spreadsheetml/2006/main";
  const relationshipsNs = "http://schemas.openxmlformats.org/package/2006/relationships";
  const documentRelationshipsNs = "http://schemas.openxmlformats.org/officeDocument/2006/relationships";
  const declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  const allRows = [COLUMNS.map(({ label }) => label), ...rows];
  const sheetRows = allRows.map((row, rowIndex) => {
    const isHeader = rowIndex === 0;
    const isTotal = rowIndex === allRows.length - 1;
    const cells = row.map((value, index) => {
      // Este reporte tiene 11 columnas fijas, A..K.
      const reference = `${String.fromCharCode(65 + index)}${rowIndex + 1}`;
      const isPercentage = !isHeader && COLUMNS[index].percentage;
      const style = isHeader ? 1 : isPercentage ? (isTotal ? 4 : 3) : (isTotal ? 2 : 0);
      if (typeof value === "number") {
        return `<c r="${reference}" s="${style}" t="n"><v>${value}</v></c>`;
      }
      // Los textos, incluso los que empiezan con =, nunca se serializan como fórmulas.
      const text = escapeXml(value).replace(/_x[0-9a-f]{4}_/gi, "_x005F_$&").replace(/\r/g, "&#13;");
      return `<c r="${reference}" s="${style}" t="inlineStr"><is><t xml:space="preserve">${text}</t></is></c>`;
    }).join("");
    return `<row r="${rowIndex + 1}">${cells}</row>`;
  }).join("\n");

  return createStoredZip({
    "[Content_Types].xml": `${declaration}
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>`,
    "_rels/.rels": `${declaration}
<Relationships xmlns="${relationshipsNs}">
  <Relationship Id="rId1" Type="${documentRelationshipsNs}/officeDocument" Target="xl/workbook.xml"/>
</Relationships>`,
    "xl/workbook.xml": `${declaration}
<workbook xmlns="${spreadsheetNs}" xmlns:r="${documentRelationshipsNs}">
  <sheets><sheet name="Producción EPS ${year}" sheetId="1" r:id="rId1"/></sheets>
</workbook>`,
    "xl/_rels/workbook.xml.rels": `${declaration}
<Relationships xmlns="${relationshipsNs}">
  <Relationship Id="rId1" Type="${documentRelationshipsNs}/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="${documentRelationshipsNs}/styles" Target="styles.xml"/>
</Relationships>`,
    "xl/styles.xml": `${declaration}
<styleSheet xmlns="${spreadsheetNs}">
  <numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>
  <fonts count="3">
    <font><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFF1F5F9"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
  </fonts>
  <fills count="4">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="5">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>`,
    "xl/worksheets/sheet1.xml": `${declaration}
<worksheet xmlns="${spreadsheetNs}">
  <dimension ref="A1:K${allRows.length}"/>
  <cols>${COLUMNS.map(({ width }, index) => `<col min="${index + 1}" max="${index + 1}" width="${Math.round(width / 7)}" customWidth="1"/>`).join("")}</cols>
  <sheetData>${sheetRows}</sheetData>
</worksheet>`,
  });
}

/** Generación pura: los totales siempre corresponden a las filas recibidas. */
export function buildMonthlyPerformanceExport(
  options: ExportPerformanceOptions,
  format: MonthlyPerformanceExportFormat,
) {
  const rows = buildRows(options);
  switch (format) {
    case "csv":
      return { content: serializeCsv(rows), mimeType: "text/csv;charset=utf-8", extension: "csv" };
    case "xlsx":
      return {
        content: serializeXlsx(rows, options.year),
        mimeType: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        extension: "xlsx",
      };
  }
}

export function downloadMonthlyPerformance(
  options: ExportPerformanceOptions,
  format: MonthlyPerformanceExportFormat,
): void {
  const file = buildMonthlyPerformanceExport(options, format);
  const url = URL.createObjectURL(new Blob([file.content], { type: file.mimeType }));
  const link = document.createElement("a");
  try {
    link.href = url;
    link.download = `audfact_produccion_eps_${options.year}.${file.extension}`;
    document.body.appendChild(link);
    link.click();
  } finally {
    link.remove();
    // El navegador necesita consumir la URL antes de revocarla.
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
