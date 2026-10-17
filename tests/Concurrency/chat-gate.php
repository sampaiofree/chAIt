<?php

// No SQL writes, HTTP requests or Redis flushes. Every key uses a random test bucket.
use App\Services\Uazapi\UazapiDeferredException;
use App\Services\Uazapi\UazapiGate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'chait_test') {
    throw new RuntimeException('Requires APP_ENV=testing and chait_test.');
}
config(['cache.default' => 'redis']);
$bucket = $argv[2] ?? 'connection:concurrency-test-'.bin2hex(random_bytes(12));
if (! preg_match('/^connection:concurrency-test-[a-f0-9]{24}$/D', $bucket)) {
    throw new RuntimeException('Unsafe test bucket.');
}
$prefix = 'chat-provider:'.$bucket;
$gate = app(UazapiGate::class);
if (($argv[1] ?? '') === 'child') {
    try {
        $release = $gate->acquire($bucket);
    } catch (UazapiDeferredException) {
        echo 'blocked';
        exit(0);
    }
    echo "held\n";
    flush();
    fgets(STDIN);
    $release();
    exit(0);
}
$input = new InputStream;
$child = null;
$release = null;
try {
    $release = $gate->acquire($bucket);
    // A separate process must see the spacing established by the first process.
    $paced = new Process([PHP_BINARY, __FILE__, 'child', $bucket]);
    $paced->setTimeout(10)->run();
    if ($paced->getOutput() !== 'blocked') {
        throw new RuntimeException('Cross-process pacing failed.');
    }
    Cache::forget($prefix.':pace');
    $child = new Process([PHP_BINARY, __FILE__, 'child', $bucket]);
    $child->setInput($input)->setTimeout(10)->start();
    if (! $child->waitUntil(fn ($type, $output) => str_contains($output, 'held'))) {
        throw new RuntimeException('Second slot was not acquired.');
    }
    Cache::forget($prefix.':pace');
    try {
        $extra = $gate->acquire($bucket);
        $extra();
        throw new RuntimeException('Concurrency exceeded two.');
    } catch (UazapiDeferredException) { /* Expected while both leases are held. */
    }
    $input->write("release\n");
    $input->close();
    $child->wait();
    $release();
    $release = null;
    $last = $gate->acquire($bucket);
    $last();
    echo "PASS: shared Redis pacing, two-slot concurrency and lease release across processes.\n";
} finally {
    $input->close();
    if ($child?->isRunning()) {
        $child->stop();
    }
    if ($release) {
        $release();
    }
    foreach (['guard', 'pace', 'human', 'background', 'cooldown', 'limited', 'latency_ms', 'slot:0', 'slot:1'] as $suffix) {
        Cache::forget($prefix.':'.$suffix);
    }
}
