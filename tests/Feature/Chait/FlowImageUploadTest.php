<?php

use App\Jobs\Chat\ProcessChatOperation;
use App\Models\ChatOperation;
use App\Models\Contact;
use App\Models\FlowAsset;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Services\Flows\FlowMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function flowImageBytes(int $width = 2400, int $height = 1800, string $format = 'png', bool $transparent = false): string
{
    $image = new Imagick;
    try {
        $image->newPseudoImage($width, $height, 'gradient:#f12345-#1234ef');
        if ($transparent) {
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $image->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.5, Imagick::CHANNEL_ALPHA);
        }
        $image->setImageFormat($format);
        $image->setImageCompressionQuality(98);
        // Representative metadata also makes the smaller-original comparison
        // deterministic without depending on a particular encoder version.
        $image->setImageProperty('comment', base64_encode(hash('sha512', 'photo', true)).str_repeat('photograph metadata ', 2000));

        return $image->getImageBlob();
    } finally {
        $image->clear();
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->imageUser = User::factory()->create();
    $this->imageWorkspace = $this->imageUser->fresh()->currentWorkspace;
    $this->imageFlow = $this->imageWorkspace->flows()->create(['name' => 'Imagens', 'graph' => ['nodes' => [], 'edges' => []]]);
    $token = (string) Str::uuid();
    $this->actingAs($this->imageUser)->postJson(route('workspaces.flows.edit-lock.acquire', [$this->imageWorkspace, $this->imageFlow]), ['token' => $token])->assertOk();
    $this->withHeader('X-Flow-Edit-Token', $token);
    $this->imageUploadUrl = route('workspaces.flows.assets', [$this->imageWorkspace, $this->imageFlow]);
});

test('flow image uploads preserve proportions and return coherent stored metadata', function (int $width, int $height, int $expectedWidth, int $expectedHeight, string $format) {
    $original = flowImageBytes($width, $height, $format);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('arte.'.$format, $original)])
        ->assertCreated()->assertJsonPath('data.media_type', 'image')->assertJsonPath('data.mime_type', 'image/jpeg')->assertJsonPath('data.original_name', 'arte.jpg');
    $asset = FlowAsset::findOrFail($response->json('data.id'));
    $bytes = app(FlowMedia::class)->contents($asset);
    $image = new Imagick;
    $image->readImageBlob($bytes);
    expect([$image->getImageWidth(), $image->getImageHeight()])->toBe([$expectedWidth, $expectedHeight])
        ->and(strlen($bytes))->toBeLessThan(strlen($original))
        ->and((int) $asset->size)->toBe(strlen($bytes))
        ->and($response->json('data.size'))->toBe(strlen($bytes))
        ->and(pathinfo($asset->path, PATHINFO_EXTENSION))->toBe('jpg')
        ->and($image->getImageProperties('comment'))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    $image->clear();
})->with([
    'landscape PNG' => [2400, 1800, 1600, 1200, 'png'],
    'portrait JPEG' => [1800, 2400, 1200, 1600, 'jpeg'],
    'square JPEG' => [2000, 2000, 1600, 1600, 'jpeg'],
    'small JPEG' => [320, 240, 320, 240, 'jpeg'],
]);

test('transparent PNG uploads preserve alpha and use a single smaller PNG', function () {
    $original = flowImageBytes(2400, 1800, 'png', true);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('logo.png', $original)])
        ->assertCreated()->assertJsonPath('data.mime_type', 'image/png')->assertJsonPath('data.original_name', 'logo.png');
    $bytes = app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id')));
    $image = new Imagick;
    $image->readImageBlob($bytes);
    expect($image->getImageAlphaChannel())->toBeTrue()
        ->and($image->getImageChannelMean(Imagick::CHANNEL_ALPHA)['mean'])->toBeLessThan($image->getQuantumRange()['quantumRangeLong'])
        ->and([$image->getImageWidth(), $image->getImageHeight()])->toBe([1600, 1200])
        ->and(strlen($bytes))->toBeLessThan(strlen($original))
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    $image->clear();
});

