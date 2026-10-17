<?php

use App\Models\Contact;
use App\Models\Thread;
use App\Models\UazapiConnection;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Uazapi\VoiceAudioConverter;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process as NativeProcess;

function voiceFixture(string $format = 'webm', float $seconds = 2, bool $video = false): UploadedFile
{
    $command = ['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000'];
    if ($video) {
        $command = array_merge($command, ['-f', 'lavfi', '-i', 'color=size=16x16:rate=1', '-c:v', 'libvpx']);
    }
    $command = array_merge($command, ['-t', (string) $seconds, '-c:a', $format === 'mp4' ? 'aac' : 'libopus']);
    if ($format === 'mp4') {
        $command = array_merge($command, ['-movflags', 'frag_keyframe+empty_moov+default_base_moof']);
    }
    $process = new NativeProcess(array_merge($command, ['-f', $format, 'pipe:1']));
    $process->setTimeout(15)->mustRun();

    return UploadedFile::fake()->createWithContent('voice.'.$format, $process->getOutput())
        ->mimeType((new finfo(FILEINFO_MIME_TYPE))->buffer($process->getOutput()));
}

function voiceThread(): array
{
    $user = User::factory()->create();
    $workspace = $user->fresh()->currentWorkspace;
    $connection = UazapiConnection::factory()->create(['workspace_id' => $workspace->id]);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
    $thread = Thread::factory()->create([
        'workspace_id' => $workspace->id, 'uazapi_connection_id' => $connection->id,
        'contact_id' => $contact->id, 'external_chat_id' => '551155501234@s.whatsapp.net',
        'status' => 'bot', 'assigned_to_user_id' => null,
    ]);

    return [$user, $workspace, $connection, $thread];
}

function claimVoiceThread(TestCase $test, User $user, $workspace, Thread $thread): void
{
    $test->actingAs($user);
    $test->postJson(route('workspaces.conversations.open', [$workspace, $thread]))->assertOk();
    $test->postJson(route('workspaces.conversations.claim', [$workspace, $thread]))->assertOk();
}

beforeEach(function () {
    config(['services.uazapi.base_url' => 'https://api.uazapi.test']);
    Http::preventStrayRequests();
});

test('voice recordings are converted to OGG Opus and sent as ptt without persistence', function (string $format, int $seconds) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    Http::fake(['*/send/media' => Http::response([
        'id' => 'provider-voice', 'messageType' => 'audioMessage', 'status' => 'Sent',
        'content' => ['ptt' => true, 'seconds' => 2],
    ])]);
    $file = voiceFixture($format, $seconds);
    $source = $file->getRealPath();
    $directories = glob(sys_get_temp_dir().'/chait-voice-*');
    $track = (string) Str::uuid();

    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => $file, 'media_type' => 'voice', 'track_id' => $track,
    ])->assertOk()->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.message.is_audio', true)->assertJsonPath('data.message.is_voice', true)
        ->assertJsonPath('data.message.mime_type', 'audio/ogg')->assertJsonPath('data.message.duration_seconds', $seconds);

    Http::assertSent(function (ClientRequest $request) use ($connection, $track) {
        $bytes = base64_decode(substr($request['file'], strlen('data:audio/ogg;base64,')));

        return $request['type'] === 'ptt' && $request['mimetype'] === 'audio/ogg'
            && $request['track_id'] === $track && $request->hasHeader('token', $connection->instance_token)
            && str_starts_with($bytes, 'OggS') && str_contains($bytes, 'OpusHead')
            && ! isset($request['text']) && ! isset($request['docName']);
    });
    expect(is_file($source))->toBeFalse()
        ->and(glob(sys_get_temp_dir().'/chait-voice-*'))->toBe($directories)
        ->and(DB::table('messages')->count())->toBe(0)
        ->and($thread->fresh()->assigned_to_user_id)->toBe($user->id)
        ->and($thread->fresh()->status)->toBe('in_progress');
})->with([
    'webm' => ['webm', 2], 'ogg' => ['ogg', 2], 'mp4' => ['mp4', 2],
    'one second' => ['webm', 1], 'two minutes OGG' => ['ogg', 120], 'two minutes MP4' => ['mp4', 120],
]);

