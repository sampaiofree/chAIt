import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { effectScope, ref } from 'vue';
import { useVoiceRecorder } from '../../resources/js/composables/useVoiceRecorder.ts';

let scope;
let microphone;
let tracksStopped;
let revoked;
let recordings;
let supported;
let permission;

class FakeRecorder {
    static isTypeSupported(mime) {
        return mime.startsWith(supported);
    }

    constructor(stream, options) {
        this.mimeType = options.mimeType;
        this.state = 'inactive';
        recordings.push(this);
    }

    start() {
        this.state = 'recording';
    }

    stop() {
        this.state = 'inactive';
        this.ondataavailable?.({
            data: new Blob(['voice'], { type: this.mimeType }),
        });
        this.onstop?.();
    }
}

beforeEach(() => {
    tracksStopped = 0;
    revoked = [];
    recordings = [];
    supported = 'audio/webm';
    microphone = { getTracks: () => [{ stop: () => tracksStopped++ }] };
    permission = async () => microphone;
    mock.timers.enable({
        apis: ['Date', 'setInterval', 'setTimeout'],
        now: 1000,
    });
    mock.method(URL, 'createObjectURL', () => 'blob:preview');
    mock.method(URL, 'revokeObjectURL', (url) => revoked.push(url));
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: {
            mediaDevices: {
                getUserMedia: (constraints) => {
                    assert.deepEqual(constraints, { audio: true });

                    return permission();
                },
            },
        },
    });
    globalThis.isSecureContext = true;
    globalThis.MediaRecorder = FakeRecorder;
    scope = effectScope();
});

afterEach(() => {
    scope.stop();
    mock.restoreAll();
    mock.timers.reset();
    delete globalThis.MediaRecorder;
    delete globalThis.isSecureContext;
    delete globalThis.navigator;
});

function setup() {
    const enabled = ref(true);
    const context = ref('workspace:thread');
    const voice = scope.run(() => useVoiceRecorder(enabled, context));

    return { voice, enabled, context };
}

test('records, stops the microphone and creates a preview without sending', async () => {
    const { voice } = setup();
    assert.equal(recordings.length, 0);
    await voice.start();
    assert.equal(voice.state.value, 'recording');
    mock.timers.tick(2200);
    voice.stop();
    assert.equal(voice.state.value, 'preview');
    assert.equal(voice.blob.value.type, 'audio/webm;codecs=opus');
    assert.equal(voice.seconds.value, 2.2);
    assert.equal(tracksStopped, 1);
    voice.discard();
    assert.equal(voice.blob.value, null);
    assert.deepEqual(revoked, ['blob:preview']);
});

test('two-minute deadline stops in preview rather than sending', async () => {
    const { voice } = setup();
    await voice.start();
    mock.timers.tick(120000);
    assert.equal(voice.state.value, 'preview');
    assert.equal(voice.seconds.value, 120);
    assert.equal(tracksStopped, 1);
});

test('uses the actual supported Safari MP4 MIME', async () => {
    supported = 'audio/mp4';
    const { voice } = setup();
    await voice.start();
    mock.timers.tick(2000);
    voice.stop();
    assert.equal(voice.blob.value.type, 'audio/mp4;codecs=mp4a.40.2');
});

test('reports denied permission and missing microphones', async () => {
    const { voice } = setup();
    permission = async () => {
        throw new DOMException('denied', 'NotAllowedError');
    };
    await voice.start();
    assert.match(voice.error.value, /Permissão de microfone negada/);
    assert.equal(voice.state.value, 'idle');
    permission = async () => {
        throw new DOMException('missing', 'NotFoundError');
    };
    await voice.start();
    assert.match(voice.error.value, /Nenhum microfone/);
});

test('does not request a microphone on insecure or unsupported browsers', async () => {
    const { voice } = setup();
    globalThis.isSecureContext = false;
    await voice.start();
    assert.match(voice.error.value, /HTTPS/);
    globalThis.isSecureContext = true;
    supported = 'unsupported';
    await voice.start();
    assert.match(voice.error.value, /formatos/);
    assert.equal(recordings.length, 0);
});

test('changing conversation discards the recording and stops tracks', async () => {
    const { voice, context } = setup();
    await voice.start();
    mock.timers.tick(2000);
    context.value = 'workspace:another-thread';
    assert.equal(voice.state.value, 'idle');
    assert.equal(voice.blob.value, null);
    assert.equal(tracksStopped, 1);
});

test('losing reply permission discards an existing preview', async () => {
    const { voice, enabled } = setup();
    await voice.start();
    mock.timers.tick(2000);
    voice.stop();
    enabled.value = false;
    assert.equal(voice.state.value, 'idle');
    assert.deepEqual(revoked, ['blob:preview']);
});

test('late permission after cancel releases tracks without starting a recorder', async () => {
    let resolve;
    permission = () =>
        new Promise((done) => {
            resolve = done;
        });
    const { voice } = setup();
    const started = voice.start();
    assert.equal(voice.state.value, 'requesting');
    voice.discard();
    resolve(microphone);
    await started;
    assert.equal(tracksStopped, 1);
    assert.equal(recordings.length, 0);
    assert.equal(voice.state.value, 'idle');
});

test('unmounting releases tracks and timers', async () => {
    const { voice } = setup();
    await voice.start();
    scope.stop();
    mock.timers.tick(120000);
    assert.equal(tracksStopped, 1);
    assert.equal(voice.state.value, 'idle');
});

test('rejects empty, short and oversized recordings', async () => {
    const { voice } = setup();
    await voice.start();
    voice.stop();
    assert.match(voice.error.value, /1 segundo/);
    await voice.start();
    recordings
        .at(-1)
        .ondataavailable({
            data: new Blob([new Uint8Array(10 * 1024 * 1024 + 1)]),
        });
    assert.match(voice.error.value, /10 MB/);
    assert.equal(voice.blob.value, null);
    assert.equal(tracksStopped, 2);
});
