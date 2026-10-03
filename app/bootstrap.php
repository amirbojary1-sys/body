<?php
declare(strict_types=1);
require_once __DIR__ . '/Config.php';
\FitBot\Config::boot(dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'FitBot\\')) return;
    $name = substr($class, 7);
    if (!preg_match('/^[A-Za-z]+$/', $name)) return;
    $file = __DIR__ . '/' . $name . '.php';
    if (is_file($file)) require_once $file;
});
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
if (PHP_VERSION_ID < 80200) throw new RuntimeException('PHP 8.2 or newer is required.');
if (!extension_loaded('mbstring')) throw new RuntimeException('The mbstring extension is required.');
