const assert = require("node:assert/strict");
const { after, test } = require("node:test");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const ts = require("typescript");
const { execFileSync } = require("node:child_process");

// Compilar solo los módulos puros con el TypeScript ya instalado en el proyecto.
const output = fs.mkdtempSync(path.join(os.tmpdir(), "audfact-export-test-"));
for (const name of ["monthly-performance", "export/stored-zip", "export/monthly-performance-export"]) {
  const source = fs.readFileSync(path.join(__dirname, "../lib", `${name}.ts`), "utf8");
  const result = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2018 },
  });
  const target = path.join(output, `${name}.js`);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, result.outputText);
}
after(() => {
  if (path.dirname(path.resolve(output)) !== path.resolve(os.tmpdir())
      || !path.basename(output).startsWith("audfact-export-test-")) {
    throw new Error("Directorio temporal fuera del alcance de la prueba");
  }
  fs.rmSync(output, { recursive: true });
});

const { summarizeMonthlyPerformance } = require(path.join(output, "monthly-performance.js"));
const { buildMonthlyPerformanceExport } = require(path.join(output, "export/monthly-performance-export.js"));

// Lector independiente con bibliotecas estándar: verifica CRC, XML y relaciones OPC.
function readXlsx(content) {
  const result = execFileSync("python", ["-c", `
import io, json, posixpath, sys, zipfile
import xml.etree.ElementTree as ET
with zipfile.ZipFile(io.BytesIO(sys.stdin.buffer.read())) as archive:
    assert archive.testzip() is None, 'CRC incorrecto'
    parts = {name: archive.read(name).decode('utf-8') for name in archive.namelist()}
    roots = {name: ET.fromstring(xml) for name, xml in parts.items()}
    for name in ['_rels/.rels', 'xl/_rels/workbook.xml.rels']:
        base = '' if name == '_rels/.rels' else 'xl'
        for relation in roots[name]:
            assert posixpath.normpath(posixpath.join(base, relation.attrib['Target'])) in parts
    ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    sheet = roots['xl/worksheets/sheet1.xml']
    cells = {}
    for cell in sheet.findall('.//s:c', ns):
        text = cell.find('s:is/s:t', ns) if cell.attrib.get('t') == 'inlineStr' else cell.find('s:v', ns)
        cells[cell.attrib['r']] = {**cell.attrib, 'value': text.text or ''}
    print(json.dumps({'parts': parts, 'cells': cells,
        'sheetName': roots['xl/workbook.xml'].find('s:sheets/s:sheet', ns).attrib['name'],
        'rowCount': len(sheet.findall('s:sheetData/s:row', ns))}))
`], { input: Buffer.from(content), encoding: "utf8", maxBuffer: 16 * 1024 * 1024 });
  return JSON.parse(result);
}
const first = Object.freeze({
  mes: 1, fac_nit_sec: 123, tercero: "EPS Bogotá", aud_conf: 1, aud_rech: 1,
  total: 2, rate_conf: 50, aud_conf_doc: 3, aud_rech_doc: 1, total_doc: 4,
});
const second = Object.freeze({
  mes: 2, fac_nit_sec: 456, tercero: "EPS Sur", aud_conf: 8, aud_rech: 0,
  total: 8, rate_conf: 100, aud_conf_doc: 16, aud_rech_doc: 0, total_doc: 16,
});

test("pondera la conformidad por facturas y consolida los soportes", () => {
  assert.deepEqual(summarizeMonthlyPerformance(Object.freeze([first, second])), {
    totalFacturas: 10, totalConf: 9, totalRech: 1,
    totalDocConf: 19, totalDocRech: 1, totalDocs: 20, rate: 90,
  });
  assert.equal(summarizeMonthlyPerformance([]).rate, 0);
});

test("exporta únicamente las filas recibidas y calcula sus totales", () => {
  const file = buildMonthlyPerformanceExport({ year: 2025, items: [first] }, "csv");
  assert.equal(file.extension, "csv");
  assert.ok(file.content.startsWith("\uFEFFsep=,\r\nMes_Nro,"));
  assert.ok(file.content.includes("1,Enero,123,EPS Bogotá,1,1,2,50.0%,3,1,4\r\n"));
  assert.ok(file.content.endsWith(",TOTALES AÑO 2025,,CONSOLIDADO VISIBLE,1,1,2,50.0%,3,1,4\r\n"));
  assert.ok(!file.content.includes("EPS Sur"));
});

