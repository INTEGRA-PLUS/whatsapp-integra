<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Connection: " . config('database.default') . "\n";
echo "Database: " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "Username: " . config('database.connections.' . config('database.default') . '.username') . "\n";
echo "Host: " . config('database.connections.' . config('database.default') . '.host') . "\n";
