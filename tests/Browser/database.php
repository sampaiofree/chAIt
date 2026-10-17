<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, 'Browser database operation refused or failed: '.get_class($error)."\n");
    exit(1);
});
$schema = config('database.connections.pgsql.search_path');
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'chait_test'
    || config('database.connections.pgsql.url')
    || ! preg_match('/\Achait_browser_[a-f0-9]{32}\z/', $schema)) {
    throw new RuntimeException('Browser fixtures require an isolated PostgreSQL schema in chait_test.');
}
if (($argv[1] ?? '') === 'create') {
    DB::statement('CREATE SCHEMA "'.$schema.'"');
} elseif (($argv[1] ?? '') === 'drop') {
    DB::statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
} else {
    throw new RuntimeException('Unknown browser database operation.');
}