test("escapa comillas, comas y saltos de línea sin modificar el origen", () => {
  const item = Object.freeze({ ...first, tercero: 'EPS "Norte", Bogotá\r\nSucursal' });
  const { content } = buildMonthlyPerformanceExport({ year: 2026, items: [item] }, "csv");
  assert.ok(content.includes('"EPS ""Norte"", Bogotá\r\nSucursal"'));
  assert.equal(item.tercero, 'EPS "Norte", Bogotá\r\nSucursal');
});

test("neutraliza prefijos de fórmula en celdas de texto", () => {
  for (const tercero of ["=1+1", "+1", "-1", "@SUM(A1)", "  =1", "\t=1", "\r=1", "\n=1"]) {
    const { content } = buildMonthlyPerformanceExport({ year: 2026, items: [{ ...first, tercero }] }, "csv");
    assert.ok(content.includes(`'${tercero}`));
  }
});

test("genera un XLSX válido con NIT textual, porcentajes y totales", () => {
  const file = buildMonthlyPerformanceExport({ year: 2026, items: [first, second] }, "xlsx");
  assert.equal(file.extension, "xlsx");
  assert.equal(file.mimeType, "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
  assert.equal(Buffer.from(file.content).subarray(0, 4).toString("hex"), "504b0304");
  const workbook = readXlsx(file.content);
  assert.equal(Object.keys(workbook.parts).length, 6);
  assert.equal(workbook.sheetName, "Producción EPS 2026");
  assert.deepEqual(workbook.cells.C2, { r: "C2", s: "0", t: "inlineStr", value: "123" });
  assert.deepEqual(workbook.cells.H4, { r: "H4", s: "4", t: "n", value: "0.9" });
  assert.equal(workbook.cells.G4.value, "10");
  assert.equal(workbook.cells.K4.value, "20");
  assert.ok(workbook.parts['xl/styles.xml'].includes('formatCode="0.0%"'));
  assert.equal(workbook.rowCount, 4);
  assert.equal(Object.keys(workbook.cells).length, 44);
});

test("escapa XML, elimina caracteres inválidos y conserva Unicode", () => {
  const { content } = buildMonthlyPerformanceExport({ year: 2026, items: [{
    ...first, tercero: 'EPS <&> "Norte"\' Bogotá 😀\u0000\u0001\uFFFF\uD800',
  }] }, "xlsx");
  const workbook = readXlsx(content);
  assert.equal(workbook.cells.D2.value, 'EPS <&> "Norte"\' Bogotá 😀');
  const sheet = workbook.parts['xl/worksheets/sheet1.xml'];
  for (const invalid of ["\u0000", "\u0001", "\uFFFF", "\uD800"]) {
    assert.ok(!sheet.includes(invalid));
  }
  assert.ok(!sheet.includes("<f>"));
});

test("Excel conserva textos con prefijos de fórmula como texto literal", () => {
  const { content } = buildMonthlyPerformanceExport({ year: 2026, items: [{
    ...first, tercero: '=HYPERLINK("https://example.test")',
  }] }, "xlsx");
  const workbook = readXlsx(content);
  assert.equal(workbook.cells.D2.t, "inlineStr");
  assert.equal(workbook.cells.D2.value, '=HYPERLINK("https://example.test")');
  assert.ok(!workbook.parts['xl/worksheets/sheet1.xml'].includes('<f>'));
});

test("un conjunto vacío genera totales cero sin NaN ni Infinity", () => {
  for (const format of ["csv", "xlsx"]) {
    const { content } = buildMonthlyPerformanceExport({ year: 2026, items: [] }, format);
    const text = format === "xlsx" ? readXlsx(content).parts['xl/worksheets/sheet1.xml'] : content;
    assert.ok(text.includes("TOTALES AÑO 2026"));
    assert.ok(!/NaN|Infinity|undefined/.test(text));
  }
});
