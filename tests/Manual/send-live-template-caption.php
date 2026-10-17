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
$field = $connection->workspace->contactCustomFields()->where('name', 'Homologação Chip')->firstOrFail();
$renderer = app(ContactTemplate::class);
$snapshot = $renderer->snapshot($connection->workspace_id, $contact->id);
$template = ['template_version' => 1, 'parts' => [
    ['type' => 'text', 'text' => 'Legenda literal {{name}}; chip='],
    ['type' => 'custom', 'field_id' => $field->id],
]];
$caption = $renderer->rendered($template, $snapshot, 1024, 'caption');
$image = imagecreatetruecolor(128, 128);
$background = imagecolorallocate($image, 235, 239, 246);
imagefilledrectangle($image, 0, 0, 127, 127, $background);
ob_start();
imagepng($image);
$png = ob_get_clean();
imagedestroy($image);
$track = 'chait-live-template-caption-'.Str::uuid();
app(TransportContext::class)->direct = true;
$response = app(UazapiClient::class)->sendMedia($connection, $contact->phone,
    'data:image/png;base64,'.base64_encode($png), 'image', $caption, null, 'image/png', $track);
$message = is_array(data_get($response, 'message')) ? $response['message'] : $response;
echo json_encode(['track_id' => $track, 'prepared_sha256' => hash('sha256', $caption),
    'prepared_length' => mb_strlen($caption), 'provider_status' => data_get($message, 'status')], JSON_UNESCAPED_SLASHES).PHP_EOL;