test('voice validation rejects invalid duration and video without sending or changing assignment', function (float $seconds, bool $video) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    claimVoiceThread($this, $user, $workspace, $thread);
    Http::fake();
    $file = voiceFixture('webm', $seconds, $video);
    $source = $file->getRealPath();
    $directories = glob(sys_get_temp_dir().'/chait-voice-*');

    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => $file, 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');

    Http::assertNothingSent();
    expect(is_file($source))->toBeFalse()
        ->and(glob(sys_get_temp_dir().'/chait-voice-*'))->toBe($directories)
        ->and($thread->fresh()->assigned_to_user_id)->toBe($user->id);
})->with(['short' => [0.5, false], 'too long without WebM duration' => [121.5, false], 'video' => [2, true]]);

test('oversized files and corrupt recordings are rejected before provider dispatch', function (bool $oversized) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    claimVoiceThread($this, $user, $workspace, $thread);
    Http::fake();
    $file = $oversized
        ? UploadedFile::fake()->create('voice.webm', 10241, 'audio/webm')
        : UploadedFile::fake()->createWithContent('voice.webm', 'corrupt audio')->mimeType('audio/webm');
    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => $file, 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('file');
    Http::assertNothingSent();
})->with([true, false]);

test('voice requires its own UUID and does not accept a caption or text', function (array $extra) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    claimVoiceThread($this, $user, $workspace, $thread);
    Http::fake();
    Process::fake();
    $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), array_merge([
        'file' => voiceFixture(), 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ], $extra))->assertUnprocessable();
    Process::assertNothingRan();
    Http::assertNothingSent();
})->with([
    'caption' => [['caption' => 'not a voice caption']],
    'text' => [['text' => 'not a combined message']],
    'invalid UUID' => [['track_id' => 'not-a-uuid']],
    'missing UUID' => [['track_id' => null]],
    'missing file' => [['file' => null]],
]);

test('conversion failure cleans partial output and does not expose process details', function () {
    $file = voiceFixture();
    $source = $file->getRealPath();
    $directories = glob(sys_get_temp_dir().'/chait-voice-*');
    $output = null;
    Process::fake(function ($process) use (&$output) {
        if ($process->timeout === 15) {
            $output = end($process->command);
            file_put_contents($output, 'partial recording');

            return Process::result(errorOutput: 'private contents and filename', exitCode: 1);
        }

        return Process::result(output: json_encode(['streams' => [['codec_type' => 'audio']], 'format' => ['format_name' => 'matroska,webm', 'duration' => '2']]));
    });
    try {
        app(VoiceAudioConverter::class)->convert($file);
        $this->fail('Expected invalid recording');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->not->toContain('private contents');
    }
    expect(is_file($source))->toBeFalse()->and(is_file($output))->toBeFalse()
        ->and(glob(sys_get_temp_dir().'/chait-voice-*'))->toBe($directories);
    Process::assertRan(fn ($process) => $process->timeout === 5 && is_array($process->command));
    Process::assertRan(fn ($process) => $process->timeout === 15 && is_array($process->command));
});

test('missing processing binaries fail safely and clean the source', function () {
    $file = voiceFixture();
    $source = $file->getRealPath();
    $directories = glob(sys_get_temp_dir().'/chait-voice-*');
    config(['services.voice.ffprobe' => '/nonexistent/chait-ffprobe']);
    expect(fn () => app(VoiceAudioConverter::class)->convert($file))->toThrow(ValidationException::class);
    expect(is_file($source))->toBeFalse()->and(glob(sys_get_temp_dir().'/chait-voice-*'))->toBe($directories);
});

