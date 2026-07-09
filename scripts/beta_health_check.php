<?php

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$cronDir = $root . 'crons' . DIRECTORY_SEPARATOR;
$requiredDocs = [
    $root . 'EARLY_ACCESS_SMOKE_CHECKLIST.md',
    $root . 'BETA_RELEASE_RUNBOOK.md',
    $root . 'SCHEMA_SYNC_APPLY_ORDER.md',
];

$results = [
    'docs' => [],
    'crons' => [],
];

foreach ($requiredDocs as $docPath) {
    $results['docs'][] = [
        'path' => $docPath,
        'exists' => file_exists($docPath),
    ];
}

$cronFiles = glob($cronDir . '*.php') ?: [];
foreach ($cronFiles as $cronFile) {
    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($cronFile), $output, $exitCode);
    $results['crons'][] = [
        'file' => basename($cronFile),
        'ok' => $exitCode === 0,
        'output' => implode(PHP_EOL, $output),
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
