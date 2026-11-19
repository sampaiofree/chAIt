<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// This router is only used by the isolated Playwright server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}

define('LARAVEL_START', microtime(true));
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->make(Vite::class)->useHotFile(sys_get_temp_dir().'/chait-browser-no-hot-file');
// Opt-in fixture for the isolated WhatsApp verification browser test only.
if ($app->environment('testing') && getenv('CHAIT_BROWSER_WHATSAPP_CHECK') === 'true') {
    config(['services.uazapi.base_url' => 'https://uazapi.browser.test', 'inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    Http::fake([
        'https://uazapi.browser.test/instance/status' => Http::response(['status' => 'connected']),
        'https://uazapi.browser.test/chat/check' => function ($request) {
            $phone = $request['numbers'][0];
            if ($phone === '5511987654323') {
                return Http::response([], 503);
            }

            return Http::response([[
                'query' => $phone, 'isInWhatsapp' => $phone !== '5511987654322',
            ]]);
        },
    ]);
}
$app->handleRequest(Request::capture());