test('already smaller uploads are stored byte for byte', function () {
    $image = new Imagick;
    $image->newImage(40, 30, 'red', 'png');
    $image->stripImage();
    $original = $image->getImageBlob();
    $image->clear();
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('small.png', $original)])
        ->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
    expect(app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id'))))->toBe($original);
});

test('JPEG orientation is applied before resizing and EXIF is removed', function () {
    $jpeg = flowImageBytes(1800, 2400, 'jpeg');
    $tiff = "II\x2A\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00"
        .pack('v', 6)."\x00\x00\x00\x00\x00\x00";
    $exif = "Exif\x00\x00".$tiff;
    $original = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('rotated.jpeg', $original)])->assertCreated();
    $bytes = app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id')));
    $image = new Imagick;
    $image->readImageBlob($bytes);
    expect([$image->getImageWidth(), $image->getImageHeight()])->toBe([1600, 1200])
        ->and($image->getImageProfiles('exif'))->toBeEmpty();
    $image->clear();
});

test('processing restores Imagick resource limits on success and validation failure', function () {
    $image = new Imagick;
    $resources = [Imagick::RESOURCETYPE_MEMORY, Imagick::RESOURCETYPE_MAP, Imagick::RESOURCETYPE_DISK, Imagick::RESOURCETYPE_THREAD, Imagick::RESOURCETYPE_TIME];
    $limits = array_map(fn ($resource) => $image->getResourceLimit($resource), $resources);
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('valid.jpeg', flowImageBytes(100, 80, 'jpeg'))])->assertCreated();
    expect(array_map(fn ($resource) => $image->getResourceLimit($resource), $resources))->toBe($limits);
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('broken.png', substr(flowImageBytes(10, 10), 0, 45))])->assertUnprocessable();
    expect(array_map(fn ($resource) => $image->getResourceLimit($resource), $resources))->toBe($limits);
    $image->clear();
});

test('GIF WebP and documents bypass optimization', function (string $format) {
    $original = $format === 'txt' ? 'Documento de exemplo' : flowImageBytes(100, 80, $format);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('original.'.$format, $original)])
        ->assertCreated()->assertJsonPath('data.original_name', 'original.'.$format);
    expect(app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id'))))->toBe($original);
})->with(['gif', 'webp', 'txt']);

test('APNG uploads bypass optimization even when the decoder only exposes one frame', function () {
    $png = flowImageBytes(100, 80);
    $data = pack('NN', 2, 0);
    $chunk = pack('N', strlen($data)).'acTL'.$data.pack('N', crc32('acTL'.$data));
    $original = substr($png, 0, 33).$chunk.substr($png, 33);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('animation.png', $original)])->assertCreated();
    expect(app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id'))))->toBe($original);
});

test('animated GIF and WebP uploads retain every original frame', function (string $format) {
    $animation = new Imagick;
    foreach (['red', 'blue'] as $color) {
        $frame = new Imagick;
        $frame->newImage(40, 30, $color, $format);
        $frame->setImageDelay(20);
        $animation->addImage($frame);
        $frame->clear();
    }
    $animation->setImageFormat($format);
    $original = $animation->getImagesBlob();
    $animation->clear();
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('animation.'.$format, $original)])->assertCreated();
    expect(app(FlowMedia::class)->contents(FlowAsset::findOrFail($response->json('data.id'))))->toBe($original);
})->with(['gif', 'webp']);

test('invalid images and excessive dimensions leave no assets or files', function (string $case) {
    if ($case === 'corrupt') {
        $original = substr(flowImageBytes(100, 80), 0, 45);
    } else {
        $width = $case === 'side' ? 16_385 : 8000;
        $height = $case === 'side' ? 1 : 8000;
        $png = flowImageBytes(10, 10);
        $header = pack('NN', $width, $height).substr($png, 24, 5);
        $original = substr($png, 0, 16).$header.pack('N', crc32('IHDR'.$header)).substr($png, 33);
    }
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('invalid.png', $original)])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(FlowAsset::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['corrupt', 'side', 'pixels']);

