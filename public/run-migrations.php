<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Bootstrap the Console Kernel to completely bypass Web/Session Middleware
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    echo "<h1>Migration Success!</h1>";
    echo nl2br(\Illuminate\Support\Facades\Artisan::output());
} catch (\Exception $e) {
    echo "<h1>Migration Failed</h1>";
    echo $e->getMessage();
}
