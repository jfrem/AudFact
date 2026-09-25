import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, readFile, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:http';

const execute = promisify(execFile);
const importer = fileURLToPath(new URL('../bin/audit-import.php', import.meta.url));
const directory = await mkdtemp(join(tmpdir(), 'audfact-import-test-'));
after(async () => {
  assert.equal(dirname(resolve(directory)), resolve(tmpdir()));
  assert.ok(basename(directory).startsWith('audfact-import-test-'));
  await rm(directory, { recursive: true });
});

async function fixture(name, content) {
  const file = join(directory, name);
  await writeFile(file, content);
  return file;
}

async function run(file, options = []) {
  try {
    const result = await execute('php', [importer, file, ...options], { cwd: directory, timeout: 15000 });
    return { ...result, code: 0 };
  } catch (error) {
    if (typeof error.code !== 'number') throw error;
    return { code: error.code, stdout: error.stdout, stderr: error.stderr };
  }
}

test('resuelve encabezado, letra e índice sin enviar la cabecera ni duplicados', async () => {
  const file = await fixture('columns.csv', '\uFEFFDisDetNro,DisId\r\nTEST001,17\r\nTEST001,17\r\nTEST002,18\r\n');
  for (const column of ['DisDetNro', 'A', '0']) {
    const result = await run(file, ['--dry-run', `--column=${column}`]);
    assert.equal(result.code, 0, result.stderr);
    assert.ok(result.stdout.includes('Registros únicos: 2'));
  }
});

test('respeta sep= y comas dentro de campos entrecomillados', async () => {
  const file = await fixture('separator.csv', '\uFEFFsep=;\r\nDisDetNro;DisId;Nota\r\nTEST003;19;"texto,con,comas"\r\n');
  const result = await run(file, ['--dry-run']);
  assert.equal(result.code, 0, result.stderr);
  assert.ok(result.stdout.includes('Registros únicos: 1'));
});

test('rechaza columna ausente, opciones desconocidas y valores inválidos', async () => {
  const file = await fixture('invalid-options.csv', 'DisDetNro,DisId\nTEST004,20\n');
  for (const option of ['--column=Desconocida', '--column=Z', '--column=-1', '--delay-ms=-1', '--delay-ms=1.5', '--endpoint', '--desconocida']) {
    const result = await run(file, ['--dry-run', option]);
    assert.equal(result.code, 1, option);
    assert.ok(result.stderr.includes('[ERROR]'));
  }
});

test('rechaza identidades contradictorias y bitácoras inválidas antes de enviar', async () => {
  const conflict = await fixture('conflict.csv', 'DisDetNro,DisId\nTEST005,1\nTEST005,2\n');
  assert.equal((await run(conflict, ['--dry-run'])).code, 1);
  const file = await fixture('resume.csv', 'DisDetNro\nTEST006\n');
  const invalid = await fixture('invalid-report.csv', 'ColumnaIncompatible\n');
  const options = ['--dry-run', '--resume', `--output=${invalid}`];
  assert.equal((await run(file, options)).code, 1);
  assert.equal((await run(file, ['--dry-run', '--resume'])).code, 1);
  assert.equal((await run(file, ['--dry-run', `--output=${file}`])).code, 1);
  assert.equal(await readFile(invalid, 'utf8'), 'ColumnaIncompatible\n');
});

test('lee XLSX con texto compartido e inline sin perder columnas vacías', async () => {
  const file = join(directory, 'input.xlsx');
  const sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    + '<row r="1"><c r="B1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>DisId</t></is></c></row>'
    + '<row r="2"><c r="B2" t="inlineStr"><is><r><t>TEST</t></r><r><t>007</t></r></is></c><c r="C2"><v>21</v></c></row>'
    + '</sheetData></worksheet>';
  const shared = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>DisDetNro</t></si></sst>';
  const createZip = '$z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE); $z->addFromString("xl/worksheets/sheet1.xml", $argv[2]); $z->addFromString("xl/sharedStrings.xml", $argv[3]); $z->close();';
  await execute('php', ['-r', createZip, file, sheet, shared]);
  const result = await run(file, ['--dry-run', '--column=DisDetNro']);
  assert.equal(result.code, 0, result.stderr);
  assert.ok(result.stdout.includes('Registros únicos: 1'));
  const broken = join(directory, 'broken.xlsx');
  await execute('php', ['-r', createZip, broken, sheet, '<invalid>']);
  assert.equal((await run(broken, ['--dry-run'])).code, 1);
});

test('solo registra aceptación con el contrato 202 y reanuda omitiendo éxitos', async () => {
  const requests = [];
  const server = createServer(async (request, response) => {
    let body = '';
    for await (const chunk of request) body += chunk;
    requests.push(JSON.parse(body));
    response.writeHead(202, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ success: true, data: { audit_id: 'fixture-audit', status: 'pending', dis_id: '22' } }));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  try {
    const file = await fixture('send.csv', 'DisDetNro,DisId\nTEST008,22\n');
    const report = join(directory, 'accepted.csv');
    const options = [`--endpoint=http://127.0.0.1:${server.address().port}/audit/single`, '--delay-ms=0', `--output=${report}`];
    const first = await run(file, options);
    assert.equal(first.code, 0, first.stderr);
    assert.deepEqual(requests, [{ disDetNro: 'TEST008', disId: '22' }]);
    const saved = await readFile(report, 'utf8');
    assert.ok(saved.includes('202,1,pending,fixture-audit'));
    const resumed = await run(file, [...options, '--resume']);
    assert.equal(resumed.code, 0, resumed.stderr);
    assert.equal(requests.length, 1);
    assert.equal(await readFile(report, 'utf8'), saved);
    assert.equal((await run(file, options)).code, 1);
  } finally {
    await new Promise((resolve) => server.close(resolve));
  }
});

test('HTML con HTTP 200 y JSON inválido con 202 producen error recuperable', async () => {
  for (const code of [200, 202]) {
    const server = createServer((_request, response) => {
      response.writeHead(code);
      response.end('<html>No es una respuesta API</html>');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    try {
      const file = await fixture(`bad-${code}.csv`, 'DisDetNro\nTEST009\n');
      const report = join(directory, `bad-report-${code}.csv`);
      const result = await run(file, [`--endpoint=http://127.0.0.1:${server.address().port}/audit/single`, '--delay-ms=0', `--output=${report}`]);
      assert.equal(result.code, 1);
      assert.ok((await readFile(report, 'utf8')).includes(`${code},0,`));
    } finally {
      await new Promise((resolve) => server.close(resolve));
    }
  }
});