test('image uploads still require the workspace and exclusive edit token', function () {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', flowImageBytes(100, 80, 'jpeg'));
    $this->withHeader('X-Flow-Edit-Token', (string) Str::uuid())->postJson($this->imageUploadUrl, ['file' => $file])->assertConflict();
    $foreign = User::factory()->create();
    $this->actingAs($foreign)->postJson($this->imageUploadUrl, ['file' => $file])->assertForbidden();
    expect(FlowAsset::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('file size and MIME extension validation still apply before processing', function () {
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->create('large.jpg', 20481, 'image/jpeg')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('wrong.jpg', flowImageBytes(10, 10))->mimeType('image/png')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(FlowAsset::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('a new upload does not alter existing flow assets', function () {
    $original = flowImageBytes(1800, 2400, 'jpeg');
    $path = 'flows/legacy.jpg';
    Storage::disk('local')->put($path, $original);
    $legacy = FlowAsset::create([
        'workspace_id' => $this->imageWorkspace->id, 'flow_id' => $this->imageFlow->id,
        'disk' => 'local', 'path' => $path, 'original_name' => 'legacy.jpg', 'mime_type' => 'image/jpeg', 'size' => strlen($original),
    ]);
    $attributes = $legacy->fresh()->getAttributes();
    $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('new.jpeg', flowImageBytes(100, 80, 'jpeg'))])->assertCreated();
    expect($legacy->fresh()->getAttributes())->toBe($attributes)
        ->and(Storage::disk('local')->get($path))->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

test('file write and database failures clean up the definitive file', function (string $failure) {
    if ($failure === 'database') {
        FlowAsset::creating(fn () => throw new RuntimeException('Database unavailable.'));
    } else {
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $bytes) use ($disk) {
            $disk->makeDirectory(dirname($path));
            file_put_contents($disk->path($path), 'partial');

            return false;
        });
        Storage::set('local', $disk);
    }
    try {
        $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('photo.jpeg', flowImageBytes(100, 80, 'jpeg'))])->assertServerError();
        expect(FlowAsset::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBeEmpty();
    } finally {
        if ($failure === 'database') {
            FlowAsset::flushEventListeners();
        }
    }
})->with(['database', 'storage']);

test('background asset transport sends the stored optimized bytes and MIME to UAZAPI', function (bool $transparent) {
    config(['services.uazapi.base_url' => 'https://provider.test', 'broadcasting.default' => 'null']);
    Http::fake(['*/send/media' => Http::response(['id' => 'optimized-image-sent', 'status' => 'sent'])]);
    $response = $this->postJson($this->imageUploadUrl, ['file' => UploadedFile::fake()->createWithContent('art.png', flowImageBytes(2400, 1800, 'png', $transparent))])->assertCreated();
    $asset = FlowAsset::findOrFail($response->json('data.id'));
    $connection = UazapiConnection::factory()->create(['workspace_id' => $this->imageWorkspace->id, 'status' => 'connected']);
    $contact = Contact::factory()->create(['workspace_id' => $this->imageWorkspace->id]);
    $thread = Thread::factory()->create(['workspace_id' => $this->imageWorkspace->id, 'uazapi_connection_id' => $connection->id, 'contact_id' => $contact->id]);
    $operation = ChatOperation::create([
        'workspace_id' => $this->imageWorkspace->id, 'uazapi_connection_id' => $connection->id, 'thread_id' => $thread->id,
        'kind' => 'transport', 'status' => 'pending', 'track_id' => (string) Str::uuid(), 'expires_at' => now()->addHour(),
        'payload' => ['transport' => 'asset', 'asset_id' => $asset->id, 'to' => $contact->phone, 'media_type' => 'image'],
    ]);
    app()->call([new ProcessChatOperation($operation->id), 'handle']);
    expect($operation->fresh()->status)->toBe('completed');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/send/media')
        && $request['type'] === 'image' && $request['mimetype'] === $asset->mime_type
        && $request['file'] === 'data:'.$asset->mime_type.';base64,'.base64_encode(app(FlowMedia::class)->contents($asset)));
})->with([false, true]);
