<?php

use App\Models\Workspace;
use App\Models\WorkspaceOpenAiIntegration;
use App\Services\Flows\AiActionService;
use App\Services\Flows\OpenAiClient;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$workspace = Workspace::sole();
$key = WorkspaceOpenAiIntegration::where('workspace_id', $workspace->id)->firstOrFail()->api_key;
$client = app(OpenAiClient::class);
$marker = 'CHAIT-TESTE-742';
$report = ['started_at' => now()->toIso8601String(), 'workspace_id' => $workspace->id, 'inputs' => []];
$path = base_path('artifacts/assistente-ia-homologacao/openai-inputs.json');

// Minimal, valid in-memory PDF fixture. Its text is synthetic; this is not a document deliverable.
$stream = 'BT /F1 16 Tf 50 740 Td (Codigo de teste: '.$marker.') Tj ET';
$objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream"];
$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $index => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 6\n0000000000 65535 f \n";
foreach (array_slice($offsets, 1) as $offset) {
    $pdf .= sprintf("%010d 00000 n \n", $offset);
}
$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
$tmp = tempnam(sys_get_temp_dir(), 'chait-docx-');
$zip = new ZipArchive;
$zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Codigo de teste: '.$marker.'</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$docx = file_get_contents($tmp);
unlink($tmp);
$cases = [
    ['image', [['type' => 'input_text', 'text' => 'Qual é a cor predominante desta imagem? Responda apenas a cor em português.'],
        ['type' => 'input_image', 'image_url' => 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('artifacts/assistente-ia-homologacao/teste-imagem.png')))]], 'verde'],
];
foreach ([['pdf', 'application/pdf', $pdf], ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docx], ['txt', 'text/plain', 'Codigo de teste: '.$marker]] as [$type, $mime, $bytes]) {
    $cases[] = [$type, [['type' => 'input_text', 'text' => 'Qual é o código de teste no documento? Responda somente o código.'],
        ['type' => 'input_file', 'filename' => 'teste.'.$type, 'file_data' => 'data:'.$mime.';base64,'.base64_encode($bytes)]], $marker];
}
foreach ($cases as [$type, $content, $expected]) {
    $id = null;
    $result = ['type' => $type];
    try {
        $id = $client->createConversation($key);
        $result['conversation_id'] = $id;
        $response = $client->respond($key, $id, 'gpt-6.1-sol', 'Teste técnico de leitura de mídia. Responda em texto, sem usar ferramentas de conclusão.', [['role' => 'user', 'content' => $content]], app(AiActionService::class)->tools([]));
        $outcome = $client->outcome($response);
        $result['passed'] = str_contains(mb_strtolower($outcome['text']), mb_strtolower($expected));
        $result['usage'] = $response['usage'] ?? null;
    } catch (Throwable $exception) {
        $result['passed'] = false;
        $result['error_class'] = get_class($exception);
    } finally {
        $result['cleanup_completed'] = false;
        if ($id) {
            try {
                for ($page = 0; $page < 10; $page++) {
                    if ($client->cleanup($key, $id)) {
                        $result['cleanup_completed'] = true;
                        break;
                    }
                }
            } catch (Throwable $exception) {
                $result['cleanup_error_class'] = get_class($exception);
            }
        }
    }
    $report['inputs'][] = $result;
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['input' => $type, 'passed' => $result['passed'], 'cleanup_completed' => $result['cleanup_completed']]).PHP_EOL;
}
$audio = ['type' => 'audio'];
$audioDirectory = sys_get_temp_dir().'/chait-assistant-audio-'.bin2hex(random_bytes(8));
mkdir($audioDirectory, 0700);
try {
    (new Process(['say', '-v', 'Luciana', '-o', $audioDirectory.'/fala.aiff', 'Eu quero fazer o curso de informática.']))->mustRun();
    (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-i', $audioDirectory.'/fala.aiff', '-c:a', 'libmp3lame', '-y', $audioDirectory.'/fala.mp3']))->mustRun();
    $transcript = $client->transcribe($key, file_get_contents($audioDirectory.'/fala.mp3'), 'teste.mp3', 'audio/mpeg');
    $audio['passed'] = str_contains(mb_strtolower($transcript), 'informática') || str_contains(mb_strtolower($transcript), 'informatica');
} catch (Throwable $exception) {
    $audio['passed'] = false;
    $audio['error_class'] = get_class($exception);
} finally {
    foreach (glob($audioDirectory.'/*') as $file) {
        unlink($file);
    }
    rmdir($audioDirectory);
}
$report['inputs'][] = $audio;
$report['completed_at'] = now()->toIso8601String();
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['input' => 'audio', 'passed' => $audio['passed']]).PHP_EOL;
