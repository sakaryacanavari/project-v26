<?php

/**
 * Small dependency-free PHP 8 compatibility scan.
 * This is intentionally narrower than a full type analyser.
 */

$root = dirname(__DIR__);
$files = [$root . '/bootstrap.php', $root . '/routes.php'];
$directories = [$root . '/App/System'];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

$removedFunctions = [
    'create_function',
    'each',
    'mysql_connect',
    'mysql_query',
    'mysql_select_db',
    'ereg',
    'eregi',
    'split',
    'spliti',
    'money_format',
];

$errors = [];
$checked = 0;

foreach (array_unique($files) as $file) {
    if (!is_file($file)) {
        continue;
    }

    $checked++;
    $tokens = token_get_all((string) file_get_contents($file));
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $name = strtolower($token[1]);
        if (!in_array($name, $removedFunctions, true)) {
            continue;
        }

        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if ($next < $count && $tokens[$next] === '(') {
            $errors[] = basename($file) . ':' . $token[2] . ' uses removed PHP function ' . $name . '().';
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'PHP 8 compatibility scan passed for ' . $checked . ' files.' . PHP_EOL);
