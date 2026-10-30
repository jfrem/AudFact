import { execSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Antigravity Stop Hook: Comprehensive Implementation Closure Verification
 *
 * Enforces that before the agent concludes execution after modifying code:
 * 1. Post-implementation test suite has run and certified green (mtime > code edits).
 * 2. Documentation and plans/changelog.md are synchronized (audfact-docs-sync).
 * 3. An implementation report artifact has been generated in the artifact directory.
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

  // Safeguard 1: Only intervene if the agent voluntarily finished with NO_TOOL_CALL.
  const terminationReason = hookInput.terminationReason;
  if (terminationReason && terminationReason !== "NO_TOOL_CALL") {
    console.log(JSON.stringify({}));
    return;
  }

  // Safeguard 2: Prevent infinite loops if already forced multiple times
  if (typeof hookInput.executionNum === "number" && hookInput.executionNum >= 3) {
    console.log(JSON.stringify({}));
    return;
  }

  // Check user bypass flags
  const lastInput = hookInput.lastUserInput || "";
  if (
    lastInput.includes("[skip-all]") ||
    lastInput.toLowerCase().includes("sin restricciones")
  ) {
    console.log(JSON.stringify({}));
    return;
  }

  // 2. Resolve workspace root
  let workspaceRoot = "";
  if (Array.isArray(hookInput.workspacePaths) && hookInput.workspacePaths.length > 0) {
    workspaceRoot = hookInput.workspacePaths[0];
  }
  if (!workspaceRoot || !fs.existsSync(workspaceRoot)) {
    const scriptDir = path.dirname(fileURLToPath(import.meta.url));
    workspaceRoot = path.resolve(scriptDir, "../..");
  }

  // Safeguard 3: Check if the current conversation actually touched/modified files.
  let sessionModifiedFiles = false;
  if (hookInput.transcriptPath && fs.existsSync(hookInput.transcriptPath)) {
    try {
      const transcriptContent = fs.readFileSync(hookInput.transcriptPath, "utf-8");
      if (
        transcriptContent.includes('"edit_file"') ||
        transcriptContent.includes('"write_file"') ||
        transcriptContent.includes('"toolName":"edit_file"') ||
        transcriptContent.includes('"toolName":"write_file"')
      ) {
        sessionModifiedFiles = true;
      }
    } catch {
      sessionModifiedFiles = true;
    }
  } else {
    sessionModifiedFiles = true;
  }

  if (!sessionModifiedFiles) {
    console.log(JSON.stringify({}));
    return;
  }

  // 3. Query git status
  let statusOutput = "";
  try {
    statusOutput = execSync("git status --porcelain", {
      cwd: workspaceRoot,
      encoding: "utf-8",
      stdio: ["ignore", "pipe", "ignore"],
    });
  } catch {
    console.log(JSON.stringify({}));
    return;
  }

  if (!statusOutput || !statusOutput.trim()) {
    console.log(JSON.stringify({}));
    return;
  }

  const lines = statusOutput.split(/\r?\n/).filter((l) => l.trim().length > 0);

  // Patterns for code files that mandate closure verification
  const codePatterns = [
    /^..\s+(app\/)/i,
    /^..\s+(core\/)/i,
    /^..\s+(frontend\/)/i,
    /^..\s+(docker\/)/i,
    /^..\s+(database\/)/i,
    /^..\s+(public\/)/i,
    /^..\s+(bin\/)/i,
    /^..\s+(\.github\/)/i,
    /^..\s+(docker-compose.*\.ya?ml)/i,
  ];

  // Patterns for documentation, changelog, and skill updates
  const docPatterns = [
    /^..\s+(plans\/changelog\.md)/i,
    /^..\s+(plans\/)/i,
    /^..\s+(\.agent\/skills\/)/i,
    /^..\s+(AGENTS\.md)/i,
    /^..\s+(README\.md)/i,
    /^..\s+(CATALOG\.md)/i,
  ];

  const modifiedCodeFiles = [];
  let latestCodeMtime = 0;
  let hasDocModifications = false;

  for (const line of lines) {
    const normalizedLine = line.replace(/\\/g, "/");

    const isCode = codePatterns.some((pattern) => pattern.test(normalizedLine));
    if (isCode) {
      const filePath = normalizedLine.substring(3).trim();
      modifiedCodeFiles.push(filePath);

      // Check mtime of modified code file
      const fullPath = path.join(workspaceRoot, filePath);
      if (fs.existsSync(fullPath)) {
        try {
          const stats = fs.statSync(fullPath);
          if (stats.mtimeMs > latestCodeMtime) {
            latestCodeMtime = stats.mtimeMs;
          }
        } catch {}
      }
    }

    const isDoc = docPatterns.some((pattern) => pattern.test(normalizedLine));
    if (isDoc) {
      hasDocModifications = true;
    }
  }

  // If no production code was modified, allow stop
  if (modifiedCodeFiles.length === 0) {
    console.log(JSON.stringify({}));
    return;
  }

  const missingEvidences = [];

  // Verification 1: Post-implementation tests in green
  const skipTests =
    lastInput.includes("[skip-tests]") ||
    lastInput.toLowerCase().includes("sin pruebas");

  if (!skipTests) {
    let postTestsPassed = false;
    const phpunitCachePath = path.join(workspaceRoot, ".phpunit.cache", "test-results");
    if (fs.existsSync(phpunitCachePath)) {
      try {
        const stats = fs.statSync(phpunitCachePath);
        // Test run must have occurred AFTER or within 2 seconds of the latest code edit
        if (stats.mtimeMs >= latestCodeMtime - 2000) {
          postTestsPassed = true;
        }
      } catch {}
    }

    // Also check transcript for test execution step occurring after last code edit
    if (!postTestsPassed && hookInput.transcriptPath && fs.existsSync(hookInput.transcriptPath)) {
      try {
        const transcript = fs.readFileSync(hookInput.transcriptPath, "utf-8");
        const lastEditIndex = Math.max(
          transcript.lastIndexOf('"edit_file"'),
          transcript.lastIndexOf('"write_file"')
        );
        const lastTestIndex = Math.max(
          transcript.lastIndexOf("vendor/bin/phpunit"),
          transcript.lastIndexOf("phpunit")
        );
        if (lastTestIndex > lastEditIndex && transcript.includes("exited with code 0")) {
          postTestsPassed = true;
        }
      } catch {}
    }

    if (!postTestsPassed) {
      missingEvidences.push(
        "PRUEBAS FINALES EN VERDE: Has modificado archivos de código pero no has vuelto a ejecutar la suite de pruebas unitarias ('vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit') para certificar que no existen regresiones tras tus cambios."
      );
    }
  }

  // Verification 2: Documentation & plans/changelog.md updated
  if (!hasDocModifications) {
    missingEvidences.push(
      "DOCUMENTACIÓN Y BITÁCORA: Debes registrar en 'plans/changelog.md' bajo la fecha de hoy los cambios técnicos realizados y sincronizar la documentación afectada (audfact-docs-sync)."
    );
  }

  // Verification 3: Implementation report artifact generated
  const skipArtifact =
    lastInput.includes("[no-artifact]") ||
    lastInput.toLowerCase().includes("sin artefacto");

  if (!skipArtifact && hookInput.artifactDirectoryPath) {
    let hasArtifact = false;
    if (fs.existsSync(hookInput.artifactDirectoryPath)) {
      try {
        const files = fs.readdirSync(hookInput.artifactDirectoryPath);
        const mdArtifacts = files.filter((f) => f.endsWith(".md"));
        if (mdArtifacts.length > 0) {
          hasArtifact = true;
        }
      } catch {}
    }

    if (!hasArtifact) {
      missingEvidences.push(
        "ARTEFACTO DE REPORTE: Debes generar un artefacto Markdown en la carpeta de artefactos con el reporte final de la implementación (resumen, componentes modificados, decisiones arquitectónicas según /clean-rebuild-policy y estado final de pruebas)."
      );
    }
  }

  // If any evidence is missing, BLOCK and instruct the agent
  if (missingEvidences.length > 0) {
    const sampleFiles = modifiedCodeFiles.slice(0, 4).join(", ");
    const moreText =
      modifiedCodeFiles.length > 4 ? ` y ${modifiedCodeFiles.length - 4} más` : "";

    const reasonsFormatted = missingEvidences
      .map((item, index) => `${index + 1}. ${item}`)
      .join("\n\n");

    const response = {
      decision: "continue",
      reason:
        `🚨 CONTROL DE CIERRE DE IMPLEMENTACIÓN (Faltan Evidencias):\n` +
        `Has modificado código fuente (${sampleFiles}${moreText}), pero faltan las siguientes evidencias obligatorias para concluir la tarea:\n\n` +
        reasonsFormatted +
        `\n\nPor favor, completa los pasos anteriores antes de dar por finalizada la respuesta.`,
    };

    console.log(JSON.stringify(response));
    return;
  }

  // All 3 evidences are present! Allow stop.
  console.log(JSON.stringify({}));
}

main();
