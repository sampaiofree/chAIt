<?php

use App\Models\FlowAsset;
use App\Models\User;
use App\Services\Flows\FlowAssetCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->cleanupWorkspace = User::factory()->create()->fresh()->currentWorkspace;
    $this->cleanupFlow = $this->cleanupWorkspace->flows()->create(['name' => 'Cleanup', 'graph' => ['nodes' => []]]);
    Storage::disk('local')->put('flows/test.png', 'image');
    $this->cleanupAsset = FlowAsset::create(['workspace_id' => $this->cleanupWorkspace->id, 'flow_id' => $this->cleanupFlow->id, 'disk' => 'local', 'path' => 'flows/test.png', 'original_name' => 'test.png', 'mime_type' => 'image/png', 'size' => 5]);
});

test('cleanup removes unreferenced files and is idempotent', function () {
    $service = app(FlowAssetCleanup::class);
    $service->request($this->cleanupFlow, [$this->cleanupAsset->id]);
    $service->run();
    $service->run();
    expect(Storage::disk('local')->exists('flows/test.png'))->toBeFalse()
        ->and(FlowAsset::find($this->cleanupAsset->id))->toBeNull()
        ->and(DB::table('flow_asset_deletions')->count())->toBe(0);
});

test('current references retain files but historical references do not', function () {
    $graph = ['nodes' => [['asset_id' => $this->cleanupAsset->id]]];
    $this->cleanupFlow->update(['graph' => $graph]);
    $this->cleanupFlow->versions()->create(['workspace_id' => $this->cleanupWorkspace->id, 'version' => 1, 'graph' => $graph, 'published_at' => now()]);
    $service = app(FlowAssetCleanup::class);
    $service->request($this->cleanupFlow, [$this->cleanupAsset->id]);
    $service->run();
    expect(Storage::disk('local')->exists('flows/test.png'))->toBeTrue();
    $this->cleanupFlow->update(['graph' => ['nodes' => []]]);
    $service->run();
    expect(Storage::disk('local')->exists('flows/test.png'))->toBeFalse();
});

test('deletion requests survive flow deletion and retry storage failures', function () {
    $disk = Mockery::mock(Storage::disk('local'))->makePartial();
    $disk->shouldReceive('delete')->twice()->andReturn(false);
    Storage::set('local', $disk);
    $this->cleanupFlow->delete();
    app(FlowAssetCleanup::class)->run();
    expect(DB::table('flow_asset_deletions')->value('attempts'))->toBe(2)
        ->and(Storage::disk('local')->exists('flows/test.png'))->toBeTrue();
    $disk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => unlink($disk->path($path)));
    app(FlowAssetCleanup::class)->run();
    expect(Storage::disk('local')->exists('flows/test.png'))->toBeFalse()
        ->and(DB::table('flow_asset_deletions')->count())->toBe(0);
});
