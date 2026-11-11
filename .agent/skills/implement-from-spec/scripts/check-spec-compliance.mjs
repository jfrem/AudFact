#!/usr/bin/env node
// Comprobación determinista del cumplimiento de una especificación SDD.
//
//   node check-spec-compliance.mjs <spec.md>                → perímetro autorizado y criterios a trazar
//   node check-spec-compliance.mjs <spec.md> --base <ref>   → además, compara el diff contra el perímetro
//
// Código de salida: 0 si todo está en orden; 1 si la especificación no es implementable,
// hay código cambiado fuera del perímetro o se tocó una ruta protegida; 2 por error de uso.
// Reutiliza el núcleo de los hooks para aplicar exactamente las mismas reglas.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { analyzeSpecContent, classifyPath, loadConfig, normalizePath } from '../../../hooks/sdd-utils.mjs';

const workspaceRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..', '..', '..');
const args = process.argv.slice(2);
const baseIdx = args.indexOf('--base');
const base = baseIdx !== -1 ? args[baseIdx + 1] : undefined;
const specArg = args.find((a, i) => !a.startsWith('--') && (baseIdx === -1 || i !== baseIdx + 1));

if (!specArg || (baseIdx !== -1 && !base)) {
  console.error('Uso: node check-spec-compliance.mjs <spec.md> [--base <ref>]');
  process.exit(2);
}

const specAbs = path.resolve(process.cwd(), specArg);
let content;
try {
  content = fs.readFileSync(specAbs, 'utf-8');
} catch {
  console.error(`No se pudo leer la especificación: ${specArg}`);
  process.exit(2);
}

const config = loadConfig(workspaceRoot);
const analysis = analyzeSpecContent(content);
const lines = content.split(/\r?\n/);

function section(headingRe) {
  const start = lines.findIndex((l) => /^#{1,6}\s/.test(l) && headingRe.test(l));
  if (start === -1) return [];
  const level = lines[start].match(/^(#+)/)[1].length;
  const out = [];
  for (let i = start + 1; i < lines.length; i++) {
    const m = lines[i].match(/^(#+)\s/);
    if (m && m[1].length <= level) break;
    out.push(lines[i]);
  }
  return out;
}

const isTableRow = (l) => l.startsWith('|') && !/^\|(\s*:?-+:?\s*\|)+$/.test(l); // excluye separadores
const criteria = section(/^#{1,6}\s+18\.\s/)
  .map((l) => l.trim())
  .filter((l) => /^([-*]|\d+[.)])\s+/.test(l) || isTableRow(l));
const requirements = section(/^#{1,6}\s+11\.\s/)
  .map((l) => l.trim())
  .filter(isTableRow)
  .slice(1); // sin la fila de encabezado

const relSpec = normalizePath(path.relative(workspaceRoot, specAbs));
const problems = [];

console.log(`Especificación: ${relSpec}`);
console.log(`Nivel: ${analysis.level}${analysis.levelDetail ? ` (${analysis.levelDetail})` : ''}`);
if (!analysis.isImplementable) problems.push(`la especificación no es implementable (nivel ${analysis.level})`);

const authorized = [...analysis.authorizedPaths].sort();
console.log(`\nArchivos autorizados (${authorized.length}):`);
for (const p of authorized) console.log(`  - ${p}`);
if (authorized.length === 0) problems.push('la especificación no autoriza ningún archivo');

console.log(`\nCriterios de aceptación — sección 18 (${criteria.length}):`);
for (const c of criteria) console.log(`  ${c}`);
console.log(`\nRequisitos — sección 11 (${requirements.length}):`);
for (const r of requirements) console.log(`  ${r}`);

if (base) {
  const git = (...gitArgs) =>
    execFileSync('git', gitArgs, { cwd: workspaceRoot, encoding: 'utf-8' })
      .split(/\r?\n/)
      .map((s) => normalizePath(s))
      .filter(Boolean);

  let changed;
  try {
    changed = [...new Set([...git('diff', '--name-only', base), ...git('ls-files', '--others', '--exclude-standard')])].sort();
  } catch (err) {
    console.error(`\nNo se pudo obtener el diff contra '${base}': ${err.message}`);
    process.exit(2);
  }

  const authorizedSet = new Set(authorized);
  const outside = [];
  const protectedTouched = [];
  for (const file of changed) {
    const kind = classifyPath(file, config);
    if (kind === 'protected') protectedTouched.push(file);
    else if (kind === 'code' && !authorizedSet.has(file.toLowerCase())) outside.push(file);
  }
  const changedLower = new Set(changed.map((f) => f.toLowerCase()));
  const untouched = authorized.filter((p) => !changedLower.has(p));

  console.log(`\nCambios desde ${base} (${changed.length}):`);
  for (const f of changed) console.log(`  - ${f}`);
  console.log(`\nCódigo fuera del perímetro (${outside.length}):`);
  for (const f of outside) console.log(`  ✗ ${f}`);
  console.log(`\nRutas protegidas modificadas (${protectedTouched.length}):`);
  for (const f of protectedTouched) console.log(`  ✗ ${f}`);
  console.log(`\nAutorizados sin modificar (${untouched.length}) — revisar si falta trabajo:`);
  for (const f of untouched) console.log(`  ? ${f}`);

  if (outside.length) problems.push(`${outside.length} archivo(s) de código fuera del perímetro`);
  if (protectedTouched.length) problems.push(`${protectedTouched.length} ruta(s) protegida(s) modificada(s)`);
}

if (problems.length) {
  console.log(`\nResultado: ✗ ${problems.join('; ')}`);
  process.exit(1);
}
console.log('\nResultado: ✓ conforme');
