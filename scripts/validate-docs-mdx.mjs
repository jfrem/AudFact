#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const ROOT = process.cwd();
const WEBSITE_PKG = path.join(ROOT, 'website', 'package.json');
const ROOT_DOCS = new Set(['README.md', 'BUSINESS.md', 'DESIGN.md']);

function isDocusaurusDoc(relPath) {
  const normalized = relPath.replace(/\\/g, '/').replace(/^\.\//, '');
  if (ROOT_DOCS.has(normalized)) return true;
  return normalized.startsWith('plans/') && normalized.endsWith('.md');
}

function collectAllDocs() {
  const files = [];
  for (const rootDoc of ROOT_DOCS) {
    if (fs.existsSync(path.join(ROOT, rootDoc))) {
      files.push(rootDoc);
    }
  }
  const plansDir = path.join(ROOT, 'plans');
  if (fs.existsSync(plansDir)) {
    const walk = (dir) => {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          walk(full);
        } else if (entry.name.endsWith('.md')) {
          files.push(path.relative(ROOT, full).replace(/\\/g, '/'));
        }
      }
    };
    walk(plansDir);
  }
  return files.sort();
}

function getStagedDocs() {
  const out = execFileSync(
    'git',
    ['diff', '--cached', '-z', '--name-only', '--diff-filter=ACMR'],
    { cwd: ROOT, encoding: 'utf8' }
  );
  return out
    .split('\0')
    .filter(Boolean)
    .filter(isDocusaurusDoc);
}

function readContent(filePath, staged) {
  if (!staged) {
    return fs.readFileSync(path.join(ROOT, filePath), 'utf8');
  }
  return execFileSync('git', ['show', `:${filePath}`], {
    cwd: ROOT,
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
  });
}

async function main() {
  const args = process.argv.slice(2);
  const staged = args.includes('--staged');
  const strict = args.includes('--strict');
  const explicitFiles = args.filter((a) => !a.startsWith('--'));

  const targets = explicitFiles.length > 0
    ? explicitFiles.map((f) => f.replace(/\\/g, '/'))
    : staged
      ? getStagedDocs()
      : collectAllDocs();

  if (targets.length === 0) {
    process.exit(0);
  }

  let mdx;
  let remarkGfm;
  let remarkMath;
  try {
    const req = createRequire(WEBSITE_PKG);
    mdx = await import(pathToFileURL(req.resolve('@mdx-js/mdx')).href);
    remarkGfm = (await import(pathToFileURL(req.resolve('remark-gfm')).href)).default;
    remarkMath = (await import(pathToFileURL(req.resolve('remark-math')).href)).default;
  } catch (err) {
    const msg = '⚠️  Dependencias MDX de website/ no instaladas (ejecuta `npm ci` en website/).';
    if (strict) {
      console.error(`❌ ${msg}`);
      process.exit(1);
    }
    console.warn(`${msg} Omitiendo validación MDX local.`);
    process.exit(0);
  }

  let failed = 0;
  for (const relPath of targets) {
    let content;
    try {
      content = readContent(relPath, staged);
    } catch (err) {
      console.error(`❌ No se pudo leer ${relPath}: ${err.message}`);
      failed++;
      continue;
    }

    try {
      await mdx.compile(content, {
        remarkPlugins: [remarkGfm, remarkMath],
        jsx: true,
      });
    } catch (err) {
      failed++;
      const loc = err.line ? `:${err.line}${err.column ? `:${err.column}` : ''}` : '';
      console.error(`❌ Error MDX en ${relPath}${loc}`);
      console.error(`   ${err.reason || err.message}`);
    }
  }

  if (failed > 0) {
    console.error(`\n🚫 Validación MDX falló (${failed}/${targets.length} archivo(s) con errores de compilación Docusaurus).`);
    process.exit(1);
  }

  console.log(`✅ Validación MDX aprobada (${targets.length} archivo(s) compatibles con Docusaurus).`);
}

main().catch((err) => {
  console.error('❌ Error inesperado validando MDX:', err);
  process.exit(1);
});
