import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const skillsRoot = path.resolve(scriptDir, "../..");
const repoRoot = path.resolve(skillsRoot, "../..");
const errors = [];

function readJson(relativePath) {
  const absolutePath = path.join(skillsRoot, relativePath);

  try {
    return JSON.parse(fs.readFileSync(absolutePath, "utf8"));
  } catch (error) {
    errors.push(`${relativePath}: JSON inválido (${error.message})`);
    return {};
  }
}

function sameValues(left, right) {
  return JSON.stringify([...left].sort()) === JSON.stringify([...right].sort());
}

function yamlQuotedValue(raw, field, indent = "  ") {
  const expression = new RegExp(`^${indent}${field}:\\s*"([^"]*)"\\s*$`, "m");
  return raw.match(expression)?.[1] ?? null;
}

function frontmatterValue(raw, field) {
  const frontmatter = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/);
  if (!frontmatter) {
    return null;
  }

  const expression = new RegExp(`^${field}:\\s*(.+)$`, "m");
  return frontmatter[1].match(expression)?.[1]?.trim().replace(/^"|"$/g, "") ?? null;
}

function validateRelativeReferences(skillPath, raw) {
  const linkPattern = /\]\(([^)]+)\)/g;

  for (const match of raw.matchAll(linkPattern)) {
    const target = match[1];
    if (/^(https?:\/\/|file:\/\/|\/|#)/.test(target)) {
      continue;
    }

    const localTarget = target.split("#")[0];
    if (!localTarget) {
      continue;
    }

    const resolved = path.resolve(path.dirname(skillPath), localTarget);
    if (!fs.existsSync(resolved)) {
      errors.push(`${path.relative(repoRoot, skillPath)}: referencia inexistente ${target}`);
    }
  }
}

const catalog = readJson("catalog.json");
const aliases = readJson("aliases.json");
const bundles = readJson("bundles.json");
const baseline = readJson("validation-baseline.json");

const skillDirs = fs.readdirSync(skillsRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .filter((name) => fs.existsSync(path.join(skillsRoot, name, "SKILL.md")))
  .sort();

const catalogNames = (catalog.skills ?? []).map((skill) => skill.name).sort();
const baselineNames = (baseline.skills ?? []).map((skill) => skill.name).sort();

if (!sameValues(skillDirs, catalogNames)) {
  errors.push("catalog.json no coincide con los directorios que contienen SKILL.md");
}

if (!sameValues(skillDirs, baselineNames)) {
  errors.push("validation-baseline.json no cubre todos los directorios de skills");
}

for (const requiredFile of baseline.checks?.required_root_files ?? []) {
  if (!fs.existsSync(path.join(skillsRoot, requiredFile))) {
    errors.push(`Falta archivo raíz requerido: ${requiredFile}`);
  }
}

for (const definition of baseline.skills ?? []) {
  for (const relativeFile of definition.files ?? []) {
    if (!fs.existsSync(path.join(repoRoot, relativeFile))) {
      errors.push(`Baseline referencia archivo inexistente: ${relativeFile}`);
    }
  }
}

for (const skillName of skillDirs) {
  const skillPath = path.join(skillsRoot, skillName, "SKILL.md");
  const skillRaw = fs.readFileSync(skillPath, "utf8");
  const declaredName = frontmatterValue(skillRaw, "name");
  const description = frontmatterValue(skillRaw, "description");

  if (declaredName !== skillName) {
    errors.push(`${skillName}: frontmatter name no coincide con el directorio`);
  }
  if (!description) {
    errors.push(`${skillName}: falta description en frontmatter`);
  }

  const agentPath = path.join(skillsRoot, skillName, "agents", "openai.yaml");
  if (!fs.existsSync(agentPath)) {
    errors.push(`${skillName}: falta agents/openai.yaml`);
  } else {
    const agentRaw = fs.readFileSync(agentPath, "utf8");
    const displayName = yamlQuotedValue(agentRaw, "display_name");
    const shortDescription = yamlQuotedValue(agentRaw, "short_description");
    const defaultPrompt = yamlQuotedValue(agentRaw, "default_prompt");

    if (!/^interface:\s*$/m.test(agentRaw)) {
      errors.push(`${skillName}: agents/openai.yaml no contiene interface`);
    }
    if (!displayName || !shortDescription || !defaultPrompt) {
      errors.push(`${skillName}: agents/openai.yaml tiene campos ausentes o no entrecomillados`);
    }
    if (shortDescription && (shortDescription.length < 25 || shortDescription.length > 64)) {
      errors.push(`${skillName}: short_description debe tener entre 25 y 64 caracteres`);
    }
    if (defaultPrompt && !defaultPrompt.includes(`$${skillName}`)) {
      errors.push(`${skillName}: default_prompt no menciona $${skillName}`);
    }
  }

  validateRelativeReferences(skillPath, skillRaw);
}

for (const [alias, target] of Object.entries(aliases.aliases ?? {})) {
  if (!skillDirs.includes(target)) {
    errors.push(`Alias inválido ${alias}: ${target}`);
  }
}

for (const bundle of bundles.bundles ?? []) {
  for (const skillName of bundle.skills ?? []) {
    if (!skillDirs.includes(skillName)) {
      errors.push(`Bundle ${bundle.name} referencia skill inexistente: ${skillName}`);
    }
  }
}

const catalogMarkdownPath = path.join(skillsRoot, "CATALOG.md");
const catalogMarkdown = fs.readFileSync(catalogMarkdownPath, "utf8");
const skillSection = catalogMarkdown.split("## Skills")[1]?.split("## Triggers")[0] ?? "";
const markdownSkillNames = [...skillSection.matchAll(/^\| `([^`]+)` \|/gm)].map((match) => match[1]);

if (!sameValues(catalogNames, markdownSkillNames)) {
  errors.push("CATALOG.md no contiene el mismo inventario que catalog.json");
}

for (const bundle of bundles.bundles ?? []) {
  const prefix = `| \`${bundle.name}\` |`;
  const row = catalogMarkdown.split(/\r?\n/).find((line) => line.startsWith(prefix));
  if (!row) {
    errors.push(`CATALOG.md no documenta el bundle ${bundle.name}`);
    continue;
  }

  const values = [...row.matchAll(/`([^`]+)`/g)].map((match) => match[1]);
  const documentedSkills = values.slice(1);
  if (JSON.stringify(documentedSkills) !== JSON.stringify(bundle.skills ?? [])) {
    errors.push(`CATALOG.md no coincide con bundles.json para ${bundle.name}`);
  }
}

if (errors.length > 0) {
  console.error("AudFact skills validation: FAIL");
  for (const error of errors) {
    console.error(`- ${error}`);
  }
  process.exit(1);
}

console.log(`AudFact skills validation: PASS (${skillDirs.length} skills, ${(bundles.bundles ?? []).length} bundles)`);
