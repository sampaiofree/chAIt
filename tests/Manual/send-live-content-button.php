<?php

use App\Models\UazapiConnection;
use App\Services\Chat\TransportContext;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Somente no ambiente local.');
}

$kind = $argv[1] ?? '';
$longReplyId = 'content:1234567890123456789:'.str_repeat('a', 32);
[$label, $value] = match ($kind) {
    'url' => ['Abrir link', 'url:https://example.com/chait?ensaio=20261016'],
    'copy' => ['Copiar código', 'copy:CODIGO-CHAIT-62'],
    'reply' => ['Responder teste', $longReplyId],
    default => throw new InvalidArgumentException('Informe url, copy ou reply.'),
};
$connection = UazapiConnection::findOrFail(1);
$track = 'chait-live-content-'.$kind.'-'.Str::uuid();
app(TransportContext::class)->direct = true;
$response = app(UazapiClient::class)->sendMenu($connection, '556295772922@s.whatsapp.net', 'button',
    'Homologação do botão '.strtoupper($kind).'.', [$label.'|'.$value], null, $track);
$message = is_array(data_get($response, 'message')) ? $response['message'] : $response;
echo json_encode([
    'kind' => $kind,
    'track_id' => $track,
    'provider_id_hash' => hash('sha256', (string) (data_get($message, 'id') ?? data_get($message, 'messageid') ?? '')),
    'provider_status' => data_get($message, 'status'),
    'choice_hash' => hash('sha256', $value),
], JSON_UNESCAPED_SLASHES).PHP_EOL;
