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
$kind = $argv[1] ?? '';
if (! in_array($kind, ['copy', 'url'], true)) {
    throw new InvalidArgumentException('Informe copy ou url.');
}
$connection = UazapiConnection::findOrFail(1);
$contact = Contact::findOrFail(3);
$field = $connection->workspace->contactCustomFields()->firstOrCreate(['name' => 'Homologação Chip Curto'], ['type' => 'text']);
$contact->customFieldValues()->updateOrCreate(['field_id' => $field->id], ['workspace_id' => $connection->workspace_id, 'value' => 'X7']);
$renderer = app(ContactTemplate::class);
$snapshot = $renderer->snapshot($connection->workspace_id, $contact->id);
$label = $renderer->rendered(['parts' => [['type' => 'text', 'text' => 'L-'], ['type' => 'custom', 'field_id' => $field->id]]], $snapshot, 120, 'label', true);
$valueTemplate = $kind === 'copy'
    ? ['parts' => [['type' => 'text', 'text' => 'C-'], ['type' => 'custom', 'field_id' => $field->id], ['type' => 'text', 'text' => ' {{name}}']]]
    : ['parts' => [['type' => 'text', 'text' => 'https://example.com/chait?c='], ['type' => 'custom', 'field_id' => $field->id]]];
$value = $renderer->rendered($valueTemplate, $snapshot, 4000, $kind, true);
$track = 'chait-live-template-'.$kind.'-'.Str::uuid();
app(TransportContext::class)->direct = true;
$response = app(UazapiClient::class)->sendMenu($connection, $contact->phone, 'button',
    'Homologação de chips em '.$kind.'.', [$label.'|'.$kind.':'.$value], null, $track);
$message = is_array(data_get($response, 'message')) ? $response['message'] : $response;
echo json_encode(['kind' => $kind, 'field_id' => $field->id, 'track_id' => $track,
    'label_sha256' => hash('sha256', $label), 'value_sha256' => hash('sha256', $value),
    'provider_status' => data_get($message, 'status')], JSON_UNESCAPED_SLASHES).PHP_EOL;
