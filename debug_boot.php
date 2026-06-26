<?php
define('LARAVEL_START', microtime(true));
require __DIR__.'/vendor/autoload.php';

try {
    $app = require_once __DIR__.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL . PHP_EOL;
    echo $e->getTraceAsString();
}