test('provider rejections fail while uncertain responses remain pending without resending', function (int $status, array $response, bool $pending) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    claimVoiceThread($this, $user, $workspace, $thread);
    $attempts = 0;
    Http::fake(['*/send/media' => function () use ($status, $response, &$attempts) {
        $attempts++;
        if ($status === 0) {
            throw new ConnectionException('private transport detail');
        }

        return Http::response($response, $status);
    }]);
    $track = (string) Str::uuid();
    $result = $this->actingAs($user)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => voiceFixture(), 'media_type' => 'voice', 'track_id' => $track,
    ]);
    $result->assertStatus($pending ? 202 : (in_array($status, [413, 415, 429]) ? $status : 422))
        ->assertJsonPath('data.status', $pending ? 'confirmation_pending' : 'failed')
        ->assertJsonPath('data.track_id', $track)->assertDontSee('private');
    expect($attempts)->toBe(1)->and(DB::table('messages')->count())->toBe(0);
})->with([
    'bad file' => [400, ['error' => 'private details'], false],
    'credentials' => [401, ['error' => 'private token'], false],
    'too large' => [413, [], false],
    'unsupported format' => [415, [], false],
    'rate limit' => [429, [], false],
    'conflict still uncertain' => [409, [], true],
    'timeout' => [0, [], true],
    'server failure' => [500, [], true],
    'unconfirmed success' => [200, ['response' => ['status' => 'success']], true],
    'queued' => [200, ['id' => 'queued-voice', 'status' => 'Queued'], true],
    'failed status' => [200, ['id' => 'failed-voice', 'status' => 'Failed'], false],
]);

test('voice sending rejects foreign workspaces and disconnected connections before processing', function (string $scenario) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    $sender = $user;
    $routeWorkspace = $workspace;
    $expected = 403;
    if ($scenario === 'foreign-workspace') {
        $routeWorkspace = User::factory()->create()->fresh()->currentWorkspace;
        WorkspaceMembership::factory()->create(['workspace_id' => $routeWorkspace->id, 'user_id' => $sender->id, 'role' => 'admin']);
        $expected = 404;
    } elseif ($scenario === 'disconnected') {
        $connection->update(['status' => 'disconnected']);
        $thread->update(['status' => 'in_progress', 'assigned_to_user_id' => $user->id]);
        $expected = 409;
    }
    Http::fake();
    Process::fake();
    $this->actingAs($sender)->postJson(route('workspaces.conversations.send', [$routeWorkspace, $thread]), [
        'file' => voiceFixture(), 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ])->assertStatus($expected);
    Process::assertNothingRan();
    Http::assertNothingSent();
})->with(['foreign-workspace', 'disconnected']);

test('agents can start an unassigned conversation by sending voice', function () {
    [$user, $workspace, $connection, $thread] = voiceThread();
    $agent = User::factory()->create();
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $agent->id, 'role' => 'agent', 'can_view_unassigned' => true]);
    Http::fake(['*/send/media' => Http::response(['id' => 'voice', 'messageType' => 'audioMessage', 'status' => 'Sent'])]);
    $this->actingAs($agent)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => voiceFixture(), 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('data.message.is_voice', true);
    expect($thread->fresh()->assigned_to_user_id)->toBe($agent->id);
});

