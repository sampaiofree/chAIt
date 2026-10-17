<?php

use App\Models\Contact;
use App\Models\UazapiConnection;
use App\Services\Chat\TransportContext;
use App\Services\Flows\ContactTemplate;
use App\Services\Uazapi\UazapiClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    throw new RuntimeException('Somente no ambiente local.');
}
if (($argv[1] ?? '') !== '--send') {
    throw new InvalidArgumentException('Informe --send para enviar o ensaio real.');
}

$connection = UazapiConnection::findOrFail(1);
$contact = Contact::findOrFail(3);
$field = $connection->workspace->contactCustomFields()->firstOrCreate(['name' => 'Homologação Chip'], ['type' => 'text']);
$contact->customFieldValues()->updateOrCreate(['field_id' => $field->id], ['workspace_id' => $connection->workspace_id,
    'value' => 'Aurora {{name}} café 😃']);
$template = ['template_version' => 1, 'parts' => [
    ['type' => 'text', 'text' => 'ChAIt literal={{first_name}}/{{lead_field01}}; chip='],
    ['type' => 'custom', 'field_id' => $field->id],
    ['type' => 'text', 'text' => '; fim.'],
]];
$renderer = app(ContactTemplate::class);
$prepared = $renderer->rendered($template, $renderer->snapshot($connection->workspace_id, $contact->id), 4000, 'text', true);
$track = 'chait-live-template-text-'.Str::uuid();
app(TransportContext::class)->direct = true;
$response = app(UazapiClient::class)->sendText($connection, $contact->phone, $prepared, $track);
$message = is_array(data_get($response, 'message')) ? $response['message'] : $response;
echo json_encode(['field_id' => $field->id, 'track_id' => $track, 'prepared_sha256' => hash('sha256', $prepared),
    'prepared_length' => mb_strlen($prepared), 'provider_status' => data_get($message, 'status')], JSON_UNESCAPED_SLASHES).PHP_EOL;
