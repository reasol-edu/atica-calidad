<?php
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . '/../public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// Symfony Runtime resolves the entry point via SCRIPT_FILENAME; the PHP
// built-in server sets it to this router instead of public/index.php,
// causing intermittent "callable object expected, int returned" fatals.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';
require __DIR__ . '/../public/index.php';