test('audio history normalizes received and sent voice and downloads authorized MP3 only', function () {
    [$user, $workspace, $connection, $thread] = voiceThread();
    $track = (string) Str::uuid();
    $message = [
        'id' => 'audio-1', 'chatid' => $thread->external_chat_id, 'messageType' => 'audioMessage',
        'track_id' => $track, 'fromMe' => false, 'content' => json_encode(['audioMessage' => ['ptt' => true, 'seconds' => 3, 'mimetype' => 'audio/ogg']]),
    ];
    $foreign = false;
    Http::fake([
        '*/message/find' => function () use ($message, &$foreign) {
            return Http::response(['messages' => [$foreign ? array_merge($message, ['chatid' => 'another-chat']) : $message]]);
        },
        '*/message/download' => Http::response(['fileURL' => 'https://cdn.test/voice.mp3', 'mimetype' => 'audio/mpeg']),
    ]);
    $this->actingAs($user)->getJson(route('workspaces.conversations.messages', [$workspace, $thread]).'?track_id='.$track)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_audio', true)
        ->assertJsonPath('data.0.is_voice', true)->assertJsonPath('data.0.duration_seconds', 3)
        ->assertJsonPath('data.0.from_me', false)->assertJsonPath('data.0.track_id', $track);
    $this->getJson(route('workspaces.conversations.media', [$workspace, $thread, 'audio-1']))
        ->assertOk()->assertJsonPath('data.url', 'https://cdn.test/voice.mp3');
    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/message/download')
        && $request['generate_mp3'] === true && $request['return_base64'] === false && $request['transcribe'] === false);
    $foreign = true;
    $this->getJson(route('workspaces.conversations.media', [$workspace, $thread, 'audio-1']))->assertNotFound();
    expect(Http::recorded(fn (ClientRequest $request) => str_ends_with($request->url(), '/message/download'))->count())->toBe(1);
});

test('processing timeouts clean partial output and hide command details', function () {
    $file = voiceFixture();
    $source = $file->getRealPath();
    $directories = glob(sys_get_temp_dir().'/chait-voice-*');
    Process::fake(function ($process) {
        if ($process->timeout === 15) {
            file_put_contents(end($process->command), 'private partial recording');
            $native = new NativeProcess($process->command);
            $native->setTimeout(15);
            $original = new Symfony\Component\Process\Exception\ProcessTimedOutException($native, 1);
            throw new ProcessTimedOutException($original, Process::result());
        }

        return Process::result(output: json_encode(['streams' => [['codec_type' => 'audio']], 'format' => ['format_name' => 'webm']]));
    });
    try {
        app(VoiceAudioConverter::class)->convert($file);
        $this->fail('Expected timeout rejection');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->not->toContain($source)->not->toContain('private partial');
    }
    expect(is_file($source))->toBeFalse()->and(glob(sys_get_temp_dir().'/chait-voice-*'))->toBe($directories);
});

test('permissions and connection are rechecked after conversion before provider dispatch', function (string $change) {
    [$user, $workspace, $connection, $thread] = voiceThread();
    if ($change === 'transfer') {
        $sender = User::factory()->create();
        WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $sender->id, 'role' => 'agent', 'can_view_unassigned' => true]);
        $thread->update(['status' => 'in_progress', 'assigned_to_user_id' => $sender->id]);
    } else {
        $sender = $user;
        claimVoiceThread($this, $sender, $workspace, $thread);
    }
    Http::fake();
    $this->mock(VoiceAudioConverter::class, function ($mock) use ($change, $sender, $user, $workspace, $connection, $thread) {
        $mock->shouldReceive('convert')->once()->andReturnUsing(function ($file) use ($change, $sender, $user, $workspace, $connection, $thread) {
            $voice = (new VoiceAudioConverter)->convert($file);
            if ($change === 'transfer') {
                $thread->update(['assigned_to_user_id' => $user->id]);
            } elseif ($change === 'role') {
                WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $sender->id)
                    ->update(['role' => 'agent', 'can_view_unassigned' => true]);
            } else {
                $connection->update(['status' => 'disconnected']);
            }

            return $voice;
        });
    });
    $this->actingAs($sender)->postJson(route('workspaces.conversations.send', [$workspace, $thread]), [
        'file' => voiceFixture(), 'media_type' => 'voice', 'track_id' => (string) Str::uuid(),
    ])->assertStatus(409);
    Http::assertNothingSent();
})->with(['transfer', 'connection']);
