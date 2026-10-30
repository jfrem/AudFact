import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Antigravity PreToolUse Hook: Ensure Green Test Baseline & SDD Spec Before Implementation
 *
 * Intercepts edit_file and write_file on production code (app/, core/, database/, frontend/)
 * and denies modification if:
 * 1. The unit test suite has not been run and certified green.
 * 2. An SDD specification in plans/sdd-*.md or an active plan artifact does not exist (unless bypassed).
 */
function main() {
  // 1. Read hook input payload from stdin
  let inputRaw = "";
  try {
    inputRaw = fs.readFileSync(0, "utf-8");
  } catch {
    inputRaw = "";
  }

  let hookInput = {};
  try {
    hookInput = inputRaw.trim() ? JSON.parse(inputRaw) : {};
  } catch {
    hookInput = {};
  }

  // 2. Resolve target file path from toolCall arguments
  const toolArgs = hookInput.toolCall?.args || {};
  const rawPath = toolArgs.Path || "";
  const normalizedPath = rawPath.replace(/\\/g, "/");

  // Safeguard: If no path provided, allow
  if (!normalizedPath) {
    console.log(JSON.stringify({ decision: "allow" }));
    return;
  }

  // Check user bypass flags in prompt
  const lastInput = hookInput.lastUserInput || "";
  const skipAll =
    lastInput.includes("[skip-all]") ||
    lastInput.toLowerCase().includes("sin restricciones");
  if (skipAll) {
    console.log(JSON.stringify({ decision: "allow" }));
    return;
  }

  // 3. Check if target file is production code that requires verified baseline
  const isProductionCode =
    normalizedPath.includes("/app/") ||
    normalizedPath.startsWith("app/") ||
    normalizedPath.includes("/core/") ||
    normalizedPath.startsWith("core/") ||
    normalizedPath.includes("/database/") ||
    normalizedPath.startsWith("database/") ||
    normalizedPath.includes("/frontend/src/") ||
    normalizedPath.startsWith("frontend/src/") ||
    normalizedPath.includes("/frontend/app/") ||
    normalizedPath.startsWith("frontend/app/");

  // Files exempted: tests, plans, documentation, skills, scripts, artifacts, scratch
  const isExempted =
    normalizedPath.includes("/tests/") ||
    normalizedPath.startsWith("tests/") ||
    normalizedPath.includes("/plans/") ||
    normalizedPath.startsWith("plans/") ||
    normalizedPath.includes("/.agent/") ||
    normalizedPath.startsWith(".agent/") ||
    normalizedPath.includes("/brain/") ||
    normalizedPath.endsWith(".md") ||
    normalizedPath.endsWith(".json") ||
    normalizedPath.endsWith(".yml") ||
    normalizedPath.endsWith(".yaml");

  if (!isProductionCode || isExempted) {
    console.log(JSON.stringify({ decision: "allow" }));
    return;
  }

  // 4. Resolve workspace root
  let workspaceRoot = "";
  if (Array.isArray(hookInput.workspacePaths) && hookInput.workspacePaths.length > 0) {
    workspaceRoot = hookInput.workspacePaths[0];
  }
  if (!workspaceRoot || !fs.existsSync(workspaceRoot)) {
    const scriptDir = path.dirname(fileURLToPath(import.meta.url));
    workspaceRoot = path.resolve(scriptDir, "../..");
  }

  // 5. Check 1: Test baseline in green
  const skipTests =
    lastInput.includes("[skip-tests]") ||
    lastInput.toLowerCase().includes("sin pruebas") ||
    lastInput.toLowerCase().includes("skip tests");

  let baselineValid = skipTests;
  if (!baselineValid) {
    // Check 1A: Execution in current transcript
    if (hookInput.transcriptPath && fs.existsSync(hookInput.transcriptPath)) {
      try {
        const transcript = fs.readFileSync(hookInput.transcriptPath, "utf-8");
        if (
          (transcript.includes("phpunit") || transcript.includes("composer test")) &&
          (transcript.includes("OK (") ||
            transcript.includes("OK, but some tests were skipped") ||
            transcript.includes("exited with code 0"))
        ) {
          baselineValid = true;
        }
      } catch {
        // ignore
      }
    }

    // Check 1B: Recent execution in .phpunit.cache/test-results (< 15 mins)
    if (!baselineValid) {
      const phpunitCachePath = path.join(workspaceRoot, ".phpunit.cache", "test-results");
      if (fs.existsSync(phpunitCachePath)) {
        try {
          const stats = fs.statSync(phpunitCachePath);
          const ageMinutes = (Date.now() - stats.mtimeMs) / (1000 * 60);
          if (ageMinutes <= 15) {
            baselineValid = true;
          }
        } catch {
          // ignore
        }
      }
    }
  }

  const fileName = path.basename(normalizedPath);

  if (!baselineValid) {
    const response = {
      decision: "deny",
      reason:
        `🚨 CONTROL PREVIO DE CALIDAD (Línea Base en Verde):\n` +
        `Estás intentando modificar código de producción ('${fileName}') sin haber certificado previamente que la suite de pruebas unitarias está en verde.\n\n` +
        `Por favor:\n` +
        `1. Ejecuta la suite de pruebas unitarias mediante la herramienta 'run_command':\n` +
        `   vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit\n` +
        `2. Confirma que la suite finalice en verde (0 fallos, 0 errores).\n` +
        `3. Una vez verificado el estado en verde, reintenta la edición del archivo.`,
    };
    console.log(JSON.stringify(response));
    return;
  }

  // 6. Check 2: SDD Spec or Plan exists
  const skipSpec =
    lastInput.includes("[skip-spec]") ||
    lastInput.includes("[hotfix]") ||
    lastInput.toLowerCase().includes("sin spec") ||
    lastInput.toLowerCase().includes("hotfix");

  let specValid = skipSpec;
  if (!specValid) {
    // Check if plans/sdd-*.md exists or was recently modified (< 4 hours)
    const plansDir = path.join(workspaceRoot, "plans");
    if (fs.existsSync(plansDir)) {
      try {
        const planFiles = fs.readdirSync(plansDir);
        const sddFiles = planFiles.filter((f) => f.startsWith("sdd-") && f.endsWith(".md"));
        for (const sddFile of sddFiles) {
          const sddPath = path.join(plansDir, sddFile);
          const stats = fs.statSync(sddPath);
          const ageHours = (Date.now() - stats.mtimeMs) / (1000 * 60 * 60);
          if (ageHours <= 4) {
            specValid = true;
            break;
          }
        }
      } catch {
        // ignore
      }
    }

    // Check if an artifact exists in artifactDirectoryPath
    if (!specValid && hookInput.artifactDirectoryPath && fs.existsSync(hookInput.artifactDirectoryPath)) {
      try {
        const artifacts = fs.readdirSync(hookInput.artifactDirectoryPath).filter((f) => f.endsWith(".md"));
        if (artifacts.length > 0) {
          specValid = true;
        }
      } catch {
        // ignore
      }
    }

    // Check if transcript references write-sdd-spec or sdd plan creation
    if (!specValid && hookInput.transcriptPath && fs.existsSync(hookInput.transcriptPath)) {
      try {
        const transcript = fs.readFileSync(hookInput.transcriptPath, "utf-8");
        if (transcript.includes("write-sdd-spec") || transcript.includes("plans/sdd-") || transcript.includes('"plans/')) {
          specValid = true;
        }
      } catch {
        // ignore
      }
    }
  }

  if (!specValid) {
    const response = {
      decision: "deny",
      reason:
        `🚨 CONTROL PREVIO DE GOBERNANZA (Especificación SDD Requerida):\n` +
        `Estás intentando implementar código de producción ('${fileName}') sin haber redactado previamente la especificación técnica correspondiente.\n\n` +
        `Por favor, cumple con el protocolo SDD (/write-sdd-spec):\n` +
        `1. Redacta la especificación en 'plans/sdd-<nombre-del-cambio>.md' o genera un artefacto de plan.\n` +
        `2. Si existen supuestos o incógnitas críticas [DESCONOCIDO], acláralos con el usuario usando ask_question.\n` +
        `3. Valida la compuerta arquitectónica (/clean-rebuild-policy).\n` +
        `4. Una vez guardada la especificación, procede con la implementación.\n` +
        `(Nota: Si es un ajuste menor que no requiere spec formal, usa la bandera [skip-spec]).`,
    };
    console.log(JSON.stringify(response));
    return;
  }

  // All checks passed, allow implementation
  console.log(JSON.stringify({ decision: "allow" }));
}

main();
