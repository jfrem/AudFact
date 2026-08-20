<?php

declare(strict_types=1);

$directories = ['app', 'core', 'public', 'bin', 'tests'];
$filesChecked = 0;
$errors = [];

echo "========================================\n";
echo "🐘 Ejecutando PHP Linter & Syntax Check...\n";
echo "========================================\n";

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filesChecked++;
            $filePath = $file->getRealPath();
            $output = [];
            $exitCode = 0;
            exec("php -l " . escapeshellarg($filePath) . " 2>&1", $output, $exitCode);

            if ($exitCode !== 0) {
                $errors[] = implode("\n", $output);
            }
        }
    }
}

echo "Total de archivos PHP analizados: {$filesChecked}\n";

if (empty($errors)) {
    echo "✅ ÉXITO: 0 errores de sintaxis detectados en todos los archivos PHP.\n";
    exit(0);
} else {
    echo "❌ Se encontraron errores de sintaxis:\n";
    foreach ($errors as $err) {
        echo "- {$err}\n";
    }
    exit(1);
}
